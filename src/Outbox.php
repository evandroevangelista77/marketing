<?php

namespace GlpiPlugin\Marketing;

/**
 * Fila de e-mails. O envio acontece na rotina agendada (cron "marketingmail") e só se as notificações por
 * e-mail do GLPI estiverem ligadas; sem SMTP os avisos ficam na fila, sem perder nada.
 */
class Outbox
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_marketing_outbox';
    }

    public static function push(int $userId, string $event, array $demand, string $title, string $message, string $dedup = ''): bool
    {
        global $DB;
        $key = $dedup !== '' ? mb_substr($event . ':' . $userId . ':' . $dedup, 0, 120) : null;
        if ($key !== null && countElementsInTable(self::getTable(), ['dedup_key' => $key])) {
            return false;
        }
        $link = !empty($demand['id']) ? "\n\n" . rtrim((string) ($GLOBALS['CFG_GLPI']['url_base'] ?? ''), '/') . '/plugins/marketing/front/demand.php?id=' . (int) $demand['id'] : '';
        $DB->insert(self::getTable(), [
            'users_id'      => $userId,
            'demands_id'    => (int) ($demand['id'] ?? 0),
            'event'         => $event,
            'subject'       => '[Marketing] ' . mb_substr($title, 0, 200),
            'body'          => $message . $link,
            'dedup_key'     => $key,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    public static function pendingCount(): int
    {
        return countElementsInTable(self::getTable(), ['sent_at' => null, 'error' => null]);
    }

    /** Envia até $limit e-mails da fila. Devolve quantos saíram. */
    public static function flush(int $limit = 20): int
    {
        global $DB, $CFG_GLPI;
        if (empty($CFG_GLPI['notifications_mailing'])) {
            return 0;
        }
        $sent = 0;
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['sent_at' => null, 'error' => null], 'ORDER' => 'id ASC', 'LIMIT' => $limit]) as $row) {
            $email = \UserEmail::getDefaultForUser((int) $row['users_id']);
            if (!$email) {
                $DB->update(self::getTable(), ['error' => 'Usuário sem e-mail'], ['id' => (int) $row['id']]);
                continue;
            }
            try {
                $mail = new \GLPIMailer();
                $mail->addAddress($email, getUserName((int) $row['users_id']));
                $mail->Subject = $row['subject'];
                $mail->isHTML(false);
                $mail->Body = $row['body'];
                if ($mail->send()) {
                    $DB->update(self::getTable(), ['sent_at' => date('Y-m-d H:i:s')], ['id' => (int) $row['id']]);
                    $sent++;
                } else {
                    $DB->update(self::getTable(), ['error' => mb_substr((string) $mail->ErrorInfo, 0, 250)], ['id' => (int) $row['id']]);
                }
            } catch (\Throwable $e) {
                $DB->update(self::getTable(), ['error' => mb_substr($e->getMessage(), 0, 250)], ['id' => (int) $row['id']]);
            }
        }
        return $sent;
    }
}

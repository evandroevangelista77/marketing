<?php

namespace GlpiPlugin\Marketing;

/**
 * Book executivo: reúne indicadores, OKRs, projetos, planos de ação e planos de recuperação de um período, gera o PDF (BookPdf),
 * guarda o arquivo (histórico) e envia por e-mail. Mensal (todo dia 1, com o mês anterior) e anual (fim do ano), pela rotina agendada.
 */
class Book
{
    public const KINDS = ['monthly' => 'Mensal', 'annual' => 'Anual', 'manual' => 'Sob demanda'];

    public static function table(): string
    {
        return 'glpi_plugin_marketing_books';
    }

    public static function dir(): string
    {
        $base = defined('GLPI_PLUGIN_DOC_DIR') ? GLPI_PLUGIN_DOC_DIR : GLPI_DOC_DIR . '/_plugins';
        return rtrim((string) $base, '/') . '/marketing/books';
    }

    /** Logotipo (PNG ou JPG) usado na capa; enviado pelo gerente em KPIs → Book. */
    public static function logoPath(): ?string
    {
        $f = Settings::get('book_logo', '');
        $path = rtrim(self::dir(), '/') . '/../' . basename($f);
        if ($f !== '' && is_file($path)) {
            return self::flatten($path);
        }
        // sem logotipo enviado: usa a árvore da Oakmont que acompanha o plugin (branca sobre azul escuro), para que todas as páginas tenham marca
        $default = dirname(__DIR__) . '/public/img/oak-tree.png';
        return is_file($default) ? $default : null;
    }

    /** Devolve um PNG sem transparência (o PDF não reproduz bem PNG com canal alfa). Usa um arquivo temporário. */
    public static function flatten(string $path): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return $path;
        }
        $info = @getimagesize($path);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            return $path;
        }
        $src = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
        if (!$src) {
            return $path;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $dst = imagecreatetruecolor($w, $h);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        $tmp = sys_get_temp_dir() . '/mkt-logo-' . md5($path . filemtime($path)) . '.png';
        return (is_file($tmp) || imagepng($dst, $tmp, 6)) ? $tmp : $path;
    }

    public static function saveLogo(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return 'Escolha uma imagem PNG ou JPG.';
        }
        $info = @getimagesize((string) $file['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true) || (int) $file['size'] > 3145728) {
            return 'O logotipo deve ser PNG ou JPG de até 3 MB.';
        }
        $dir = dirname(self::dir());
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return 'Não foi possível criar a pasta.';
        }
        $name = 'book-logo-' . bin2hex(random_bytes(6)) . '.png';
        $dest = $dir . '/' . $name;
        $done = false;
        if (function_exists('imagecreatetruecolor')) {
            // o PDF não reproduz bem PNG com transparência: o logotipo é achatado sobre fundo branco (a capa o coloca num quadro branco)
            $src = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng((string) $file['tmp_name']) : @imagecreatefromjpeg((string) $file['tmp_name']);
            if ($src) {
                $w = imagesx($src);
                $h = imagesy($src);
                $dst = imagecreatetruecolor($w, $h);
                imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
                imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
                $done = imagepng($dst, $dest, 6);
            }
        }
        if (!$done && !@move_uploaded_file((string) $file['tmp_name'], $dest)) {
            return 'Não foi possível gravar o logotipo.';
        }
        $old = Settings::get('book_logo', '');
        if ($old !== '') {
            @unlink($dir . '/' . basename($old));
        }
        Settings::save(['book_logo' => $name]);
        return null;
    }

    // ------------------------------------------------------------------ períodos

    /** @return array{0: string, 1: string, 2: string} [de, até, rótulo] do mês 'AAAA-MM' */
    public static function monthPeriod(string $month): array
    {
        $from = $month . '-01';
        $names = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        return [$from, date('Y-m-t', strtotime($from)), $names[(int) date('n', strtotime($from))] . ' de ' . date('Y', strtotime($from))];
    }

    public static function yearPeriod(int $year): array
    {
        return [$year . '-01-01', $year . '-12-31', 'ano de ' . $year];
    }

    // ------------------------------------------------------------------ dados

    /**
     * Tudo o que o book mostra, calculado uma vez.
     *
     * @return array<string, mixed>
     */
    public static function collect(string $kind, string $from, string $to, string $label): array
    {
        Kpi::resetCache();
        $asof = min($to, date('Y-m-d'));
        $today = date('Y-m-d');
        $len = max(1, (int) round((strtotime($to) - strtotime($from)) / 86400) + 1);
        if ($kind === 'annual') {
            $pFrom = date('Y-m-d', strtotime('-1 year', strtotime($from)));
            $pTo = date('Y-m-d', strtotime('-1 year', strtotime($to)));
        } else {
            $pTo = date('Y-m-d', strtotime('-1 day', strtotime($from)));
            $pFrom = date('Y-m-d', strtotime('-' . ($len - 1) . ' day', strtotime($pTo)));
            if ($kind === 'monthly') {
                $pFrom = date('Y-m-01', strtotime($pTo));
            }
        }
        $vals = Kpi::values($from, $to);
        $prev = Kpi::values($pFrom, $pTo);
        $sum = Kpi::summarize($vals, $prev);
        // série de 12 meses terminando no mês final do período
        $series = Kpi::series(date('Y-m', strtotime($to)), 12);
        $monthly = [];
        foreach ($series as $m => $mv) {
            $ms = Kpi::summarize($mv);
            $empty = ((float) ($mv['entradas'] ?? 0)) === 0.0 && ((float) ($mv['backlog'] ?? 0)) === 0.0;
            $monthly[$m] = ['overall' => $empty ? null : $ms['overall'], 'groups' => array_map(fn ($g) => $empty ? null : $g['score'], $ms['groups'])];
        }
        $offenders = Kpi::offenders($sum['rows'], date('Y-m-d'));
        // OKRs que se sobrepõem ao período
        $okrs = [];
        foreach (Okr::all(true) as $o) {
            if ($o['date_from'] <= $to && $o['date_to'] >= $from) {
                $okrs[] = Okr::evaluate($o, $asof);
            }
        }
        // planos de ação
        $plans = [];
        $acts = [];
        foreach (Structure::activities([]) as $a) {
            if ((int) $a['plans_id'] > 0) {
                $acts[(int) $a['plans_id']][] = $a;
            }
        }
        $projects = Structure::projects();
        foreach (Structure::plans() as $pid => $pl) {
            if ($pl['is_archived']) {
                continue;
            }
            $rows = Structure::withoutSources($acts[$pid] ?? []);
            $late = count(array_filter($rows, fn ($r) => Demand::isOverdue($r, $today)));
            $plans[] = ['id' => $pid, 'name' => $pl['name'], 'project' => $projects[(int) $pl['mkt_projects_id']]['name'] ?? '—', 'owner' => (int) $pl['users_id'],
                'end' => $pl['end_date'], 'activities' => count($rows), 'progress' => Structure::progress($rows), 'effective' => Structure::effective($rows),
                'expected' => Structure::expected($pl['start_date'], $pl['end_date'], $today), 'late' => $late,
                'open' => count(array_filter($rows, fn ($r) => Demand::isOpen($r)))];
        }
        usort($plans, fn ($a, $b) => ($b['late'] <=> $a['late']) ?: (($a['effective'] ?? 0) <=> ($b['effective'] ?? 0)));
        // marcos dos próximos 30 dias
        global $DB;
        $milestones = [];
        foreach ($DB->request(['FROM' => Structure::milestonesTable(), 'WHERE' => ['is_done' => 0, 'due_date' => ['<=', date('Y-m-d', strtotime('+30 day'))]], 'ORDER' => 'due_date ASC']) as $m) {
            $milestones[] = $m + ['project' => $projects[(int) $m['mkt_projects_id']]['name'] ?? '—'];
        }
        // carga por pessoa e distribuição por pilar (retrato do fim do período)
        $load = [];
        $byPillar = [];
        foreach (Structure::activities([]) as $a) {
            if (Demand::isOpen($a)) {
                if ((int) $a['users_id_assigned'] > 0) {
                    $load[(int) $a['users_id_assigned']] = ($load[(int) $a['users_id_assigned']] ?? 0) + 1;
                }
            }
            $pn = (int) $a['pillars_id'] > 0 ? (Structure::pillars()[(int) $a['pillars_id']]['name'] ?? 'Sem pilar') : 'Sem pilar';
            $byPillar[$pn] = ($byPillar[$pn] ?? 0) + 1;
        }
        arsort($load);
        arsort($byPillar);
        return [
            'kind' => $kind, 'label' => $label, 'from' => $from, 'to' => $to, 'asof' => $asof, 'prevFrom' => $pFrom, 'prevTo' => $pTo,
            'vals' => $vals, 'prev' => $prev, 'sum' => $sum, 'series' => $series, 'monthly' => $monthly, 'offenders' => $offenders,
            'offenderDemands' => Kpi::offenderDemands($asof === $today || $to >= date('Y-m-d', strtotime('-6 day')) ? $today : $asof, 25), 'projectRisks' => Kpi::projectRisks($today), 'plans' => $plans, 'okrs' => $okrs,
            'milestones' => $milestones, 'load' => array_slice($load, 0, 12, true), 'byPillar' => $byPillar, 'narrative' => Kpi::narrative($sum, $label),
            'generated' => date('Y-m-d H:i:s'),
        ];
    }

    // ------------------------------------------------------------------ geração e guarda

    /** Gera o PDF do período, guarda o arquivo e registra no histórico. Devolve o id do book. */
    public static function generate(string $kind, string $from, string $to, string $label, int $userId = 0): int
    {
        global $DB;
        $data = self::collect($kind, $from, $to, $label);
        $author = $userId > 0 ? getUserName($userId) : 'Envio automático';
        $pdf = BookPdf::render($data, $author, self::logoPath());
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return 0;
        }
        $stored = bin2hex(random_bytes(16)) . '.pdf';
        if (file_put_contents($dir . '/' . $stored, $pdf) === false) {
            return 0;
        }
        @chmod($dir . '/' . $stored, 0640);
        $DB->insert(self::table(), [
            'kind' => $kind, 'label' => mb_substr($label, 0, 80), 'date_from' => $from, 'date_to' => $to, 'stored_name' => $stored, 'size' => strlen($pdf),
            'score' => $data['sum']['overall'] !== null ? (int) $data['sum']['overall'] : null, 'users_id' => $userId, 'date_creation' => date('Y-m-d H:i:s'),
        ]);
        return (int) $DB->insertId();
    }

    public static function get(int $id): ?array
    {
        global $DB;
        $r = $DB->request(['FROM' => self::table(), 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();
        return $r ?: null;
    }

    public static function path(array $row): string
    {
        return self::dir() . '/' . basename((string) $row['stored_name']);
    }

    public static function fileName(array $row): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', Importer::norm($row['label'])));
        return 'kpis-marketing-' . trim($slug, '-') . '.pdf';
    }

    public static function delete(int $id): void
    {
        global $DB;
        if ($r = self::get($id)) {
            @unlink(self::path($r));
            $DB->delete(self::table(), ['id' => $id]);
        }
    }

    // ------------------------------------------------------------------ envio

    /** @return array{emails: array<string, string>, users: int[]} destinatários: usuários com e-mail e e-mails avulsos */
    public static function recipients(): array
    {
        $emails = [];
        $users = [];
        foreach (array_filter(array_map('intval', explode(',', Settings::get('book_recipients', '')))) as $uid) {
            $users[] = $uid;
            if ($mail = \UserEmail::getDefaultForUser($uid)) {
                $emails[strtolower($mail)] = getUserName($uid);
            }
        }
        foreach (preg_split('/[\s,;]+/', Settings::get('book_emails', '')) ?: [] as $mail) {
            if (filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                $emails[strtolower($mail)] = $mail;
            }
        }
        return ['emails' => $emails, 'users' => array_values(array_unique($users))];
    }

    /** Envia o book por e-mail (com o PDF anexo) e avisa por pop-up. Devolve a descrição do resultado, que também fica no histórico. */
    public static function send(int $id): string
    {
        global $DB, $CFG_GLPI;
        $row = self::get($id);
        if (!$row || !is_file(self::path($row))) {
            return 'Arquivo do book não encontrado.';
        }
        $rec = self::recipients();
        $status = '';
        $sentTo = [];
        if (!$rec['emails'] && !$rec['users']) {
            $status = 'Nenhum destinatário configurado.';
        } else {
            foreach ($rec['users'] as $uid) {
                Alert::push($uid, 'book', ['id' => 0], 'KPIs Marketing disponível', $row['label'] . ' — acesse KPIs no menu Marketing.', 'book:' . $id);
            }
            if (empty($CFG_GLPI['notifications_mailing'])) {
                $status = 'E-mail não enviado: as notificações por e-mail do GLPI estão desligadas (o book está no histórico e os destinatários receberam o aviso na tela).';
            } elseif (!$rec['emails']) {
                $status = 'Nenhum destinatário com e-mail.';
            } else {
                try {
                    $mail = new \GLPIMailer();
                    foreach ($rec['emails'] as $addr => $name) {
                        $mail->addAddress($addr, $name);
                    }
                    $mail->Subject = '[Marketing] KPIs Marketing — ' . $row['label'];
                    $mail->isHTML(true);
                    $score = $row['score'] !== null ? (int) $row['score'] : null;
                    $mail->Body = '<p>Segue o book executivo <b>KPIs Marketing</b> referente ao período <b>' . htmlspecialchars((string) $row['label']) . '</b>.</p>'
                        . ($score !== null ? '<p>Índice geral de desempenho: <b>' . $score . ' / 100</b>.</p>' : '')
                        . '<p>O arquivo PDF está anexo. O histórico de books fica em <i>Marketing → KPIs</i>.</p>'
                        . '<p style="color:#64748b;font-size:12px">Mensagem automática da Gestão de Demandas.</p>';
                    $mail->AltBody = 'KPIs Marketing — ' . $row['label'] . ($score !== null ? ' — índice geral ' . $score . '/100' : '') . '. PDF anexo.';
                    $mail->addAttachment(self::path($row), self::fileName($row));
                    if ($mail->send()) {
                        $sentTo = array_keys($rec['emails']);
                        $status = 'Enviado a ' . count($sentTo) . ' destinatário(s).';
                    } else {
                        $status = 'Falha no envio: ' . mb_substr((string) $mail->ErrorInfo, 0, 200);
                    }
                } catch (\Throwable $e) {
                    $status = 'Falha no envio: ' . mb_substr($e->getMessage(), 0, 200);
                }
            }
        }
        $DB->update(self::table(), ['sent_at' => $sentTo ? date('Y-m-d H:i:s') : null, 'sent_to' => implode(', ', $sentTo), 'send_status' => mb_substr($status, 0, 250)], ['id' => $id]);
        return $status;
    }

    // ------------------------------------------------------------------ rotina agendada

    /**
     * Chamada diariamente: grava o instantâneo dos KPIs e, nos primeiros 5 dias do mês, envia o book do mês anterior (se ainda não enviado);
     * o book anual vai em 31/12 (ou no dia 1º de janeiro, conforme a configuração), com recuperação nos primeiros dias de janeiro.
     * Devolve quantos books foram gerados.
     */
    public static function cron(?string $today = null): int
    {
        $today ??= date('Y-m-d');
        $day = (int) date('j', strtotime($today));
        $month = (int) date('n', strtotime($today));
        $year = (int) date('Y', strtotime($today));
        Kpi::snapshotNow();
        $made = 0;
        if (Settings::get('book_monthly', '1') === '1' && $day <= 5) {
            $prev = date('Y-m', strtotime('first day of last month', strtotime($today)));
            if (Settings::get('book_last_monthly', '') !== $prev) {
                [$from, $to, $label] = self::monthPeriod($prev);
                $id = self::generate('monthly', $from, $to, $label, 0);
                if ($id) {
                    self::send($id);
                    Settings::save(['book_last_monthly' => $prev]);
                    $made++;
                }
            }
        }
        if (Settings::get('book_annual', '1') === '1') {
            $when = Settings::get('book_annual_when', 'dec31');
            $target = null;
            if ($when === 'dec31' && $month === 12 && $day === 31) {
                $target = $year;
            } elseif ($month === 1 && $day <= 5) {
                $target = $year - 1; // dia 1º de janeiro (ou recuperação do 31/12)
            }
            if ($target !== null && Settings::get('book_last_annual', '') !== (string) $target) {
                [$from, $to, $label] = self::yearPeriod($target);
                $id = self::generate('annual', $from, $to, $label, 0);
                if ($id) {
                    self::send($id);
                    Settings::save(['book_last_annual' => (string) $target]);
                    $made++;
                }
            }
        }
        return $made;
    }
}

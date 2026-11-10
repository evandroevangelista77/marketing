<?php

/**
 * Instalação, atualização e desinstalação do plugin Gestão de Demandas (chave "marketing").
 * A instalação é idempotente: rodar de novo cria só o que falta (tabelas, colunas, sementes, rotinas).
 */

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Alert;
use GlpiPlugin\Marketing\Automation;
use GlpiPlugin\Marketing\Cron;
use GlpiPlugin\Marketing\Demand;
use GlpiPlugin\Marketing\Note;
use GlpiPlugin\Marketing\Outbox;
use GlpiPlugin\Marketing\Slo;
use GlpiPlugin\Marketing\Type;

/** Todas as tabelas do plugin, na ordem de criação. */
function plugin_marketing_tables(): array
{
    return [
        Demand::getTable(), Demand::getEventsTable(), Demand::getEvidencesTable(), Demand::getApprovalsTable(),
        Type::getTable(), Slo::getTable(), Automation::getTable(), Alert::getTable(), Note::getTable(), Outbox::getTable(),
    ];
}

function plugin_marketing_install(): bool
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();
    $opts      = "ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC";
    $int       = "int $sign NOT NULL DEFAULT 0";
    $id        = "`id` int $sign NOT NULL AUTO_INCREMENT";

    $create = function (string $table, string $columns) use ($DB, $opts): void {
        if (!$DB->tableExists($table)) {
            $DB->doQuery("CREATE TABLE `$table` ($columns) $opts");
        }
    };

    $create(Demand::getTable(), "$id, `entities_id` $int, `is_recursive` tinyint NOT NULL DEFAULT 0, `code` varchar(30) NOT NULL, `name` varchar(255) NOT NULL,
        `requester_users_id` $int, `area` varchar(120) DEFAULT NULL, `objective` text, `audience` text, `deliverable` text, `context` text,
        `desired_date` date DEFAULT NULL, `confirmed_date` date DEFAULT NULL, `sent_at` timestamp NULL DEFAULT NULL,
        `status` varchar(24) NOT NULL DEFAULT 'received', `priority` tinyint NOT NULL DEFAULT 3,
        `users_id_assigned` $int, `users_id_approver` $int, `projects_id` $int,
        `is_deleted` tinyint NOT NULL DEFAULT 0, `date_creation` timestamp NULL DEFAULT NULL, `date_mod` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`), UNIQUE KEY `entity_code` (`entities_id`,`code`), KEY `status_idx` (`entities_id`,`status`),
        KEY `requester` (`requester_users_id`), KEY `projects` (`projects_id`)");
    $create(Demand::getEventsTable(), "$id, `demands_id` $int, `users_id` $int, `event` varchar(30) NOT NULL, `old_value` varchar(60) DEFAULT NULL,
        `new_value` varchar(60) DEFAULT NULL, `note` text, `date_creation` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`), KEY `demand` (`demands_id`,`date_creation`)");
    $create(Demand::getEvidencesTable(), "$id, `demands_id` $int, `kind` varchar(10) NOT NULL DEFAULT 'link', `name` varchar(255) NOT NULL,
        `url` varchar(500) DEFAULT NULL, `comment` text, `users_id` $int, `date_creation` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`), KEY `demand` (`demands_id`)");
    $create(Demand::getApprovalsTable(), "$id, `demands_id` $int, `users_id_approver` $int, `status` varchar(10) NOT NULL DEFAULT 'waiting',
        `comment` text, `users_id_decision` $int, `date_creation` timestamp NULL DEFAULT NULL, `date_decision` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`), KEY `demand` (`demands_id`,`status`)");
    $create(Type::getTable(), "$id, `name` varchar(120) NOT NULL, `color` char(7) NOT NULL DEFAULT '#6366f1', `is_active` tinyint NOT NULL DEFAULT 1,
        `required_fields` text, `description` text, `date_creation` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`)");
    $create(Slo::getTable(), "$id, `name` varchar(160) NOT NULL, `from_status` varchar(24) NOT NULL DEFAULT 'received', `to_statuses` varchar(255) NOT NULL,
        `limit_days` int NOT NULL DEFAULT 1, `calendars_id` $int, `users_id_escalate` $int, `is_active` tinyint NOT NULL DEFAULT 0,
        `date_creation` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`)");
    $create(Automation::getTable(), "$id, `name` varchar(160) NOT NULL, `trigger_event` varchar(30) NOT NULL, `trigger_value` varchar(60) NOT NULL DEFAULT '',
        `action` varchar(30) NOT NULL, `action_target` varchar(60) NOT NULL DEFAULT '', `action_value` varchar(500) NOT NULL DEFAULT '',
        `is_active` tinyint NOT NULL DEFAULT 0, `run_count` int NOT NULL DEFAULT 0, `date_creation` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`), KEY `trigger_idx` (`trigger_event`,`is_active`)");
    $create(Alert::getTable(), "$id, `users_id` $int, `demands_id` $int, `event` varchar(30) NOT NULL, `title` varchar(255) NOT NULL, `message` varchar(500) DEFAULT NULL,
        `dedup_key` varchar(120) DEFAULT NULL, `date_creation` timestamp NULL DEFAULT NULL, `delivered_at` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`), KEY `user_pending` (`users_id`,`delivered_at`), KEY `dedup` (`dedup_key`)");
    $create(Note::getTable(), "$id, `demands_id` $int, `users_id` $int, `kind` varchar(10) NOT NULL DEFAULT 'note', `body` text,
        `date_creation` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`), KEY `demand` (`demands_id`)");
    $create(Outbox::getTable(), "$id, `users_id` $int, `demands_id` $int, `event` varchar(30) NOT NULL, `subject` varchar(255) NOT NULL, `body` text,
        `dedup_key` varchar(120) DEFAULT NULL, `date_creation` timestamp NULL DEFAULT NULL, `sent_at` timestamp NULL DEFAULT NULL, `error` varchar(255) DEFAULT NULL,
        PRIMARY KEY (`id`), KEY `pending` (`sent_at`,`error`), KEY `dedup` (`dedup_key`)");

    // colunas acrescentadas depois da primeira versão (cada uma só é criada se faltar)
    $newColumns = [
        'accepted_at'       => 'timestamp NULL DEFAULT NULL',
        'accepted_users_id' => $int,
        'users_id_approver' => $int,
        'exception_note'    => 'text',
        'types_id'          => $int,
        'completed_at'      => 'timestamp NULL DEFAULT NULL',
    ];
    foreach ($newColumns as $column => $definition) {
        if (!$DB->fieldExists(Demand::getTable(), $column)) {
            $DB->doQuery("ALTER TABLE `" . Demand::getTable() . "` ADD `$column` $definition");
        }
    }

    plugin_marketing_seed();

    // rotinas agendadas: envio de e-mails, SLOs vencidos e limpeza de avisos
    CronTask::register(Cron::class, 'marketingmail', 5 * MINUTE_TIMESTAMP, ['comment' => 'Gestão de Demandas: envia a fila de e-mails', 'mode' => CronTask::MODE_EXTERNAL]);
    CronTask::register(Cron::class, 'marketingslo', 15 * MINUTE_TIMESTAMP, ['comment' => 'Gestão de Demandas: avisa SLOs vencidos', 'mode' => CronTask::MODE_EXTERNAL]);
    CronTask::register(Cron::class, 'marketingpurge', DAY_TIMESTAMP, ['comment' => 'Gestão de Demandas: limpa avisos antigos', 'mode' => CronTask::MODE_EXTERNAL]);

    // direito "plugin_marketing" (aba em Perfis). Padrão: central com UPDATE = tudo; demais = leitura
    if (!countElementsInTable(ProfileRight::getTable(), ['name' => Access::$rightname])) {
        ProfileRight::addProfileRights([Access::$rightname]);
        $all   = ALLSTANDARDRIGHT | Access::APPROVE | Access::CONFIGURE;
        $basic = READ;
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => Profile::getTable(), 'WHERE' => ['interface' => 'central']]) as $row) {
            $config = ProfileRight::getProfileRights($row['id'], ['config'])['config'] ?? 0;
            $isAdmin = ($config & UPDATE) || in_array($row['name'], ['Super-Admin', 'Admin'], true);
            $DB->update(
                ProfileRight::getTable(),
                ['rights' => $isAdmin ? $all : $basic],
                ['profiles_id' => $row['id'], 'name' => Access::$rightname]
            );
        }
    }

    // sessão já aberta passa a enxergar o direito sem novo login
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $current = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Access::$rightname]);
        $_SESSION['glpiactiveprofile'][Access::$rightname] = (int) ($current[Access::$rightname] ?? 0);
    }
    unset($_SESSION['glpimenu']);

    return true;
}

/** Tipos, SLOs (parâmetros do briefing, §6 da especificação) e automações de exemplo; só se as tabelas estiverem vazias. */
function plugin_marketing_seed(): void
{
    global $DB;
    $now = date('Y-m-d H:i:s');

    if (!countElementsInTable(Type::getTable())) {
        foreach ([
            ['Campanha', '#6366f1'], ['Evento', '#f59e0b'], ['Comunicação', '#0ea5e9'], ['Conteúdo', '#22c55e'],
            ['GTM (go-to-market)', '#ec4899'], ['CRM e inteligência', '#a855f7'],
        ] as [$name, $color]) {
            $DB->insert(Type::getTable(), ['name' => $name, 'color' => $color, 'is_active' => 1, 'date_creation' => $now]);
        }
    }

    // §6 da especificação: valores do briefing, ainda não homologados, por isso nascem desligados (RF-017)
    if (!countElementsInTable(Slo::getTable())) {
        foreach ([
            ['Confirmação de recebimento', 'received', 'triage', 1],
            ['Triagem', 'triage', 'waiting_requester,planned', 2],
            ['Definição de responsável e prazo', 'triage', 'planned', 4],
        ] as [$name, $from, $to, $days]) {
            $DB->insert(Slo::getTable(), ['name' => $name, 'from_status' => $from, 'to_statuses' => $to, 'limit_days' => $days, 'is_active' => 0, 'date_creation' => $now]);
        }
    }

    if (!countElementsInTable(Automation::getTable())) {
        foreach ([
            ['Avisar o solicitante quando a demanda for concluída', 'status_changed', 'done', 'notify', 'requester', 'Sua demanda foi concluída.'],
            ['Avisar o responsável quando a demanda ficar bloqueada', 'status_changed', 'blocked', 'notify', 'assignee', 'A demanda foi bloqueada.'],
            ['Registrar atualização quando entrar em risco', 'status_changed', 'at_risk', 'add_note', '', 'A demanda entrou em risco; revisar prazo e plano.'],
        ] as [$name, $trigger, $value, $action, $target, $text]) {
            $DB->insert(Automation::getTable(), [
                'name' => $name, 'trigger_event' => $trigger, 'trigger_value' => $value, 'action' => $action,
                'action_target' => $target, 'action_value' => $text, 'is_active' => 0, 'run_count' => 0, 'date_creation' => $now,
            ]);
        }
    }
}

function plugin_marketing_uninstall(): bool
{
    global $DB;

    CronTask::unregister('marketing');
    ProfileRight::deleteProfileRights([Access::$rightname]);
    Config::deleteConfigurationValues('plugin:marketing');
    unset($_SESSION['glpimenu']);

    // dados são preservados por padrão; apagar só com MARKETING_PURGE=1 (após backup)
    if (getenv('MARKETING_PURGE') === '1') {
        foreach (array_reverse(plugin_marketing_tables()) as $table) {
            $DB->doQuery('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }
    return true;
}

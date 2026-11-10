<?php

namespace GlpiPlugin\Marketing;

use CronTask;

/** Rotinas agendadas do GLPI (Configurar → Ações automáticas). */
class Cron
{
    public static function cronInfo(string $name): array
    {
        return ['description' => match ($name) {
            'marketingmail'  => 'Gestão de Demandas: envia a fila de e-mails',
            'marketingslo'   => 'Gestão de Demandas: avisa SLOs vencidos',
            'marketingpurge' => 'Gestão de Demandas: limpa avisos antigos',
            default          => $name,
        }];
    }

    public static function cronMarketingmail(CronTask $task): int
    {
        $n = Outbox::flush(20);
        $task->addVolume($n);
        return $n > 0 ? 1 : 0;
    }

    public static function cronMarketingslo(CronTask $task): int
    {
        $n = Slo::checkBreaches();
        $task->addVolume($n);
        return $n > 0 ? 1 : 0;
    }

    public static function cronMarketingpurge(CronTask $task): int
    {
        $n = Alert::purge(30);
        $task->addVolume($n);
        return $n > 0 ? 1 : 0;
    }
}

<?php

namespace GlpiPlugin\Marketing;

/**
 * Motor de indicadores (KPIs) do Marketing: catálogo com fórmula, meta e semáforo; cálculo por período (fluxo no período, estoque na data final);
 * série mensal; pontuação por grupo e geral; ofensores (indicadores fora da meta e demandas críticas) com plano e prazo de recuperação.
 * Só leitura e sem restrição de visibilidade: quem usa precisa do direito "Book executivo" (Access::EXECUTIVE) ou ser a rotina agendada.
 */
class Kpi
{
    public const GROUPS = [
        'volume'    => ['Volume e demanda', '#6366f1'],
        'prazo'     => ['Prazos e entrega', '#ef4444'],
        'fluxo'     => ['Fluxo e eficiência', '#0ea5e9'],
        'qualidade' => ['Qualidade e governança', '#22c55e'],
        'slo'       => ['SLOs', '#eab308'],
        'projetos'  => ['Projetos e planos de ação', '#a855f7'],
        'pessoas'   => ['Capacidade e carga', '#f97316'],
    ];

    /** Limite de demandas abertas por pessoa acima do qual há sobrecarga. */
    public const OVERLOAD = 10;

    /**
     * Catálogo. dir: up (maior é melhor), down (menor é melhor), info (sem meta). unit: % | d (dias) | n | pp.
     * target/warn: verde até target; âmbar até warn; além, vermelho. cur: só existe o valor de hoje (sem reconstrução histórica).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function catalog(): array
    {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }
        $k = fn (string $group, string $name, string $unit, string $dir, ?float $target, ?float $warn, string $formula, string $why, array $actions, string $owner, bool $cur = false, bool $quick = false) =>
            compact('group', 'name', 'unit', 'dir', 'target', 'warn', 'formula', 'why', 'actions', 'owner', 'cur', 'quick');
        return $memo = [
            // ---------------------------------------------------------------- volume
            'entradas' => $k('volume', 'Demandas recebidas', 'n', 'info', null, null,
                'Demandas (externas e internas) criadas no período.', 'Mede a pressão de demanda sobre a equipe.', [], 'Gerência de Marketing'),
            'concluidas' => $k('volume', 'Demandas concluídas', 'n', 'info', null, null,
                'Demandas que chegaram à situação Concluída no período (data de conclusão).', 'Mede a entrega efetiva da equipe (vazão).', [], 'Gerência de Marketing'),
            'vazao' => $k('volume', 'Vazão (concluídas ÷ recebidas)', '%', 'up', 100, 80,
                'Concluídas no período ÷ recebidas no período × 100.', 'Abaixo de 100% o backlog cresce: entra mais do que sai.',
                ['Revisar a capacidade da equipe e priorizar o que entra (triagem mais rigorosa).', 'Cancelar ou adiar formalmente demandas sem retorno do solicitante.', 'Redistribuir demandas dos responsáveis mais carregados.'], 'Analista de Operações'),
            'backlog' => $k('volume', 'Backlog em aberto', 'n', 'info', null, null,
                'Demandas não concluídas nem canceladas na data final do período.', 'Tamanho da fila de trabalho acumulada.', [], 'Gerência de Marketing'),
            'idade_backlog' => $k('volume', 'Idade média do backlog', 'd', 'down', 20, 35,
                'Média, em dias, entre a entrada e a data final do período das demandas em aberto.', 'Fila envelhecida indica demandas esquecidas ou travadas.',
                ['Fazer uma limpeza do backlog: concluir, cancelar ou replanejar as mais antigas.', 'Definir um limite de idade para revisão obrigatória (ex.: 30 dias).', 'Tratar primeiro as demandas com mais de 60 dias.'], 'Analista de Operações'),
            'backlog_60' => $k('volume', 'Backlog com mais de 60 dias', '%', 'down', 10, 20,
                'Demandas em aberto há mais de 60 dias ÷ total em aberto × 100.', 'Parte do backlog que já perdeu valor ou está travada.',
                ['Revisar uma a uma com o solicitante: manter, replanejar ou cancelar.', 'Registrar a decisão e o novo prazo na ficha.'], 'Analista de Operações'),
            'cancelamento' => $k('volume', 'Taxa de cancelamento', '%', 'down', 8, 15,
                'Demandas canceladas no período ÷ recebidas no período × 100.', 'Cancelamento alto sugere triagem fraca ou pedidos mal qualificados.',
                ['Reforçar o briefing obrigatório e a validação na triagem.', 'Conversar com as áreas que mais cancelam sobre o que motivou os pedidos.'], 'Analista de Operações'),
            'sem_responsavel' => $k('volume', 'Demandas em aberto sem responsável', '%', 'down', 2, 10,
                'Em aberto sem responsável ÷ total em aberto × 100.', 'Sem dono, a demanda não anda.',
                ['Atribuir responsável a todas as demandas em aberto hoje.', 'Definir o responsável padrão da triagem em Configurações.'], 'Analista de Operações', true, true),
            'mix_interno' => $k('volume', 'Participação do trabalho interno', '%', 'info', null, null,
                'Atividades do Marketing ÷ total recebido no período × 100.', 'Equilíbrio entre atender outras áreas e executar o planejamento próprio.', [], 'Gerência de Marketing'),
            // ---------------------------------------------------------------- prazo
            'otd' => $k('prazo', 'Entregas no prazo', '%', 'up', 90, 75,
                'Concluídas no período, com prazo, entregues até o prazo vigente na conclusão ÷ concluídas com prazo × 100.', 'Principal medida de confiabilidade perante o solicitante.',
                ['Revisar a estimativa de prazos na triagem (usar histórico do mesmo tipo).', 'Avisar o solicitante e reprogramar assim que o risco aparecer, não depois.', 'Acompanhar semanalmente as demandas de prazo curto.'], 'Gerência de Marketing'),
            'atrasadas_pct' => $k('prazo', 'Demandas atrasadas', '%', 'down', 10, 25,
                'Em aberto com prazo vencido na data final ÷ total em aberto × 100.', 'Parcela do backlog que já descumpriu o compromisso.',
                ['Fazer uma reunião de recuperação com os responsáveis das atrasadas.', 'Definir novo prazo realista e registrar a reprogramação com justificativa.', 'Priorizar as de prioridade muito alta e alta.'], 'Gerência de Marketing'),
            'atraso_medio' => $k('prazo', 'Atraso médio das atrasadas', 'd', 'down', 5, 15,
                'Média de dias de atraso das demandas em aberto com prazo vencido.', 'Mostra a gravidade do atraso, não só a quantidade.',
                ['Atacar primeiro as de maior atraso.', 'Escalar impedimentos de terceiros para a gerência.'], 'Gerência de Marketing'),
            'adiadas_pct' => $k('prazo', 'Demandas adiadas', '%', 'down', 10, 20,
                'Em aberto com prazo empurrado para frente ao menos uma vez ÷ total em aberto × 100.', 'Adiamento frequente indica planejamento otimista ou falta de capacidade.',
                ['Analisar as justificativas das reprogramações e agrupar as causas.', 'Ajustar a estimativa de prazo na triagem.', 'Rever prioridades que competem pela mesma equipe.'], 'Gerência de Marketing'),
            'reincidentes' => $k('prazo', 'Demandas adiadas 2 vezes ou mais', 'n', 'down', 0, 3,
                'Em aberto com duas ou mais reprogramações de prazo.', 'Reincidência é o sinal mais forte de que o plano original não é viável.',
                ['Replanejar a demanda do zero com o solicitante (escopo, entrega parcial ou cancelamento).', 'Definir um único prazo final, aprovado pela gerência.'], 'Gerência de Marketing'),
            'reprogramacoes' => $k('prazo', 'Reprogramações de prazo no período', 'n', 'info', null, null,
                'Quantidade de trocas de prazo confirmado registradas no período.', 'Volume de renegociação de prazos.', [], 'Gerência de Marketing'),
            // ---------------------------------------------------------------- fluxo
            'lead_time' => $k('fluxo', 'Tempo médio de conclusão', 'd', 'down', 15, 25,
                'Média, em dias, entre a entrada e a conclusão das demandas concluídas no período.', 'Velocidade ponta a ponta percebida pelo solicitante.',
                ['Mapear a etapa que mais consome tempo (painel: tempo médio por etapa).', 'Eliminar esperas: aprovação, retorno do solicitante e terceiros.', 'Quebrar demandas grandes em entregas menores.'], 'Analista de Operações'),
            'tempo_triagem' => $k('fluxo', 'Tempo médio até planejar', 'd', 'down', 2, 4,
                'Média, em dias, entre a entrada e a primeira vez em Planejada (solicitações externas).', 'Quanto o solicitante espera até ter responsável e prazo.',
                ['Triagem diária em horário fixo.', 'Definir responsável padrão da triagem e substituto.', 'Padronizar o briefing para evitar idas e vindas.'], 'Analista de Operações'),
            'tempo_aprovacao' => $k('fluxo', 'Tempo médio de aprovação', 'd', 'down', 2, 4,
                'Média, em dias, entre o pedido de aprovação e a decisão do aprovador.', 'Aprovação lenta trava a entrega.',
                ['Combinar um prazo de resposta com os aprovadores.', 'Indicar um aprovador substituto.'], 'Gerência de Marketing'),
            'risco_bloq' => $k('fluxo', 'Em risco ou bloqueadas', '%', 'down', 5, 12,
                'Em aberto na situação Em risco ou Bloqueada ÷ total em aberto × 100.', 'Demandas paradas por impedimento ou risco declarado.',
                ['Levantar o impedimento de cada uma e quem pode removê-lo.', 'Escalar para a gerência o que depende de outra área.'], 'Gerência de Marketing'),
            'dep_bloq' => $k('fluxo', 'Atividades esperando outra atividade', 'n', 'down', 0, 5,
                'Atividades em aberto cuja dependência ainda não foi concluída.', 'Dependências abertas formam gargalos em cadeia.',
                ['Antecipar a atividade da qual as outras dependem.', 'Revisar se a dependência ainda é necessária.'], 'Líder do plano', true),
            // ---------------------------------------------------------------- qualidade
            'com_evidencia' => $k('qualidade', 'Concluídas com evidência', '%', 'up', 95, 85,
                'Concluídas no período com evidência, entregável (arquivo) ou exceção justificada ÷ concluídas × 100.', 'Rastreabilidade das entregas.',
                ['Exigir o envio do entregável antes de concluir.', 'Lembrar a equipe no fechamento semanal.'], 'Analista de Operações'),
            'com_aceite' => $k('qualidade', 'Concluídas com aceite do solicitante', '%', 'up', 90, 75,
                'Solicitações externas concluídas no período com aceite registrado (ou exceção) ÷ concluídas externas × 100.', 'Confirma que a entrega atendeu quem pediu.',
                ['Pedir o aceite assim que a entrega for feita.', 'Usar a exceção apenas com justificativa.'], 'Analista de Operações'),
            'excecoes' => $k('qualidade', 'Concluídas por exceção', '%', 'down', 5, 10,
                'Concluídas no período com justificativa de exceção ÷ concluídas × 100.', 'Exceções frequentes enfraquecem o controle de qualidade.',
                ['Revisar os motivos das exceções e corrigir o processo que as causa.'], 'Gerência de Marketing'),
            'retrabalho' => $k('qualidade', 'Aprovações recusadas', '%', 'down', 15, 30,
                'Aprovações recusadas no período ÷ aprovações decididas × 100.', 'Retrabalho: a entrega voltou para ajuste.',
                ['Antecipar o alinhamento com o aprovador antes de finalizar.', 'Documentar critérios de aprovação por tipo de peça.'], 'Gerência de Marketing'),
            'briefing_completo' => $k('qualidade', 'Solicitações com briefing completo na entrada', '%', 'up', 80, 60,
                'Solicitações externas do período com todos os campos obrigatórios preenchidos ÷ solicitações externas × 100.', 'Briefing completo evita idas e vindas e atrasos de triagem.',
                ['Tornar o formulário mais objetivo e orientar os solicitantes.', 'Revisar os campos obrigatórios em Configurações.'], 'Analista de Operações'),
            // ---------------------------------------------------------------- slo
            'slo_cumprido' => $k('slo', 'SLOs cumpridos', '%', 'up', 90, 80,
                'SLOs cumpridos no prazo ÷ SLOs medidos (cumpridos, fora do prazo e vencidos) × 100, das demandas do período.', 'Cumprimento dos acordos de serviço homologados.',
                ['Tratar as demandas com SLO vencido hoje.', 'Revisar o prazo dos SLOs que mais estouram (com homologação).'], 'Gerência de Marketing'),
            'slo_vencidos' => $k('slo', 'SLOs vencidos', 'n', 'down', 0, 3,
                'Demandas com SLO vencido e ainda não cumprido.', 'Violações abertas dos acordos de serviço.',
                ['Dar prioridade às demandas com SLO vencido.', 'Escalar para a pessoa de escalonamento definida no SLO.'], 'Gerência de Marketing', true),
            // ---------------------------------------------------------------- projetos
            'proj_exec' => $k('projetos', 'Execução efetiva dos projetos', '%', 'info', null, null,
                'Média da execução efetiva (percentual informado) dos projetos em planejamento ou execução.', 'Quanto do trabalho dos projetos já foi realizado.', [], 'Gerência de Marketing', true),
            'proj_desvio' => $k('projetos', 'Desvio vs cronograma dos projetos', 'pp', 'up', 0, -10,
                'Média de (execução efetiva − cronograma decorrido) dos projetos com datas, em pontos percentuais.', 'Negativo = projetos atrasados em relação ao tempo.',
                ['Pedir aos responsáveis a atualização do % de execução nas atividades.', 'Replanejar os projetos com desvio pior que −15 p.p.', 'Reforçar a equipe ou reduzir escopo dos projetos críticos.'], 'Responsáveis de projeto', true),
            'proj_risco' => $k('projetos', 'Projetos em risco', '%', 'down', 10, 25,
                'Projetos ativos com desvio pior que −15 p.p. ou fim previsto vencido ÷ projetos ativos × 100.', 'Projetos que dificilmente fecharão no prazo.',
                ['Fazer revisão individual dos projetos em risco com o responsável.', 'Definir um novo cronograma e registrar os marcos.'], 'Responsáveis de projeto', true),
            'marcos' => $k('projetos', 'Marcos cumpridos', '%', 'up', 90, 70,
                'Marcos com data vencida e cumpridos ÷ marcos com data vencida × 100.', 'Disciplina de cumprimento dos pontos de controle.',
                ['Marcar os marcos cumpridos e replanejar os vencidos.', 'Rever a quantidade e a data dos marcos.'], 'Responsáveis de projeto', true),
            'planos_sem_resp' => $k('projetos', 'Planos de ação sem responsável', '%', 'down', 0, 10,
                'Planos de ação ativos sem responsável principal ÷ planos ativos × 100.', 'Plano sem dono não é executado.',
                ['Definir o responsável principal de cada plano.'], 'Gerência de Marketing', true, true),
            'sem_pct' => $k('projetos', 'Atividades de projeto sem % de execução', '%', 'down', 20, 50,
                'Atividades abertas ligadas a projetos sem percentual de execução informado ÷ total × 100.', 'Qualidade do dado: sem o %, a execução efetiva não é confiável.',
                ['Pedir a atualização semanal do % na edição em lote do plano.'], 'Responsáveis de projeto', true, true),
            // ---------------------------------------------------------------- pessoas
            'wip_pessoa' => $k('pessoas', 'Demandas em aberto por responsável', 'n', 'down', 8, 14,
                'Média de demandas em aberto por pessoa com demanda atribuída.', 'Carga média da equipe.',
                ['Redistribuir demandas ou ampliar a capacidade.', 'Limitar o trabalho em andamento por pessoa.'], 'Gerência de Marketing'),
            'concentracao' => $k('pessoas', 'Concentração de carga', '%', 'down', 30, 45,
                'Demandas em aberto do responsável mais carregado ÷ total atribuído × 100.', 'Dependência de uma só pessoa é risco de continuidade.',
                ['Distribuir demandas do responsável mais carregado.', 'Treinar um substituto para as demandas críticas.'], 'Gerência de Marketing'),
            'sobrecarga' => $k('pessoas', 'Pessoas sobrecarregadas', 'n', 'down', 0, 2,
                'Pessoas com mais de ' . self::OVERLOAD . ' demandas em aberto.', 'Risco de atraso e esgotamento.',
                ['Redistribuir a carga imediatamente.', 'Negociar adiamento das demandas de menor prioridade.'], 'Gerência de Marketing'),
            'produtividade' => $k('pessoas', 'Concluídas por responsável', 'n', 'info', null, null,
                'Concluídas no período ÷ pessoas que concluíram ao menos uma demanda.', 'Referência de produtividade média.', [], 'Gerência de Marketing'),
        ];
    }

    // ------------------------------------------------------------------ metas

    /** Catálogo com as metas personalizadas (Configurações do plugin) aplicadas. */
    public static function targets(): array
    {
        $cat = self::catalog();
        $custom = json_decode(Settings::get('kpi_targets', '{}'), true);
        foreach (is_array($custom) ? $custom : [] as $key => $t) {
            if (isset($cat[$key]) && is_array($t)) {
                foreach (['target', 'warn'] as $f) {
                    if (array_key_exists($f, $t) && $t[$f] !== '' && $t[$f] !== null && is_numeric($t[$f])) {
                        $cat[$key][$f] = (float) $t[$f];
                    }
                }
            }
        }
        return $cat;
    }

    /** Pontuação de 0 a 100 do indicador (100 = na meta; 50 = no limite âmbar; 0 = muito pior). null sem meta ou sem valor. */
    public static function score(array $def, ?float $v): ?float
    {
        if ($v === null || $def['dir'] === 'info' || $def['target'] === null || $def['warn'] === null) {
            return null;
        }
        $t = (float) $def['target'];
        $w = (float) $def['warn'];
        $span = max(0.0001, abs($t - $w));
        $good = $def['dir'] === 'up' ? $v >= $t : $v <= $t;
        if ($good) {
            return 100.0;
        }
        $off = abs($v - $t); // distância da meta, no sentido ruim
        return max(0.0, 100.0 - 50.0 * $off / $span);
    }

    /** green | amber | red | none */
    public static function status(array $def, ?float $v): string
    {
        $s = self::score($def, $v);
        if ($s === null) {
            return 'none';
        }
        return $s >= 100 ? 'green' : ($s >= 50 ? 'amber' : 'red');
    }

    public const STATUS_COLORS = ['green' => '#16a34a', 'amber' => '#f59e0b', 'red' => '#dc2626', 'none' => '#94a3b8'];
    public const STATUS_LABELS = ['green' => 'Na meta', 'amber' => 'Atenção', 'red' => 'Crítico', 'none' => 'Sem meta'];

    public static function format(array $def, ?float $v): string
    {
        if ($v === null) {
            return '—';
        }
        return match ($def['unit']) {
            '%'  => number_format($v, 0, ',', '.') . '%',
            'd'  => number_format($v, 1, ',', '.') . ' d',
            'pp' => ($v > 0 ? '+' : '') . number_format($v, 1, ',', '.') . ' p.p.',
            default => number_format($v, 0, ',', '.'),
        };
    }

    /** Meta em texto. Com $plain usa "mín."/"máx." (o PDF não tem os símbolos ≥ e ≤). */
    public static function formatTarget(array $def, bool $plain = false): string
    {
        if ($def['dir'] === 'info' || $def['target'] === null) {
            return '—';
        }
        $v = self::format($def, (float) $def['target']);
        if ($plain) {
            return ($def['dir'] === 'up' ? 'mín. ' : 'máx. ') . $v;
        }
        return ($def['dir'] === 'up' ? '≥ ' : '≤ ') . $v;
    }

    // ------------------------------------------------------------------ dados

    /** @var array<string, mixed>|null */
    private static ?array $ctx = null;

    public static function resetCache(): void
    {
        self::$ctx = null;
    }

    private static function ctx(): array
    {
        global $DB;
        if (self::$ctx !== null) {
            return self::$ctx;
        }
        $c = ['rows' => [], 'events' => [], 'resched' => [], 'cancelled' => [], 'evid' => [], 'approvals' => [], 'projects' => [], 'plans' => [], 'milestones' => []];
        foreach ($DB->request(['FROM' => Demand::getTable(), 'WHERE' => ['is_deleted' => 0]]) as $r) {
            $r['created'] = $r['sent_at'] ?: $r['date_creation'];
            $c['rows'][(int) $r['id']] = $r;
        }
        foreach ($DB->request(['FROM' => Demand::getEventsTable(), 'WHERE' => ['event' => 'status'], 'ORDER' => 'id ASC']) as $e) {
            $c['events'][(int) $e['demands_id']][] = $e;
            if ($e['new_value'] === Demand::STATUS_CANCELLED) {
                $c['cancelled'][(int) $e['demands_id']] = $e['date_creation'];
            }
        }
        foreach ($DB->request(['FROM' => Demand::reschedulesTable(), 'ORDER' => 'id ASC']) as $e) {
            $c['resched'][(int) $e['demands_id']][] = $e;
        }
        foreach ($DB->request(['SELECT' => ['demands_id'], 'FROM' => Demand::getEvidencesTable()]) as $e) {
            $c['evid'][(int) $e['demands_id']] = true;
        }
        foreach ($DB->request(['SELECT' => ['demands_id'], 'FROM' => Demand::filesTable()]) as $e) {
            $c['evid'][(int) $e['demands_id']] = true;
        }
        foreach ($DB->request(['FROM' => Demand::getApprovalsTable()]) as $e) {
            $c['approvals'][] = $e;
        }
        $c['projects'] = Structure::projects();
        $c['plans'] = Structure::plans();
        foreach ($DB->request(['FROM' => Structure::milestonesTable()]) as $m) {
            $c['milestones'][] = $m;
        }
        return self::$ctx = $c;
    }

    private static function ts(?string $d): ?int
    {
        $t = $d ? strtotime($d) : false;
        return $t === false ? null : $t;
    }

    private static function days(int $a, int $b): float
    {
        return max(0.0, ($b - $a) / 86400);
    }

    private static function openAt(array $c, array $r, int $at): bool
    {
        $created = self::ts($r['created']);
        if ($created === null || $created > $at) {
            return false;
        }
        $done = self::ts($r['completed_at'] ?? null);
        if ($r['status'] === Demand::STATUS_DONE && $done !== null && $done <= $at) {
            return false;
        }
        if ($r['status'] === Demand::STATUS_DONE && $done === null && self::ts($r['date_mod'] ?? null) !== null && self::ts($r['date_mod']) <= $at) {
            return false;
        }
        if ($r['status'] === Demand::STATUS_CANCELLED) {
            $cd = self::ts($c['cancelled'][(int) $r['id']] ?? $r['date_mod'] ?? null);
            if ($cd !== null && $cd <= $at) {
                return false;
            }
        }
        return true;
    }

    /** Prazo vigente em $at, reconstruído pelas reprogramações; null se não havia prazo. */
    private static function deadlineAt(array $c, array $r, int $at): ?string
    {
        $list = $c['resched'][(int) $r['id']] ?? [];
        if ($list) {
            $last = null;
            foreach ($list as $x) {
                if (self::ts($x['date_creation']) !== null && self::ts($x['date_creation']) <= $at) {
                    $last = $x;
                }
            }
            return $last ? $last['new_date'] : $list[0]['old_date'];
        }
        return $r['confirmed_date'] ?: ($r['desired_date'] ?: null);
    }

    private static function statusAt(array $c, array $r, int $at): string
    {
        $st = Demand::STATUS_RECEIVED;
        foreach ($c['events'][(int) $r['id']] ?? [] as $e) {
            $t = self::ts($e['date_creation']);
            if ($t !== null && $t <= $at) {
                $st = $e['new_value'];
            }
        }
        return $st;
    }

    /** Data (AAAA-MM-DD) $days dias úteis depois de $from. */
    public static function bd(string $from, int $days, int $cal = 0): string
    {
        return substr(Slo::addBusinessDays(substr($from, 0, 10), $days, $cal), 0, 10);
    }

    private static function pct(float $n, float $d): ?float
    {
        return $d > 0 ? round($n / $d * 100, 1) : null;
    }

    private static function avg(array $v): ?float
    {
        return $v ? round(array_sum($v) / count($v), 1) : null;
    }

    // ------------------------------------------------------------------ cálculo

    /**
     * Valores de todos os indicadores. Fluxo (recebidas, concluídas, tempos…) é medido entre $from e $to;
     * estoque (backlog, atrasadas…) é medido ao fim de $to. $to no futuro é limitado a agora.
     *
     * @return array<string, float|null>
     */
    public static function values(string $from, string $to, bool $fillCurrent = true): array
    {
        $c = self::ctx();
        $fromT = strtotime($from . ' 00:00:00');
        $toT = min(strtotime($to . ' 23:59:59'), time());
        $asDate = date('Y-m-d', $toT);
        $isNow = $toT >= strtotime('-6 days'); // período que acabou de fechar (book do dia 1º): vale o retrato de hoje
        $v = array_fill_keys(array_keys(self::catalog()), null);

        $created = $done = $cancel = $open = [];
        foreach ($c['rows'] as $id => $r) {
            $ct = self::ts($r['created']);
            if ($ct !== null && $ct >= $fromT && $ct <= $toT) {
                $created[$id] = $r;
            }
            if ($r['status'] === Demand::STATUS_DONE) {
                $dt = self::ts($r['completed_at']) ?? self::ts($r['date_mod']);
                if ($dt !== null && $dt >= $fromT && $dt <= $toT) {
                    $r['done_at'] = $dt;
                    $done[$id] = $r;
                }
            }
            if ($r['status'] === Demand::STATUS_CANCELLED) {
                $cd = self::ts($c['cancelled'][$id] ?? $r['date_mod']);
                if ($cd !== null && $cd >= $fromT && $cd <= $toT) {
                    $cancel[$id] = $r;
                }
            }
            if (self::openAt($c, $r, $toT)) {
                $open[$id] = $r;
            }
        }

        // ---- volume
        $v['entradas'] = (float) count($created);
        $v['concluidas'] = (float) count($done);
        $v['vazao'] = self::pct(count($done), count($created));
        $v['backlog'] = (float) count($open);
        $ages = array_map(fn ($r) => self::days(self::ts($r['created']), $toT), $open);
        $v['idade_backlog'] = self::avg($ages);
        $v['backlog_60'] = self::pct(count(array_filter($ages, fn ($a) => $a > 60)), count($open));
        $v['cancelamento'] = self::pct(count($cancel), count($created));
        $v['sem_responsavel'] = self::pct(count(array_filter($open, fn ($r) => (int) $r['users_id_assigned'] <= 0)), count($open));
        $v['mix_interno'] = self::pct(count(array_filter($created, fn ($r) => $r['origin'] === Demand::ORIGIN_INTERNAL)), count($created));

        // ---- prazo
        $withDl = $onTime = 0;
        foreach ($done as $r) {
            $dl = self::deadlineAt($c, $r, $r['done_at']);
            if ($dl) {
                $withDl++;
                $onTime += date('Y-m-d', $r['done_at']) <= $dl ? 1 : 0;
            }
        }
        $v['otd'] = self::pct($onTime, $withDl);
        $late = [];
        $postponed = $repeat = 0;
        foreach ($open as $r) {
            $dl = self::deadlineAt($c, $r, $toT);
            if ($dl && $dl < $asDate) {
                $late[] = self::days(strtotime($dl), strtotime($asDate));
            }
            $list = array_filter($c['resched'][(int) $r['id']] ?? [], fn ($x) => self::ts($x['date_creation']) <= $toT);
            if (array_filter($list, fn ($x) => $x['new_date'] > $x['old_date'])) {
                $postponed++;
            }
            $repeat += count($list) >= 2 ? 1 : 0;
        }
        $v['atrasadas_pct'] = self::pct(count($late), count($open));
        $v['atraso_medio'] = $late ? self::avg($late) : (count($open) ? 0.0 : null);
        $v['adiadas_pct'] = self::pct($postponed, count($open));
        $v['reincidentes'] = (float) $repeat;
        $nResched = 0;
        foreach ($c['resched'] as $list) {
            foreach ($list as $x) {
                $t = self::ts($x['date_creation']);
                $nResched += ($t !== null && $t >= $fromT && $t <= $toT) ? 1 : 0;
            }
        }
        $v['reprogramacoes'] = (float) $nResched;

        // ---- fluxo
        $v['lead_time'] = self::avg(array_map(fn ($r) => self::days(self::ts($r['created']), $r['done_at']), $done));
        $triage = [];
        foreach ($created as $id => $r) {
            if ($r['origin'] !== Demand::ORIGIN_EXTERNAL) {
                continue;
            }
            foreach ($c['events'][$id] ?? [] as $e) {
                if ($e['new_value'] === Demand::STATUS_PLANNED) {
                    $triage[] = self::days(self::ts($r['created']), self::ts($e['date_creation']));
                    break;
                }
            }
        }
        $v['tempo_triagem'] = self::avg($triage);
        $appr = [];
        $decided = $refused = 0;
        foreach ($c['approvals'] as $a) {
            $dd = self::ts($a['date_decision'] ?? null);
            if ($dd !== null && $dd >= $fromT && $dd <= $toT) {
                $decided++;
                $refused += $a['status'] === 'refused' ? 1 : 0;
                $appr[] = self::days(self::ts($a['date_creation']), $dd);
            }
        }
        $v['tempo_aprovacao'] = self::avg($appr);
        $v['retrabalho'] = self::pct($refused, $decided);
        $risk = 0;
        foreach ($open as $r) {
            $risk += in_array(self::statusAt($c, $r, $toT), [Demand::STATUS_AT_RISK, Demand::STATUS_BLOCKED], true) ? 1 : 0;
        }
        $v['risco_bloq'] = self::pct($risk, count($open));
        if ($isNow) {
            $dep = 0;
            foreach ($open as $r) {
                $d = (int) $r['depends_on_id'];
                $dep += ($d > 0 && isset($c['rows'][$d]) && Demand::isOpen($c['rows'][$d])) ? 1 : 0;
            }
            $v['dep_bloq'] = (float) $dep;
        }

        // ---- qualidade
        $ev = $ac = $ex = $ext = 0;
        foreach ($done as $id => $r) {
            $hasEx = trim((string) ($r['exception_note'] ?? '')) !== '';
            $ev += ($hasEx || isset($c['evid'][$id])) ? 1 : 0;
            $ex += $hasEx ? 1 : 0;
            if ($r['origin'] === Demand::ORIGIN_EXTERNAL) {
                $ext++;
                $ac += ($hasEx || !empty($r['accepted_at'])) ? 1 : 0;
            }
        }
        $v['com_evidencia'] = self::pct($ev, count($done));
        $v['com_aceite'] = self::pct($ac, $ext);
        $v['excecoes'] = self::pct($ex, count($done));
        $extCreated = array_filter($created, fn ($r) => $r['origin'] === Demand::ORIGIN_EXTERNAL);
        $v['briefing_completo'] = self::pct(count(array_filter($extCreated, fn ($r) => !Demand::missingInputs($r))), count($extCreated));

        // ---- SLO (demandas do período)
        if (Slo::all(true) && $created) {
            $met = $bad = $over = 0;
            foreach (Slo::evaluateMany($created, date('Y-m-d H:i:s', $toT)) as $per) {
                foreach ($per as $res) {
                    $s = $res['state'] ?? '';
                    $met += $s === 'met' ? 1 : 0;
                    $bad += $s === 'breached' ? 1 : 0;
                    $over += $s === 'overdue' ? 1 : 0;
                }
            }
            $v['slo_cumprido'] = self::pct($met, $met + $bad + $over);
            if ($isNow) {
                $v['slo_vencidos'] = (float) $over;
            }
        }

        // ---- carga
        $perUser = [];
        foreach ($open as $r) {
            if ((int) $r['users_id_assigned'] > 0) {
                $perUser[(int) $r['users_id_assigned']] = ($perUser[(int) $r['users_id_assigned']] ?? 0) + 1;
            }
        }
        $v['wip_pessoa'] = $perUser ? round(array_sum($perUser) / count($perUser), 1) : null;
        $v['concentracao'] = $perUser ? self::pct(max($perUser), array_sum($perUser)) : null;
        $v['sobrecarga'] = (float) count(array_filter($perUser, fn ($n) => $n > self::OVERLOAD));
        $doers = [];
        foreach ($done as $r) {
            if ((int) $r['users_id_assigned'] > 0) {
                $doers[(int) $r['users_id_assigned']] = true;
            }
        }
        $v['produtividade'] = $doers ? round(count($done) / count($doers), 1) : null;

        // ---- projetos e planos (só o retrato de hoje)
        if ($isNow) {
            $today = date('Y-m-d');
            $acts = [];
            foreach ($c['rows'] as $r) {
                if ((int) $r['mkt_projects_id'] > 0 && $r['status'] !== Demand::STATUS_CANCELLED) {
                    $acts[(int) $r['mkt_projects_id']][] = $r;
                }
            }
            $effs = $devs = [];
            $active = $risky = 0;
            foreach ($c['projects'] as $pid => $p) {
                if ($p['is_archived'] || !in_array($p['status'], ['planning', 'active'], true)) {
                    continue;
                }
                $active++;
                $rows = Structure::withoutSources($acts[$pid] ?? []);
                $eff = Structure::effective($rows);
                $exp = Structure::expected($p['start_date'], $p['end_date'], $today);
                if ($eff !== null) {
                    $effs[] = $eff;
                }
                $dev = ($eff !== null && $exp !== null) ? $eff - $exp : null;
                if ($dev !== null) {
                    $devs[] = $dev;
                }
                $risky += (($dev !== null && $dev < -15) || ($p['end_date'] && $p['end_date'] < $today)) ? 1 : 0;
            }
            $v['proj_exec'] = self::avg($effs);
            $v['proj_desvio'] = self::avg($devs);
            $v['proj_risco'] = self::pct($risky, $active);
            $dueM = $doneM = 0;
            foreach ($c['milestones'] as $m) {
                if ($m['due_date'] && $m['due_date'] <= $today) {
                    $dueM++;
                    $doneM += $m['is_done'] ? 1 : 0;
                }
            }
            $v['marcos'] = self::pct($doneM, $dueM);
            $plansActive = array_filter($c['plans'], fn ($p) => !$p['is_archived']);
            $v['planos_sem_resp'] = self::pct(count(array_filter($plansActive, fn ($p) => (int) $p['users_id'] <= 0)), count($plansActive));
            $pa = array_filter($c['rows'], fn ($r) => (int) $r['mkt_projects_id'] > 0 && Demand::isOpen($r));
            $v['sem_pct'] = self::pct(count(array_filter($pa, fn ($r) => $r['exec_percent'] === null)), count($pa));
        }
        if (!$isNow) {
            // período antigo: indicadores de "retrato do dia" só existem se houve instantâneo naquele mês
            $snap = self::snapshots(substr($to, 0, 7));
            foreach (self::catalog() as $key => $def) {
                if ($def['cur'] && $v[$key] === null && isset($snap[$key])) {
                    $v[$key] = $snap[$key];
                }
            }
            // sem instantâneo daquele mês: usa o retrato de hoje (os books informam que projetos e planos mostram o dia da geração)
            if ($fillCurrent && array_filter(self::catalog(), fn ($d, $k) => $d['cur'] && $v[$k] === null, ARRAY_FILTER_USE_BOTH)) {
                $today = date('Y-m-d');
                $now = self::values($today, $today);
                foreach (self::catalog() as $key => $def) {
                    if ($def['cur'] && $v[$key] === null) {
                        $v[$key] = $now[$key] ?? null;
                    }
                }
            }
        }
        return $v;
    }

    /**
     * Série mensal dos indicadores: retroativa para fluxo e estoque reconstruível; os de "retrato de hoje" vêm dos instantâneos diários.
     *
     * @return array<string, array<string, float|null>> 'AAAA-MM' => [chave => valor]
     */
    public static function series(string $endMonth, int $months = 12): array
    {
        $out = [];
        $base = strtotime($endMonth . '-01');
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = date('Y-m', strtotime("-$i month", $base));
            $from = $m . '-01';
            $to = date('Y-m-t', strtotime($from));
            if ($from > date('Y-m-d')) {
                $out[$m] = array_fill_keys(array_keys(self::catalog()), null); // mês futuro: sem dados
                continue;
            }
            $vals = self::values($from, $to, false);
            $snap = self::snapshots($m);
            foreach (self::catalog() as $key => $def) {
                if ($vals[$key] === null && isset($snap[$key])) {
                    $vals[$key] = $snap[$key];
                }
            }
            $out[$m] = $vals;
        }
        return $out;
    }

    public static function snapTable(): string
    {
        return 'glpi_plugin_marketing_kpisnaps';
    }

    /** @return array<string, float> */
    public static function snapshots(string $month): array
    {
        global $DB;
        $out = [];
        if (!$DB->tableExists(self::snapTable())) {
            return $out;
        }
        foreach ($DB->request(['FROM' => self::snapTable(), 'WHERE' => ['period' => $month]]) as $r) {
            $out[$r['kpi']] = (float) $r['value'];
        }
        return $out;
    }

    /** Grava os valores de hoje como instantâneo do mês corrente (o último dia do mês fica guardado). Devolve quantos indicadores gravou. */
    public static function snapshotNow(): int
    {
        global $DB;
        self::resetCache();
        $m = date('Y-m');
        $vals = self::values($m . '-01', date('Y-m-d'));
        $n = 0;
        foreach ($vals as $key => $val) {
            if ($val === null) {
                continue;
            }
            $DB->delete(self::snapTable(), ['period' => $m, 'kpi' => $key]);
            $DB->insert(self::snapTable(), ['period' => $m, 'kpi' => $key, 'value' => $val, 'date_mod' => date('Y-m-d H:i:s')]);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------ detalhamento (gaveta)

    /**
     * Itens que compõem um indicador no período (para a gaveta lateral). Demandas: id, code, name, status, assignee, deadline, detail.
     * Indicadores de projetos devolvem projetos, planos ou marcos (kind) em vez de demandas.
     *
     * @return array{kind: string, note: string, rows: array<int, array<string, mixed>>, total: int}
     */
    public static function drill(string $key, string $from, string $to, int $limit = 200): array
    {
        $c = self::ctx();
        $fromT = strtotime($from . ' 00:00:00');
        $toT = min(strtotime($to . ' 23:59:59'), time());
        $asDate = date('Y-m-d', $toT);
        $created = $done = $cancel = $open = [];
        foreach ($c['rows'] as $id => $r) {
            $ct = self::ts($r['created']);
            if ($ct !== null && $ct >= $fromT && $ct <= $toT) {
                $created[$id] = $r;
            }
            if ($r['status'] === Demand::STATUS_DONE) {
                $dt = self::ts($r['completed_at']) ?? self::ts($r['date_mod']);
                if ($dt !== null && $dt >= $fromT && $dt <= $toT) {
                    $r['done_at'] = $dt;
                    $done[$id] = $r;
                }
            }
            if ($r['status'] === Demand::STATUS_CANCELLED) {
                $cd = self::ts($c['cancelled'][$id] ?? $r['date_mod']);
                if ($cd !== null && $cd >= $fromT && $cd <= $toT) {
                    $cancel[$id] = $r;
                }
            }
            if (self::openAt($c, $r, $toT)) {
                $open[$id] = $r;
            }
        }
        $row = function (array $r, string $detail = '', ?int $at = null) use ($c, $toT) {
            $at ??= $toT;
            return ['id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'status' => $r['status'], 'assignee' => (int) $r['users_id_assigned'],
                'deadline' => self::deadlineAt($c, $r, $at), 'detail' => $detail, 'priority' => (int) $r['priority']];
        };
        $kind = 'demands';
        $note = '';
        $rows = [];
        $meta = self::catalog()[$key] ?? null;
        switch ($key) {
            case 'entradas':
            case 'vazao':
            case 'mix_interno':
                foreach ($created as $r) {
                    if ($key === 'mix_interno' && $r['origin'] !== Demand::ORIGIN_INTERNAL) {
                        continue;
                    }
                    $rows[] = $row($r, 'Entrou em ' . date('d/m/Y', self::ts($r['created'])) . ' · ' . ($r['origin'] === Demand::ORIGIN_INTERNAL ? 'interna' : 'externa'));
                }
                $note = 'Demandas que entraram no período.';
                break;
            case 'concluidas':
            case 'lead_time':
            case 'produtividade':
                foreach ($done as $r) {
                    $rows[] = $row($r, 'Concluída em ' . date('d/m/Y', $r['done_at']) . ' · ' . round(self::days(self::ts($r['created']), $r['done_at']), 1) . ' dias desde a entrada');
                }
                $note = 'Demandas concluídas no período.';
                break;
            case 'otd':
                foreach ($done as $r) {
                    $dl = self::deadlineAt($c, $r, $r['done_at']);
                    if ($dl) {
                        $ok = date('Y-m-d', $r['done_at']) <= $dl;
                        $rows[] = $row($r, ($ok ? 'No prazo' : 'Atrasou') . ' · concluída em ' . date('d/m/Y', $r['done_at']) . ' · prazo ' . date('d/m/Y', strtotime($dl)), $r['done_at']) + ['bad' => !$ok];
                    }
                }
                usort($rows, fn ($a, $b) => ($b['bad'] <=> $a['bad']));
                $note = 'Concluídas com prazo no período (as que atrasaram aparecem primeiro).';
                break;
            case 'com_evidencia':
            case 'com_aceite':
            case 'excecoes':
                foreach ($done as $id => $r) {
                    $ex = trim((string) ($r['exception_note'] ?? '')) !== '';
                    $has = $key === 'com_evidencia' ? ($ex || isset($c['evid'][$id])) : ($key === 'com_aceite' ? ($ex || !empty($r['accepted_at'])) : $ex);
                    if ($key === 'com_aceite' && $r['origin'] !== Demand::ORIGIN_EXTERNAL) {
                        continue;
                    }
                    $label = $key === 'excecoes' ? ($ex ? 'Concluída por exceção' : 'Sem exceção') : ($has ? 'Atende' : 'Não atende');
                    $rows[] = $row($r, $label) + ['bad' => $key === 'excecoes' ? $ex : !$has];
                }
                usort($rows, fn ($a, $b) => $b['bad'] <=> $a['bad']);
                $note = 'Concluídas do período; as que pioram o indicador aparecem primeiro.';
                break;
            case 'cancelamento':
                foreach ($cancel as $r) {
                    $rows[] = $row($r, 'Cancelada');
                }
                $note = 'Demandas canceladas no período.';
                break;
            case 'backlog':
            case 'idade_backlog':
            case 'sem_responsavel':
            case 'backlog_60':
            case 'wip_pessoa':
            case 'concentracao':
            case 'sobrecarga':
            case 'risco_bloq':
            case 'atrasadas_pct':
            case 'atraso_medio':
            case 'adiadas_pct':
            case 'reincidentes':
            case 'dep_bloq':
                $perUser = [];
                foreach ($open as $r) {
                    if ((int) $r['users_id_assigned'] > 0) {
                        $perUser[(int) $r['users_id_assigned']] = ($perUser[(int) $r['users_id_assigned']] ?? 0) + 1;
                    }
                }
                foreach ($open as $id => $r) {
                    $age = self::days(self::ts($r['created']), $toT);
                    $dl = self::deadlineAt($c, $r, $toT);
                    $late = $dl && $dl < $asDate ? (int) self::days(strtotime($dl), strtotime($asDate)) : 0;
                    $nRes = count(array_filter($c['resched'][$id] ?? [], fn ($x) => self::ts($x['date_creation']) <= $toT));
                    $postponed = (bool) array_filter($c['resched'][$id] ?? [], fn ($x) => self::ts($x['date_creation']) <= $toT && $x['new_date'] > $x['old_date']);
                    $st = self::statusAt($c, $r, $toT);
                    $dep = (int) $r['depends_on_id'];
                    $keep = match ($key) {
                        'sem_responsavel' => (int) $r['users_id_assigned'] <= 0,
                        'backlog_60' => $age > 60,
                        'risco_bloq' => in_array($st, [Demand::STATUS_AT_RISK, Demand::STATUS_BLOCKED], true),
                        'atrasadas_pct', 'atraso_medio' => $late > 0,
                        'adiadas_pct' => $postponed,
                        'reincidentes' => $nRes >= 2,
                        'sobrecarga' => (int) $r['users_id_assigned'] > 0 && ($perUser[(int) $r['users_id_assigned']] ?? 0) > self::OVERLOAD,
                        'dep_bloq' => $dep > 0 && isset($c['rows'][$dep]) && Demand::isOpen($c['rows'][$dep]),
                        'wip_pessoa', 'concentracao' => (int) $r['users_id_assigned'] > 0,
                        default => true,
                    };
                    if (!$keep) {
                        continue;
                    }
                    $bits = ['aberta há ' . (int) $age . ' d'];
                    if ($late) {
                        $bits[] = 'atrasada há ' . $late . ' d';
                    }
                    if ($nRes) {
                        $bits[] = $nRes . ' reprogramação(ões)';
                    }
                    if ($key === 'dep_bloq') {
                        $bits[] = 'espera ' . ($c['rows'][$dep]['code'] ?? '');
                    }
                    $rows[] = $row($r, implode(' · ', $bits)) + ['weight' => $key === 'atraso_medio' || $key === 'atrasadas_pct' ? $late : $age];
                }
                usort($rows, fn ($a, $b) => $b['weight'] <=> $a['weight']);
                $note = 'Demandas em aberto na data final do período que entram neste indicador.';
                break;
            case 'reprogramacoes':
                $seen = [];
                foreach ($c['resched'] as $did => $list) {
                    foreach ($list as $x) {
                        $t = self::ts($x['date_creation']);
                        if ($t !== null && $t >= $fromT && $t <= $toT && isset($c['rows'][$did])) {
                            $rows[] = $row($c['rows'][$did], date('d/m/Y', strtotime($x['old_date'])) . ' → ' . date('d/m/Y', strtotime($x['new_date'])) . ' · ' . mb_substr((string) $x['reason'], 0, 90));
                        }
                    }
                }
                $note = 'Reprogramações de prazo do período, com a justificativa.';
                break;
            case 'tempo_triagem':
                foreach ($created as $id => $r) {
                    if ($r['origin'] !== Demand::ORIGIN_EXTERNAL) {
                        continue;
                    }
                    foreach ($c['events'][$id] ?? [] as $e) {
                        if ($e['new_value'] === Demand::STATUS_PLANNED) {
                            $rows[] = $row($r, 'Planejada após ' . round(self::days(self::ts($r['created']), self::ts($e['date_creation'])), 1) . ' dias');
                            break;
                        }
                    }
                }
                $note = 'Solicitações do período e quanto levaram até serem planejadas.';
                break;
            case 'briefing_completo':
                foreach ($created as $r) {
                    if ($r['origin'] === Demand::ORIGIN_EXTERNAL) {
                        $miss = Demand::missingInputs($r);
                        $rows[] = $row($r, $miss ? 'Faltou: ' . implode(', ', $miss) : 'Briefing completo') + ['bad' => (bool) $miss];
                    }
                }
                usort($rows, fn ($a, $b) => $b['bad'] <=> $a['bad']);
                $note = 'Solicitações externas do período e se o briefing chegou completo.';
                break;
            case 'tempo_aprovacao':
            case 'retrabalho':
                foreach ($c['approvals'] as $a) {
                    $dd = self::ts($a['date_decision'] ?? null);
                    if ($dd !== null && $dd >= $fromT && $dd <= $toT && isset($c['rows'][(int) $a['demands_id']])) {
                        $rows[] = $row($c['rows'][(int) $a['demands_id']], ($a['status'] === 'refused' ? 'Recusada' : 'Aprovada') . ' em ' . round(self::days(self::ts($a['date_creation']), $dd), 1) . ' dias');
                    }
                }
                $note = 'Aprovações decididas no período.';
                break;
            case 'slo_cumprido':
            case 'slo_vencidos':
                foreach (Slo::evaluateMany($created, date('Y-m-d H:i:s', $toT)) as $did => $per) {
                    foreach ($per as $sid => $res) {
                        $stt = $res['state'] ?? '';
                        if (in_array($stt, ['breached', 'overdue', 'met'], true) && ($key === 'slo_cumprido' || $stt === 'overdue')) {
                            $rows[] = $row($created[$did], (Slo::all(true)[$sid]['name'] ?? 'SLO') . ': ' . ['met' => 'cumprido', 'breached' => 'cumprido fora do prazo', 'overdue' => 'vencido'][$stt]) + ['bad' => $stt !== 'met'];
                        }
                    }
                }
                usort($rows, fn ($a, $b) => $b['bad'] <=> $a['bad']);
                $note = 'Demandas do período com SLO ativo e o resultado de cada um.';
                break;
            case 'proj_exec':
            case 'proj_desvio':
            case 'proj_risco':
                $kind = 'projects';
                foreach (self::projectRisks(date('Y-m-d')) as $p) {
                    $rows[] = ['id' => $p['id'], 'name' => $p['name'], 'owner' => $p['owner'], 'detail' => 'Execução ' . ($p['effective'] ?? '—') . '% · cronograma ' . ($p['expected'] ?? '—') . '% · desvio ' . ($p['deviation'] === null ? '—' : (int) $p['deviation'] . ' p.p.') . ' · ' . $p['late'] . ' atrasada(s)', 'risk' => $p['risk']];
                }
                $note = 'Projetos ativos (os em risco aparecem primeiro). Retrato de hoje.';
                break;
            case 'planos_sem_resp':
                $kind = 'plans';
                foreach ($c['plans'] as $pl) {
                    if (!$pl['is_archived'] && (int) $pl['users_id'] <= 0) {
                        $rows[] = ['id' => (int) $pl['id'], 'name' => $pl['name'], 'detail' => 'Projeto: ' . ($c['projects'][(int) $pl['mkt_projects_id']]['name'] ?? '—')];
                    }
                }
                $note = 'Planos de ação ativos sem responsável principal.';
                break;
            case 'marcos':
                $kind = 'milestones';
                foreach ($c['milestones'] as $m) {
                    if ($m['due_date'] && $m['due_date'] <= date('Y-m-d')) {
                        $rows[] = ['id' => (int) $m['mkt_projects_id'], 'name' => $m['name'], 'detail' => ($c['projects'][(int) $m['mkt_projects_id']]['name'] ?? '—') . ' · vence ' . date('d/m/Y', strtotime($m['due_date'])) . ' · ' . ($m['is_done'] ? 'cumprido' : 'NÃO cumprido'), 'risk' => !$m['is_done']];
                    }
                }
                usort($rows, fn ($a, $b) => $b['risk'] <=> $a['risk']);
                $note = 'Marcos com data vencida e se foram cumpridos.';
                break;
            case 'sem_pct':
                foreach ($c['rows'] as $r) {
                    if ((int) $r['mkt_projects_id'] > 0 && Demand::isOpen($r) && $r['exec_percent'] === null) {
                        $rows[] = $row($r, 'Projeto: ' . ($c['projects'][(int) $r['mkt_projects_id']]['name'] ?? '—'), $toT);
                    }
                }
                $note = 'Atividades abertas de projetos sem percentual de execução informado.';
                break;
        }
        return ['kind' => $kind, 'note' => $note, 'rows' => array_slice($rows, 0, $limit), 'total' => count($rows), 'name' => $meta['name'] ?? $key];
    }

    // ------------------------------------------------------------------ pontuação

    /**
     * Resumo de um conjunto de valores: linha por indicador (valor, meta, status, pontuação, valor anterior, tendência),
     * pontuação por grupo e geral.
     *
     * @param array<string, float|null> $vals
     * @param array<string, float|null> $prev
     * @return array{rows: array<string, array<string, mixed>>, groups: array<string, array<string, mixed>>, overall: ?float}
     */
    public static function summarize(array $vals, array $prev = []): array
    {
        $cat = self::targets();
        $rows = [];
        $groups = [];
        foreach (self::GROUPS as $gid => [$gname, $gcolor]) {
            $groups[$gid] = ['name' => $gname, 'color' => $gcolor, 'scores' => [], 'score' => null, 'green' => 0, 'amber' => 0, 'red' => 0, 'count' => 0];
        }
        foreach ($cat as $key => $def) {
            $val = $vals[$key] ?? null;
            $pv = $prev[$key] ?? null;
            $score = self::score($def, $val);
            $status = self::status($def, $val);
            // tendência: melhora/piora conforme o sentido do indicador; "estável" dentro de 2% do alcance da meta
            $trend = 'flat';
            if ($val !== null && $pv !== null && $def['dir'] !== 'info') {
                $span = max(0.0001, abs((float) ($def['target'] ?? 0) - (float) ($def['warn'] ?? 0)));
                $diff = $val - $pv;
                if (abs($diff) > 0.02 * $span) {
                    $trend = (($diff > 0) === ($def['dir'] === 'up')) ? 'better' : 'worse';
                }
            } elseif ($val !== null && $pv !== null && abs($val - $pv) > 0.0001) {
                $trend = $val > $pv ? 'up' : 'down';
            }
            $rows[$key] = $def + ['key' => $key, 'value' => $val, 'prev' => $pv, 'score' => $score, 'status' => $status, 'trend' => $trend];
            $g = &$groups[$def['group']];
            $g['count']++;
            if ($score !== null) {
                $g['scores'][] = $score;
                $g[$status]++;
            }
            unset($g);
        }
        $gs = [];
        foreach ($groups as $gid => &$g) {
            $g['score'] = $g['scores'] ? round(array_sum($g['scores']) / count($g['scores']), 0) : null;
            if ($g['score'] !== null) {
                $gs[] = $g['score'];
            }
        }
        unset($g);
        return ['rows' => $rows, 'groups' => $groups, 'overall' => $gs ? round(array_sum($gs) / count($gs), 0) : null];
    }

    // ------------------------------------------------------------------ ofensores e recuperação

    /**
     * Indicadores fora da meta com plano de recuperação: severidade, ações recomendadas, responsável sugerido,
     * meta intermediária e prazos recomendados (dias úteis a partir de $from).
     *
     * @param array<string, array<string, mixed>> $rows linhas de summarize()
     * @return array<int, array<string, mixed>> do mais grave ao menos grave
     */
    public static function offenders(array $rows, ?string $from = null): array
    {
        $from ??= date('Y-m-d');
        $out = [];
        foreach ($rows as $key => $r) {
            if (!in_array($r['status'], ['amber', 'red'], true) || $r['value'] === null) {
                continue;
            }
            $sev = $r['status'] === 'amber' ? 'medio' : ($r['score'] < 25 || $r['trend'] === 'worse' ? 'critico' : 'alto');
            $days = ['critico' => 20, 'alto' => 30, 'medio' => 40][$sev]; // dias úteis para chegar à meta
            if (!empty($r['quick'])) {
                $days = (int) ceil($days / 2); // higiene de dados: resolve-se mais rápido
            }
            $half = (int) ceil($days / 2);
            $target = (float) $r['target'];
            $mid = $r['value'] + ($target - $r['value']) * 0.5;
            $out[] = [
                'key' => $key, 'name' => $r['name'], 'group' => $r['group'], 'severity' => $sev, 'score' => $r['score'],
                'value' => $r['value'], 'target' => $r['target'], 'unit' => $r['unit'], 'dir' => $r['dir'], 'trend' => $r['trend'],
                'gap' => $target - $r['value'], 'why' => $r['why'], 'actions' => $r['actions'], 'owner' => $r['owner'],
                'mid_value' => $mid, 'mid_date' => self::bd($from, $half, 0), 'due_date' => self::bd($from, $days, 0), 'days' => $days,
                'review_date' => self::bd($from, 5, 0),
            ];
        }
        usort($out, fn ($a, $b) => (['critico' => 0, 'alto' => 1, 'medio' => 2][$a['severity']] <=> ['critico' => 0, 'alto' => 1, 'medio' => 2][$b['severity']]) ?: ($a['score'] <=> $b['score']));
        return $out;
    }

    public const SEVERITY = ['critico' => ['Crítico', '#dc2626'], 'alto' => ['Alto', '#ea580c'], 'medio' => ['Médio', '#d97706']];

    /**
     * Demandas ofensoras com prazo recomendado de recuperação: atrasadas (por gravidade), adiadas reincidentes, bloqueadas há mais de 14 dias,
     * abertas há mais de 60 dias e sem responsável. Prazo recomendado em dias úteis a partir de $asof: prioridade muito alta/alta 5, média 10,
     * baixa/muito baixa 15; reincidentes e bloqueadas: 3 (replanejar e definir novo prazo).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function offenderDemands(?string $asof = null, int $limit = 25): array
    {
        $asof ??= date('Y-m-d');
        $base = max($asof, date('Y-m-d')); // prazo recomendado nunca começa no passado
        $c = self::ctx();
        $at = strtotime($asof . ' 23:59:59');
        $list = [];
        foreach ($c['rows'] as $id => $r) {
            if (!self::openAt($c, $r, $at)) {
                continue;
            }
            $reasons = [];
            $weight = 0.0;
            $dl = self::deadlineAt($c, $r, $at);
            $lateDays = ($dl && $dl < $asof) ? self::days(strtotime($dl), strtotime($asof)) : 0;
            if ($lateDays > 0) {
                $reasons[] = 'Atrasada há ' . (int) $lateDays . ' dia(s)';
                $weight += $lateDays;
            }
            $nRes = count(array_filter($c['resched'][$id] ?? [], fn ($x) => self::ts($x['date_creation']) <= $at));
            if ($nRes >= 2) {
                $reasons[] = 'Prazo reprogramado ' . $nRes . ' vezes';
                $weight += 15 * $nRes;
            }
            $st = self::statusAt($c, $r, $at);
            if (in_array($st, [Demand::STATUS_BLOCKED, Demand::STATUS_AT_RISK], true)) {
                $since = null;
                foreach ($c['events'][$id] ?? [] as $e) {
                    if ($e['new_value'] === $st) {
                        $since = self::ts($e['date_creation']);
                    }
                }
                $d = $since ? self::days($since, $at) : 0;
                if ($d > 14) {
                    $reasons[] = (Demand::STATUSES[$st] ?? $st) . ' há ' . (int) $d . ' dias';
                    $weight += $d;
                }
            }
            $age = self::days(self::ts($r['created']), $at);
            if ($age > 60) {
                $reasons[] = 'Aberta há ' . (int) $age . ' dias';
                $weight += $age / 2;
            }
            if ((int) $r['users_id_assigned'] <= 0) {
                $reasons[] = 'Sem responsável';
                $weight += 10;
            }
            if (!$reasons) {
                continue;
            }
            $prio = (int) $r['priority'];
            $du = ($nRes >= 2 || in_array($st, [Demand::STATUS_BLOCKED], true)) ? 3 : ($prio <= 2 ? 5 : ($prio === 3 ? 10 : 15));
            $list[] = [
                'id' => $id, 'code' => $r['code'], 'name' => $r['name'], 'priority' => $prio, 'status' => $st, 'assignee' => (int) $r['users_id_assigned'],
                'deadline' => $dl, 'reasons' => $reasons, 'weight' => $weight + (6 - $prio) * 3, 'recommended' => self::bd($base, $du, 0), 'du' => $du,
                'action' => $nRes >= 2 ? 'Replanejar com o solicitante e fixar um prazo final aprovado pela gerência.'
                    : ($st === Demand::STATUS_BLOCKED ? 'Remover o impedimento ou escalar à gerência.'
                    : ($lateDays > 0 ? 'Reprogramar com justificativa e entregar no prazo recomendado.'
                    : ((int) $r['users_id_assigned'] <= 0 ? 'Atribuir um responsável.' : 'Concluir, replanejar ou cancelar.'))),
            ];
        }
        usort($list, fn ($a, $b) => $b['weight'] <=> $a['weight']);
        return array_slice($list, 0, $limit);
    }

    /** Projetos em risco (desvio < −15 p.p. ou fim vencido) com o prazo recomendado para replanejar. */
    public static function projectRisks(?string $asof = null): array
    {
        $asof ??= date('Y-m-d');
        $c = self::ctx();
        $acts = [];
        foreach ($c['rows'] as $r) {
            if ((int) $r['mkt_projects_id'] > 0 && $r['status'] !== Demand::STATUS_CANCELLED) {
                $acts[(int) $r['mkt_projects_id']][] = $r;
            }
        }
        $out = [];
        foreach ($c['projects'] as $pid => $p) {
            if ($p['is_archived'] || !in_array($p['status'], ['planning', 'active'], true)) {
                continue;
            }
            $rows = Structure::withoutSources($acts[$pid] ?? []);
            $eff = Structure::effective($rows);
            $prog = Structure::progress($rows);
            $exp = Structure::expected($p['start_date'], $p['end_date'], $asof);
            $dev = ($eff !== null && $exp !== null) ? $eff - $exp : null;
            $late = count(array_filter($rows, fn ($r) => Demand::isOverdue($r, $asof)));
            $ended = $p['end_date'] && $p['end_date'] < $asof;
            $out[] = ['id' => $pid, 'name' => $p['name'], 'owner' => (int) $p['users_id'], 'start' => $p['start_date'], 'end' => $p['end_date'], 'status' => $p['status'],
                'progress' => $prog, 'effective' => $eff, 'expected' => $exp, 'deviation' => $dev, 'late' => $late, 'activities' => count($rows),
                'risk' => ($dev !== null && $dev < -15) || $ended, 'recommended' => self::bd($asof, ($dev !== null && $dev < -30) || $ended ? 5 : 10, 0)];
        }
        usort($out, fn ($a, $b) => ($b['risk'] <=> $a['risk']) ?: (($a['deviation'] ?? 0) <=> ($b['deviation'] ?? 0)));
        return $out;
    }

    // ------------------------------------------------------------------ narrativa executiva

    /** Frases do sumário executivo geradas dos números do período. */
    public static function narrative(array $sum, string $label): array
    {
        $r = $sum['rows'];
        $f = fn (string $k) => self::format($r[$k], $r[$k]['value']);
        $out = [];
        if ($r['entradas']['value'] !== null) {
            $out[] = "Em $label entraram " . $f('entradas') . ' demandas e foram concluídas ' . $f('concluidas') . ($r['vazao']['value'] !== null ? ' (vazão de ' . $f('vazao') . ').' : '.')
                . ' O backlog fechou com ' . $f('backlog') . ' demandas em aberto, com idade média de ' . $f('idade_backlog') . '.';
        }
        if ($r['otd']['value'] !== null) {
            $out[] = 'A pontualidade das entregas foi de ' . $f('otd') . ' (meta ' . self::formatTarget($r['otd']) . '); ' . $f('atrasadas_pct') . ' do backlog está atrasado e ' . $f('adiadas_pct') . ' teve o prazo adiado.';
        }
        if ($r['lead_time']['value'] !== null) {
            $out[] = 'O tempo médio de conclusão foi de ' . $f('lead_time') . ', com ' . $f('tempo_triagem') . ' até o planejamento.';
        }
        if ($sum['overall'] !== null) {
            $reds = array_filter($r, fn ($x) => $x['status'] === 'red');
            $greens = array_filter($r, fn ($x) => $x['status'] === 'green');
            $out[] = 'O índice geral de desempenho é ' . $sum['overall'] . ' de 100: ' . count($greens) . ' indicadores na meta e ' . count($reds) . ' em situação crítica.';
        }
        return $out;
    }
}

<?php

/**
 * Projetos e planos de ação, agrupados por pilar. Duas visões: "Cartões" (portfólio) e "Tabela" (mesmo formato da tabela
 * principal de demandas, com árvore: projeto → planos → atividades). Criar projeto exige o direito "Cadastro interno".
 */

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Demand;
use GlpiPlugin\Marketing\Settings;
use GlpiPlugin\Marketing\Structure;
use GlpiPlugin\Marketing\Ui;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
global $DB;

$e = fn ($v) => Ui::e($v);
$today = date('Y-m-d');
$showArchived = !empty($_GET['archived']);

// visão escolhida fica guardada na sessão
$view = (string) ($_GET['view'] ?? ($_SESSION['marketing_projects_view'] ?? 'cards'));
$view = $view === 'table' ? 'table' : 'cards';
$_SESSION['marketing_projects_view'] = $view;

$pillars = Structure::pillars();
$projects = Structure::projects();
$colors = Settings::statusColors();
$pstColors = ['planning' => '#94a3b8', 'active' => '#3b82f6', 'paused' => '#f59e0b', 'done' => '#22c55e'];

$byProject = $byPlan = [];
foreach (Structure::withoutSources(Structure::activities(['mkt_projects_id' => ['>', 0]] + getEntitiesRestrictCriteria('glpi_plugin_marketing_demands', 'entities_id', '', true))) as $a) {
    $byProject[(int) $a['mkt_projects_id']][] = $a;
    $byPlan[(int) $a['plans_id']][] = $a;
}
$plansByProject = [];
foreach (Structure::plans() as $pl) {
    $plansByProject[(int) $pl['mkt_projects_id']][] = $pl;
}
$milestones = [];
foreach ($DB->request(['FROM' => Structure::milestonesTable(), 'ORDER' => ['due_date ASC', 'id ASC']]) as $m) {
    $milestones[(int) $m['mkt_projects_id']][] = $m;
}

// ---------------------------------------------------------------- filtros
$fPillar = isset($_GET['pillar']) && $_GET['pillar'] !== '' ? (int) $_GET['pillar'] : null;
$fStatus = (string) ($_GET['status'] ?? '');
$fMine = !empty($_GET['mine']);
$me = (int) Session::getLoginUserID();

$groups = [];
$kpi = ['projects' => 0, 'active' => 0, 'acts' => 0, 'late' => 0, 'sum' => 0, 'n' => 0];
foreach ($projects as $id => $p) {
    if ($p['is_archived'] && !$showArchived) {
        continue;
    }
    if ($fPillar !== null && (int) $p['pillars_id'] !== $fPillar) {
        continue;
    }
    if ($fStatus !== '' && $p['status'] !== $fStatus) {
        continue;
    }
    if ($fMine && (int) $p['users_id'] !== $me) {
        continue;
    }
    $groups[(int) $p['pillars_id']][$id] = $p;
    $acts = $byProject[$id] ?? [];
    $kpi['projects']++;
    $kpi['active'] += $p['status'] === 'active' ? 1 : 0;
    $kpi['acts'] += count($acts);
    foreach ($acts as $a) {
        $kpi['late'] += Demand::isOverdue($a, $today) ? 1 : 0;
    }
    if (($pg = Structure::progress($acts)) !== null) {
        $kpi['sum'] += $pg;
        $kpi['n']++;
    }
}
// pilares na ordem de cadastro; "sem pilar" por último
uksort($groups, fn ($a, $b) => ($a === 0) <=> ($b === 0) ?: $a <=> $b);

Ui::header('Projetos e planos', 'projects');
$keep = array_filter(['pillar' => $fPillar ?? '', 'status' => $fStatus, 'mine' => $fMine ? 1 : '', 'archived' => $showArchived ? 1 : '']);
echo Ui::title(
    'Projetos e planos',
    'Pilar → projeto → plano de ação → atividade. Demandas avulsas continuam valendo, sem projeto.',
    (Access::canInternal() ? Ui::button('Novo projeto', '#novo-projeto', 'ti-plus') . ' ' : '')
    . ($showArchived ? Ui::button('Ocultar arquivados', Ui::url('/front/projects.php?' . http_build_query(array_diff_key($keep, ['archived' => 1]) + ['view' => $view])), 'ti-eye-off', 'btn-outline-secondary')
        : Ui::button('Arquivados', Ui::url('/front/projects.php?' . http_build_query($keep + ['archived' => 1, 'view' => $view])), 'ti-archive', 'btn-outline-secondary'))
);

// ---------------------------------------------------------------- indicadores
$avg = $kpi['n'] ? (int) round($kpi['sum'] / $kpi['n']) : null;
$card = fn (string $label, string $value, string $sub, string $color) => "<div class='mkt-kpi' style='--c:$color'><div class='mkt-kpi-label'>" . $e($label) . "</div><div class='mkt-kpi-value'>" . $e($value) . "</div><div class='mkt-kpi-sub'>" . $e($sub) . "</div></div>";
echo "<div class='mkt-dash'><div class='mkt-kpis'>"
    . $card('Projetos', (string) $kpi['projects'], $kpi['active'] . ' em execução', '#3b82f6')
    . $card('Atividades', (string) $kpi['acts'], 'em todos os projetos', '#6366f1')
    . $card('Progresso médio', $avg === null ? '—' : $avg . '%', 'média dos projetos', '#22c55e')
    . $card('Atrasadas', (string) $kpi['late'], 'prazo vencido e em aberto', $kpi['late'] ? '#ef4444' : '#94a3b8')
    . "</div></div>";

// ---------------------------------------------------------------- abas e filtros
$tab = fn (string $v, string $label, string $icon) => "<a class='" . ($view === $v ? 'on' : '') . "' href='" . $e(Ui::url('/front/projects.php?' . http_build_query($keep + ['view' => $v]))) . "'><i class='ti $icon me-1'></i>" . $e($label) . "</a>";
echo "<nav class='mkt-viewtabs'>" . $tab('cards', 'Cartões', 'ti-layout-grid') . $tab('table', 'Tabela com árvore', 'ti-table') . "</nav>";

echo "<form method='get' class='card card-body mb-3'><input type='hidden' name='view' value='" . $e($view) . "'>" . ($showArchived ? "<input type='hidden' name='archived' value='1'>" : '') . "<div class='mkt-filters'>";
echo "<div><label>Pilar</label>" . Ui::select('pillar', Structure::pillarOptions(false), $fPillar ?? '', '', true) . "</div>";
echo "<div><label>Situação</label>" . Ui::select('status', Structure::PROJECT_STATUSES, $fStatus, '', true) . "</div>";
echo "<div><label>Busca rápida</label><input class='form-control' type='search' id='mkt-q' placeholder='Nome do projeto, plano ou atividade' autocomplete='off'></div>";
echo "<div class='form-check mb-2'><input class='form-check-input' type='checkbox' name='mine' value='1' id='mine'" . ($fMine ? ' checked' : '') . "><label class='form-check-label' for='mine'>Só meus projetos</label></div>";
echo "<div class='mkt-filter-actions'><button class='btn btn-primary'><i class='ti ti-filter me-1'></i>Filtrar</button></div></div></form>";

if (!$groups) {
    echo "<div class='card card-body text-center text-secondary'>Nenhum projeto neste filtro" . (Access::canInternal() ? ': crie o primeiro abaixo.' : '.') . "</div>";
}

/** Chip colorido de situação. */
$chip = fn (string $label, string $color) => "<span class='mkt-st ro' style='--c:" . $e($color) . "'>" . $e($label) . "</span>";
$planPhase = function (?int $pg) {
    if ($pg === null) {
        return ['Sem atividades', '#94a3b8'];
    }
    return $pg >= 100 ? ['Concluído', '#22c55e'] : ($pg <= 0 ? ['Não iniciado', '#94a3b8'] : ['Em andamento', '#3b82f6']);
};
$tl = fn (?int $pg, string $tip = '') => $pg === null ? "<span class='text-secondary small'>—</span>" : "<div class='mkt-tl' title='" . $e($tip !== '' ? $tip : $pg . '%') . "'><span style='width:{$pg}%'></span></div><span class='mkt-tl-n'>{$pg}%</span>";

// ================================================================= VISÃO EM CARTÕES
if ($view === 'cards') {
    foreach ($groups as $pillarId => $list) {
        $pillar = $pillars[$pillarId] ?? null;
        $pc = $pillar['color'] ?? '#6366f1';
        echo "<section class='mkt-pgroup' data-q-group><h3 class='mkt-pgroup-title'>" . ($pillar ? Ui::badge($pillar['name'], $pc) : "<span class='text-secondary'>Sem pilar</span>") . "<em>" . count($list) . "</em></h3><div class='mkt-pgrid'>";
        foreach ($list as $id => $p) {
            $acts = $byProject[$id] ?? [];
            $pg = Structure::progress($acts);
            $late = 0;
            $done = 0;
            foreach ($acts as $a) {
                $late += Demand::isOverdue($a, $today) ? 1 : 0;
                $done += $a['status'] === Demand::STATUS_DONE ? 1 : 0;
            }
            $ms = $milestones[$id] ?? [];
            $next = null;
            foreach ($ms as $m) {
                if (!$m['is_done']) {
                    $next = $m;
                    break;
                }
            }
            $msDone = count(array_filter($ms, fn ($m) => $m['is_done']));
            $planNames = implode(' ', array_map(fn ($pl) => $pl['name'], $plansByProject[$id] ?? []));
            $search = mb_strtolower($p['name'] . ' ' . $planNames);
            $pcolor = $pstColors[$p['status']] ?? '#94a3b8';
            echo "<a class='mkt-pcard' href='" . $e(Ui::url('/front/project.php?id=' . $id)) . "' style='--g:" . $e($pc) . "' data-q='" . $e($search) . "'>"
                . "<div class='mkt-pcard-top'><span class='mkt-pcard-status' style='--c:" . $e($pcolor) . "'>" . $e(Structure::PROJECT_STATUSES[$p['status']] ?? $p['status']) . "</span>"
                . ($p['is_archived'] ? "<span class='text-secondary small'>arquivado</span>" : '') . Ui::avatar((int) $p['users_id']) . "</div>"
                . "<div class='mkt-pcard-name'>" . $e($p['name']) . "</div>"
                . "<div class='mkt-pcard-obj'>" . $e(mb_strimwidth((string) $p['objective'], 0, 130, '…')) . "</div>"
                . "<div class='mkt-pcard-prog'>" . ($pg === null ? "<span class='text-secondary small'>sem atividades</span>" : "<div class='mkt-tl'><span style='width:{$pg}%'></span></div><b>{$pg}%</b>") . "</div>"
                . "<div class='mkt-pcard-stats'><span title='Planos de ação'><i class='ti ti-list-check'></i>" . count($plansByProject[$id] ?? []) . (count($plansByProject[$id] ?? []) === 1 ? ' plano' : ' planos') . "</span>"
                . "<span title='Atividades concluídas / total'><i class='ti ti-checkbox'></i>$done/" . count($acts) . "</span>"
                . ($late ? "<span class='late' title='Atividades com prazo vencido'><i class='ti ti-alert-triangle'></i>$late atrasadas</span>" : '') . "</div>"
                . "<div class='mkt-pcard-foot'><span><i class='ti ti-calendar'></i>" . $e(Ui::shortDate($p['start_date'])) . " → " . $e(Ui::shortDate($p['end_date'])) . "</span>"
                . ($ms ? "<span title='Marcos cumpridos'><i class='ti ti-flag'></i>$msDone/" . count($ms) . ($next ? ' · próximo ' . $e(Ui::shortDate($next['due_date'])) : '') . "</span>" : '') . "</div>"
                . "</a>";
        }
        echo "</div></section>";
    }
}

// ================================================================= VISÃO EM TABELA (com árvore)
if ($view === 'table') {
    echo "<div class='mkt-table mkt-ptable' id='mkt-ptable'>";
    foreach ($groups as $pillarId => $list) {
        $pillar = $pillars[$pillarId] ?? null;
        $pc = $pillar['color'] ?? '#6366f1';
        echo "<section class='mkt-grp' style='--g:" . $e($pc) . "' data-q-group>";
        echo "<div class='mkt-grp-head'><button type='button' class='mkt-grp-title' data-toggle aria-expanded='true'><i class='ti ti-chevron-down'></i><span>" . $e($pillar['name'] ?? 'Sem pilar') . "</span><em>" . count($list) . "</em></button>"
            . "<div class='mkt-col-names'><span>Resp.</span><span>Situação</span><span>Progresso</span><span>Prazo</span><span>Atividades</span></div></div><div class='mkt-grp-body'>";
        foreach ($list as $id => $p) {
            $acts = $byProject[$id] ?? [];
            $pg = Structure::progress($acts);
            $late = $p['end_date'] && $p['end_date'] < $today && $p['status'] !== 'done';
            $plans = $plansByProject[$id] ?? [];
            $search = mb_strtolower($p['name'] . ' ' . implode(' ', array_map(fn ($pl) => $pl['name'], $plans)) . ' ' . implode(' ', array_map(fn ($a) => $a['name'], $acts)));
            echo "<div class='mkt-node' data-q='" . $e($search) . "'>";
            echo "<div class='mkt-row mkt-lv0" . ($late ? ' late' : '') . "'>"
                . "<div class='mkt-cell-name'><span class='mkt-nm'><button type='button' class='mkt-exp' data-exp aria-expanded='false' title='Abrir planos e atividades'><i class='ti ti-chevron-right'></i></button>"
                . "<a href='" . $e(Ui::url('/front/project.php?id=' . $id)) . "'>" . $e($p['name']) . "</a></span><small>" . count($plans) . (count($plans) === 1 ? ' plano' : ' planos') . " · " . $e(Ui::date($p['start_date']) . ' a ' . Ui::date($p['end_date'])) . ($p['is_archived'] ? ' · arquivado' : '') . "</small></div>"
                . "<div>" . Ui::avatar((int) $p['users_id']) . "</div>"
                . "<div class='mkt-cell-st'>" . $chip(Structure::PROJECT_STATUSES[$p['status']] ?? $p['status'], $pstColors[$p['status']] ?? '#94a3b8') . "</div>"
                . "<div class='mkt-cell-tl'>" . $tl($pg) . "</div>"
                . "<div class='mkt-cell-dl " . ($late ? 'late' : '') . "'>" . $e(Ui::shortDate($p['end_date'])) . "</div>"
                . "<div class='mkt-cell-pr'>" . count($acts) . "</div></div>";

            // ---- ramo: marcos, planos e atividades
            echo "<div class='mkt-branch' hidden>";
            foreach ($milestones[$id] ?? [] as $m) {
                $mlate = !$m['is_done'] && $m['due_date'] < $today;
                echo "<div class='mkt-row mkt-lv1 mkt-ms-row'><div class='mkt-cell-name'><span class='mkt-nm'><i class='ti ti-flag me-1'></i>" . ($m['is_done'] ? "<s class='text-secondary'>" . $e($m['name']) . "</s>" : $e($m['name'])) . "</span><small>Marco</small></div>"
                    . "<div></div><div class='mkt-cell-st'>" . $chip($m['is_done'] ? 'Cumprido' : ($mlate ? 'Atrasado' : 'Pendente'), $m['is_done'] ? '#22c55e' : ($mlate ? '#ef4444' : '#94a3b8')) . "</div>"
                    . "<div class='mkt-cell-tl'></div><div class='mkt-cell-dl " . ($mlate ? 'late' : '') . "'>" . $e(Ui::shortDate($m['due_date'])) . "</div><div class='mkt-cell-pr'></div></div>";
            }
            foreach ($plans as $pl) {
                $pacts = $byPlan[(int) $pl['id']] ?? [];
                $ppg = Structure::progress($pacts);
                [$phase, $phaseColor] = $planPhase($ppg);
                echo "<div class='mkt-node mkt-pnode'><div class='mkt-row mkt-lv1'>"
                    . "<div class='mkt-cell-name'><span class='mkt-nm'><button type='button' class='mkt-exp' data-exp aria-expanded='false' title='Abrir atividades'><i class='ti ti-chevron-right'></i></button>"
                    . "<a href='" . $e(Ui::url('/front/plan.php?id=' . (int) $pl['id'])) . "'>" . $e($pl['name']) . "</a></span><small>Plano de ação" . ($pl['criteria'] ? ' · ' . $e(mb_strimwidth($pl['criteria'], 0, 70, '…')) : '') . "</small></div>"
                    . "<div>" . Ui::avatar((int) $pl['users_id']) . "</div><div class='mkt-cell-st'>" . $chip($phase, $phaseColor) . "</div>"
                    . "<div class='mkt-cell-tl'>" . $tl($ppg) . "</div><div class='mkt-cell-dl'>" . $e(Ui::shortDate($pl['end_date'])) . "</div><div class='mkt-cell-pr'>" . count($pacts) . "</div></div>";
                echo "<div class='mkt-branch' hidden>";
                foreach ($pacts as $a) {
                    $st = (string) $a['status'];
                    $dl = Demand::deadline($a);
                    $alate = Demand::isOverdue($a, $today);
                    $apg = Demand::PROGRESS[$st] ?? 0;
                    echo "<div class='mkt-row mkt-lv2" . ($alate ? ' late' : '') . "'>"
                        . "<div class='mkt-cell-name'><span class='mkt-nm'><a href='" . $e(Ui::url('/front/demand.php?id=' . (int) $a['id'])) . "'>" . $e($a['name']) . "</a></span><small>" . $e($a['code']) . ' · ' . ($a['origin'] === Demand::ORIGIN_INTERNAL ? 'interna' : 'externa') . "</small></div>"
                        . "<div>" . Ui::avatar((int) $a['users_id_assigned']) . "</div><div class='mkt-cell-st'>" . $chip(Demand::STATUSES[$st] ?? $st, $colors[$st] ?? '#94a3b8') . "</div>"
                        . "<div class='mkt-cell-tl'>" . $tl($apg) . "</div><div class='mkt-cell-dl " . ($alate ? 'late' : '') . "'>" . $e(Ui::shortDate($dl)) . "</div><div class='mkt-cell-pr'></div></div>";
                }
                if (!$pacts) {
                    echo "<div class='mkt-empty-branch'>Nenhuma atividade neste plano.</div>";
                }
                echo "</div></div>";
            }
            // atividades do projeto sem plano
            $loose = array_filter($acts, fn ($a) => (int) $a['plans_id'] === 0);
            foreach ($loose as $a) {
                $st = (string) $a['status'];
                $dl = Demand::deadline($a);
                $alate = Demand::isOverdue($a, $today);
                echo "<div class='mkt-row mkt-lv1" . ($alate ? ' late' : '') . "'><div class='mkt-cell-name'><span class='mkt-nm'><a href='" . $e(Ui::url('/front/demand.php?id=' . (int) $a['id'])) . "'>" . $e($a['name']) . "</a></span><small>" . $e($a['code']) . " · direto no projeto</small></div>"
                    . "<div>" . Ui::avatar((int) $a['users_id_assigned']) . "</div><div class='mkt-cell-st'>" . $chip(Demand::STATUSES[$st] ?? $st, $colors[$st] ?? '#94a3b8') . "</div>"
                    . "<div class='mkt-cell-tl'>" . $tl(Demand::PROGRESS[$st] ?? 0) . "</div><div class='mkt-cell-dl " . ($alate ? 'late' : '') . "'>" . $e(Ui::shortDate($dl)) . "</div><div class='mkt-cell-pr'></div></div>";
            }
            if (!$plans && !$loose && empty($milestones[$id])) {
                echo "<div class='mkt-empty-branch'>Este projeto ainda não tem planos nem atividades.</div>";
            }
            echo "</div></div>";
        }
        echo "</div></section>";
    }
    echo "</div>";
    echo "<div class='mkt-ptoolbar'><button type='button' class='btn btn-sm btn-outline-secondary' data-expand-all><i class='ti ti-arrows-diagonal me-1'></i>Abrir tudo</button> <button type='button' class='btn btn-sm btn-outline-secondary' data-collapse-all><i class='ti ti-arrows-diagonal-minimize-2 me-1'></i>Recolher tudo</button></div>";
}

// ---------------------------------------------------------------- novo projeto
if (Access::canInternal()) {
    $users = Ui::userOptions();
    echo "<form id='novo-projeto' method='post' action='" . $e(Ui::url('/front/action.php')) . "' class='card card-body mt-3'>" . Ui::csrf() . "<input type='hidden' name='do' value='project_save'><input type='hidden' name='id' value='0'>";
    echo "<h3 class='card-title'>Novo projeto</h3><div class='row'><div class='col-md-4 mb-3'><label class='form-label'>Nome *</label><input class='form-control' name='name' required></div>";
    echo "<div class='col-md-3 mb-3'><label class='form-label'>Pilar / frente</label>" . Ui::select('pillars_id', Structure::pillarOptions(), '', '', true, 'Sem pilar') . "</div>";
    echo "<div class='col-md-3 mb-3'><label class='form-label'>Responsável</label>" . Ui::select('users_id', $users, $me, '', true, 'Ninguém') . "</div>";
    echo "<div class='col-md-2 mb-3'><label class='form-label'>Situação</label>" . Ui::select('status', Structure::PROJECT_STATUSES, 'planning') . "</div></div>";
    echo "<div class='row'><div class='col-md-2 mb-3'><label class='form-label'>Início</label><input class='form-control' type='date' name='start_date'></div>";
    echo "<div class='col-md-2 mb-3'><label class='form-label'>Fim previsto</label><input class='form-control' type='date' name='end_date'></div>";
    echo "<div class='col-md-8 mb-3'><label class='form-label'>Objetivo</label><input class='form-control' name='objective'></div></div>";
    echo "<div><button class='btn btn-primary'>Criar projeto</button></div></form>";
}
echo Ui::script('projetos.js');
Html::footer();

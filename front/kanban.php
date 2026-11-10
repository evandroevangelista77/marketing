<?php

/**
 * Quadro de demandas com duas visões, escolhidas nas abas do topo (a escolha fica salva por usuário):
 *  - Kanban: uma coluna por situação, cartões arrastáveis (mesmas regras da ficha).
 *  - Tabela principal (estilo monday): grupos por mês do prazo, com Resp., Status (editável), Timeline, Prazo e Prioridade.
 * Filtros por responsável e tipo, "Só minhas" e "Mostrar canceladas" valem nas duas.
 */

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Demand;
use GlpiPlugin\Marketing\Settings;
use GlpiPlugin\Marketing\Type;
use GlpiPlugin\Marketing\Ui;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
global $DB;

$e = fn ($v) => Ui::e($v);
$me = (int) Session::getLoginUserID();
$view = (string) ($_GET['view'] ?? '');
if (in_array($view, ['board', 'table'], true)) {
    if (Settings::userPref($me, 'kanban_view') !== $view) {
        Settings::setUserPref($me, 'kanban_view', $view);
    }
} else {
    $view = Settings::userPref($me, 'kanban_view', 'board') === 'table' ? 'table' : 'board';
}
$mine = !empty($_GET['mine']);
$assignee = $mine ? $me : max(0, (int) ($_GET['assignee'] ?? 0));
$typeId = max(0, (int) ($_GET['type'] ?? 0));
$showCancelled = !empty($_GET['cancelled']);
$showDone = !empty($_GET['done']);
$colors = Settings::statusColors();
$types = Type::all();
$canMove = Access::canUpdate();
$today = date('Y-m-d');

$where = ['is_deleted' => 0] + getEntitiesRestrictCriteria(Demand::getTable(), 'entities_id', '', true);
if ($assignee > 0) {
    $where['users_id_assigned'] = $assignee;
}
if ($typeId > 0) {
    $where['types_id'] = $typeId;
}
$all = iterator_to_array($DB->request(['FROM' => Demand::getTable(), 'WHERE' => $where, 'ORDER' => ['priority ASC', 'id DESC']]), false);

Ui::header($view === 'table' ? 'Tabela principal' : 'Quadro Kanban', 'kanban');
echo Ui::title(
    'Quadro de demandas',
    $view === 'table'
        ? 'Visão em tabela, agrupada pelo mês do prazo. Clique na situação para mudá-la; clique no nome do grupo para recolher.'
        : ($canMove ? 'Arraste um cartão para outra coluna para mudar a situação. As regras são as mesmas da ficha da demanda.' : 'Visão por situação. Seu perfil não permite mudar a situação.'),
    Access::canCreate() ? Ui::button('Nova demanda', Ui::url('/front/demand.php?id=0')) : ''
);

// ---------------------------------------------------------------- abas das visões
$keep = array_filter(['assignee' => $assignee && !$mine ? $assignee : '', 'type' => $typeId ?: '', 'mine' => $mine ? 1 : '', 'cancelled' => $showCancelled ? 1 : '', 'done' => $showDone ? 1 : '']);
$tab = fn (string $v, string $label, string $icon) => "<a class='" . ($view === $v ? 'on' : '') . "' href='" . $e(Ui::url('/front/kanban.php?' . http_build_query($keep + ['view' => $v]))) . "'><i class='ti $icon me-1'></i>" . $e($label) . "</a>";
echo "<nav class='mkt-viewtabs'>" . $tab('table', 'Tabela principal', 'ti-table') . $tab('board', 'Kanban', 'ti-layout-kanban') . "</nav>";

echo "<form method='get' class='card card-body mb-3'><input type='hidden' name='view' value='" . $e($view) . "'><div class='mkt-filters'>";
echo "<div><label>Responsável</label>" . Ui::select('assignee', Ui::userOptions(), $mine ? '' : ($assignee ?: ''), '', true) . "</div>";
echo "<div><label>Tipo</label>" . Ui::select('type', array_map(fn ($t) => $t['name'], $types), $typeId ?: '', '', true) . "</div>";
echo "<div class='form-check mb-2'><input class='form-check-input' type='checkbox' name='mine' value='1' id='mine'" . ($mine ? ' checked' : '') . "><label class='form-check-label' for='mine'>Só minhas</label></div>";
if ($view === 'table') {
    echo "<div class='form-check mb-2'><input class='form-check-input' type='checkbox' name='done' value='1' id='done'" . ($showDone ? ' checked' : '') . "><label class='form-check-label' for='done'>Mostrar concluídas</label></div>";
}
echo "<div class='form-check mb-2'><input class='form-check-input' type='checkbox' name='cancelled' value='1' id='canc'" . ($showCancelled ? ' checked' : '') . "><label class='form-check-label' for='canc'>Mostrar canceladas</label></div>";
echo "<div class='mkt-filter-actions'><button class='btn btn-primary'><i class='ti ti-filter me-1'></i>Filtrar</button></div></div></form>";

// ================================================================== visão Kanban
if ($view === 'board') {
    $byStatus = array_fill_keys(array_keys(Demand::STATUSES), []);
    foreach ($all as $r) {
        $byStatus[$r['status']][] = $r;
    }
    echo "<div class='mkt-board' data-endpoint='" . $e(Ui::url('/front/ajax.php')) . "' data-drag='" . ($canMove ? '1' : '0') . "'>";
    foreach (Demand::STATUSES as $key => $label) {
        if ($key === Demand::STATUS_CANCELLED && !$showCancelled) {
            continue;
        }
        $c = $colors[$key];
        echo "<section class='mkt-col' data-status='" . $e($key) . "' style='--c:" . $e($c) . "'><div class='mkt-col-head'><span>" . $e($label) . "</span><span class='n'>" . count($byStatus[$key]) . "</span></div><div class='mkt-col-body'>";
        foreach ($byStatus[$key] as $r) {
            $late = Demand::isOverdue($r, $today);
            $deadline = Demand::deadline($r);
            $typeColor = (int) $r['types_id'] > 0 ? ($types[(int) $r['types_id']]['color'] ?? $c) : $c;
            echo "<article class='mkt-card" . ($late ? ' late' : '') . "' draggable='" . ($canMove ? 'true' : 'false') . "' data-id='" . (int) $r['id'] . "' style='--c:" . $e($typeColor) . "'>"
                . "<div class='mkt-code'>" . $e($r['code']) . ((int) $r['types_id'] > 0 ? ' · ' . $e($types[(int) $r['types_id']]['name'] ?? '') : '') . "</div>"
                . "<a class='name' href='" . $e(Ui::url('/front/demand.php?id=' . (int) $r['id'])) . "'>" . $e($r['name']) . "</a>"
                . "<div class='meta'><span><i class='ti ti-user'></i> " . $e((int) $r['users_id_assigned'] > 0 ? getUserName((int) $r['users_id_assigned']) : 'sem responsável') . "</span>"
                . ($deadline ? "<span class='" . ($late ? 'text-danger fw-bold' : '') . "'><i class='ti ti-calendar'></i> " . $e(Ui::date($deadline)) . "</span>" : '')
                . "<span>" . $e(Demand::PRIORITIES[(int) $r['priority']] ?? '') . "</span></div></article>";
        }
        echo "</div></section>";
    }
    echo "</div>";
    echo Ui::script('kanban.js');
    Html::footer();
    return;
}

// ================================================================== visão Tabela principal
$months = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$thisMonth = date('Y-m');
$nextMonth = date('Y-m', strtotime('first day of next month'));
$groups = [];
foreach ($all as $r) {
    if ($r['status'] === Demand::STATUS_DONE && !$showDone) {
        continue;
    }
    if ($r['status'] === Demand::STATUS_CANCELLED && !$showCancelled) {
        continue;
    }
    $dl = Demand::deadline($r);
    $key = $dl === null ? 'zz-none' : (substr($dl, 0, 7) < $thisMonth ? '0-past' : substr($dl, 0, 7));
    $groups[$key][] = $r;
}
ksort($groups);
foreach ($groups as &$list) {
    usort($list, fn ($a, $b) => [Demand::deadline($a) ?? '9999-99-99', (int) $a['priority'], (int) $a['id']] <=> [Demand::deadline($b) ?? '9999-99-99', (int) $b['priority'], (int) $b['id']]);
}
unset($list);

$palette = ['#14b8a6', '#f97316', '#ec4899', '#84cc16', '#0ea5e9', '#eab308'];
$pi = 0;
$total = array_sum(array_map('count', $groups));
echo "<div class='mkt-table' data-endpoint='" . $e(Ui::url('/front/ajax.php')) . "'>";
if (!$groups) {
    echo "<div class='card card-body text-center text-secondary'>Nenhuma demanda neste filtro.</div>";
}
foreach ($groups as $key => $list) {
    if ($key === '0-past') {
        [$title, $color] = ['Meses anteriores', '#ef4444'];
    } elseif ($key === 'zz-none') {
        [$title, $color] = ['Sem prazo', '#94a3b8'];
    } elseif ($key === $thisMonth) {
        [$title, $color] = ['Este mês', '#3b82f6'];
    } elseif ($key === $nextMonth) {
        [$title, $color] = ['Próximo mês', '#a855f7'];
    } else {
        $title = ucfirst($months[(int) substr($key, 5, 2)]) . ' de ' . substr($key, 0, 4);
        $color = $palette[$pi++ % count($palette)];
    }
    echo "<section class='mkt-grp' style='--g:" . $e($color) . "'>";
    echo "<div class='mkt-grp-head'><button type='button' class='mkt-grp-title' data-toggle aria-expanded='true'><i class='ti ti-chevron-down'></i><span>" . $e($title) . "</span><em>" . count($list) . "</em></button>"
        . "<div class='mkt-col-names'><span>Resp.</span><span>Status</span><span>Timeline</span><span>Prazo</span><span>Prioridade</span></div></div>";
    echo "<div class='mkt-grp-body'>";
    foreach ($list as $r) {
        $st = (string) $r['status'];
        $stColor = $colors[$st] ?? '#94a3b8';
        $dl = Demand::deadline($r);
        $late = Demand::isOverdue($r, $today);
        $progress = Demand::PROGRESS[$st] ?? 0;
        $next = $canMove ? (Demand::TRANSITIONS[$st] ?? []) : [];
        if ($next) {
            $opts = "<option value='" . $e($st) . "' data-color='" . $e($stColor) . "' selected>" . $e(Demand::STATUSES[$st]) . "</option>";
            foreach ($next as $to) {
                $opts .= "<option value='" . $e($to) . "' data-color='" . $e($colors[$to]) . "'>" . $e(Demand::STATUSES[$to]) . "</option>";
            }
            $status = "<select class='mkt-st' data-id='" . (int) $r['id'] . "' style='--c:" . $e($stColor) . "' aria-label='Situação'>$opts</select>";
        } else {
            $status = "<span class='mkt-st ro' style='--c:" . $e($stColor) . "'>" . $e(Demand::STATUSES[$st] ?? $st) . "</span>";
        }
        $tip = 'Entrada ' . Ui::date($r['sent_at'] ?: $r['date_creation']) . ' → prazo ' . ($dl ? Ui::date($dl) : 'sem prazo') . ' · progresso ' . $progress . '%';
        $stars = 6 - max(1, min(5, (int) $r['priority']));
        echo "<div class='mkt-row" . ($late ? ' late' : '') . "' data-id='" . (int) $r['id'] . "'>"
            . "<div class='mkt-cell-name'><a href='" . $e(Ui::url('/front/demand.php?id=' . (int) $r['id'])) . "'>" . $e($r['name']) . "</a><small>" . $e($r['code']) . ((int) $r['types_id'] > 0 ? ' · ' . $e($types[(int) $r['types_id']]['name'] ?? '') : '') . "</small></div>"
            . "<div class='mkt-cell-resp'>" . Ui::avatar((int) $r['users_id_assigned']) . "</div>"
            . "<div class='mkt-cell-st'>$status</div>"
            . "<div class='mkt-cell-tl'><div class='mkt-tl' title='" . $e($tip) . "'><span style='width:{$progress}%'></span></div></div>"
            . "<div class='mkt-cell-dl " . ($late ? 'late' : '') . "'>" . $e(Ui::shortDate($dl)) . "</div>"
            . "<div class='mkt-cell-pr' title='" . $e('Prioridade: ' . (Demand::PRIORITIES[(int) $r['priority']] ?? '')) . "'><span class='on'>" . str_repeat('★', $stars) . "</span><span>" . str_repeat('★', 5 - $stars) . "</span></div>"
            . "</div>";
    }
    echo "</div></section>";
}
echo "</div>";
if ($groups) {
    echo "<div class='text-secondary small mt-2'>$total demanda(s)" . (!$showDone ? ' em aberto — marque “Mostrar concluídas” para incluir as concluídas.' : '.') . "</div>";
}
echo Ui::script('tabela.js');
Html::footer();

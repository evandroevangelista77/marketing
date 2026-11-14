<?php

/** Lista de demandas com busca e filtros (situação, tipo, responsável, só minhas) e paginação (RF-009, RNF-05). */

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Demand;
use GlpiPlugin\Marketing\Settings;
use GlpiPlugin\Marketing\Structure;
use GlpiPlugin\Marketing\Type;
use GlpiPlugin\Marketing\Ui;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
global $DB;

$e = fn ($v) => Ui::e($v);
$status = isset(Demand::STATUSES[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$typeId = max(0, (int) ($_GET['type'] ?? 0));
$me = (int) Session::getLoginUserID();
$mine = !empty($_GET['mine']);
$assignee = $mine ? $me : max(0, (int) ($_GET['assignee'] ?? 0));
$origin = isset(Demand::ORIGINS[$_GET['origin'] ?? '']) ? $_GET['origin'] : '';
$pillarId = max(0, (int) ($_GET['pillar'] ?? 0));
$projectId = max(0, (int) ($_GET['project'] ?? 0));
$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$where = ['is_deleted' => 0] + getEntitiesRestrictCriteria(Demand::getTable(), 'entities_id', '', true);
if ($status !== '') {
    $where['status'] = $status;
}
if ($typeId > 0) {
    $where['types_id'] = $typeId;
}
if ($assignee > 0) {
    $where['users_id_assigned'] = $assignee;
}
if ($origin !== '') {
    $where['origin'] = $origin;
}
if ($pillarId > 0) {
    $where['pillars_id'] = $pillarId;
}
if ($projectId > 0) {
    $where['mkt_projects_id'] = $projectId;
}
if (!empty($_GET['postponed'])) {
    $where['is_postponed'] = 1;
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = ['OR' => [['name' => ['LIKE', $like]], ['code' => ['LIKE', $like]], ['objective' => ['LIKE', $like]]]];
}
$where = Demand::restrict($where);
$total = countElementsInTable(Demand::getTable(), $where);
$rows = iterator_to_array($DB->request([
    'FROM'   => Demand::getTable(),
    'WHERE'  => $where,
    'ORDER'  => 'id DESC',
    'LIMIT'  => $perPage,
    'START'  => ($page - 1) * $perPage,
]), false);
$types = Type::all();
$colors = Settings::statusColors();
$today = date('Y-m-d');

Ui::header('Demandas de Marketing', 'demands');
$buttons = (Access::canInternal() ? Ui::button('Nova atividade do Marketing', Ui::url('/front/activity.php'), 'ti-building', 'btn-outline-primary') : '')
    . (Access::canCreate() ? Ui::button('Nova solicitação', Ui::url('/front/demand.php?id=0')) : '');
echo Ui::title('Demandas e atividades', 'Solicitações de outras áreas e atividades do próprio Marketing, visíveis para o seu perfil e entidade.', $buttons);
$pillars = Structure::pillars();
$projectsAll = Structure::projects();

echo "<form method='get' class='card card-body mb-3'><div class='mkt-filters'>";
echo "<div style='min-width:220px'><label>Buscar</label><input class='form-control' type='search' name='q' value='" . $e($q) . "' placeholder='Código, título ou objetivo'></div>";
echo "<div><label>Origem</label>" . Ui::select('origin', Demand::ORIGINS, $origin, '', true) . "</div>";
echo "<div><label>Pilar</label>" . Ui::select('pillar', Structure::pillarOptions(false), $pillarId ?: '', '', true) . "</div>";
echo "<div><label>Projeto</label>" . Ui::select('project', Structure::projectOptions(false), $projectId ?: '', '', true) . "</div>";
echo "<div><label>Situação</label>" . Ui::select('status', Demand::STATUSES, $status, '', true) . "</div>";
echo "<div><label>Tipo</label>" . Ui::select('type', array_map(fn ($t) => $t['name'], $types), $typeId ?: '', '', true) . "</div>";
echo "<div><label>Responsável</label>" . Ui::select('assignee', Ui::userOptions(), $mine ? '' : ($assignee ?: ''), '', true) . "</div>";
echo "<div class='form-check mb-2'><input class='form-check-input' type='checkbox' name='mine' value='1' id='mine'" . ($mine ? ' checked' : '') . "><label class='form-check-label' for='mine'>Só minhas</label></div>";
echo "<div class='form-check mb-2'><input class='form-check-input' type='checkbox' name='postponed' value='1' id='postponed'" . (!empty($_GET['postponed']) ? ' checked' : '') . "><label class='form-check-label' for='postponed'>Só adiadas</label></div>";
echo "<div><button class='btn btn-primary'><i class='ti ti-search me-1'></i>Filtrar</button></div></div></form>";

echo "<div class='card'><div class='card-header'><h3 class='card-title'>$total demanda(s)</h3></div>";
echo "<div class='table-responsive'><table class='table table-vcenter card-table table-hover'><thead><tr>";
echo "<th>Código</th><th>Título</th><th>Origem</th><th>Pilar · Projeto</th><th>Tipo</th><th>Situação</th><th>Responsável</th><th>Prazo desejado</th><th>Prazo confirmado</th><th>Prioridade</th></tr></thead><tbody>";
if (!$rows) {
    echo "<tr><td colspan='10' class='text-center text-secondary'>Nenhuma demanda.</td></tr>";
}
foreach ($rows as $r) {
    $url = Ui::url('/front/demand.php?id=' . (int) $r['id']);
    $late = Demand::isOverdue($r, $today) ? " <span class='text-danger small fw-bold'>atrasada</span>" : '';
    echo "<tr><td><a href='" . $e($url) . "'>" . $e($r['code']) . "</a></td>"
        . "<td>" . $e($r['name']) . $late . Ui::postponedBadge($r) . "</td>"
        . "<td>" . Ui::originBadge((string) $r['origin']) . "</td>"
        . "<td class='small'>" . ((int) $r['pillars_id'] > 0 && isset($pillars[(int) $r['pillars_id']]) ? Ui::badge($pillars[(int) $r['pillars_id']]['name'], $pillars[(int) $r['pillars_id']]['color']) : '') . ((int) $r['mkt_projects_id'] > 0 && isset($projectsAll[(int) $r['mkt_projects_id']]) ? ' ' . $e($projectsAll[(int) $r['mkt_projects_id']]['name']) : ((int) $r['pillars_id'] > 0 ? '' : '—')) . "</td>"
        . "<td>" . ((int) $r['types_id'] > 0 ? Ui::badge($types[(int) $r['types_id']]['name'] ?? '—', $types[(int) $r['types_id']]['color'] ?? '#94a3b8') : '—') . "</td>"
        . "<td>" . Ui::badge(Demand::STATUSES[$r['status']] ?? $r['status'], $colors[$r['status']] ?? '#94a3b8') . "</td>"
        . "<td>" . $e((int) $r['users_id_assigned'] > 0 ? getUserName((int) $r['users_id_assigned']) : '—') . "</td>"
        . "<td>" . $e(Ui::date($r['desired_date'])) . "</td><td>" . $e(Ui::date($r['confirmed_date'])) . "</td>"
        . "<td>" . $e(Demand::PRIORITIES[(int) $r['priority']] ?? '') . "</td></tr>";
}
echo "</tbody></table></div>";

$pages = max(1, (int) ceil($total / $perPage));
if ($pages > 1) {
    $qs = http_build_query(array_filter(['q' => $q, 'status' => $status, 'type' => $typeId ?: '', 'origin' => $origin, 'pillar' => $pillarId ?: '', 'project' => $projectId ?: '', 'assignee' => $mine ? '' : ($assignee ?: ''), 'mine' => $mine ? 1 : '', 'postponed' => !empty($_GET['postponed']) ? 1 : '']));
    echo "<div class='card-footer d-flex gap-2 flex-wrap'>";
    for ($i = 1; $i <= $pages; $i++) {
        echo "<a class='btn btn-sm " . ($i === $page ? 'btn-primary' : 'btn-outline-secondary') . "' href='?page=$i" . ($qs ? '&' . $e($qs) : '') . "'>$i</a>";
    }
    echo "</div>";
}
echo "</div>";
Html::footer();

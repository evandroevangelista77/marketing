<?php

/** Ficha do projeto: objetivo, marcos, planos de ação e atividades, com progresso consolidado. */

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

$id = (int) ($_GET['id'] ?? 0);
$project = Structure::projects()[$id] ?? null;
if (!$project) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
$e = fn ($v) => Ui::e($v);
$can = Access::canInternal();
$users = Ui::userOptions();
$pillars = Structure::pillars();
$pillar = $pillars[(int) $project['pillars_id']] ?? null;
$acts = Structure::activities(['mkt_projects_id' => $id] + getEntitiesRestrictCriteria(Demand::getTable(), 'entities_id', '', true));
$plans = Structure::plans($id);
$acts = Structure::withoutSources($acts);
$colors = Settings::statusColors();
$action = Ui::url('/front/action.php');
$open = fn (string $do, int $rid = 0, string $cls = '') => "<form method='post' action='" . $e($action) . "' class='$cls'>" . Ui::csrf() . "<input type='hidden' name='do' value='$do'><input type='hidden' name='id' value='$rid'>";

Ui::header($project['name'], 'projects');
echo Ui::title($project['name'], 'Projeto' . ($pillar ? ' do pilar ' . $pillar['name'] : ' sem pilar'), Ui::button('Projetos e planos', Ui::url('/front/projects.php'), 'ti-arrow-left', 'btn-outline-secondary'));

echo "<div class='card card-body mb-3'><div class='d-flex flex-wrap gap-4 align-items-center'><div><div class='text-secondary small'>Progresso</div>" . Ui::bar(Structure::progress($acts), $pillar['color'] ?? '#6366f1') . "</div>"
    . "<div><div class='text-secondary small'>Responsável</div>" . Ui::avatar((int) $project['users_id']) . "</div>"
    . "<div><div class='text-secondary small'>Período</div>" . $e(Ui::date($project['start_date']) . ' a ' . Ui::date($project['end_date'])) . "</div>"
    . "<div><div class='text-secondary small'>Situação</div>" . $e(Structure::PROJECT_STATUSES[$project['status']] ?? '') . ($project['is_archived'] ? ' (arquivado)' : '') . "</div>"
    . "<div><div class='text-secondary small'>Atividades</div>" . count($acts) . "</div></div>"
    . ($project['objective'] ? "<div class='mt-3'><div class='text-secondary small'>Objetivo</div>" . nl2br($e($project['objective'])) . "</div>" : '') . "</div>";

if ($can) {
    echo "<details class='card card-body mb-3'><summary class='fw-bold'>Editar projeto</summary>" . $open('project_save', $id, 'mt-3');
    echo "<div class='row'><div class='col-md-4 mb-3'><label class='form-label'>Nome *</label><input class='form-control' name='name' value='" . $e($project['name']) . "' required></div>";
    echo "<div class='col-md-3 mb-3'><label class='form-label'>Pilar</label>" . Ui::select('pillars_id', Structure::pillarOptions(false), $project['pillars_id'], '', true, 'Sem pilar') . "</div>";
    echo "<div class='col-md-3 mb-3'><label class='form-label'>Responsável</label>" . Ui::select('users_id', $users, $project['users_id'], '', true, 'Ninguém') . "</div>";
    echo "<div class='col-md-2 mb-3'><label class='form-label'>Situação</label>" . Ui::select('status', Structure::PROJECT_STATUSES, $project['status']) . "</div></div>";
    echo "<div class='row'><div class='col-md-2 mb-3'><label class='form-label'>Início</label><input class='form-control' type='date' name='start_date' value='" . $e($project['start_date']) . "'></div>";
    echo "<div class='col-md-2 mb-3'><label class='form-label'>Fim previsto</label><input class='form-control' type='date' name='end_date' value='" . $e($project['end_date']) . "'></div>";
    echo "<div class='col-md-8 mb-3'><label class='form-label'>Objetivo</label><input class='form-control' name='objective' value='" . $e($project['objective']) . "'></div></div>";
    echo "<label class='form-check'><input class='form-check-input' type='checkbox' name='is_archived' value='1'" . ($project['is_archived'] ? ' checked' : '') . "><span class='form-check-label'>Arquivado</span></label>";
    echo "<div class='mt-2'><button class='btn btn-primary'>Salvar projeto</button></div></form></details>";
}

// marcos
echo "<div class='card card-body mb-3'><h4>Marcos</h4>";
$ms = Structure::milestones($id);
if (!$ms) {
    echo "<p class='text-secondary mb-2'>Nenhum marco.</p>";
}
foreach ($ms as $m) {
    $late = !$m['is_done'] && $m['due_date'] && $m['due_date'] < date('Y-m-d');
    echo "<div class='mkt-ms" . ($m['is_done'] ? ' done' : '') . "'>" . ($can ? $open('milestone_done', $id, 'd-inline') . "<input type='hidden' name='milestone' value='" . (int) $m['id'] . "'><input type='hidden' name='done' value='" . ($m['is_done'] ? 0 : 1) . "'><button class='btn btn-sm " . ($m['is_done'] ? 'btn-success' : 'btn-outline-secondary') . "' title='Marcar como " . ($m['is_done'] ? 'pendente' : 'cumprido') . "'><i class='ti ti-check'></i></button></form>" : '')
        . "<span class='n'>" . $e($m['name']) . "</span><span class='text-secondary small'>" . $e(Ui::date($m['due_date'])) . "</span>" . ($late ? " <span class='text-danger small fw-bold'>atrasado</span>" : '') . "</div>";
}
if ($can) {
    echo $open('milestone_add', $id, 'row g-2 mt-1') . "<div class='col-md-6'><input class='form-control' name='name' placeholder='Novo marco' required></div><div class='col-md-3'><input class='form-control' type='date' name='due_date' required></div><div class='col-md-3'><button class='btn btn-outline-primary w-100'>Adicionar marco</button></div></form>";
}
echo "</div>";

// planos
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Planos de ação</h3></div><div class='table-responsive'><table class='table card-table'><thead><tr><th>Plano</th><th>Responsável</th><th>Período</th><th>Origem</th><th>Atividades</th><th>Progresso</th></tr></thead><tbody>";
if (!$plans) {
    echo "<tr><td colspan='6' class='text-center text-secondary'>Nenhum plano neste projeto.</td></tr>";
}
foreach ($plans as $pid => $pl) {
    $pa = array_filter($acts, fn ($a) => (int) $a['plans_id'] === $pid);
    $src = (int) $pl['source_demands_id'] > 0 ? "<a href='" . $e(Ui::url('/front/demand.php?id=' . (int) $pl['source_demands_id'])) . "'>demanda original</a>" : 'cadastro interno';
    echo "<tr><td><a href='" . $e(Ui::url('/front/plan.php?id=' . $pid)) . "'>" . $e($pl['name']) . "</a></td><td>" . Ui::avatar((int) $pl['users_id']) . "</td>"
        . "<td class='small'>" . $e(Ui::date($pl['start_date']) . ' a ' . Ui::date($pl['end_date'])) . "</td><td class='small'>$src</td><td>" . count($pa) . "</td><td>" . Ui::bar(Structure::progress($pa)) . "</td></tr>";
}
echo "</tbody></table></div></div>";
if ($can) {
    echo $open('plan_save', 0, 'card card-body mb-3') . "<input type='hidden' name='mkt_projects_id' value='$id'><h4>Novo plano de ação</h4><div class='row'>"
        . "<div class='col-md-4 mb-3'><label class='form-label'>Nome *</label><input class='form-control' name='name' required></div>"
        . "<div class='col-md-3 mb-3'><label class='form-label'>Responsável</label>" . Ui::select('users_id', $users, $project['users_id'], '', true, 'Ninguém') . "</div>"
        . "<div class='col-md-2 mb-3'><label class='form-label'>Início</label><input class='form-control' type='date' name='start_date'></div>"
        . "<div class='col-md-3 mb-3'><label class='form-label'>Fim previsto</label><input class='form-control' type='date' name='end_date'></div></div>"
        . "<div class='mb-3'><label class='form-label'>Objetivo</label><input class='form-control' name='objective'></div><div><button class='btn btn-primary'>Criar plano</button></div></form>";
}

// atividades
echo "<div class='card'><div class='card-header'><h3 class='card-title'>Atividades do projeto</h3>" . ($can ? "<div class='ms-auto'>" . Ui::button('Nova atividade', Ui::url('/front/activity.php?project=' . $id)) . "</div>" : '') . "</div><div class='table-responsive'><table class='table card-table'><thead><tr><th>Código</th><th>Atividade</th><th>Origem</th><th>Plano</th><th>Situação</th><th>Resp.</th><th>Prazo</th></tr></thead><tbody>";
if (!$acts) {
    echo "<tr><td colspan='7' class='text-center text-secondary'>Nenhuma atividade vinculada.</td></tr>";
}
foreach ($acts as $a) {
    echo "<tr><td><a href='" . $e(Ui::url('/front/demand.php?id=' . (int) $a['id'])) . "'>" . $e($a['code']) . "</a></td><td>" . $e($a['name']) . (Demand::isOverdue($a) ? " <span class='text-danger small fw-bold'>atrasada</span>" : '') . "</td>"
        . "<td>" . Ui::originBadge((string) $a['origin']) . "</td><td class='small'>" . $e((int) $a['plans_id'] > 0 ? (Structure::plans()[(int) $a['plans_id']]['name'] ?? '—') : '—') . "</td>"
        . "<td>" . Ui::badge(Demand::STATUSES[$a['status']] ?? $a['status'], $colors[$a['status']] ?? '#94a3b8') . "</td><td>" . Ui::avatar((int) $a['users_id_assigned']) . "</td><td>" . $e(Ui::date(Demand::deadline($a))) . "</td></tr>";
}
echo "</tbody></table></div></div>";
Html::footer();

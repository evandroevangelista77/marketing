<?php

/** Ficha do plano de ação: objetivo, critérios de conclusão, origem (demanda original) e atividades com progresso. */

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
$plan = Structure::plans()[$id] ?? null;
if (!$plan) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
$e = fn ($v) => Ui::e($v);
$can = Access::canInternal();
$users = Ui::userOptions();
$project = Structure::projects()[(int) $plan['mkt_projects_id']] ?? null;
$acts = Structure::activities(['plans_id' => $id] + getEntitiesRestrictCriteria(Demand::getTable(), 'entities_id', '', true));
$acts = Structure::withoutSources($acts);
$colors = Settings::statusColors();
$source = null;
if ((int) $plan['source_demands_id'] > 0) {
    $d = new Demand();
    $source = $d->getFromDB((int) $plan['source_demands_id']) ? $d->fields : null;
}
$action = Ui::url('/front/action.php');
$open = fn (string $do, int $rid = 0, string $cls = '') => "<form method='post' action='" . $e($action) . "' class='$cls'>" . Ui::csrf() . "<input type='hidden' name='do' value='$do'><input type='hidden' name='id' value='$rid'>";

Ui::header($plan['name'], 'projects');
echo Ui::title($plan['name'], 'Plano de ação' . ($project ? ' do projeto ' . $project['name'] : ' sem projeto'),
    Ui::button($project ? 'Projeto' : 'Projetos e planos', Ui::url($project ? '/front/project.php?id=' . (int) $project['id'] : '/front/projects.php'), 'ti-arrow-left', 'btn-outline-secondary'));

echo "<div class='card card-body mb-3'><div class='d-flex flex-wrap gap-4 align-items-center'><div><div class='text-secondary small'>Progresso</div>" . Ui::bar(Structure::progress($acts)) . "</div>"
    . "<div><div class='text-secondary small'>Responsável</div>" . Ui::avatar((int) $plan['users_id']) . "</div>"
    . "<div><div class='text-secondary small'>Período</div>" . $e(Ui::date($plan['start_date']) . ' a ' . Ui::date($plan['end_date'])) . "</div>"
    . "<div><div class='text-secondary small'>Origem</div>" . ($source ? "<a href='" . $e(Ui::url('/front/demand.php?id=' . (int) $source['id'])) . "'>" . $e($source['code'] . ' · ' . $source['name']) . "</a>" : 'Cadastro interno') . "</div></div>"
    . ($plan['objective'] ? "<div class='mt-3'><div class='text-secondary small'>Objetivo</div>" . nl2br($e($plan['objective'])) . "</div>" : '')
    . ($plan['criteria'] ? "<div class='mt-3'><div class='text-secondary small'>Critérios de conclusão</div>" . nl2br($e($plan['criteria'])) . "</div>" : '') . "</div>";

if ($can) {
    echo "<details class='card card-body mb-3'><summary class='fw-bold'>Editar plano</summary>" . $open('plan_save', $id, 'mt-3');
    echo "<div class='row'><div class='col-md-4 mb-3'><label class='form-label'>Nome *</label><input class='form-control' name='name' value='" . $e($plan['name']) . "' required></div>";
    echo "<div class='col-md-3 mb-3'><label class='form-label'>Projeto</label>" . Ui::select('mkt_projects_id', Structure::projectOptions(false), $plan['mkt_projects_id'], '', true, 'Sem projeto') . "</div>";
    echo "<div class='col-md-3 mb-3'><label class='form-label'>Responsável principal</label>" . Ui::select('users_id', $users, $plan['users_id'], '', true, 'Ninguém') . "</div></div>";
    echo "<div class='row'><div class='col-md-2 mb-3'><label class='form-label'>Início</label><input class='form-control' type='date' name='start_date' value='" . $e($plan['start_date']) . "'></div>";
    echo "<div class='col-md-2 mb-3'><label class='form-label'>Fim previsto</label><input class='form-control' type='date' name='end_date' value='" . $e($plan['end_date']) . "'></div>";
    echo "<div class='col-md-8 mb-3'><label class='form-label'>Objetivo</label><input class='form-control' name='objective' value='" . $e($plan['objective']) . "'></div></div>";
    echo "<div class='mb-3'><label class='form-label'>Critérios de conclusão</label><textarea class='form-control' name='criteria' rows='2'>" . $e($plan['criteria']) . "</textarea></div>";
    echo "<label class='form-check'><input class='form-check-input' type='checkbox' name='is_archived' value='1'" . ($plan['is_archived'] ? ' checked' : '') . "><span class='form-check-label'>Arquivado</span></label>";
    echo "<div class='mt-2'><button class='btn btn-primary'>Salvar plano</button></div></form></details>";
}

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Atividades</h3></div><div class='table-responsive'><table class='table card-table'><thead><tr><th>Código</th><th>Atividade</th><th>Situação</th><th>Resp.</th><th>Início</th><th>Prazo</th><th>Depende de</th></tr></thead><tbody>";
if (!$acts) {
    echo "<tr><td colspan='7' class='text-center text-secondary'>Nenhuma atividade no plano.</td></tr>";
}
foreach ($acts as $a) {
    $dep = (int) $a['depends_on_id'] > 0 ? ($acts[(int) $a['depends_on_id']]['code'] ?? '#' . (int) $a['depends_on_id']) : '—';
    echo "<tr><td><a href='" . $e(Ui::url('/front/demand.php?id=' . (int) $a['id'])) . "'>" . $e($a['code']) . "</a></td><td>" . $e($a['name']) . (Demand::isOverdue($a) ? " <span class='text-danger small fw-bold'>atrasada</span>" : '') . "</td>"
        . "<td>" . Ui::badge(Demand::STATUSES[$a['status']] ?? $a['status'], $colors[$a['status']] ?? '#94a3b8') . "</td><td>" . Ui::avatar((int) $a['users_id_assigned']) . "</td>"
        . "<td>" . $e(Ui::date($a['start_date'])) . "</td><td>" . $e(Ui::date(Demand::deadline($a))) . "</td><td class='small'>" . $e($dep) . "</td></tr>";
}
echo "</tbody></table></div></div>";

if ($can) {
    // com demanda original, a atividade entra como subtarefa dela (a conclusão da mãe espera as atividades); sem ela, é atividade avulsa do plano
    $isSub = $source !== null;
    echo $open($isSub ? 'subtask_create' : 'activity_create', $isSub ? (int) $source['id'] : 0, 'card card-body mb-3');
    if (!$isSub) {
        echo "<input type='hidden' name='plans_id' value='$id'><input type='hidden' name='mkt_projects_id' value='" . (int) $plan['mkt_projects_id'] . "'>";
    }
    echo "<h4>Nova atividade do plano</h4><div class='row'><div class='col-md-5 mb-3'><label class='form-label'>Título *</label><input class='form-control' name='name' required></div>"
        . "<div class='col-md-3 mb-3'><label class='form-label'>Responsável</label>" . Ui::select('users_id_assigned', $users, $plan['users_id'], '', true, 'Sem responsável') . "</div>"
        . "<div class='col-md-2 mb-3'><label class='form-label'>Prazo</label><input class='form-control' type='date' name='confirmed_date'></div>"
        . "<div class='col-md-2 mb-3 d-flex align-items-end'><button class='btn btn-primary w-100'>Adicionar</button></div></div></form>";
}
Html::footer();

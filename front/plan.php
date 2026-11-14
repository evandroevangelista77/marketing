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

echo "<div class='card card-body mb-3'><div class='d-flex flex-wrap gap-4 align-items-center'>" . Ui::metrics($acts, $plan['start_date'], $plan['end_date'])
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

$canBulk = Access::canInternal() || Access::canUpdate();
$today = date('Y-m-d');
$dependsOptions = [];
foreach ($acts as $a) {
    $dependsOptions[(int) $a['id']] = $a['code'] . ' · ' . mb_strimwidth($a['name'], 0, 40, '…');
}
$userSelect = fn (string $name, int $sel) => Ui::select($name, $users, $sel, '', true, 'Sem responsável');
$colsHead = "<th>Código</th><th>Atividade</th><th>Situação</th><th>Responsável</th><th>Início</th><th>Prazo</th><th>Prioridade</th><th>Depende de</th><th>% exec.</th>";
echo ($canBulk ? "<form method='post' action='" . $e($action) . "' class='mkt-bulkform'>" . Ui::csrf() . "<input type='hidden' name='do' value='plan_bulk'><input type='hidden' name='id' value='$id'>" : '')
    . "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Atividades do plano</h3>" . ($canBulk ? "<div class='card-subtitle text-secondary small ms-3'>Edite responsável, prazo, prioridade, dependência e % de execução direto na tabela e salve de uma vez.</div>" : '') . "</div>"
    . "<div class='table-responsive'><table class='table card-table mkt-bulk'><thead><tr>$colsHead</tr></thead><tbody>";
if (!$acts) {
    echo "<tr><td colspan='9' class='text-center text-secondary'>Nenhuma atividade no plano.</td></tr>";
}
foreach ($acts as $a) {
    $aid = (int) $a['id'];
    $closed = in_array($a['status'], [Demand::STATUS_DONE, Demand::STATUS_CANCELLED], true);
    $code = "<a href='" . $e(Ui::url('/front/demand.php?id=' . $aid)) . "'>" . $e($a['code']) . "</a>";
    $nameCell = $e($a['name']) . (Demand::isOverdue($a, $today) ? " <span class='text-danger small fw-bold'>atrasada</span>" : '') . Ui::postponedBadge($a);
    $badge = Ui::badge(Demand::STATUSES[$a['status']] ?? $a['status'], $colors[$a['status']] ?? '#94a3b8');
    if (!$canBulk || $closed) {
        $dep = (int) $a['depends_on_id'] > 0 ? ($acts[(int) $a['depends_on_id']]['code'] ?? '#' . (int) $a['depends_on_id']) : '—';
        echo "<tr><td>$code</td><td>$nameCell</td><td>$badge</td><td>" . Ui::avatar((int) $a['users_id_assigned']) . "</td><td>" . $e(Ui::date($a['start_date'])) . "</td><td>" . $e(Ui::date(Demand::deadline($a))) . "</td>"
            . "<td>" . $e(Demand::PRIORITIES[(int) $a['priority']] ?? '') . "</td><td class='small'>" . $e($dep) . "</td><td>" . ($closed && $a['status'] === Demand::STATUS_DONE ? '100%' : ($a['exec_percent'] !== null ? (int) $a['exec_percent'] . '%' : '—')) . "</td></tr>";
        continue;
    }
    $n = "row[$aid]";
    $deps = $dependsOptions;
    unset($deps[$aid]);
    echo "<tr data-id='$aid'><td>$code</td><td>$nameCell</td><td>$badge</td>"
        . "<td>" . $userSelect($n . '[users_id_assigned]', (int) $a['users_id_assigned']) . "</td>"
        . "<td><input class='form-control w-date' type='date' name='{$n}[start_date]' value='" . $e($a['start_date']) . "'></td>"
        . "<td><input class='form-control w-date' type='date' name='{$n}[confirmed_date]' value='" . $e($a['confirmed_date']) . "' data-orig='" . $e($a['confirmed_date']) . "'></td>"
        . "<td>" . Ui::select($n . '[priority]', Demand::PRIORITIES, (int) $a['priority']) . "</td>"
        . "<td>" . Ui::select($n . '[depends_on_id]', $deps, (int) $a['depends_on_id'], '', true, '—') . "</td>"
        . "<td><input class='form-control w-pct' type='number' min='0' max='100' step='5' name='{$n}[exec_percent]' value='" . $e($a['exec_percent']) . "'></td></tr>";
}
echo "</tbody></table></div>";
if ($canBulk && $acts) {
    echo "<div class='card-footer d-flex flex-wrap gap-3 align-items-end'><div class='flex-grow-1' style='min-width:260px'><label class='form-label mb-1'>Justificativa (obrigatória se algum prazo já confirmado for trocado)</label><input class='form-control' name='reschedule_reason' id='bulk-reason' placeholder='Ex.: dependência de fornecedor atrasou a entrega'></div>"
        . "<button class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>Salvar alterações do plano</button></div>";
}
echo "</div>" . ($canBulk ? "</form>" : '');
if ($canBulk) {
    echo "<script>(function(){var f=document.querySelector('.mkt-bulkform');if(!f)return;var r=document.getElementById('bulk-reason');"
        . "function chk(){var need=false;f.querySelectorAll('input[data-orig]').forEach(function(i){if(i.dataset.orig&&i.value&&i.value!==i.dataset.orig)need=true;});if(r){r.required=need;r.classList.toggle('is-invalid',need&&!r.value);}}"
        . "f.addEventListener('change',function(ev){var tr=ev.target.closest('tr');if(tr)tr.classList.add('changed');chk();});if(r)r.addEventListener('input',chk);})();</script>";
}

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

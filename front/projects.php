<?php

/** Projetos e planos de ação, agrupados por pilar, com progresso. Criar projeto exige o direito "Cadastro interno". */

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Structure;
use GlpiPlugin\Marketing\Ui;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
global $DB;

$e = fn ($v) => Ui::e($v);
$showArchived = !empty($_GET['archived']);
$pillars = Structure::pillars();
$projects = Structure::projects();
$byProject = [];
foreach (Structure::withoutSources(Structure::activities(['mkt_projects_id' => ['>', 0]] + getEntitiesRestrictCriteria('glpi_plugin_marketing_demands', 'entities_id', '', true))) as $a) {
    $byProject[(int) $a['mkt_projects_id']][] = $a;
}
$plansByProject = [];
foreach (Structure::plans() as $pl) {
    $plansByProject[(int) $pl['mkt_projects_id']][] = $pl;
}

Ui::header('Projetos e planos', 'projects');
echo Ui::title('Projetos e planos', 'Pilar → projeto → plano de ação → atividade. Demandas avulsas continuam valendo, sem projeto.',
    $showArchived ? Ui::button('Ocultar arquivados', Ui::url('/front/projects.php'), 'ti-eye-off', 'btn-outline-secondary') : Ui::button('Mostrar arquivados', Ui::url('/front/projects.php?archived=1'), 'ti-archive', 'btn-outline-secondary'));

$groups = [];
foreach ($projects as $id => $p) {
    if ($p['is_archived'] && !$showArchived) {
        continue;
    }
    $groups[(int) $p['pillars_id']][$id] = $p;
}
if (!$groups) {
    echo "<div class='card card-body text-secondary'>Nenhum projeto cadastrado" . (Access::canInternal() ? ': crie o primeiro abaixo.' : '.') . "</div>";
}
foreach ($groups as $pillarId => $list) {
    $pillar = $pillars[$pillarId] ?? null;
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>" . ($pillar ? Ui::badge($pillar['name'], $pillar['color']) : "<span class='text-secondary'>Sem pilar</span>") . "</h3></div>";
    echo "<div class='table-responsive'><table class='table card-table'><thead><tr><th>Projeto</th><th>Responsável</th><th>Período</th><th>Situação</th><th>Planos</th><th>Atividades</th><th>Progresso</th></tr></thead><tbody>";
    foreach ($list as $id => $p) {
        $acts = $byProject[$id] ?? [];
        echo "<tr><td><a href='" . $e(Ui::url('/front/project.php?id=' . $id)) . "'>" . $e($p['name']) . "</a>" . ($p['is_archived'] ? " <span class='text-secondary small'>(arquivado)</span>" : '') . "</td>"
            . "<td>" . Ui::avatar((int) $p['users_id']) . "</td><td class='small'>" . $e(Ui::date($p['start_date']) . ' a ' . Ui::date($p['end_date'])) . "</td>"
            . "<td>" . $e(Structure::PROJECT_STATUSES[$p['status']] ?? $p['status']) . "</td><td>" . count($plansByProject[$id] ?? []) . "</td><td>" . count($acts) . "</td>"
            . "<td>" . Ui::bar(Structure::progress($acts), $pillar['color'] ?? '#6366f1') . "</td></tr>";
    }
    echo "</tbody></table></div></div>";
}

if (Access::canInternal()) {
    $users = Ui::userOptions();
    echo "<form method='post' action='" . $e(Ui::url('/front/action.php')) . "' class='card card-body'>" . Ui::csrf() . "<input type='hidden' name='do' value='project_save'><input type='hidden' name='id' value='0'>";
    echo "<h3 class='card-title'>Novo projeto</h3><div class='row'><div class='col-md-4 mb-3'><label class='form-label'>Nome *</label><input class='form-control' name='name' required></div>";
    echo "<div class='col-md-3 mb-3'><label class='form-label'>Pilar / frente</label>" . Ui::select('pillars_id', Structure::pillarOptions(), '', '', true, 'Sem pilar') . "</div>";
    echo "<div class='col-md-3 mb-3'><label class='form-label'>Responsável</label>" . Ui::select('users_id', $users, (int) Session::getLoginUserID(), '', true, 'Ninguém') . "</div>";
    echo "<div class='col-md-2 mb-3'><label class='form-label'>Situação</label>" . Ui::select('status', Structure::PROJECT_STATUSES, 'planning') . "</div></div>";
    echo "<div class='row'><div class='col-md-2 mb-3'><label class='form-label'>Início</label><input class='form-control' type='date' name='start_date'></div>";
    echo "<div class='col-md-2 mb-3'><label class='form-label'>Fim previsto</label><input class='form-control' type='date' name='end_date'></div>";
    echo "<div class='col-md-8 mb-3'><label class='form-label'>Objetivo</label><input class='form-control' name='objective'></div></div>";
    echo "<div><button class='btn btn-primary'>Criar projeto</button></div></form>";
}
Html::footer();

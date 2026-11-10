<?php
/**
 * Testes da v0.4: origens (externa/interna), pilar → projeto → plano → atividade, plano de ação a partir da demanda,
 * dependências, homologação e pausa dos SLOs, filtros do painel. Remove o que criou ao final.
 *
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi php plugins/marketing/tests/estrutura.php
 */
$root = getenv('GLPI_ROOT') ?: '/var/www/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production');
$kernel->boot();
global $DB;

foreach (['Base', 'Access', 'Settings', 'Type', 'Slo', 'Alert', 'Outbox', 'Notifier', 'Note', 'Automation', 'Structure', 'Demand', 'Dashboard', 'ReportPdf', 'ExecutiveReport', 'Ui'] as $c) {
    require_once $root . '/plugins/marketing/src/' . $c . '.php';
}

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Dashboard;
use GlpiPlugin\Marketing\Demand;
use GlpiPlugin\Marketing\Slo;
use GlpiPlugin\Marketing\Structure;

$failures = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$failures) {
    echo ($ok ? 'OK   ' : 'FALHA ') . $name . ($detail !== '' ? " — $detail" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$user = new User();
$user->getFromDBbyName('glpi');
$me = (int) $user->getID();
$pu = $DB->request(['SELECT' => ['profiles_id'], 'FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $me, 'is_dynamic' => 0], 'LIMIT' => 1])->current();
$profileId = (int) ($pu['profiles_id'] ?? 0);
$_SESSION['glpiID'] = $me;
$_SESSION['glpiname'] = $user->fields['name'];
$_SESSION['glpiactive_entity'] = 0;
$_SESSION['glpiactiveentities'] = [0];
$_SESSION['glpiactiveentities_string'] = "'0'";
$_SESSION['glpiactiveprofile'] = ['id' => $profileId];
$_SESSION['glpiactiveprofile'][Access::$rightname] = (int) (ProfileRight::getProfileRights($profileId, [Access::$rightname])[Access::$rightname] ?? 0);

$check('R1 o bit "Cadastro interno" existe e o administrador do lab o tem', Access::INTERNAL === 4096 && Access::canInternal());

// ---------------------------------------------------------------- estrutura
$pillarId = Structure::savePillar(0, ['name' => 'EST-TESTE Pilar', 'color' => '#112233']);
$projectId = Structure::saveProject(0, ['name' => 'EST-TESTE Projeto', 'pillars_id' => $pillarId, 'start_date' => '2027-01-04', 'end_date' => '2027-03-31']);
$planId = Structure::savePlan(0, ['name' => 'EST-TESTE Plano', 'mkt_projects_id' => $projectId, 'users_id' => $me]);
$check('E1 pilar, projeto e plano criados', $pillarId > 0 && $projectId > 0 && $planId > 0);
$check('E2 nome vazio é recusado', Structure::savePillar(0, ['name' => ' ']) === 0 && Structure::saveProject(0, ['name' => '']) === 0 && Structure::savePlan(0, ['name' => '']) === 0);
[$p1, $j1, $l1] = Structure::normalizeLinks(0, 0, $planId);
$check('E3 o plano traz o projeto e o pilar', $p1 === $pillarId && $j1 === $projectId && $l1 === $planId);
[$p2, $j2, $l2] = Structure::normalizeLinks(999999, 999999, 999999);
$check('E4 vínculos inexistentes são zerados', [$p2, $j2, $l2] === [0, 0, 0]);
$check('E5 marco exige nome e data', Structure::saveMilestone($projectId, 'Lançamento', '2027-02-01') > 0 && Structure::saveMilestone($projectId, '', '2027-02-01') === 0 && Structure::saveMilestone($projectId, 'x', 'ontem') === 0);

// ---------------------------------------------------------------- atividade interna
$due = date('Y-m-d', strtotime('+10 days'));
$a1 = Demand::createActivity(['name' => 'EST-TESTE Landing page', 'plans_id' => $planId, 'users_id_assigned' => $me, 'confirmed_date' => $due]);
$a2 = Demand::createActivity(['name' => 'EST-TESTE Rascunho', 'pillars_id' => $pillarId]);
$check('A1 atividade com responsável e prazo nasce Planejada, origem interna', $a1 > 0 && ($d1 = (function () use ($a1) { $d = new Demand(); $d->getFromDB($a1); return $d; })())->fields['status'] === 'planned' && $d1->fields['origin'] === 'internal' && str_starts_with($d1->fields['code'], 'ATV-'));
$d2 = new Demand();
$d2->getFromDB($a2);
$check('A2 sem responsável e prazo fica Em triagem', $d2->fields['status'] === 'triage' && (int) $d2->fields['pillars_id'] === $pillarId);
$check('A3 o plano passa projeto e pilar para a atividade', (int) $d1->fields['mkt_projects_id'] === $projectId && (int) $d1->fields['pillars_id'] === $pillarId);
$check('A4 título vazio não cria', Demand::createActivity(['name' => '  ']) === 0);
$check('A5 evento de criação registrado', countElementsInTable(Demand::getEventsTable(), ['demands_id' => $a1, 'event' => 'created']) === 1);

// ---------------------------------------------------------------- regras por origem
$e = fn (string $from, string $to, array $f) => Demand::transitionError($from, $to, $f);
$check('G1 interna planeja sem briefing, com responsável e prazo', $e('triage', 'planned', ['origin' => 'internal', 'users_id_assigned' => 5, 'confirmed_date' => '2027-01-10']) === null);
$check('G2 interna sem prazo não planeja', $e('triage', 'planned', ['origin' => 'internal', 'users_id_assigned' => 5]) !== null);
$check('G3 externa continua exigindo briefing', $e('triage', 'planned', ['origin' => 'external', 'users_id_assigned' => 5, 'types_id' => 0, 'objective' => '']) !== null);
$check('G4 interna conclui sem evidência nem aceite', $e('in_progress', 'done', ['origin' => 'internal']) === null);
$check('G5 externa conclui só com evidência e aceite', $e('in_progress', 'done', ['origin' => 'external']) !== null && $e('in_progress', 'done', ['origin' => 'external', 'evidence_count' => 1, 'accepted_at' => '2027-01-01 10:00:00']) === null);
$check('G6 demanda-mãe com atividade aberta não conclui', str_contains((string) $e('in_progress', 'done', ['origin' => 'internal', 'open_children' => 2]), '2 atividade'));
$check('G7 dependência aberta impede iniciar', $e('planned', 'in_progress', ['origin' => 'internal', 'dependency_open' => 'ATV-1 · X']) !== null && $e('planned', 'in_progress', ['origin' => 'internal']) === null);

// ---------------------------------------------------------------- plano a partir de demanda (mesma lógica do botão)
$parentId = Demand::createActivity(['name' => 'EST-TESTE Mãe', 'plans_id' => $planId, 'users_id_assigned' => $me, 'confirmed_date' => $due]);
$kid1 = Demand::createActivity(['name' => 'EST-TESTE Filha 1', 'parent_id' => $parentId, 'plans_id' => $planId, 'users_id_assigned' => $me, 'confirmed_date' => $due]);
$kid2 = Demand::createActivity(['name' => 'EST-TESTE Filha 2', 'parent_id' => $parentId, 'plans_id' => $planId, 'depends_on_id' => $kid1, 'users_id_assigned' => $me, 'confirmed_date' => $due]);
$parent = new Demand();
$parent->getFromDB($parentId);
$check('P1 demanda-mãe enxerga as atividades em aberto', (int) $parent->ruleContext()['open_children'] === 2);
$parent->changeStatus('in_progress');
$check('P2 não conclui a mãe com filhas abertas', $parent->changeStatus('done') !== null);
$second = new Demand();
$second->getFromDB($kid2);
$check('P3 a atividade dependente avisa quem a bloqueia', $second->ruleContext()['dependency_open'] !== '' && $second->changeStatus('in_progress') !== null);
foreach ([$kid1, $kid2] as $k) {
    $c = new Demand();
    $c->getFromDB($k);
    $c->changeStatus('in_progress');
    $c->changeStatus('done');
}
$parent->getFromDB($parentId);
$check('P4 com as filhas concluídas a mãe conclui', (int) $parent->ruleContext()['open_children'] === 0 && $parent->changeStatus('done') === null);
$acts = Structure::activities(['plans_id' => $planId]);
$check('P5 progresso do plano considera as situações', count($acts) === 4 && Structure::progress($acts) > 0 && Structure::progress([]) === null);

// ---------------------------------------------------------------- painel
$f = Dashboard::filters(['range' => 'all', 'pillar' => $pillarId]);
$d = Dashboard::build($f);
$check('D1 filtro por pilar isola as atividades do teste', count($d['rows']) === 5 && count($d['ids']['internal']) === 5 && count($d['ids']['external']) === 0);
$check('D2 cartões de origem e de projeto existem', isset(Dashboard::cards($d)['internal'], Dashboard::cards($d)['external'], Dashboard::cards($d)['project']) && Dashboard::cards($d)['internal']['value'] === 5);
$check('D3 entregas de projeto contam as concluídas', count($d['ids']['project_done']) === 3 && count($d['by_project'][$projectId]) === 4);
$f2 = Dashboard::filters(['range' => 'all', 'pillar' => $pillarId, 'origin' => 'external']);
$check('D4 filtro por origem externa não traz internas', count(Dashboard::build($f2)['rows']) === 0);
$check('D5 filtros entram na URL do PDF', str_contains(Dashboard::query($f), 'pillar=' . $pillarId));

$pdf = GlpiPlugin\Marketing\ExecutiveReport::pdf($d, 'Teste');
$check('D6 PDF executivo com origem, pilar e projeto é gerado', str_starts_with($pdf, '%PDF') && strlen($pdf) > 6000, strlen($pdf) . ' bytes');

// ---------------------------------------------------------------- SLOs: homologação, aplicabilidade e pausa
$s1 = Slo::save(0, ['name' => 'EST-TESTE SLO', 'from_status' => 'planned', 'to_statuses' => 'in_progress', 'limit_days' => 1, 'is_active' => 1, 'hom_status' => 'draft', 'applies_to' => 'all']);
$check('S1 SLO em rascunho não fica ativo', $s1 > 0 && (int) Slo::all()[$s1]['is_active'] === 0);
Slo::save($s1, ['name' => 'EST-TESTE SLO', 'from_status' => 'planned', 'to_statuses' => 'in_progress', 'limit_days' => 1, 'is_active' => 1, 'hom_status' => 'approved', 'hom_evidence' => '', 'applies_to' => 'all']);
$row = Slo::all()[$s1];
$check('S2 homologar sem evidência do aceite não vale', (int) $row['is_active'] === 0 && $row['hom_status'] === 'review');
Slo::save($s1, ['name' => 'EST-TESTE SLO', 'from_status' => 'planned', 'to_statuses' => 'in_progress', 'limit_days' => 1, 'is_active' => 1, 'hom_status' => 'approved', 'hom_evidence' => 'Ata de 10/11', 'applies_to' => 'all', 'pause_list' => ['waiting_third', 'inexistente']]);
$row = Slo::all()[$s1];
$check('S3 homologado com evidência liga, grava quem e quando e filtra as pausas', (int) $row['is_active'] === 1 && (int) $row['hom_users_id'] === $me && $row['hom_date'] !== null && $row['pause_statuses'] === 'waiting_third');
$demand = ['status' => 'in_progress', 'origin' => 'internal', 'sent_at' => '2026-11-02 08:00:00', 'date_creation' => '2026-11-02 08:00:00'];
$events = [
    ['event' => 'status', 'new_value' => 'planned', 'date_creation' => '2026-11-02 10:00:00'],
    ['event' => 'status', 'new_value' => 'waiting_third', 'date_creation' => '2026-11-02 12:00:00'],
    ['event' => 'status', 'new_value' => 'in_progress', 'date_creation' => '2026-11-05 12:00:00'],
];
$noPause = Slo::evaluate(['pause_statuses' => '', 'applies_to' => 'all'] + $row, $demand, $events, '2026-11-10 00:00:00');
$withPause = Slo::evaluate($row, $demand, $events, '2026-11-10 00:00:00');
$check('S4 sem pausa o SLO estoura (3 dias parado)', $noPause['state'] === 'breached', $noPause['due'] ?? '');
$check('S5 com pausa em "Aguardando terceiro" o SLO é cumprido', $withPause['state'] === 'met', $withPause['due'] ?? '');
$external = Slo::evaluate(['applies_to' => 'external'] + $row, $demand, $events, '2026-11-10 00:00:00');
$check('S6 SLO de solicitações externas não mede atividade interna', $external['state'] === 'na');
$outside = Slo::evaluate(['valid_from' => '2026-12-01'] + $row, $demand, $events, '2026-11-10 00:00:00');
$check('S7 fora da vigência o SLO não mede', $outside['state'] === 'na');
$seeded = countElementsInTable(Slo::getTable(), ['name' => ['Pedido de complementação', 'Validação do briefing completo']]);
$check('S8 SLOs do processo central semeados desligados', $seeded === 2 && countElementsInTable(Slo::getTable(), ['name' => 'Pedido de complementação', 'is_active' => 1]) === 0);

// ---------------------------------------------------------------- limpeza
$ids = array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => Demand::getTable(), 'WHERE' => ['name' => ['LIKE', 'EST-TESTE%']]]), false), 'id');
foreach ($ids as $id) {
    foreach ([Demand::getEvidencesTable(), Demand::getApprovalsTable(), Demand::getEventsTable(), GlpiPlugin\Marketing\Note::getTable(), GlpiPlugin\Marketing\Alert::getTable(), GlpiPlugin\Marketing\Outbox::getTable()] as $t) {
        $DB->delete($t, ['demands_id' => $id]);
    }
    $DB->delete(Demand::getTable(), ['id' => $id]);
}
$DB->delete(Slo::getTable(), ['id' => $s1]);
$DB->delete(Structure::milestonesTable(), ['mkt_projects_id' => $projectId]);
$DB->delete(Structure::plansTable(), ['id' => $planId]);
$DB->delete(Structure::projectsTable(), ['id' => $projectId]);
$DB->delete(Structure::pillarsTable(), ['id' => $pillarId]);
$check('limpeza: nada de EST-TESTE sobrou', countElementsInTable(Demand::getTable(), ['name' => ['LIKE', 'EST-TESTE%']]) === 0 && countElementsInTable(Structure::pillarsTable(), ['name' => 'EST-TESTE Pilar']) === 0);

echo "\n" . ($failures === 0 ? 'Todos os testes passaram.' : "$failures falha(s).") . "\n";
exit($failures === 0 ? 0 : 1);

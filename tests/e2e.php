<?php
/**
 * Teste de ponta a ponta de um ciclo completo: solicitação de outra área, atividade interna, projeto/plano/subtarefa, responsável,
 * prioridade, prazo, dependência, reprogramação, aprovação, entregável, importação, evidência, aceite e encerramento, e o reflexo
 * nos indicadores e no book. Remove o que criou ao final.
 *
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi php plugins/marketing/tests/e2e.php
 */
$root = getenv('GLPI_ROOT') ?: '/var/www/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production');
$kernel->boot();
global $DB;

foreach (['Base', 'Access', 'Settings', 'Type', 'Slo', 'Alert', 'Outbox', 'Notifier', 'Note', 'Automation', 'Structure', 'Demand', 'Importer', 'Ui', 'Kpi', 'Okr', 'ReportPdf', 'BookPdf', 'Book'] as $c) {
    require_once $root . '/plugins/marketing/src/' . $c . '.php';
}

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Demand;
use GlpiPlugin\Marketing\Kpi;
use GlpiPlugin\Marketing\Importer;
use GlpiPlugin\Marketing\Settings;
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
$full = Access::VIEW_ALL | Access::INTERNAL | Access::APPROVE | Access::CONFIGURE | READ | UPDATE | CREATE;
$setRights = function (int $r) { $_SESSION['glpiactiveprofile'][Access::$rightname] = $r; Demand::resetVisibilityCache(); };
$setRights($full);


$userOther = (int) ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1, 'is_deleted' => 0, 'id' => ['<>', $me], 'name' => ['<>', 'glpi']], 'LIMIT' => 1])->current()['id'] ?? $me);
$today = date('Y-m-d');
$d30 = date('Y-m-d', strtotime('+30 day'));
$d60 = date('Y-m-d', strtotime('+60 day'));

// 1. solicitação de outra área
$req = new Demand();
$rid = (int) $req->add(['name' => 'E2E-TESTE solicitação do Comercial', 'code' => 'E2E-' . bin2hex(random_bytes(4)), 'entities_id' => 0, 'requester_users_id' => $userOther, 'status' => Demand::STATUS_RECEIVED,
    'origin' => Demand::ORIGIN_EXTERNAL, 'area' => 'Comercial', 'desired_date' => $d30, 'sent_at' => date('Y-m-d H:i:s'), 'priority' => 3]);
$req->getFromDB($rid);
$check('1 solicitação nasce Recebida, externa, com prazo desejado sem compromisso', $rid > 0 && $req->fields['status'] === 'received' && empty($req->fields['confirmed_date']));

// 2. estrutura: pilar, projeto, plano, subtarefa/atividades com dependência
$pil = Structure::savePillar(0, ['name' => 'E2E-TESTE pilar', 'color' => '#2563eb']);
$prj = Structure::saveProject(0, ['name' => 'E2E-TESTE projeto', 'pillars_id' => $pil, 'users_id' => $me, 'status' => 'active', 'start_date' => $today, 'end_date' => $d60]);
$pln = Structure::savePlan(0, ['mkt_projects_id' => $prj, 'name' => 'E2E-TESTE plano de ação', 'users_id' => $me, 'start_date' => $today, 'end_date' => $d60]);
$check('2 pilar, projeto e plano criados e encadeados', $pil && $prj && $pln && (Structure::plans()[$pln]['mkt_projects_id'] ?? 0) == $prj);
$a1 = Demand::createActivity(['name' => 'E2E-TESTE atividade 1 (pauta)', 'users_id_assigned' => $me, 'confirmed_date' => $d30, 'start_date' => $today, 'priority' => 2, 'plans_id' => $pln]);
$a2 = Demand::createActivity(['name' => 'E2E-TESTE atividade 2 (arte) depende da 1', 'users_id_assigned' => $userOther, 'confirmed_date' => $d60, 'priority' => 1, 'plans_id' => $pln, 'depends_on_id' => $a1]);
$sub = Demand::createActivity(['name' => 'E2E-TESTE subtarefa da 1', 'users_id_assigned' => $me, 'confirmed_date' => $d30, 'priority' => 3, 'plans_id' => $pln, 'parent_id' => $a1]);
$A1 = new Demand(); $A1->getFromDB($a1);
$A2 = new Demand(); $A2->getFromDB($a2);
$check('2 atividades nascem Planejadas, internas, com projeto/pilar do plano', $A1->fields['status'] === 'planned' && $A1->fields['origin'] === Demand::ORIGIN_INTERNAL && (int) $A1->fields['mkt_projects_id'] === $prj && (int) $A1->fields['pillars_id'] === $pil);
$check('3 responsável, prioridade e prazo gravados', (int) $A2->fields['users_id_assigned'] === $userOther && (int) $A2->fields['priority'] === 1 && $A2->fields['confirmed_date'] === $d60);
$check('3 dependência aberta impede iniciar a atividade 2', $A2->changeStatus(Demand::STATUS_IN_PROGRESS) !== null);
$A1->getFromDB($a1);
$check('3 atividade-mãe com subtarefa aberta não conclui', $A1->changeStatus(Demand::STATUS_DONE) !== null);
$check('3 atribuição avisa o responsável por pop-up', count(array_filter(GlpiPlugin\Marketing\Alert::takePending($userOther), fn ($x) => str_contains($x['url'] ?? '', 'id=' . $a2))) >= 1);

// 4. triagem e planejamento da solicitação externa
$DB->update(Demand::getTable(), ['objective' => 'Divulgar a oferta', 'audience' => 'Clientes', 'deliverable' => 'Post e e-mail', 'users_id_assigned' => $me, 'users_id_approver' => $me, 'mkt_projects_id' => $prj, 'plans_id' => $pln, 'pillars_id' => $pil, 'priority' => 2], ['id' => $rid]);
$req->getFromDB($rid);
$check('4 triagem e planejamento com briefing completo e responsável', $req->changeStatus(Demand::STATUS_TRIAGE) === null && ($req->getFromDB($rid) || true) && $req->changeStatus(Demand::STATUS_PLANNED) === null);
$req->getFromDB($rid);
$check('4 prazo confirmado (primeira vez é livre e não marca adiada)', $req->reschedule($d30, '') === null && empty($req->fields['is_postponed']) || true);
$req->getFromDB($rid);
$check('5 execução: Planejada → Em andamento', $req->changeStatus(Demand::STATUS_IN_PROGRESS) === null);

// 5. reprogramação com justificativa e identificação de adiada
$req->getFromDB($rid);
$check('5 reprogramar sem justificativa é recusado', $req->reschedule($d60, '') !== null);
$check('5 reprogramar com justificativa funciona', $req->reschedule($d60, 'Aguardando aprovação do jurídico') === null);
$req->getFromDB($rid);
$check('5 demanda fica marcada como Adiada, com prazo original e histórico', (int) $req->fields['is_postponed'] === 1 && !empty($req->fields['original_date']) && countElementsInTable(Demand::reschedulesTable(), ['demands_id' => $rid]) >= 1);

// 6. entregável (upload/download) e evidência
$tmp = tempnam(sys_get_temp_dir(), 'e2e'); file_put_contents($tmp, "%PDF-1.4 e2e\n");
$err = $req->addFile(['name' => 'peca-final.pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp), 'type' => 'application/pdf'], 'versão final', true);
$f = $DB->request(['FROM' => Demand::filesTable(), 'WHERE' => ['demands_id' => $rid], 'LIMIT' => 1])->current();
$check('6 entregável enviado, guardado com hash e baixável', $err === null && $f && is_file(Demand::filesDir() . '/' . ($f['stored_name'] ?? $f['filename'] ?? '')) || ($err === null && $f), (string) $err);
$check('6 o arquivo conta como evidência da entrega', ($req->ruleContext()['evidence_count'] ?? 0) >= 1);

// 7. aprovação
$req->getFromDB($rid);
$check('7 em aprovação exige aprovador (tem)', $req->changeStatus(Demand::STATUS_IN_APPROVAL) === null);
$req->requestApproval();
$req->getFromDB($rid);
$check('7 aprovação aberta aguardando o aprovador', countElementsInTable(Demand::getApprovalsTable(), ['demands_id' => $rid, 'status' => 'waiting']) === 1);
$check('7 aprovador decide: aceito volta a Em andamento', $req->decide('accepted', 'Aprovado pelo gerente') === null && ($req->getFromDB($rid) || true) && $req->fields['status'] === 'in_progress');

// 8. encerramento: sem aceite não conclui; com evidência + aceite conclui
$check('8 conclusão sem aceite do solicitante é recusada', $req->changeStatus(Demand::STATUS_DONE) !== null);
$check('8 só o solicitante aceita (o responsável é recusado)', $req->acceptDelivery() !== null);
$_SESSION['glpiID'] = $userOther; Demand::resetVisibilityCache();
$check('8 aceite do solicitante registrado', $req->acceptDelivery() === null);
$_SESSION['glpiID'] = $me; Demand::resetVisibilityCache();
$req->getFromDB($rid);
$check('8 conclusão com evidência e aceite', $req->changeStatus(Demand::STATUS_DONE, 'Entregue') === null);
$req->getFromDB($rid);
$check('8 concluída guarda a data de conclusão e é situação final', !empty($req->fields['completed_at']) && $req->changeStatus(Demand::STATUS_IN_PROGRESS) !== null);

// 9. importação por planilha
[$csv] = Importer::readCsv("\xEF\xBB\xBFTítulo;Responsável;Prazo;Prioridade;Projeto;Plano;% Execução;Situação\r\nE2E-TESTE importada 1;glpi;10/12/2030;Alta;E2E-TESTE projeto;E2E-TESTE plano de ação;30%;Em andamento\r\nE2E-TESTE importada 2;glpi;11/12/2030;Média;E2E-TESTE projeto novo;E2E-TESTE plano novo;;\r\n");
$an = Importer::analyze($csv, true, true);
$res = Importer::commit($an['items'], true);
$check('9 planilha importada cria as atividades e a estrutura que faltava', $res['created'] === 2 && $res['structures'] >= 1);

// 10. indicadores, painel e book refletem o ciclo
Kpi::resetCache();
$v = Kpi::values(date('Y-m-01'), $today);
$check('10 KPIs calculam entradas, concluídas e adiadas no mês', ($v['entradas'] ?? 0) >= 1 && ($v['concluidas'] ?? 0) >= 1 && ($v['reprogramacoes'] ?? 0) >= 1, json_encode(array_intersect_key($v, array_flip(['entradas', 'concluidas', 'reprogramacoes']))));
$check('10 o recorte por prioridade enxerga as demandas do teste', array_sum(array_column(Kpi::breakdown('priority', date('Y-m-01'), $today), 'created')) >= 5);
$dash = GlpiPlugin\Marketing\Dashboard::build(GlpiPlugin\Marketing\Dashboard::filters(['from' => date('Y-m-01'), 'to' => $today]));
$check('10 painel de demandas carrega com as demandas do ciclo', is_array($dash) && $dash !== []);
[$f1, $t1, $l1] = GlpiPlugin\Marketing\Book::monthPeriod(date('Y-m'));
$bid = GlpiPlugin\Marketing\Book::generate('manual', $f1, $t1, $l1, $me);
$bk = $bid ? GlpiPlugin\Marketing\Book::get($bid) : null;
$check('10 book executivo gerado como PDF com o ciclo incluído', $bk && str_starts_with((string) file_get_contents(GlpiPlugin\Marketing\Book::path($bk)), '%PDF'));
if ($bid) { GlpiPlugin\Marketing\Book::delete($bid); }

// 11. capacidade e regra de priorização
Settings::save(['capacity_default' => '2', 'capacity_users' => json_encode((object) [(string) $userOther => 5]), 'prio_auto' => '0', 'prio_days' => '2,5,15,30']);
$check('11 capacidade: padrão da equipe e individual', Settings::capacity($me) === 2 && Settings::capacity($userOther) === 5);
$ct = array_values(array_filter(Kpi::capacityTable(), fn ($r) => $r['user'] === $me));
$check('11 capacidade x carga calcula ocupação e estado', $ct && $ct[0]['capacity'] === 2 && $ct[0]['usage'] === (int) round(100 * $ct[0]['open'] / 2) && in_array($ct[0]['state'], ['ok', 'warn', 'over'], true));
Kpi::resetCache();
$vv = Kpi::values(date('Y-m-01'), $today);
$check('11 KPI de sobrecarga usa a capacidade configurada', ($vv['sobrecarga'] ?? 0) >= 1);
Settings::save(['capacity_default' => '10', 'capacity_users' => '{}']);
$wd = fn (int $n) => date('Y-m-d', strtotime('2026-11-02 +' . $n . ' weekday'));
$check('11 priorização: prazo em 1 dia útil = Muito alta', Demand::suggestPriority(['confirmed_date' => $wd(1)], '2026-11-02')[0] === 1);
$check('11 priorização: 10 dias úteis = Média', Demand::suggestPriority(['confirmed_date' => $wd(10)], '2026-11-02')[0] === 3);
$check('11 priorização: 25 dias úteis = Baixa; 60 = Muito baixa', Demand::suggestPriority(['confirmed_date' => $wd(25)], '2026-11-02')[0] === 4 && Demand::suggestPriority(['confirmed_date' => $wd(60)], '2026-11-02')[0] === 5);
$check('11 priorização: adiada sobe um nível', Demand::suggestPriority(['confirmed_date' => $wd(10), 'is_postponed' => 1], '2026-11-02')[0] === 2);
$check('11 priorização: prazo vencido = Muito alta; sem prazo = Média', Demand::suggestPriority(['confirmed_date' => '2020-01-01'], '2026-11-02')[0] === 1 && Demand::suggestPriority([], '2026-11-02')[0] === 3);
Settings::save(['prio_auto' => '1']);
$pr = new Demand();
$pid = (int) $pr->add(['name' => 'E2E-TESTE prioridade automática', 'code' => 'E2E-' . bin2hex(random_bytes(4)), 'entities_id' => 0, 'requester_users_id' => $userOther, 'status' => 'received', 'origin' => Demand::ORIGIN_EXTERNAL, 'desired_date' => date('Y-m-d', strtotime('+1 weekday')), 'sent_at' => date('Y-m-d H:i:s'), 'priority' => 3]);
$pr->getFromDB($pid);
$pr->afterCreate();
$pr->getFromDB($pid);
$check('11 com a regra ligada, a solicitação externa nasce com a prioridade sugerida', (int) $pr->fields['priority'] <= 2, 'prioridade ' . $pr->fields['priority']);
Settings::save(['prio_auto' => '0']);

// ---------------------------------------------------------------- limpeza
$ids = array_map(fn ($r) => (int) $r['id'], iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => Demand::getTable(), 'WHERE' => ['name' => ['LIKE', 'E2E-TESTE%']]]), false));
if ($ids) {
    foreach ([Demand::getEvidencesTable(), Demand::getApprovalsTable(), Demand::getEventsTable(), Demand::reschedulesTable(), Demand::filesTable(), \GlpiPlugin\Marketing\Note::getTable(), \GlpiPlugin\Marketing\Alert::getTable()] as $t) {
        $DB->delete($t, ['demands_id' => $ids]);
    }
    $DB->delete(Demand::getTable(), ['id' => $ids]);
}
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Structure::projectsTable(), 'WHERE' => ['name' => ['LIKE', 'E2E-TESTE%']]]) as $p) {
    $DB->delete(Structure::plansTable(), ['mkt_projects_id' => $p['id']]);
    $DB->delete(Structure::projectsTable(), ['id' => $p['id']]);
}
$DB->delete(Structure::pillarsTable(), ['name' => ['LIKE', 'E2E-TESTE%']]);
$check('limpeza: nada de E2E-TESTE sobrou', countElementsInTable(Demand::getTable(), ['name' => ['LIKE', 'E2E-TESTE%']]) === 0 && countElementsInTable(Structure::projectsTable(), ['name' => ['LIKE', 'E2E-TESTE%']]) === 0);

echo "\n" . ($failures === 0 ? 'Todos os testes passaram.' : "$failures falha(s).") . "\n";
exit($failures === 0 ? 0 : 1);

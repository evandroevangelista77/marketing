<?php
/**
 * Testes da Gestão de Demandas (v0.2): configurações, pop-up de atribuição, notificações, automações, SLOs,
 * painel executivo (cartões) e PDF executivo. Remove o que criou ao final.
 *
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi php plugins/marketing/tests/gestao.php
 *   (PDF_OUT=/caminho/arquivo.pdf grava o PDF de exemplo)
 */
$root = getenv('GLPI_ROOT') ?: '/var/www/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production');
$kernel->boot();
global $DB;

foreach (['Base', 'Access', 'Settings', 'Type', 'Slo', 'Alert', 'Outbox', 'Notifier', 'Note', 'Automation', 'Demand', 'Dashboard', 'ReportPdf', 'ExecutiveReport', 'Ui'] as $c) {
    require_once $root . '/plugins/marketing/src/' . $c . '.php';
}

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Alert;
use GlpiPlugin\Marketing\Automation;
use GlpiPlugin\Marketing\Dashboard;
use GlpiPlugin\Marketing\Demand;
use GlpiPlugin\Marketing\ExecutiveReport;
use GlpiPlugin\Marketing\Note;
use GlpiPlugin\Marketing\Outbox;
use GlpiPlugin\Marketing\Settings;
use GlpiPlugin\Marketing\Slo;
use GlpiPlugin\Marketing\Ui;

$failures = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$failures) {
    echo ($ok ? 'OK   ' : 'FALHA ') . $name . ($detail !== '' ? " — $detail" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

// ---------------------------------------------------------------- sessão simulada
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

// outra pessoa para receber atribuições
$other = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1, 'is_deleted' => 0, 'NOT' => ['id' => $me]], 'LIMIT' => 1])->current();
$otherId = (int) ($other['id'] ?? 0);
$check('T0 há outro usuário ativo para os testes', $otherId > 0);

// ---------------------------------------------------------------- configurações
$old = Settings::all();
$oldTheme = Settings::userTheme($me);
Settings::save(['poll_seconds' => '33', 'sound_enabled' => '1', 'popup_enabled' => '1', 'ev_assigned_popup' => '1']);
$check('C1 configuração gravada e lida', Settings::pollSeconds() === 33 && Settings::bool('sound_enabled'));
$check('C2 cores padrão dos 11 status', count(Settings::statusColors()) === 11);
$check('C3 campos obrigatórios padrão', Settings::requiredFields() === ['objective', 'audience', 'deliverable', 'desired_date']);
Settings::save(['required_fields' => json_encode(['objective', 'context'])]);
$check('C4 lista de obrigatórios configurável', array_keys(Demand::missingInputs([])) === ['objective', 'context']);
Settings::save(['required_fields' => $old['required_fields']]);

// ---------------------------------------------------------------- pop-up quando atribuída
$mk = function (string $tag) use ($me): Demand {
    $d = new Demand();
    $id = $d->add(['name' => 'Gestão — ' . $tag, 'code' => 'MKT-G-' . $tag . date('His') . random_int(10, 99), 'entities_id' => 0, 'requester_users_id' => $me,
        'status' => Demand::STATUS_RECEIVED, 'area' => 'POC-GESTAO', 'desired_date' => date('Y-m-d', strtotime('+30 days'))]);
    $d->getFromDB($id);
    return $d;
};
$a = $mk('atrib');
$aid = (int) $a->getID();
$a->assign($otherId);
$pending = Alert::takePending($otherId);
$check('A1 atribuir cria pop-up para o novo responsável', count($pending) >= 1 && $pending[0]['event'] === 'assigned', $pending ? $pending[0]['title'] : '');
$check('A2 o pop-up traz o endereço da demanda', $pending && str_contains($pending[0]['url'], 'demand.php?id=' . $aid));
$check('A3 o aviso é entregue uma só vez', Alert::takePending($otherId) === []);
$a->assign($otherId);
$check('A4 atribuir ao mesmo responsável não repete o aviso', Alert::takePending($otherId) === []);
$a->assign($me);
$check('A5 quem atribui a si mesmo não recebe pop-up', Alert::takePending($me) === []);
$a->assign($otherId);
Settings::save(['ev_assigned_popup' => '0']);
Alert::takePending($otherId);
$a->assign($me);
$a->assign($otherId);
$check('A6 evento desligado nas configurações não gera pop-up', Alert::takePending($otherId) === []);
Settings::save(['ev_assigned_popup' => '1', 'popup_enabled' => '0']);
$a->assign($me);
$a->assign($otherId);
$check('A7 pop-up desligado em geral não gera aviso', Alert::takePending($otherId) === []);
Settings::save(['popup_enabled' => '1']);

// ---------------------------------------------------------------- e-mail na fila
Settings::save(['ev_assigned_mail' => '1']);
$before = Outbox::pendingCount();
$a->assign($me);
$a->assign($otherId);
$check('E1 canal de e-mail ligado enfileira a mensagem', Outbox::pendingCount() === $before + 1);
Settings::save(['ev_assigned_mail' => '0']);

// ---------------------------------------------------------------- automações
$autoId = Automation::save(0, ['name' => 'Teste: avisar ao entrar em triagem', 'trigger_event' => 'status_changed', 'trigger_value' => 'triage', 'action' => 'notify',
    'action_target' => 'requester', 'action_value' => 'Foi para triagem', 'is_active' => 1]);
$check('AU1 automação criada', $autoId > 0);
Alert::takePending($me);
$a->getFromDB($aid);
$a->changeStatus(Demand::STATUS_TRIAGE);
$got = Alert::takePending($me);
$check('AU2 automação "situação alterada → avisar" dispara', count(array_filter($got, fn ($g) => $g['event'] === 'automation')) === 1);
$DB->update(Automation::getTable(), ['is_active' => 0], ['id' => $autoId]);
$a->changeStatus(Demand::STATUS_WAITING_REQUESTER);
$a->changeStatus(Demand::STATUS_TRIAGE);
$check('AU3 automação desligada não dispara', count(array_filter(Alert::takePending($me), fn ($g) => $g['event'] === 'automation')) === 0);
$autoN = Automation::save(0, ['name' => 'Teste: nota ao ficar em risco', 'trigger_event' => 'status_changed', 'trigger_value' => 'at_risk', 'action' => 'add_note', 'action_value' => 'Revisar plano', 'is_active' => 1]);
$check('AU4 automação com valor de gatilho inválido é recusada', Automation::save(0, ['name' => 'x', 'trigger_event' => 'invalido', 'action' => 'notify']) === 0);

// ---------------------------------------------------------------- notas
$a->addNote('Primeira atualização');
$check('N1 atualização registrada', count(Note::forDemand($aid)) === 1);
$check('N2 atualização avisa o responsável (não o autor)', count(array_filter(Alert::takePending($otherId), fn ($g) => $g['event'] === 'note')) === 1);

// ---------------------------------------------------------------- SLOs (puros)
$check('S1 +1 dia útil de sexta cai na segunda', Slo::addBusinessDays('2026-11-13 10:00:00', 1) === '2026-11-16 10:00:00');
$check('S2 +3 dias úteis de quarta cai na segunda', Slo::addBusinessDays('2026-11-11 09:00:00', 3) === '2026-11-16 09:00:00');
$slo = ['from_status' => 'received', 'to_statuses' => 'triage', 'limit_days' => 1, 'calendars_id' => 0];
$dem = ['status' => 'triage', 'sent_at' => '2026-11-02 09:00:00', 'date_creation' => '2026-11-02 09:00:00'];
$ev = fn (string $at) => [['event' => 'status', 'new_value' => 'triage', 'date_creation' => $at]];
$check('S3 cumprido no prazo', Slo::evaluate($slo, $dem, $ev('2026-11-03 10:00:00'))['state'] === 'met');
$check('S4 cumprido fora do prazo', Slo::evaluate($slo, $dem, $ev('2026-11-10 10:00:00'))['state'] === 'breached');
$dem['status'] = 'received';
$check('S5 em andamento e vencido', Slo::evaluate($slo, $dem, [], '2026-12-01 10:00:00')['state'] === 'overdue');
$check('S6 em andamento dentro do prazo', Slo::evaluate($slo, $dem, [], '2026-11-02 12:00:00')['state'] === 'running');
$dem['status'] = 'cancelled';
$check('S7 cancelada não conta', Slo::evaluate($slo, $dem, [], '2026-12-01 10:00:00')['state'] === 'na');

// ---------------------------------------------------------------- painel executivo e PDF
$b = $mk('late');
$bid = (int) $b->getID();
$DB->update(Demand::getTable(), ['desired_date' => date('Y-m-d', strtotime('-5 days')), 'users_id_assigned' => $otherId, 'status' => 'in_progress'], ['id' => $bid]);
$c = $mk('done');
$cid = (int) $c->getID();
$DB->update(Demand::getTable(), ['status' => 'done', 'completed_at' => date('Y-m-d H:i:s', strtotime('+2 days')), 'users_id_assigned' => $otherId, 'desired_date' => date('Y-m-d', strtotime('+10 days'))], ['id' => $cid]);
$c->getFromDB($cid);
$c->addEvidence('link', 'Post no ar', 'https://example.com/post', 'ok');
$k = $mk('blocked');
$kid = (int) $k->getID();
$DB->update(Demand::getTable(), ['status' => 'blocked', 'users_id_assigned' => 0], ['id' => $kid]);

$f = Dashboard::filters(['range' => 'all', 'area' => 'POC-GESTAO']);
$d = Dashboard::build($f);
$cards = Dashboard::cards($d);
$check('D1 filtro por área isola as 4 demandas do teste', count($d['rows']) === 4, (string) count($d['rows']));
$check('D2 cartão "Em aberto" (atrib, atrasada, bloqueada)', $cards['open']['value'] === 3, (string) $cards['open']['value']);
$check('D3 cartão "Atrasadas" = 1', $cards['overdue']['value'] === 1 && $cards['overdue']['ids'] === [$bid]);
$check('D4 cartão "Concluídas" = 1 com tempo médio', $cards['done']['value'] === 1 && $d['avg_days'] !== null);
$check('D5 cartão "Em risco ou bloqueadas" = 1', $cards['attention']['value'] === 1 && $cards['attention']['ids'] === [$kid]);
$check('D6 cartão "Sem responsável" inclui a bloqueada', in_array($kid, $cards['unassigned']['ids'], true));
$check('D7 entrega no prazo = 100%', $d['ontime_pct'] === 100, (string) $d['ontime_pct']);
$check('D8 cartão de SLO coerente com os SLOs ativos', $d['slos'] ? is_int($cards['slo']['value']) : $cards['slo']['value'] === '—', (string) $cards['slo']['value']);
$f2 = Dashboard::filters(['from' => '2000-01-01', 'to' => '2000-01-02', 'area' => 'POC-GESTAO']);
$check('D9 filtro de datas fora do período devolve vazio', count(Dashboard::build($f2)['rows']) === 0);
$f3 = Dashboard::filters(['range' => 'all', 'area' => 'POC-GESTAO', 'assignee' => $otherId]);
$check('D10 filtro por responsável', count(Dashboard::build($f3)['rows']) === 3);

// gráficos executivos
$ch = Dashboard::charts($d);
$openIds = array_sum(array_map(fn ($a) => count($a['ids']), $ch['aging']));
$check('G1 envelhecimento soma as demandas em aberto', $openIds === count($d['ids']['open']), $openIds . ' de ' . count($d['ids']['open']));
$check('G2 prioridade soma as demandas em aberto', array_sum(array_map(fn ($p) => count($p[1]), $ch['priority'])) === count($d['ids']['open']));
$check('G3 semanas sem lacunas e com as entradas do período', count($ch['weeks']) >= 1 && array_sum(array_column($ch['weeks'], 'entries')) === count($d['rows']));
$check('G4 taxa de conclusão = 1 de 4 (25%)', $ch['completion_pct'] === 25, (string) $ch['completion_pct']);
$check('G5 carga por responsável cobre todas as demandas', array_sum(array_map(fn ($a) => count($a['all']), $ch['assignees'])) === count($d['rows']));
$empty = Dashboard::charts(Dashboard::build($f2));
$check('G6 gráficos com período vazio não quebram', $empty['weeks'] === [] && $empty['completion_pct'] === null);

// comparativo e fluxo (funil e tempo por etapa)
$prev = Dashboard::previous(['from' => '2026-11-01', 'to' => '2026-11-10', 'range' => '', 'types_id' => 0, 'area' => '', 'assignee' => 0, 'status' => '']);
$check('X1 período anterior tem a mesma duração', $prev['from'] === '2026-10-22' && $prev['to'] === '2026-10-31', $prev['from'] . ' a ' . $prev['to']);
$check('X2 "Tudo" não tem período anterior', Dashboard::previous(['from' => '', 'to' => '2026-11-10']) === null);
$check('X3 variação percentual', Dashboard::delta(12, 10) === 20 && Dashboard::delta(5, 10) === -50 && Dashboard::delta(0, 0) === 0 && Dashboard::delta(5, 0) === 100 && Dashboard::delta(null, 3) === null);
$flow = Dashboard::flow($d);
$check('X4 funil conta quem chegou a cada etapa', array_column($flow['funnel'], 'value') === [4, 4, 3, 3, 1], implode(',', array_column($flow['funnel'], 'value')));
$check('X5 funil termina em 25% de conclusão', $flow['funnel'][4]['pct'] === 25 && $flow['funnel'][0]['pct'] === 100);
$check('X6 tempo por etapa só traz situações com histórico', count($flow['stages']) >= 1 && $flow['bottleneck'] !== null && $flow['bottleneck']['days'] >= 0);
$check('X7 fluxo com período vazio não quebra', Dashboard::flow(Dashboard::build($f2))['bottleneck'] === null);

// tema diurno/noturno por usuário, visão do quadro e peças da tabela principal
$check('TH1 tema padrão é automático', Settings::userTheme(987654) === 'auto');
Settings::setUserPref($me, 'theme', 'light');
$check('TH2 tema diurno gravado por usuário', Settings::userTheme($me) === 'light' && Settings::userTheme(987654) === 'auto');
Settings::setUserPref($me, 'theme', 'dark');
$check('TH3 tema noturno gravado', Settings::userTheme($me) === 'dark');
Settings::setUserPref($me, 'theme', 'invalido');
$check('TH4 valor inválido volta a automático', Settings::userTheme($me) === 'auto');
Settings::setUserPref($me, 'theme', $oldTheme ?? 'auto');
Settings::setUserPref($me, 'kanban_view', 'table');
$check('TH5 visão do quadro gravada', Settings::userPref($me, 'kanban_view', 'board') === 'table');
Settings::setUserPref($me, 'kanban_view', 'board');
$check('TB1 todos os estados têm progresso na linha do tempo', count(array_diff(array_keys(Demand::STATUSES), array_keys(Demand::PROGRESS))) === 0 && Demand::PROGRESS['done'] === 100);
$check('TB2 data curta no estilo "15 set"', Ui::shortDate('2026-09-15') === '15 set' && Ui::shortDate(null) === '—');
$check('TB3 avatar traz as iniciais e o sem responsável é vazio', str_contains(Ui::avatar($me), 'mkt-av') && str_contains(Ui::avatar(0), 'mkt-av-none'));

$pdf = ExecutiveReport::pdf($d, 'Teste');
$check('P1 PDF executivo gerado', str_starts_with($pdf, '%PDF') && strlen($pdf) > 6000, strlen($pdf) . ' bytes');
if (getenv('PDF_OUT')) {
    file_put_contents(getenv('PDF_OUT'), $pdf);
}
$empty = ExecutiveReport::pdf(Dashboard::build($f2), 'Teste');
$check('P2 PDF sem demandas também é gerado', str_starts_with($empty, '%PDF'));

// ---------------------------------------------------------------- limpeza
foreach ([$aid, $bid, $cid, $kid] as $id) {
    foreach ([Demand::getEvidencesTable(), Demand::getApprovalsTable(), Demand::getEventsTable(), Note::getTable(), Alert::getTable(), Outbox::getTable()] as $t) {
        $DB->delete($t, ['demands_id' => $id]);
    }
    $DB->delete(Demand::getTable(), ['id' => $id]);
}
$DB->delete(Automation::getTable(), ['id' => [$autoId, $autoN]]);
Settings::save(['poll_seconds' => $old['poll_seconds'], 'sound_enabled' => $old['sound_enabled'], 'popup_enabled' => $old['popup_enabled'], 'ev_assigned_popup' => $old['ev_assigned_popup'], 'ev_assigned_mail' => $old['ev_assigned_mail']]);
$check('limpeza: demandas de teste removidas', countElementsInTable(Demand::getTable(), ['area' => 'POC-GESTAO']) === 0);

echo "\n" . ($failures === 0 ? 'Todos os testes passaram.' : "$failures falha(s).") . "\n";
exit($failures === 0 ? 0 : 1);

<?php
/**
 * Testes da POC do plugin Marketing pela linha de comando (experimentos E2 a E4 de docs/POC-Fase0.md).
 * Remove o que criou ao final.
 *
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi11test php tests/poc.php
 *
 * Precisa do plugin instalado e ativo.
 */
$root = getenv('GLPI_ROOT') ?: '/var/www/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production');
$kernel->boot();
global $DB;

require_once $root . '/plugins/marketing/src/Base.php';
require_once $root . '/plugins/marketing/src/Access.php';
require_once $root . '/plugins/marketing/src/Demand.php';

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Demand;

$failures = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$failures) {
    echo ($ok ? 'OK   ' : 'FALHA ') . $name . ($detail !== '' ? " — $detail" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

// E2 — direito gravado para o perfil super-admin (instalação)
$row = $DB->request(['SELECT' => ['profiles_id', 'rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => Access::$rightname]]);
$rights = [];
foreach ($row as $r) {
    $rights[(int) $r['profiles_id']] = (int) $r['rights'];
}
$check('E2 direito plugin_marketing criado por perfil central', count($rights) > 0, count($rights) . ' perfis');
$check('E2 aba registrada (ProfileTab) para perfis central', class_exists(\GlpiPlugin\Marketing\ProfileTab::class));

// E3 — briefing incompleto não passa para planejamento (RF-004, CA-02)
$incomplete = ['objective' => 'Lançar campanha', 'audience' => '', 'deliverable' => 'Post', 'desired_date' => null];
$missing = Demand::missingInputs($incomplete);
$check('E3 campos vazios são listados', array_keys($missing) === ['audience', 'desired_date'], implode(', ', array_keys($missing)));
$check('E3 briefing incompleto não avança a Planejada', Demand::canPlan($incomplete) === false);
$complete = ['objective' => 'x', 'audience' => 'y', 'deliverable' => 'z', 'desired_date' => '2026-12-01'];
$check('E3 briefing completo libera planejamento', Demand::canPlan($complete) === true);

// E4 — criação com código único e trilha de criação (CA-01)
// Em linha de comando não há sessão web: simulamos o usuário e o perfil ativo na sessão
// (Session::init tenta iniciar sessão PHP e falha sem navegador).
$user = new User();
$user->getFromDBbyName('glpi');
$pu = $DB->request(['SELECT' => ['profiles_id'], 'FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $user->getID(), 'is_dynamic' => 0], 'LIMIT' => 1])->current();
$profileId = (int) ($pu['profiles_id'] ?? 0);
$_SESSION['glpiID'] = (int) $user->getID();
$_SESSION['glpiname'] = $user->fields['name'];
$_SESSION['glpiactive_entity'] = 0;
$_SESSION['glpiactiveentities'] = [0];
$_SESSION['glpiactiveentities_string'] = "'0'";
$_SESSION['glpiactiveprofile'] = ['id' => $profileId];
$_SESSION['glpiactiveprofile'][Access::$rightname] = (int) (ProfileRight::getProfileRights($profileId, [Access::$rightname])[Access::$rightname] ?? 0);

$demand = new Demand();
$code = 'MKT-POC-' . date('YmdHis');
$id = $demand->add([
    'name'               => 'POC — demanda de teste',
    'code'               => $code,
    'entities_id'        => 0,
    'requester_users_id' => $user->getID(),
    'objective'          => 'Teste de criação',
    'audience'           => 'Interno',
    'deliverable'        => 'Relatório',
    'desired_date'       => '2026-12-01',
    'status'             => Demand::STATUS_RECEIVED,
]);
$check('E4 demanda criada', $id !== false && $id > 0, "id=$id");
if ($id) {
    $demand->getFromDB($id);
    $check('E4 código gravado', $demand->fields['code'] === $code);
    $check('E4 prazo desejado separado de prazo confirmado', $demand->fields['desired_date'] === '2026-12-01' && $demand->fields['confirmed_date'] === null);
    $check('E4 trilha de criação', !empty($demand->fields['date_creation']));
    $demand->delete(['id' => $id], true);
}
$leftover = countElementsInTable(Demand::getTable(), ['code' => $code]);
$check('limpeza: demanda de teste removida', $leftover === 0);

// ---------------------------------------------------------------- E5 — vínculo com Project/ProjectTask nativos
$pid = $tid = $sid = 0;
$demand2 = new Demand();
$did = $demand2->add([
    'name' => 'POC — vínculo nativo', 'code' => 'MKT-POC5-' . date('YmdHis'), 'entities_id' => 0,
    'requester_users_id' => $user->getID(), 'status' => Demand::STATUS_PLANNED,
]);
$project = new Project();
$pid = $project->add(['name' => 'POC — projeto nativo', 'entities_id' => 0]);
$task = new ProjectTask();
$tid = $task->add(['name' => 'POC — tarefa', 'projects_id' => $pid, 'entities_id' => 0]);
$sub = new ProjectTask();
$sid = $sub->add(['name' => 'POC — subtarefa', 'projects_id' => $pid, 'projecttasks_id' => $tid, 'entities_id' => 0]);
$demand2->update(['id' => $did, 'projects_id' => $pid]);
$demand2->getFromDB($did);
$check('E5 demanda aponta para Project nativo', (int) $demand2->fields['projects_id'] === (int) $pid);
$check('E5 tarefa e subtarefa no Project', countElementsInTable(ProjectTask::getTable(), ['projects_id' => $pid]) === 2);
$sub->getFromDB($sid);
$check('E5 subtarefa aninhada na tarefa', (int) $sub->fields['projecttasks_id'] === (int) $tid);

// ---------------------------------------------------------------- E6 — direitos negam leitura/alteração sem permissão
$saved = $_SESSION['glpiactiveprofile'][Access::$rightname];
$_SESSION['glpiactiveprofile'][Access::$rightname] = 0;
$check('E6 sem direito: canView negado', Access::canView() === false);
$check('E6 sem direito: canUpdate negado', Access::canUpdate() === false);
$check('E6 sem direito: canCreate negado', Access::canCreate() === false);
$_SESSION['glpiactiveprofile'][Access::$rightname] = READ;
$check('E6 só leitura: canView liberado', Access::canView() === true);
$check('E6 só leitura: canUpdate negado', Access::canUpdate() === false);
$_SESSION['glpiactiveprofile'][Access::$rightname] = $saved;

// ---------------------------------------------------------------- E7 — SLA em dias úteis com Calendar nativo
$cal = new Calendar();
$calId = $cal->add(['name' => 'POC — calendário útil', 'entities_id' => 0]);
foreach ([1, 2, 3, 4, 5] as $day) {
    (new CalendarSegment())->add(['calendars_id' => $calId, 'day' => $day, 'begin' => '08:00:00', 'end' => '18:00:00']);
}
// sexta 13/11/2026 08:00 + 11 h úteis (delay em segundos) → segunda 16/11/2026 (pula sábado e domingo)
$cal->getFromDB($calId);
$endNoHoliday = $cal->computeEndDate('2026-11-13 08:00:00', 39600, 0, false, false);
$check('E7 pula fim de semana', str_starts_with((string) $endNoHoliday, '2026-11-16'), (string) $endNoHoliday);

$hol = new Holiday();
$holId = $hol->add(['name' => 'POC — feriado', 'entities_id' => 0, 'begin_date' => '2026-11-16', 'end_date' => '2026-11-16', 'is_perpetual' => 0]);
// usar a classe nativa (ela limpa o cache de feriados do calendário)
(new Calendar_Holiday())->add(['calendars_id' => $calId, 'holidays_id' => $holId]);
$cal->getFromDB($calId);
$check('E7 feriado gravado no calendário (isHoliday)', $holId > 0 && $cal->isHoliday('2026-11-16') === true, "holId=$holId");
$endHoliday =$cal->computeEndDate('2026-11-13 08:00:00', 39600, 0, false, false);
$check('E7 pula feriado cadastrado', str_starts_with((string) $endHoliday, '2026-11-17'), (string) $endHoliday);

// ---------------------------------------------------------------- E8 — aprovação nativa só existe para Ticket
$check('E8 TicketValidation é fixo em Ticket (não serve à demanda)', TicketValidation::$itemtype === Ticket::class);

// ---------------------------------------------------------------- limpeza dos dados de E5 a E7
$demand2->delete(['id' => $did], true);
ProjectTask::getTable();
$DB->delete(ProjectTask::getTable(), ['id' => [$sid, $tid]]);
$DB->delete(Project::getTable(), ['id' => $pid]);
$DB->delete('glpi_calendars_holidays', ['calendars_id' => $calId]);
$DB->delete('glpi_holidays', ['id' => $holId]);
$DB->delete('glpi_calendarsegments', ['calendars_id' => $calId]);
$DB->delete(Calendar::getTable(), ['id' => $calId]);
$check('limpeza E5–E7', countElementsInTable(Calendar::getTable(), ['id' => $calId]) === 0 && countElementsInTable(Project::getTable(), ['id' => $pid]) === 0);

echo "\n" . ($failures === 0 ? 'Todos os testes passaram.' : "$failures falha(s).") . "\n";
exit($failures === 0 ? 0 : 1);

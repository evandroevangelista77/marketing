<?php
/**
 * Testes da Fase 1 (MVP) do plugin Marketing pela linha de comando: regras de transição,
 * persistência com trilha de eventos e bloqueio de item sem direito (E6 no nível do item).
 * Remove o que criou ao final.
 *
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi11test php tests/fase1.php
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

// ---------------------------------------------------------------- regras puras de transição (RF-007, RF-004, RF-008)
$full = ['objective' => 'x', 'audience' => 'y', 'deliverable' => 'z', 'desired_date' => '2026-12-01', 'users_id_assigned' => 5];
$noBrief = ['objective' => 'x', 'audience' => '', 'deliverable' => 'z', 'desired_date' => '2026-12-01', 'users_id_assigned' => 5];
$noAssignee = $full;
$noAssignee['users_id_assigned'] = 0;

$check('T1 triagem → planejada com briefing e responsável', Demand::transitionError(Demand::STATUS_TRIAGE, Demand::STATUS_PLANNED, $full) === null);
$e = Demand::transitionError(Demand::STATUS_TRIAGE, Demand::STATUS_PLANNED, $noBrief);
$check('T2 briefing incompleto bloqueia planejamento', $e !== null && str_contains($e, 'Público'), (string) $e);
$e = Demand::transitionError(Demand::STATUS_TRIAGE, Demand::STATUS_PLANNED, $noAssignee);
$check('T3 sem responsável bloqueia planejamento', $e !== null && str_contains($e, 'responsável'), (string) $e);
$check('T4 recebida → concluída não é permitida', Demand::transitionError(Demand::STATUS_RECEIVED, Demand::STATUS_DONE, $full) !== null);
$check('T5 em andamento → concluída sem evidência e aceite é recusada (RN-05)', Demand::transitionError(Demand::STATUS_IN_PROGRESS, Demand::STATUS_DONE, $full) !== null);
$check('T5b em andamento → concluída com evidência e aceite é permitida', Demand::transitionError(Demand::STATUS_IN_PROGRESS, Demand::STATUS_DONE, $full + ['evidence_count' => 1, 'accepted_at' => '2026-11-09 10:00:00']) === null);
$check('T6 concluída não tem saída', Demand::transitionError(Demand::STATUS_DONE, Demand::STATUS_IN_PROGRESS, $full) !== null);
$check('T7 estado inexistente é recusado', Demand::transitionError(Demand::STATUS_TRIAGE, 'inventado', $full) !== null);

// ---------------------------------------------------------------- sessão simulada (como em tests/poc.php)
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
$rights = (int) (ProfileRight::getProfileRights($profileId, [Access::$rightname])[Access::$rightname] ?? 0);
$_SESSION['glpiactiveprofile'][Access::$rightname] = $rights;

// ---------------------------------------------------------------- persistência com trilha (RF-011)
$d = new Demand();
$did = $d->add([
    'name' => 'Fase 1 — teste', 'code' => 'MKT-T1-' . date('YmdHis'), 'entities_id' => 0,
    'requester_users_id' => $user->getID(), 'status' => Demand::STATUS_RECEIVED,
    'desired_date' => '2026-12-01',
]);
$d->getFromDB($did);
$check('P1 transição recebida → triagem gravada', $d->changeStatus(Demand::STATUS_TRIAGE, 'triagem') === null);
$d->getFromDB($did);
$check('P1 estado persistido', $d->fields['status'] === Demand::STATUS_TRIAGE);

$err = $d->changeStatus(Demand::STATUS_PLANNED, 'tentativa sem briefing');
$d->getFromDB($did);
$check('P2 planejamento recusado sem briefing', $err !== null && $d->fields['status'] === Demand::STATUS_TRIAGE, (string) $err);

$d->update(['id' => $did, 'objective' => 'Objetivo', 'audience' => 'Público', 'deliverable' => 'Entrega', 'users_id_assigned' => $user->getID()]);
$d->getFromDB($did);
$check('P3 planejamento liberado com briefing e responsável', $d->changeStatus(Demand::STATUS_PLANNED) === null);

$events = countElementsInTable(Demand::getEventsTable(), ['demands_id' => $did, 'event' => 'status']);
$check('P4 cada mudança aceita gera um evento (2 esperados)', $events === 2, "eventos=$events");
$last = $DB->request(['FROM' => Demand::getEventsTable(), 'WHERE' => ['demands_id' => $did], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
$check('P4 evento guarda de/para e usuário', ($last['old_value'] ?? '') === Demand::STATUS_TRIAGE && ($last['new_value'] ?? '') === Demand::STATUS_PLANNED && (int) ($last['users_id'] ?? 0) === (int) $user->getID());

// ---------------------------------------------------------------- E6 no nível do item
$_SESSION['glpiactiveprofile'][Access::$rightname] = 0;
$d->getFromDB($did);
$check('E6 item: sem direito não pode ver a demanda', $d->canViewItem() === false);
$_SESSION['glpiactiveprofile'][Access::$rightname] = $rights;
$d->getFromDB($did);
$check('E6 item: com direito pode ver a demanda', $d->canViewItem() === true);

// ---------------------------------------------------------------- limpeza
$DB->delete(Demand::getEventsTable(), ['demands_id' => $did]);
$d->delete(['id' => $did], true);
$check('limpeza: demanda e eventos removidos', countElementsInTable(Demand::getTable(), ['id' => $did]) === 0
    && countElementsInTable(Demand::getEventsTable(), ['demands_id' => $did]) === 0);

echo "\n" . ($failures === 0 ? 'Todos os testes passaram.' : "$failures falha(s).") . "\n";
exit($failures === 0 ? 0 : 1);

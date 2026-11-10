<?php
/**
 * Testes da Fase 1 (parte 2): evidências, aceite, aprovação e conclusão com exceção (RN-05, RF-012, RF-013).
 * Remove o que criou ao final.
 *
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi11test php tests/fase1b.php
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

// ---------------------------------------------------------------- regras puras (RN-05, RF-013)
$base = ['objective' => 'x', 'audience' => 'y', 'deliverable' => 'z', 'desired_date' => '2026-12-01', 'users_id_assigned' => 5];
$check('R1 em aprovação sem aprovador é recusado', Demand::transitionError(Demand::STATUS_IN_PROGRESS, Demand::STATUS_IN_APPROVAL, $base) !== null);
$check('R1 em aprovação com aprovador é permitido', Demand::transitionError(Demand::STATUS_IN_PROGRESS, Demand::STATUS_IN_APPROVAL, $base + ['users_id_approver' => 7]) === null);
$e = Demand::transitionError(Demand::STATUS_IN_PROGRESS, Demand::STATUS_DONE, $base);
$check('R2 concluir sem evidência e aceite é recusado', $e !== null && str_contains($e, 'evidência') && str_contains($e, 'aceite'), (string) $e);
$check('R2 concluir só com evidência ainda é recusado', Demand::transitionError(Demand::STATUS_IN_PROGRESS, Demand::STATUS_DONE, $base + ['evidence_count' => 1]) !== null);
$check('R2 concluir com evidência e aceite é permitido', Demand::transitionError(Demand::STATUS_IN_PROGRESS, Demand::STATUS_DONE, $base + ['evidence_count' => 1, 'accepted_at' => '2026-11-09 10:00:00']) === null);
$check('R2 exceção registrada libera a conclusão', Demand::transitionError(Demand::STATUS_IN_PROGRESS, Demand::STATUS_DONE, $base + ['exception_note' => 'Motivo']) === null);

// ---------------------------------------------------------------- sessão simulada (usuário glpi, super-administrador)
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
$me = (int) $user->getID();

$newDemand = function (string $tag) use ($me): Demand {
    $d = new Demand();
    $id = $d->add([
        'name' => 'Fase 1b — ' . $tag, 'code' => 'MKT-T2-' . $tag . date('YmdHis'), 'entities_id' => 0,
        'requester_users_id' => $me, 'status' => Demand::STATUS_RECEIVED, 'desired_date' => '2026-12-01',
    ]);
    $d->getFromDB($id);
    return $d;
};

// ---------------------------------------------------------------- fluxo completo: evidência, aprovação, aceite e conclusão
$d = $newDemand('fluxo');
$did = (int) $d->getID();
$DB->update(Demand::getTable(), ['users_id_assigned' => $me, 'objective' => 'x', 'audience' => 'y', 'deliverable' => 'z', 'users_id_approver' => $me], ['id' => $did]);
$d->getFromDB($did);
$d->changeStatus(Demand::STATUS_TRIAGE);
$d->changeStatus(Demand::STATUS_PLANNED);
$d->changeStatus(Demand::STATUS_IN_PROGRESS);
$d->getFromDB($did);

$err = $d->changeStatus(Demand::STATUS_DONE, 'sem evidência');
$d->getFromDB($did);
$check('F1 conclusão sem evidência e aceite recusada', $err !== null && $d->fields['status'] === Demand::STATUS_IN_PROGRESS, (string) $err);

$d->addEvidence('link', 'Post publicado', 'https://example.com/post', 'print anexado');
$check('F2 evidência registrada', countElementsInTable(Demand::getEvidencesTable(), ['demands_id' => $did]) === 1);

$d->getFromDB($did);
$check('F3 aceite pelo solicitante registrado', $d->acceptDelivery() === null);
$d->getFromDB($did);
$check('F3 aceite gravado com data', !empty($d->fields['accepted_at']));
$check('F3 segundo aceite recusado', $d->acceptDelivery() !== null);

$d->getFromDB($did);
$check('F4 conclusão com evidência e aceite permitida', $d->changeStatus(Demand::STATUS_DONE, 'entregue') === null);

// aprovação em outra demanda, para não misturar com a conclusão acima
$a = $newDemand('aprov');
$aid = (int) $a->getID();
$DB->update(Demand::getTable(), ['users_id_assigned' => $me, 'objective' => 'x', 'audience' => 'y', 'deliverable' => 'z', 'users_id_approver' => $me], ['id' => $aid]);
$a->getFromDB($aid);
$a->changeStatus(Demand::STATUS_TRIAGE);
$a->changeStatus(Demand::STATUS_PLANNED);
$a->changeStatus(Demand::STATUS_IN_PROGRESS);
$a->getFromDB($aid);
$a->changeStatus(Demand::STATUS_IN_APPROVAL);
$a->requestApproval();
$a->getFromDB($aid);
$check('F5 em aprovação com aprovador e aprovação aberta', $a->fields['status'] === Demand::STATUS_IN_APPROVAL
    && countElementsInTable(Demand::getApprovalsTable(), ['demands_id' => $aid, 'status' => 'waiting']) === 1);
$check('F6 decisão de quem não é o aprovador indicado recusada', (function () use ($a, $DB, $aid, $me) {
    // a aprovação aberta passa a ser de outra pessoa; quem decide agora não tem nada pendente
    $DB->update(Demand::getApprovalsTable(), ['users_id_approver' => 999999], ['demands_id' => $aid, 'status' => 'waiting']);
    $r = $a->decide('accepted', '');
    $DB->update(Demand::getApprovalsTable(), ['users_id_approver' => $me], ['demands_id' => $aid, 'status' => 'waiting']);
    return $r !== null;
})());
$check('F7 aprovação registrada volta a em andamento', $a->decide('accepted', 'ok') === null);
$a->getFromDB($aid);
$check('F7 situação volta a em andamento e aprovação fechada', $a->fields['status'] === Demand::STATUS_IN_PROGRESS
    && countElementsInTable(Demand::getApprovalsTable(), ['demands_id' => $aid, 'status' => 'accepted']) === 1);

// ---------------------------------------------------------------- conclusão com exceção (só aprovador)
$x = $newDemand('excecao');
$xid = (int) $x->getID();
$DB->update(Demand::getTable(), ['users_id_assigned' => $me, 'objective' => 'x', 'audience' => 'y', 'deliverable' => 'z'], ['id' => $xid]);
$x->getFromDB($xid);
$x->changeStatus(Demand::STATUS_TRIAGE);
$x->changeStatus(Demand::STATUS_PLANNED);
$x->changeStatus(Demand::STATUS_IN_PROGRESS);
$x->getFromDB($xid);
$check('F8 exceção sem justificativa recusada', $x->completeWithException('') !== null);
$x->getFromDB($xid);
$check('F8 exceção com justificativa conclui', $x->completeWithException('Cliente dispensou evidência') === null);
$x->getFromDB($xid);
$check('F8 exceção gravada na demanda', $x->fields['status'] === Demand::STATUS_DONE && str_contains((string) $x->fields['exception_note'], 'dispensou'));

// ---------------------------------------------------------------- limpeza
foreach ([$did, $aid, $xid] as $id) {
    $DB->delete(Demand::getEvidencesTable(), ['demands_id' => $id]);
    $DB->delete(Demand::getApprovalsTable(), ['demands_id' => $id]);
    $DB->delete(Demand::getEventsTable(), ['demands_id' => $id]);
    $DB->delete(Demand::getTable(), ['id' => $id]);
}
$check('limpeza: demandas de teste removidas', countElementsInTable(Demand::getTable(), ['id' => [$did, $aid, $xid]]) === 0);

echo "\n" . ($failures === 0 ? 'Todos os testes passaram.' : "$failures falha(s).") . "\n";
exit($failures === 0 ? 0 : 1);

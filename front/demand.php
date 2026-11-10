<?php

/**
 * Ficha da demanda: cadastro, triagem (responsável, aprovador, prazo confirmado, prioridade), mudança de situação,
 * evidências, aceite, aprovação, conclusão com exceção, atualizações (comentários) e trilha de eventos.
 */

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Demand;
use GlpiPlugin\Marketing\Note;
use GlpiPlugin\Marketing\Settings;
use GlpiPlugin\Marketing\Type;
use GlpiPlugin\Marketing\Ui;

Session::checkLoginUser();
global $DB;

$id = (int) ($_GET['id'] ?? 0);
$demand = new Demand();

if ($id === 0) {
    if (!Access::canCreate()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
} elseif (!$demand->getFromDB($id) || !$demand->canViewItem()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$f = fn ($v) => Ui::e($v);
$fields = $demand->fields ?? [];
$action = Ui::url('/front/action.php');
$csrf = Ui::csrf();
$canEdit = $id === 0 ? Access::canCreate() : (Access::canUpdate() || $demand->canComplement());
$me = (int) Session::getLoginUserID();
$isRequester = $id > 0 && (int) ($fields['requester_users_id'] ?? 0) === $me;
$closed = in_array($fields['status'] ?? '', [Demand::STATUS_DONE, Demand::STATUS_CANCELLED], true);
$colors = Settings::statusColors();
$types = Type::all(true);
$users = Ui::userOptions();

/** Cabeça de um formulário POST para action.php. */
$open = fn (string $do, string $cls = '') => "<form method='post' action='" . $f($action) . "' class='$cls'>" . $csrf . "<input type='hidden' name='do' value='" . $f($do) . "'><input type='hidden' name='id' value='$id'>";

Ui::header($id === 0 ? 'Nova demanda' : (string) $fields['code'], 'demands');
echo Ui::title(
    $id === 0 ? 'Nova demanda' : $fields['code'] . ' — ' . $fields['name'],
    $id === 0 ? 'Descreva o pedido. O envio não implica aceite nem confirmação de prazo (RN-01).' : '',
    $id > 0 ? Ui::badge(Demand::STATUSES[$fields['status']] ?? $fields['status'], $colors[$fields['status']] ?? '#94a3b8') : ''
);

// ---------------------------------------------------------------- formulário
echo $open('save', 'card card-body mb-3');
$dis = $canEdit ? '' : ' disabled';
$text = fn (string $name, string $label) => "<div class='mb-3'><label class='form-label'>" . $f($label) . "</label><textarea class='form-control' name='$name' rows='2'$dis>" . $f($fields[$name] ?? '') . "</textarea></div>";
$line = fn (string $name, string $label, string $type = 'text') => "<div class='mb-3'><label class='form-label'>" . $f($label) . "</label><input class='form-control' type='$type' name='$name' value='" . $f($fields[$name] ?? '') . "'$dis></div>";

echo "<div class='row'><div class='col-md-5'>" . $line('name', 'Título *') . "</div>";
echo "<div class='col-md-3 mb-3'><label class='form-label'>Tipo</label>" . Ui::select('types_id', array_map(fn ($t) => $t['name'], $types), $fields['types_id'] ?? 0, $dis, true, 'Sem tipo') . "</div>";
echo "<div class='col-md-2'>" . $line('area', 'Área solicitante') . "</div>";
echo "<div class='col-md-2'>" . $line('desired_date', 'Prazo desejado', 'date') . "</div></div>";
echo $text('objective', 'Objetivo');
echo "<div class='row'><div class='col-md-6'>" . $text('audience', 'Público') . "</div><div class='col-md-6'>" . $text('deliverable', 'Entrega') . "</div></div>";
echo $text('context', 'Contexto');

if ($id > 0 && Access::canUpdate()) {
    echo "<hr><h4>Triagem</h4><div class='row'>";
    echo "<div class='col-md-3 mb-3'><label class='form-label'>Responsável</label>" . Ui::select('users_id_assigned', $users, $fields['users_id_assigned'] ?? 0, '', true, 'Sem responsável') . "</div>";
    echo "<div class='col-md-3 mb-3'><label class='form-label'>Aprovador (RF-013)</label>" . Ui::select('users_id_approver', $users, $fields['users_id_approver'] ?? 0, '', true, 'Sem aprovador') . "</div>";
    echo "<div class='col-md-3'>" . $line('confirmed_date', 'Prazo confirmado', 'date') . "</div>";
    echo "<div class='col-md-3 mb-3'><label class='form-label'>Prioridade</label>" . Ui::select('priority', Demand::PRIORITIES, $fields['priority'] ?? 3) . "</div></div>";
    $missing = Demand::missingInputs($fields);
    if ($missing && in_array($fields['status'], [Demand::STATUS_RECEIVED, Demand::STATUS_TRIAGE, Demand::STATUS_WAITING_REQUESTER], true)) {
        echo "<div class='alert alert-warning py-2'>Para planejar faltam: <b>" . $f(implode(', ', $missing)) . "</b>.</div>";
    }
}
if ($canEdit) {
    echo "<div><button class='btn btn-primary'>Salvar</button></div>";
}
echo "</form>";

if ($id > 0) {
    // ------------------------------------------------------------ mudança de situação (RF-007)
    if (Access::canUpdate()) {
        $next = Demand::TRANSITIONS[$fields['status']] ?? [];
        echo "<div class='card card-body mb-3'><h4>Mudar situação</h4>";
        if (!$next) {
            echo "<p class='text-secondary mb-0'>Situação final: sem transições.</p>";
        } else {
            echo $open('transition', 'd-flex flex-wrap gap-2 align-items-end');
            echo "<div class='flex-grow-1'><label class='form-label'>Nota (opcional)</label><input class='form-control' name='note'></div>";
            echo "<div><label class='form-label'>Para</label><select class='form-select' name='to'>";
            foreach ($next as $to) {
                echo "<option value='" . $f($to) . "'>" . $f(Demand::STATUSES[$to]) . "</option>";
            }
            echo "</select></div><button class='btn btn-outline-primary'>Aplicar</button></form>";
        }
        echo "</div>";
    }

    // ------------------------------------------------------------ evidências (RF-012)
    $evidences = iterator_to_array($DB->request(['FROM' => Demand::getEvidencesTable(), 'WHERE' => ['demands_id' => $id], 'ORDER' => 'id ASC']), false);
    echo "<div class='card card-body mb-3'><h4>Evidências da entrega</h4>";
    if (!$evidences) {
        echo "<p class='text-secondary'>Nenhuma evidência registrada.</p>";
    }
    foreach ($evidences as $ev) {
        $label = $ev['url'] ? "<a href='" . $f($ev['url']) . "' target='_blank' rel='noopener'>" . $f($ev['name']) . "</a>" : $f($ev['name']);
        echo "<div class='mb-1'><span class='badge bg-secondary me-1'>" . $f($ev['kind']) . "</span>$label " . $f($ev['comment']) . "</div>";
    }
    if (Access::canUpdate() && !$closed) {
        echo $open('evidence', 'row g-2 mt-2');
        echo "<div class='col-md-2'><select class='form-select' name='kind'><option value='link'>Link</option><option value='note'>Nota</option></select></div>";
        echo "<div class='col-md-3'><input class='form-control' name='name' placeholder='Nome' required></div>";
        echo "<div class='col-md-3'><input class='form-control' name='url' placeholder='URL (opcional)'></div>";
        echo "<div class='col-md-3'><input class='form-control' name='comment' placeholder='Comentário'></div>";
        echo "<div class='col-md-1'><button class='btn btn-outline-primary w-100'>Adicionar</button></div></form>";
    }
    echo "</div>";

    // ------------------------------------------------------------ aceite do solicitante (RN-05)
    if ($isRequester && !$closed && empty($fields['accepted_at'])) {
        echo "<div class='card card-body mb-3'><h4>Aceite da entrega</h4>" . $open('accept') . "<button class='btn btn-success'>Aceitar entrega</button></form></div>";
    } elseif (!empty($fields['accepted_at'])) {
        echo "<p class='text-secondary'>Entrega aceita em " . $f(Ui::datetime($fields['accepted_at'])) . ".</p>";
    }

    // ------------------------------------------------------------ aprovação (RF-013)
    $approvals = iterator_to_array($DB->request(['FROM' => Demand::getApprovalsTable(), 'WHERE' => ['demands_id' => $id], 'ORDER' => 'id DESC']), false);
    if ($approvals || ($fields['status'] ?? '') === Demand::STATUS_IN_APPROVAL) {
        echo "<div class='card card-body mb-3'><h4>Aprovação</h4>";
        foreach ($approvals as $ap) {
            echo "<div class='mb-1'>" . $f(getUserName((int) $ap['users_id_approver'])) . ": <b>" . $f($ap['status']) . "</b> " . $f($ap['comment']) . "</div>";
        }
        $pending = $DB->request(['FROM' => Demand::getApprovalsTable(), 'WHERE' => ['demands_id' => $id, 'status' => 'waiting', 'users_id_approver' => $me], 'LIMIT' => 1])->current();
        if ($pending && Access::canApprove()) {
            echo $open('decide', 'row g-2 mt-2');
            echo "<div class='col-md-3'><select class='form-select' name='decision'><option value='accepted'>Aprovar</option><option value='refused'>Recusar</option></select></div>";
            echo "<div class='col-md-7'><input class='form-control' name='comment' placeholder='Comentário da decisão'></div>";
            echo "<div class='col-md-2'><button class='btn btn-primary w-100'>Registrar</button></div></form>";
        }
        echo "</div>";
    }

    // ------------------------------------------------------------ conclusão com exceção (RN-05)
    if (Access::canApprove() && !$closed && !in_array($fields['status'], [Demand::STATUS_RECEIVED, Demand::STATUS_TRIAGE], true)) {
        echo "<div class='card card-body mb-3'><h4>Concluir com exceção</h4><p class='text-secondary small mb-2'>Use só quando faltar evidência ou aceite. A justificativa fica registrada.</p>";
        echo $open('exception', 'd-flex gap-2') . "<input class='form-control' name='note' placeholder='Justificativa' required><button class='btn btn-outline-danger'>Concluir</button></form></div>";
    }

    // ------------------------------------------------------------ atualizações (estilo monday)
    echo "<div class='card card-body mb-3'><h4>Atualizações</h4>";
    echo $open('note', 'mb-3') . "<textarea class='form-control' name='body' rows='2' placeholder='Escreva uma atualização para o responsável e o solicitante…' required></textarea><button class='btn btn-primary btn-sm mt-2'>Publicar atualização</button></form>";
    $notes = Note::forDemand($id);
    if (!$notes) {
        echo "<p class='text-secondary mb-0'>Nenhuma atualização.</p>";
    }
    foreach ($notes as $n) {
        $who = $n['kind'] === 'system' ? 'Sistema' : getUserName((int) $n['users_id']);
        echo "<div class='mkt-feed-item " . ($n['kind'] === 'system' ? 'system' : '') . "'><div class='d-flex justify-content-between'><span class='who'>" . $f($who) . "</span><span class='when'>" . $f(Ui::datetime($n['date_creation'])) . "</span></div>"
            . "<div>" . nl2br($f($n['body'])) . "</div></div>";
    }
    echo "</div>";

    // ------------------------------------------------------------ trilha (RF-011)
    echo "<div class='card'><div class='card-header'><h3 class='card-title'>Histórico</h3></div><div class='table-responsive'>";
    echo "<table class='table card-table'><thead><tr><th>Data</th><th>Evento</th><th>De</th><th>Para</th><th>Usuário</th><th>Nota</th></tr></thead><tbody>";
    $events = $DB->request(['FROM' => Demand::getEventsTable(), 'WHERE' => ['demands_id' => $id], 'ORDER' => 'id DESC', 'LIMIT' => 200]);
    $any = false;
    foreach ($events as $ev) {
        $any = true;
        $shown = fn (string $v) => $ev['event'] === 'assign' ? ((int) $v > 0 ? getUserName((int) $v) : '—') : (Demand::STATUSES[$v] ?? $v);
        echo "<tr><td>" . $f(Ui::datetime($ev['date_creation'])) . "</td><td>" . $f($ev['event']) . "</td><td>" . $f($shown((string) $ev['old_value'])) . "</td>"
            . "<td>" . $f($shown((string) $ev['new_value'])) . "</td><td>" . $f(getUserName((int) $ev['users_id'])) . "</td><td>" . $f($ev['note']) . "</td></tr>";
    }
    if (!$any) {
        echo "<tr><td colspan='6' class='text-center text-secondary'>Sem eventos.</td></tr>";
    }
    echo "</tbody></table></div></div>";
}

Html::footer();

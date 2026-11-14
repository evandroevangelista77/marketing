<?php

/**
 * Ficha da demanda: cadastro, triagem (responsável, aprovador, prazo confirmado, prioridade), mudança de situação,
 * evidências, aceite, aprovação, conclusão com exceção, atualizações (comentários) e trilha de eventos.
 */

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Demand;
use GlpiPlugin\Marketing\Note;
use GlpiPlugin\Marketing\Settings;
use GlpiPlugin\Marketing\Structure;
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
$isInternal = $id > 0 && ($fields['origin'] ?? 'external') === Demand::ORIGIN_INTERNAL;
$colors = Settings::statusColors();
$types = Type::all(true);
$users = Ui::userOptions();

/** Cabeça de um formulário POST para action.php. */
$open = fn (string $do, string $cls = '') => "<form method='post' action='" . $f($action) . "' class='$cls'>" . $csrf . "<input type='hidden' name='do' value='" . $f($do) . "'><input type='hidden' name='id' value='$id'>";

Ui::header($id === 0 ? 'Nova demanda' : (string) $fields['code'], 'demands');
echo Ui::title(
    $id === 0 ? 'Nova demanda' : $fields['code'] . ' — ' . $fields['name'],
    $id === 0 ? 'Descreva o pedido. O envio não implica aceite nem confirmação de prazo (RN-01).' : '',
    $id > 0 ? Ui::originBadge((string) ($fields['origin'] ?? 'external')) . ' ' . Ui::badge(Demand::STATUSES[$fields['status']] ?? $fields['status'], $colors[$fields['status']] ?? '#94a3b8') . Ui::postponedBadge($fields) : ''
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
    echo "<div class='row'><div class='col-md-3 mb-3'><label class='form-label'>Execução efetiva (%)</label><input class='form-control' type='number' min='0' max='100' step='5' name='exec_percent' value='" . $f($fields['exec_percent'] ?? '') . "' placeholder='0 a 100'><div class='form-text'>Quanto do trabalho já foi feito. Concluída vale 100%.</div></div>"
        . "<div class='col-md-9 mb-3'><label class='form-label'>Justificativa da reprogramação do prazo</label><input class='form-control' name='reschedule_reason' placeholder='Obrigatória ao trocar um prazo confirmado por outro; fica no histórico e avisa a Gerência'>"
        . (!empty($fields['original_date']) ? "<div class='form-text'>Prazo original: <b>" . $f(Ui::date($fields['original_date'])) . "</b> · reprogramações: " . (int) $fields['postponed_count'] . "</div>" : '') . "</div></div>";
    $missing = Demand::missingInputs($fields);
    if (!$isInternal && $missing && in_array($fields['status'], [Demand::STATUS_RECEIVED, Demand::STATUS_TRIAGE, Demand::STATUS_WAITING_REQUESTER], true)) {
        echo "<div class='alert alert-warning py-2'>Para planejar faltam: <b>" . $f(implode(', ', $missing)) . "</b>.</div>";
    }
}
if ($canEdit) {
    echo "<div><button class='btn btn-primary'>Salvar</button></div>";
}
echo "</form>";

if ($id > 0) {
    // ------------------------------------------------------------ pilar, projeto e plano de ação
    $canLink = Access::canUpdate() || Access::canInternal();
    $pillar = Structure::pillars()[(int) $fields['pillars_id']] ?? null;
    $proj = Structure::projects()[(int) $fields['mkt_projects_id']] ?? null;
    $plan = Structure::plans()[(int) $fields['plans_id']] ?? null;
    $parent = null;
    if ((int) $fields['parent_id'] > 0) {
        $pd = new Demand();
        $parent = $pd->getFromDB((int) $fields['parent_id']) ? $pd->fields : null;
    }
    echo "<div class='card card-body mb-3'><h4>Pilar, projeto e plano de ação</h4>";
    echo "<div class='mb-2 small'>"
        . ($pillar ? Ui::badge($pillar['name'], $pillar['color']) : "<span class='text-secondary'>Sem pilar</span>") . " → "
        . ($proj ? "<a href='" . $f(Ui::url('/front/project.php?id=' . (int) $proj['id'])) . "'>" . $f($proj['name']) . "</a>" : "<span class='text-secondary'>sem projeto (avulsa)</span>") . " → "
        . ($plan ? "<a href='" . $f(Ui::url('/front/plan.php?id=' . (int) $plan['id'])) . "'>" . $f($plan['name']) . "</a>" : "<span class='text-secondary'>sem plano</span>")
        . ($parent ? " · subtarefa de <a href='" . $f(Ui::url('/front/demand.php?id=' . (int) $parent['id'])) . "'>" . $f($parent['code']) . "</a>" : '') . "</div>";
    if ($canLink) {
        $dependsOptions = [];
        foreach ($DB->request(['SELECT' => ['id', 'code', 'name'], 'FROM' => Demand::getTable(), 'WHERE' => Demand::restrict(['is_deleted' => 0, 'NOT' => ['id' => $id]]), 'ORDER' => 'id DESC', 'LIMIT' => 300]) as $r) {
            $dependsOptions[(int) $r['id']] = $r['code'] . ' · ' . $r['name'];
        }
        echo $open('link_save');
        echo Ui::structureSelects($fields);
        echo "<div class='row'><div class='col-md-3 mb-3'><label class='form-label'>Data inicial</label><input class='form-control' type='date' name='start_date' value='" . $f($fields['start_date'] ?? '') . "'></div>"
            . "<div class='col-md-6 mb-3'><label class='form-label'>Depende de</label>" . Ui::select('depends_on_id', $dependsOptions, $fields['depends_on_id'] ?? 0, '', true, 'Nenhuma dependência') . "</div>"
            . "<div class='col-md-3 mb-3 d-flex align-items-end'><button class='btn btn-outline-primary w-100'>Salvar vínculos</button></div></div></form>";
    }
    echo "</div>";

    // ------------------------------------------------------------ plano de ação e subtarefas
    $children = Structure::activities(['parent_id' => $id]);
    if ($plan || $children) {
        echo "<div class='card card-body mb-3'><h4>Atividades do plano" . ($plan ? ' · ' . $f($plan['name']) : '') . "</h4>";
        echo "<div class='mb-2'>" . Ui::bar(Structure::progress($children)) . "</div><ul class='mkt-tree'>";
        foreach ($children as $c) {
            echo "<li class='d-flex flex-wrap gap-2 align-items-center'><a href='" . $f(Ui::url('/front/demand.php?id=' . (int) $c['id'])) . "'>" . $f($c['code']) . "</a><span>" . $f($c['name']) . "</span>"
                . Ui::badge(Demand::STATUSES[$c['status']] ?? $c['status'], $colors[$c['status']] ?? '#94a3b8') . Ui::avatar((int) $c['users_id_assigned'])
                . "<span class='text-secondary small ms-auto'>" . $f(Ui::date(Demand::deadline($c))) . "</span></li>";
        }
        echo "</ul>";
        if (!$children) {
            echo "<p class='text-secondary mb-2'>Nenhuma atividade ainda.</p>";
        }
        if (Access::canInternal() && !$closed) {
            echo $open('subtask_create', 'row g-2 mt-1') . "<div class='col-md-5'><input class='form-control' name='name' placeholder='Nova atividade / subtarefa' required></div>"
                . "<div class='col-md-3'>" . Ui::select('users_id_assigned', $users, $fields['users_id_assigned'] ?? 0, '', true, 'Sem responsável') . "</div>"
                . "<div class='col-md-2'><input class='form-control' type='date' name='confirmed_date'></div><div class='col-md-2'><button class='btn btn-outline-primary w-100'>Adicionar</button></div></form>";
        }
        echo "<div class='text-secondary small mt-2'>A conclusão desta demanda só é permitida quando todas as atividades estiverem concluídas ou canceladas" . ($isInternal ? '.' : ', além da evidência e do aceite do solicitante.') . "</div></div>";
    } elseif (Access::canInternal() && !$closed && !$isInternal
        && !in_array($fields['status'], [Demand::STATUS_RECEIVED, Demand::STATUS_TRIAGE, Demand::STATUS_WAITING_REQUESTER], true)) {
        // demanda aceita e planejada: pode seguir como execução simples (uma atividade) ou virar um plano de ação
        echo "<details class='card card-body mb-3'><summary class='fw-bold'><i class='ti ti-hierarchy-2 me-1'></i>Criar plano de ação</summary>"
            . "<p class='text-secondary small mt-2'>O plano mantém o código <b>" . $f($fields['code']) . "</b>, o briefing e as decisões desta solicitação. Use quando a entrega exigir várias atividades; para uma entrega simples, siga sem plano.</p>"
            . $open('plan_create');
        echo "<div class='row'><div class='col-md-6 mb-3'><label class='form-label'>Nome do plano</label><input class='form-control' name='name' value='" . $f($fields['name']) . "'></div>"
            . "<div class='col-md-3 mb-3'><label class='form-label'>Responsável principal</label>" . Ui::select('users_id', $users, $fields['users_id_assigned'] ?? 0, '', true, 'Responsável da demanda') . "</div>"
            . "<div class='col-md-3 mb-3'><label class='form-label'>Projeto</label>" . Ui::select('mkt_projects_id', Structure::projectOptions(), $fields['mkt_projects_id'] ?? 0, '', true, 'Sem projeto') . "</div></div>";
        echo "<div class='row'><div class='col-md-3 mb-3'><label class='form-label'>Início</label><input class='form-control' type='date' name='start_date'></div>"
            . "<div class='col-md-3 mb-3'><label class='form-label'>Fim previsto</label><input class='form-control' type='date' name='end_date' value='" . $f($fields['confirmed_date'] ?? '') . "'></div>"
            . "<div class='col-md-6 mb-3'><label class='form-label'>Critérios de conclusão</label><input class='form-control' name='criteria'></div></div>";
        echo "<div class='mb-3'><label class='form-label'>Atividades (uma por linha; opcional <code>título | AAAA-MM-DD</code> para o prazo)</label><textarea class='form-control' name='activities' rows='5' placeholder='Validar ICP e oferta | 2027-02-10&#10;Produzir peças&#10;Configurar mídia e tracking&#10;Executar QA'></textarea></div>"
            . "<button class='btn btn-primary'>Criar plano de ação</button></form></details>";
    }

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

    // ------------------------------------------------------------ reprogramações de prazo
    $resched = iterator_to_array($DB->request(['FROM' => Demand::reschedulesTable(), 'WHERE' => ['demands_id' => $id], 'ORDER' => 'id DESC']), false);
    if ($resched) {
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Reprogramações de prazo " . Ui::postponedBadge($fields) . "</h3></div><div class='table-responsive'><table class='table card-table'><thead><tr><th>Quando</th><th>De</th><th>Para</th><th>Quem</th><th>Justificativa</th></tr></thead><tbody>";
        foreach ($resched as $r) {
            $later = $r['new_date'] > $r['old_date'];
            echo "<tr><td>" . $f(Ui::datetime($r['date_creation'])) . "</td><td>" . $f(Ui::date($r['old_date'])) . "</td><td><b class='" . ($later ? 'text-danger' : 'text-success') . "'>" . $f(Ui::date($r['new_date'])) . "</b> <span class='small text-secondary'>" . ($later ? 'adiado' : 'antecipado') . "</span></td><td>" . $f(getUserName((int) $r['users_id'])) . "</td><td>" . nl2br($f($r['reason'])) . "</td></tr>";
        }
        echo "</tbody></table></div></div>";
    }

    // ------------------------------------------------------------ entregáveis: envio e download
    $files = iterator_to_array($DB->request(['FROM' => Demand::filesTable(), 'WHERE' => ['demands_id' => $id], 'ORDER' => 'id ASC']), false);
    $canFiles = (Access::canUpdate() || Access::canInternal()) && !$closed;
    echo "<div class='card card-body mb-3'><h4>Entregáveis (arquivos)</h4>";
    if (!$files) {
        echo "<p class='text-secondary'>Nenhum arquivo enviado.</p>";
    }
    $fmt = fn (int $b) => $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : number_format(max(1, $b / 1024), 0, ',', '.') . ' KB';
    foreach ($files as $fl) {
        echo "<div class='d-flex flex-wrap align-items-center gap-2 py-1 border-bottom'><i class='ti ti-file'></i>"
            . "<a href='" . $f(Ui::url('/front/download.php?id=' . (int) $fl['id'])) . "'><b>" . $f($fl['name']) . "</b></a><span class='text-secondary small'>" . $f($fmt((int) $fl['size'])) . " · " . $f(getUserName((int) $fl['users_id'])) . " · " . $f(Ui::datetime($fl['date_creation'])) . "</span>"
            . ($fl['comment'] ? "<span class='small'>" . $f($fl['comment']) . "</span>" : '') . "<span class='text-secondary small' title='SHA-256: " . $f($fl['sha256']) . "'><i class='ti ti-fingerprint'></i></span>";
        if ($canFiles) {
            echo "<span class='ms-auto'>" . $open('file_remove', 'd-inline') . "<input type='hidden' name='file' value='" . (int) $fl['id'] . "'><button class='btn btn-sm btn-outline-danger' onclick=\"return confirm('Remover este entregável?')\"><i class='ti ti-trash'></i></button></form></span>";
        }
        echo "</div>";
    }
    if ($canFiles) {
        echo "<form method='post' action='" . $f($action) . "' enctype='multipart/form-data' class='row g-2 mt-2'>" . $csrf . "<input type='hidden' name='do' value='file_upload'><input type='hidden' name='id' value='$id'>"
            . "<div class='col-md-6'><input class='form-control' type='file' name='files[]' multiple required></div>"
            . "<div class='col-md-4'><input class='form-control' name='comment' placeholder='Comentário (opcional)'></div>"
            . "<div class='col-md-2'><button class='btn btn-outline-primary w-100'><i class='ti ti-upload me-1'></i>Enviar</button></div>"
            . "<div class='form-text'>Até " . round(Demand::uploadLimit() / 1048576) . " MB por envio. Não são aceitos arquivos executáveis nem páginas (.html, .js, .php, .svg…): compacte em .zip ou registre um link em Evidências. Os arquivos contam como evidência da entrega.</div></form>";
    }
    echo "</div>";

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
    if ($isInternal) {
        // atividade do Marketing não tem solicitante externo: sem aceite
    } elseif ($isRequester && !$closed && empty($fields['accepted_at'])) {
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
        $shown = fn (string $v) => $ev['event'] === 'assign' ? ((int) $v > 0 ? getUserName((int) $v) : '—') : (in_array($ev['event'], ['reschedule', 'deadline'], true) ? ($v !== '' ? Ui::date($v) : '—') : (Demand::STATUSES[$v] ?? $v));
        echo "<tr><td>" . $f(Ui::datetime($ev['date_creation'])) . "</td><td>" . $f($ev['event']) . "</td><td>" . $f($shown((string) $ev['old_value'])) . "</td>"
            . "<td>" . $f($shown((string) $ev['new_value'])) . "</td><td>" . $f(getUserName((int) $ev['users_id'])) . "</td><td>" . $f($ev['note']) . "</td></tr>";
    }
    if (!$any) {
        echo "<tr><td colspan='6' class='text-center text-secondary'>Sem eventos.</td></tr>";
    }
    echo "</tbody></table></div></div>";
}

Html::footer();

<?php

/**
 * Configurações da Gestão de Demandas: tipos e campos obrigatórios, cores dos status, SLOs, notificações
 * (pop-up, som, e-mail por evento) e automações no estilo monday. Exige o direito "Configurar".
 */

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Automation;
use GlpiPlugin\Marketing\Demand;
use GlpiPlugin\Marketing\Outbox;
use GlpiPlugin\Marketing\Settings;
use GlpiPlugin\Marketing\Slo;
use GlpiPlugin\Marketing\Structure;
use GlpiPlugin\Marketing\Type;
use GlpiPlugin\Marketing\Ui;

Session::checkLoginUser();
if (!Access::canConfigure()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
global $DB;

$e = fn ($v) => Ui::e($v);
$tabs = ['demandas' => 'Demandas e tipos', 'estrutura' => 'Pilares', 'status' => 'Status e cores', 'slos' => 'SLOs', 'notificacoes' => 'Notificações', 'automacoes' => 'Automações'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'demandas';
$users = Ui::userOptions();
$action = Ui::url('/front/action.php');

/** Formulário que envia para action.php. */
$form = fn (string $do, string $inner, int $id = 0, string $btn = 'Salvar', string $cls = 'btn-primary') =>
    "<form method='post' action='" . $e($action) . "'>" . Ui::csrf() . "<input type='hidden' name='do' value='" . $e($do) . "'><input type='hidden' name='id' value='$id'>$inner"
    . ($btn !== '' ? "<button class='btn $cls mt-3'>" . $e($btn) . "</button>" : '') . "</form>";
$check = fn (string $name, bool $on, string $label, string $value = '1') => "<label class='form-check me-3'><input class='form-check-input' type='checkbox' name='" . $e($name) . "' value='" . $e($value) . "'" . ($on ? ' checked' : '') . "><span class='form-check-label'>" . $e($label) . "</span></label>";


/** Linha extra da tabela, em largura total, com o formulário de edição escondido num "Editar". */
$editRow = fn (int $cols, string $inner) => "<tr class='mkt-edit-row'><td colspan='$cols' class='p-0'><details class='mkt-edit'><summary>Editar</summary><div class='mkt-edit-body'>$inner</div></details></td></tr>";

Ui::header('Configurações', 'config');
echo Ui::title('Configurações', 'Opções das demandas, SLOs, notificações e automações da Gestão de Demandas.');
echo "<nav class='mkt-tabs'>";
foreach ($tabs as $key => $label) {
    echo "<a class='" . ($key === $tab ? 'active' : '') . "' href='" . $e(Ui::url('/front/config.php?tab=' . $key)) . "'>" . $e($label) . "</a>";
}
echo "</nav>";

// ================================================================== demandas e tipos
if ($tab === 'demandas') {
    $required = Settings::requiredFields();
    $inner = "<div class='mb-3'><div class='fw-bold mb-1'>Campos obrigatórios para planejar</div><div class='text-secondary small mb-2'>Sem eles preenchidos, a demanda não passa de triagem para planejada (RF-004). Cada tipo pode ter a sua própria lista.</div>";
    foreach (Settings::BRIEFING_FIELDS as $k => $label) {
        $inner .= $check('required[]', in_array($k, $required, true), $label, $k);
    }
    $inner .= "</div><div class='mb-2' style='max-width:340px'><label class='form-label fw-bold'>Responsável padrão da triagem</label>"
        . Ui::select('default_triage_user', $users, Settings::get('default_triage_user', '0'), '', true, 'Ninguém (atribuição manual)')
        . "<div class='text-secondary small mt-1'>Toda demanda nova é atribuída a esta pessoa, que recebe o pop-up.</div></div>";
    echo "<div class='card card-body mb-3'><h3 class='card-title'>Briefing</h3>" . $form('cfg_demands', $inner) . "</div>";

    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Tipos de demanda</h3></div><div class='table-responsive'><table class='table card-table'>";
    echo "<thead><tr><th>Tipo</th><th>Campos obrigatórios próprios</th><th>Situação</th></tr></thead><tbody>";
    foreach (Type::all() as $t) {
        $own = Type::requiredFields((int) $t['id']);
        $inner = "<div class='row g-2'><div class='col-md-4'><label class='form-label'>Nome</label><input class='form-control' name='name' value='" . $e($t['name']) . "' required></div>"
            . "<div class='col-md-2'><label class='form-label'>Cor</label><input class='form-control form-control-color' type='color' name='color' value='" . $e($t['color']) . "'></div>"
            . "<div class='col-md-6'><label class='form-label'>Descrição</label><input class='form-control' name='description' value='" . $e($t['description']) . "'></div></div>"
            . "<div class='mt-2 small fw-bold'>Campos obrigatórios (em branco = lista geral)</div>";
        foreach (Settings::BRIEFING_FIELDS as $k => $label) {
            $inner .= $check('required[]', $own !== null && in_array($k, $own, true), $label, $k);
        }
        $inner .= "<div>" . $check('is_active', (bool) $t['is_active'], 'Ativo') . "</div>";
        echo "<tr><td>" . Ui::badge($t['name'], $t['color']) . "</td><td class='small'>" . $e($own ? implode(', ', array_map(fn ($k) => Settings::BRIEFING_FIELDS[$k], $own)) : 'lista geral') . "</td>"
            . "<td>" . ($t['is_active'] ? 'Ativo' : 'Inativo') . "</td></tr>" . $editRow(3, $form('type_save', $inner, (int) $t['id']));
    }
    echo "</tbody></table></div></div>";

    $inner = "<div class='row g-2'><div class='col-md-4'><label class='form-label'>Nome</label><input class='form-control' name='name' required></div>"
        . "<div class='col-md-2'><label class='form-label'>Cor</label><input class='form-control form-control-color' type='color' name='color' value='#6366f1'></div>"
        . "<div class='col-md-6'><label class='form-label'>Descrição</label><input class='form-control' name='description'></div></div><input type='hidden' name='is_active' value='1'>";
    echo "<div class='card card-body'><h3 class='card-title'>Novo tipo</h3>" . $form('type_save', $inner, 0, 'Criar tipo') . "</div>";
}

// ================================================================== pilares
if ($tab === 'estrutura') {
    $pillarForm = fn (array $r) => "<div class='row g-2'><div class='col-md-3'><label class='form-label'>Pilar / frente</label><input class='form-control' name='name' value='" . $e($r['name'] ?? '') . "' required></div>"
        . "<div class='col-md-2'><label class='form-label'>Cor</label><input class='form-control form-control-color' type='color' name='color' value='" . $e($r['color'] ?? '#6366f1') . "'></div>"
        . "<div class='col-md-3'><label class='form-label'>Responsável</label>" . Ui::select('users_id', $users, $r['users_id'] ?? 0, '', true, 'Ninguém') . "</div>"
        . "<div class='col-md-4'><label class='form-label'>Descrição</label><input class='form-control' name='description' value='" . $e($r['description'] ?? '') . "'></div>"
        . "<div class='col-12'>" . $check('is_archived', !empty($r['is_archived']), 'Arquivado (some das listas de escolha, mas o histórico fica)') . "</div></div>";
    echo "<div class='alert alert-info'><div>Pilares (ou frentes) são o primeiro nível da estrutura <b>pilar → projeto → plano de ação → atividade</b>. Cadastre e edite aqui, sem mexer em código. Projetos e planos são criados em <a href='" . $e(Ui::url('/front/projects.php')) . "'>Projetos e planos</a>.</div></div>";
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Pilares</h3></div><div class='table-responsive'><table class='table card-table'><thead><tr><th>Pilar</th><th>Responsável</th><th>Projetos</th><th>Situação</th></tr></thead><tbody>";
    foreach (Structure::pillars() as $r) {
        $n = countElementsInTable(Structure::projectsTable(), ['pillars_id' => (int) $r['id']]);
        echo "<tr><td>" . Ui::badge($r['name'], $r['color']) . "</td><td>" . $e((int) $r['users_id'] > 0 ? getUserName((int) $r['users_id']) : '—') . "</td><td>$n</td><td>" . ($r['is_archived'] ? 'Arquivado' : 'Ativo') . "</td></tr>"
            . $editRow(4, $form('pillar_save', $pillarForm($r), (int) $r['id']));
    }
    echo "</tbody></table></div></div>";
    echo "<div class='card card-body'><h3 class='card-title'>Novo pilar</h3>" . $form('pillar_save', $pillarForm([]), 0, 'Criar pilar') . "</div>";
}

// ================================================================== status e cores
if ($tab === 'status') {
    $colors = Settings::statusColors();
    $inner = "<div class='text-secondary small mb-3'>As cores aparecem no Kanban, no painel executivo e no PDF.</div><div class='row g-3'>";
    foreach (Demand::STATUSES as $k => $label) {
        $inner .= "<div class='col-6 col-md-3'><label class='form-label'>" . $e($label) . "</label><input class='form-control form-control-color' type='color' name='color[" . $e($k) . "]' value='" . $e($colors[$k]) . "'></div>";
    }
    $inner .= "</div>";
    echo "<div class='card card-body'><h3 class='card-title'>Cores dos status</h3>" . $form('cfg_status', $inner) . "</div>";
}

// ================================================================== SLOs
if ($tab === 'slos') {
    $calendars = [];
    foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_calendars', 'ORDER' => 'name ASC']) as $c) {
        $calendars[(int) $c['id']] = $c['name'];
    }
    $sloForm = function (array $s) use ($e, $check, $users, $calendars): string {
        $pausing = array_filter(explode(',', (string) ($s['pause_statuses'] ?? '')));
        $pauseBoxes = '';
        foreach (Demand::STATUSES as $k => $label) {
            $pauseBoxes .= $check('pause_list[]', in_array($k, $pausing, true), $label, $k);
        }
        return "<div class='row g-2'><div class='col-md-4'><label class='form-label'>Nome</label><input class='form-control' name='name' value='" . $e($s['name'] ?? '') . "' required></div>"
            . "<div class='col-md-3'><label class='form-label'>Grupo</label>" . Ui::select('grp', Slo::GROUPS, $s['grp'] ?? 'triage') . "</div>"
            . "<div class='col-md-5'><label class='form-label'>Processo e etapa</label><input class='form-control' name='process' value='" . $e($s['process'] ?? '') . "' placeholder='Ex.: Entrada e triagem · confirmação de recebimento'></div>"
            . "<div class='col-md-2'><label class='form-label'>Começa quando entra em</label>" . Ui::select('from_status', Demand::STATUSES, $s['from_status'] ?? 'received') . "</div>"
            . "<div class='col-md-3'><label class='form-label'>Termina ao chegar a (separe por vírgula)</label><input class='form-control' name='to_statuses' value='" . $e($s['to_statuses'] ?? '') . "' placeholder='triage,planned' required></div>"
            . "<div class='col-md-1'><label class='form-label'>Dias úteis</label><input class='form-control' type='number' min='1' max='365' name='limit_days' value='" . $e($s['limit_days'] ?? 1) . "'></div>"
            . "<div class='col-md-3'><label class='form-label'>Calendário (feriados)</label>" . Ui::select('calendars_id', $calendars, $s['calendars_id'] ?? 0, '', true, 'Seg–sex') . "</div>"
            . "<div class='col-md-3'><label class='form-label'>Vale para</label>" . Ui::select('applies_to', Slo::APPLIES, $s['applies_to'] ?? 'external') . "</div>"
            . "<div class='col-md-4'><label class='form-label'>Escalonar para</label>" . Ui::select('users_id_escalate', $users, $s['users_id_escalate'] ?? 0, '', true, 'Ninguém') . "</div>"
            . "<div class='col-md-12'><div class='small fw-bold'>Situações que pausam a contagem (o tempo parado nelas empurra o vencimento)</div>$pauseBoxes</div>"
            . "<div class='col-md-2'><label class='form-label'>Vigência: de</label><input class='form-control' type='date' name='valid_from' value='" . $e($s['valid_from'] ?? '') . "'></div>"
            . "<div class='col-md-2'><label class='form-label'>até</label><input class='form-control' type='date' name='valid_to' value='" . $e($s['valid_to'] ?? '') . "'></div>"
            . "<div class='col-md-3'><label class='form-label'>Homologação</label>" . Ui::select('hom_status', Slo::HOM_STATUSES, $s['hom_status'] ?? 'draft') . "</div>"
            . "<div class='col-md-5'><label class='form-label'>Evidência do aceite (ata, e-mail, link)</label><input class='form-control' name='hom_evidence' value='" . $e($s['hom_evidence'] ?? '') . "'></div>"
            . "<div class='col-md-12'>" . $check('is_active', !empty($s['is_active']), 'SLO ativo (só liga se estiver Homologado e com a evidência do aceite, RF-017)') . "</div></div>";
    };
    echo "<div class='alert alert-info'><div>Os SLOs medem o tempo entre a entrada da demanda num estado e a chegada a um estado final, em dias úteis pelo calendário escolhido. Os valores iniciais vêm do briefing e <b>nascem desligados e em rascunho</b>: só ligam depois de <b>homologados</b>, com a evidência do aceite (RF-017). Cada SLO é um indicador independente: o painel não soma SLOs de etapas diferentes, e eles não se confundem com o prazo confirmado da entrega nem com o prazo da tarefa. Quando um SLO vence, o responsável (e quem for escalonado) recebe o aviso.</div></div>";
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>SLOs</h3></div><div class='table-responsive'><table class='table card-table'>";
    echo "<thead><tr><th>SLO</th><th>Grupo</th><th>Mede</th><th>Prazo</th><th>Homologação</th><th>Situação</th></tr></thead><tbody>";
    foreach (Slo::all() as $s) {
        $to = implode(', ', array_map(fn ($k) => Demand::STATUSES[$k] ?? $k, array_filter(explode(',', $s['to_statuses']))));
        echo "<tr><td>" . $e($s['name']) . "</td><td class='small'>" . $e(Slo::GROUPS[$s['grp']] ?? '') . "</td><td class='small'>" . $e((Demand::STATUSES[$s['from_status']] ?? $s['from_status']) . ' → ' . $to) . "</td><td>" . (int) $s['limit_days'] . " dia(s) útil(eis)</td>"
            . "<td>" . ($s['hom_status'] === 'approved' ? Ui::badge('Homologado', '#22c55e') : Ui::badge(Slo::HOM_STATUSES[$s['hom_status']] ?? '—', '#94a3b8')) . "</td>"
            . "<td>" . ($s['is_active'] ? Ui::badge('Ativo', '#22c55e') : Ui::badge('Desligado', '#94a3b8')) . "</td></tr>"
            . $editRow(6, $form('slo_save', $sloForm($s), (int) $s['id']) . $form('slo_delete', '', (int) $s['id'], 'Remover', 'btn-outline-danger btn-sm'));
    }
    echo "</tbody></table></div></div>";
    echo "<div class='card card-body'><h3 class='card-title'>Novo SLO</h3>" . $form('slo_save', $sloForm([]), 0, 'Criar SLO') . "</div>";
}

// ================================================================== notificações
if ($tab === 'notificacoes') {
    $inner = "<div class='mb-3'>" . $check('popup_enabled', Settings::bool('popup_enabled'), 'Pop-up no canto da tela') . $check('sound_enabled', Settings::bool('sound_enabled'), 'Efeito sonoro com o pop-up') . "</div>"
        . "<div class='mb-3' style='max-width:260px'><label class='form-label'>Consultar novos avisos a cada (segundos)</label><input class='form-control' type='number' min='5' max='300' name='poll_seconds' value='" . (int) Settings::pollSeconds() . "'></div>"
        . "<div class='table-responsive'><table class='table'><thead><tr><th>Evento</th><th>Quem recebe</th><th class='text-center'>Pop-up</th><th class='text-center'>E-mail</th></tr></thead><tbody>";
    foreach (Settings::EVENTS as $key => [$title, $who]) {
        $inner .= "<tr><td>" . $e($title) . "</td><td class='text-secondary small'>" . $e($who) . "</td>"
            . "<td class='text-center'><input class='form-check-input' type='checkbox' name='ev[" . $e($key) . "][popup]' value='1'" . (Settings::bool('ev_' . $key . '_popup') ? ' checked' : '') . "></td>"
            . "<td class='text-center'><input class='form-check-input' type='checkbox' name='ev[" . $e($key) . "][mail]' value='1'" . (Settings::bool('ev_' . $key . '_mail') ? ' checked' : '') . "></td></tr>";
    }
    $inner .= "</tbody></table></div>";
    echo "<div class='card card-body mb-3'><h3 class='card-title'>Pop-up, som e canais por evento</h3>" . $form('cfg_notifications', $inner) . "</div>";
    global $CFG_GLPI;
    echo "<div class='card card-body'><h3 class='card-title'>E-mail</h3><p class='mb-1'>Fila: <b>" . Outbox::pendingCount() . "</b> mensagem(ns) aguardando envio. "
        . (empty($CFG_GLPI['notifications_mailing']) ? "<span class='text-warning'>As notificações por e-mail do GLPI estão desligadas; os avisos ficam na fila e saem quando forem ligadas.</span>" : "<span class='text-success'>Notificações por e-mail do GLPI ligadas.</span>")
        . "</p><div class='text-secondary small'>O envio roda pela ação automática \"Gestão de Demandas: envia a fila de e-mails\" (a cada 5 minutos).</div></div>";
    echo "<div class='card card-body mt-3'><h3 class='card-title'>Testar o pop-up</h3><p class='text-secondary small'>Mostra um aviso de exemplo com o som, neste navegador.</p><div><button type='button' class='btn btn-outline-primary' data-mkt-test='1'><i class='ti ti-bell-ringing me-1'></i>Mostrar aviso de teste</button></div></div>";
}

// ================================================================== automações
if ($tab === 'automacoes') {
    $autoForm = function (array $a) use ($e, $check, $users): string {
        return "<div class='row g-2'><div class='col-md-4'><label class='form-label'>Nome</label><input class='form-control' name='name' value='" . $e($a['name'] ?? '') . "' required></div>"
            . "<div class='col-md-3'><label class='form-label'>Quando</label>" . Ui::select('trigger_event', Automation::TRIGGERS, $a['trigger_event'] ?? 'status_changed') . "</div>"
            . "<div class='col-md-2'><label class='form-label'>Valor do gatilho</label>" . Ui::select('trigger_value', Demand::STATUSES, $a['trigger_value'] ?? '', '', true, 'Qualquer') . "</div>"
            . "<div class='col-md-3'><label class='form-label'>Então</label>" . Ui::select('action', Automation::ACTIONS, $a['action'] ?? 'notify') . "</div>"
            . "<div class='col-md-3'><label class='form-label'>Para (avisar)</label>" . Ui::select('action_target', Automation::TARGETS + array_combine(array_map(fn ($i) => 'user:' . $i, array_keys($users)), $users), $a['action_target'] ?? '', '', true, '—') . "</div>"
            . "<div class='col-md-6'><label class='form-label'>Texto, usuário (id) ou prioridade 1–5</label><input class='form-control' name='action_value' value='" . $e($a['action_value'] ?? '') . "'></div>"
            . "<div class='col-md-3 d-flex align-items-end'>" . $check('is_active', !empty($a['is_active']), 'Ligada') . "</div></div>"
            . "<div class='text-secondary small mt-2'>\"Valor do gatilho\" vale para \"Situação alterada\" (o novo estado). Em \"Atribuir a\", informe o <b>id</b> do usuário em \"Texto\".</div>";
    };
    echo "<div class='alert alert-info'><div>Receitas no estilo do monday: <b>quando</b> algo acontece, <b>então</b> o sistema age. Nascem desligadas. Uma automação não dispara outras além de duas camadas.</div></div>";
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Automações</h3></div><div class='table-responsive'><table class='table card-table'>";
    echo "<thead><tr><th>Receita</th><th>Quando</th><th>Então</th><th class='text-end'>Execuções</th><th>Situação</th></tr></thead><tbody>";
    foreach (Automation::all() as $a) {
        $when = Automation::TRIGGERS[$a['trigger_event']] . ($a['trigger_value'] !== '' ? ' → ' . (Demand::STATUSES[$a['trigger_value']] ?? $a['trigger_value']) : '');
        echo "<tr><td>" . $e($a['name']) . "</td><td class='small'>" . $e($when) . "</td><td class='small'>" . $e(Automation::ACTIONS[$a['action']] ?? $a['action']) . "</td><td class='text-end'>" . (int) $a['run_count'] . "</td>"
            . "<td>" . $form('auto_toggle', '', (int) $a['id'], $a['is_active'] ? 'Desligar' : 'Ligar', $a['is_active'] ? 'btn-outline-secondary btn-sm' : 'btn-success btn-sm') . "</td>"
            . "</tr>" . $editRow(5, $form('auto_save', $autoForm($a), (int) $a['id']) . $form('auto_delete', '', (int) $a['id'], 'Remover', 'btn-outline-danger btn-sm'));
    }
    echo "</tbody></table></div></div>";
    echo "<div class='card card-body'><h3 class='card-title'>Nova automação</h3>" . $form('auto_save', $autoForm([]), 0, 'Criar automação') . "</div>";
}

Html::footer();

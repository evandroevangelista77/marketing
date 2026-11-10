<?php

/**
 * Recebe os formulários do plugin: demanda (salvar, situação, evidência, aceite, aprovação, exceção, atualização)
 * e configurações (geral, status, notificações, tipos, SLOs, automações).
 * O CSRF é validado pelo próprio GLPI em todo POST: não chame checkCSRF aqui (o token é de uso único).
 */

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Automation;
use GlpiPlugin\Marketing\Demand;
use GlpiPlugin\Marketing\Settings;
use GlpiPlugin\Marketing\Slo;
use GlpiPlugin\Marketing\Structure;
use GlpiPlugin\Marketing\Type;
use GlpiPlugin\Marketing\Ui;

Session::checkLoginUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new \Glpi\Exception\Http\BadRequestHttpException();
}
global $DB;

$p = $_POST;
$do = (string) ($p['do'] ?? '');
$id = (int) ($p['id'] ?? 0);
$denied = fn () => new \Glpi\Exception\Http\AccessDeniedHttpException();
$go = fn (string $path) => Html::redirect(Ui::url($path));
$msg = fn (string $text, bool $ok = true) => Session::addMessageAfterRedirect($text, false, $ok ? INFO : ERROR);

/** Carrega a demanda e confirma que o usuário pode agir sobre ela por um dos caminhos permitidos. */
$load = function (bool $forUpdate) use ($id, $denied): Demand {
    $demand = new Demand();
    if (!$demand->getFromDB($id) || !$demand->canViewItem()) {
        throw $denied();
    }
    if ($forUpdate && !($demand->canUpdateItem() || $demand->canComplement())) {
        throw $denied();
    }
    return $demand;
};
$back = fn (int $demandId) => $go('/front/demand.php?id=' . $demandId);

// ================================================================== configurações (direito de configurar)
if (str_starts_with($do, 'cfg_') || in_array($do, ['type_save', 'type_toggle', 'slo_save', 'slo_delete', 'auto_save', 'auto_toggle', 'auto_delete', 'pillar_save'], true)) {
    if (!Access::canConfigure()) {
        throw $denied();
    }
    $tab = 'demandas';
    switch ($do) {
        case 'cfg_demands':
            $fields = array_values(array_intersect((array) ($p['required'] ?? []), array_keys(Settings::BRIEFING_FIELDS)));
            Settings::save(['required_fields' => json_encode($fields ?: ['objective', 'audience', 'deliverable', 'desired_date']), 'default_triage_user' => (string) max(0, (int) ($p['default_triage_user'] ?? 0))]);
            $msg('Configurações das demandas salvas.');
            break;
        case 'cfg_status':
            $colors = [];
            foreach (array_keys(Settings::STATUS_COLORS) as $k) {
                $v = (string) (($p['color'] ?? [])[$k] ?? '');
                $colors[$k] = preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : Settings::STATUS_COLORS[$k];
            }
            Settings::save(['status_colors' => json_encode($colors)]);
            $tab = 'status';
            $msg('Cores dos status salvas.');
            break;
        case 'cfg_notifications':
            $values = [
                'popup_enabled' => !empty($p['popup_enabled']) ? '1' : '0',
                'sound_enabled' => !empty($p['sound_enabled']) ? '1' : '0',
                'poll_seconds'  => (string) max(5, min(300, (int) ($p['poll_seconds'] ?? 20))),
            ];
            foreach (array_keys(Settings::EVENTS) as $ev) {
                $values['ev_' . $ev . '_popup'] = !empty($p['ev'][$ev]['popup']) ? '1' : '0';
                $values['ev_' . $ev . '_mail'] = !empty($p['ev'][$ev]['mail']) ? '1' : '0';
            }
            Settings::save($values);
            $tab = 'notificacoes';
            $msg('Notificações salvas.');
            break;
        case 'type_save':
            $saved = Type::save($id, (string) ($p['name'] ?? ''), (string) ($p['color'] ?? ''), !empty($p['is_active']), (array) ($p['required'] ?? []), (string) ($p['description'] ?? ''));
            $msg($saved ? 'Tipo salvo.' : 'Informe o nome do tipo.', (bool) $saved);
            break;
        case 'type_toggle':
            $row = Type::find($id);
            if ($row) {
                $DB->update(Type::getTable(), ['is_active' => $row['is_active'] ? 0 : 1], ['id' => $id]);
                $msg($row['is_active'] ? 'Tipo desativado.' : 'Tipo ativado.');
            }
            break;
        case 'slo_save':
            $saved = Slo::save($id, $p);
            $tab = 'slos';
            $msg($saved > 0 ? 'SLO salvo.' . (!empty($p['is_active']) && !Slo::isHomologated($saved) ? ' Ele só fica ativo depois de homologado (com a evidência do aceite).' : '') : 'Informe o nome e ao menos um estado final válido.', $saved > 0);
            break;
        case 'slo_delete':
            $DB->delete(Slo::getTable(), ['id' => $id]);
            $tab = 'slos';
            $msg('SLO removido.');
            break;
        case 'pillar_save':
            $saved = Structure::savePillar($id, $p);
            $tab = 'estrutura';
            $msg($saved ? 'Pilar salvo.' : 'Informe o nome do pilar.', (bool) $saved);
            break;
        case 'auto_save':
            $saved = Automation::save($id, $p);
            $tab = 'automacoes';
            $msg($saved ? 'Automação salva.' : 'Preencha o nome, o gatilho e a ação.', (bool) $saved);
            break;
        case 'auto_toggle':
            $row = Automation::all()[$id] ?? null;
            if ($row) {
                $DB->update(Automation::getTable(), ['is_active' => $row['is_active'] ? 0 : 1], ['id' => $id]);
                $msg($row['is_active'] ? 'Automação desligada.' : 'Automação ligada.');
            }
            $tab = 'automacoes';
            break;
        case 'auto_delete':
            $DB->delete(Automation::getTable(), ['id' => $id]);
            $tab = 'automacoes';
            $msg('Automação removida.');
            break;
    }
    $go('/front/config.php?tab=' . $tab);
}

// ================================================================== estrutura: atividades internas, projetos, planos
/** Cadastro interno exige o bit próprio (separado do formulário de solicitação). */
$needInternal = function () use ($denied): void {
    if (!Access::canInternal()) {
        throw $denied();
    }
};
$structureInput = fn () => [
    'pillars_id' => (int) ($p['pillars_id'] ?? 0), 'mkt_projects_id' => (int) ($p['mkt_projects_id'] ?? 0), 'plans_id' => (int) ($p['plans_id'] ?? 0),
];

if ($do === 'activity_create') {
    $needInternal();
    $newId = Demand::createActivity($structureInput() + [
        'name' => $p['name'] ?? '', 'objective' => $p['objective'] ?? '', 'deliverable' => $p['deliverable'] ?? '',
        'users_id_assigned' => $p['users_id_assigned'] ?? 0, 'start_date' => $p['start_date'] ?? '', 'confirmed_date' => $p['confirmed_date'] ?? '',
        'priority' => $p['priority'] ?? 3, 'types_id' => $p['types_id'] ?? 0, 'depends_on_id' => $p['depends_on_id'] ?? 0,
    ]);
    $msg($newId ? 'Atividade cadastrada.' : 'Informe o título da atividade.', (bool) $newId);
    $newId ? $back($newId) : $go('/front/activity.php');
}

if ($do === 'subtask_create') {
    $needInternal();
    $parent = $load(false);
    $newId = Demand::createActivity([
        'name' => $p['name'] ?? '', 'users_id_assigned' => $p['users_id_assigned'] ?? 0, 'confirmed_date' => $p['confirmed_date'] ?? '',
        'priority' => $parent->fields['priority'], 'types_id' => $parent->fields['types_id'], 'parent_id' => $id,
        'pillars_id' => $parent->fields['pillars_id'], 'mkt_projects_id' => $parent->fields['mkt_projects_id'], 'plans_id' => $parent->fields['plans_id'],
    ]);
    $msg($newId ? 'Atividade adicionada ao plano.' : 'Informe o título.', (bool) $newId);
    $back($id);
}

if ($do === 'plan_create') {
    // "Criar plano de ação" na ficha da demanda: o plano guarda o código original e as atividades ficam vinculadas à demanda-mãe
    $needInternal();
    $demand = $load(true);
    if ((int) ($demand->fields['plans_id'] ?? 0) > 0) {
        $msg('Esta demanda já tem plano de ação.', false);
        $back($id);
    }
    if (!Demand::isOpen($demand->fields) || in_array($demand->fields['status'], [Demand::STATUS_RECEIVED, Demand::STATUS_TRIAGE, Demand::STATUS_WAITING_REQUESTER], true)) {
        $msg('Crie o plano depois da triagem e do aceite (demanda planejada ou em andamento).', false);
        $back($id);
    }
    [$pillar, $project] = Structure::normalizeLinks((int) ($p['pillars_id'] ?? $demand->fields['pillars_id']), (int) ($p['mkt_projects_id'] ?? 0), 0);
    $planId = Structure::savePlan(0, [
        'name' => trim((string) ($p['name'] ?? '')) ?: $demand->fields['name'], 'mkt_projects_id' => $project, 'source_demands_id' => $id,
        'objective' => $demand->fields['objective'] ?? '', 'users_id' => (int) ($p['users_id'] ?? 0) ?: (int) $demand->fields['users_id_assigned'],
        'start_date' => $p['start_date'] ?? '', 'end_date' => ($p['end_date'] ?? '') ?: $demand->fields['confirmed_date'], 'criteria' => $p['criteria'] ?? '',
    ]);
    $DB->update(Demand::getTable(), ['plans_id' => $planId, 'pillars_id' => $pillar, 'mkt_projects_id' => $project, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $id]);
    $demand->getFromDB($id);
    $demand->addEvent('plan', '', (string) $planId, 'Plano de ação criado a partir desta demanda');
    $made = 0;
    $owner = (int) ($p['users_id'] ?? 0) ?: (int) $demand->fields['users_id_assigned'];
    foreach (array_slice(preg_split('/\R/', (string) ($p['activities'] ?? '')) ?: [], 0, 60) as $line) {
        // formato: "Título" ou "Título | AAAA-MM-DD" (prazo)
        [$title, $due] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
        if ($title === '') {
            continue;
        }
        $made += Demand::createActivity([
            'name' => $title, 'confirmed_date' => $due, 'users_id_assigned' => $owner, 'priority' => $demand->fields['priority'], 'types_id' => $demand->fields['types_id'],
            'parent_id' => $id, 'pillars_id' => $pillar, 'mkt_projects_id' => $project, 'plans_id' => $planId,
        ]) > 0 ? 1 : 0;
    }
    $msg('Plano de ação criado' . ($made ? " com $made atividade(s)" : '') . '.');
    $go('/front/plan.php?id=' . $planId);
}

if ($do === 'link_save') {
    // pilar, projeto, plano, dependência e início da demanda/atividade
    if (!Access::canUpdate() && !Access::canInternal()) {
        throw $denied();
    }
    $demand = $load(false);
    [$pillar, $project, $plan] = Structure::normalizeLinks((int) ($p['pillars_id'] ?? 0), (int) ($p['mkt_projects_id'] ?? 0), (int) ($p['plans_id'] ?? 0));
    $dep = (int) ($p['depends_on_id'] ?? 0);
    $depCode = '';
    if ($dep > 0) {
        $d2 = new Demand();
        $dep = ($d2->getFromDB($dep) && $dep !== $id) ? $dep : 0;
    }
    $start = ($p['start_date'] ?? '') && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $p['start_date']) ? $p['start_date'] : null;
    $DB->update(Demand::getTable(), ['pillars_id' => $pillar, 'mkt_projects_id' => $project, 'plans_id' => $plan, 'depends_on_id' => $dep, 'start_date' => $start, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $id]);
    $demand->addEvent('link', '', '', 'Pilar, projeto e plano atualizados');
    $msg('Vínculos salvos.');
    $back($id);
}

if (in_array($do, ['project_save', 'plan_save', 'milestone_add', 'milestone_done'], true)) {
    $needInternal();
    switch ($do) {
        case 'project_save':
            $saved = Structure::saveProject($id, $p);
            $msg($saved ? 'Projeto salvo.' : 'Informe o nome do projeto.', (bool) $saved);
            $saved ? $go('/front/project.php?id=' . $saved) : $go('/front/projects.php');
            break;
        case 'plan_save':
            $saved = Structure::savePlan($id, $p);
            $msg($saved ? 'Plano salvo.' : 'Informe o nome do plano.', (bool) $saved);
            $saved ? $go('/front/plan.php?id=' . $saved) : $go('/front/projects.php');
            break;
        case 'milestone_add':
            $msg(Structure::saveMilestone($id, (string) ($p['name'] ?? ''), (string) ($p['due_date'] ?? '')) ? 'Marco adicionado.' : 'Informe o nome e a data do marco.', true);
            $go('/front/project.php?id=' . $id);
            break;
        case 'milestone_done':
            $DB->update(Structure::milestonesTable(), ['is_done' => !empty($p['done']) ? 1 : 0], ['id' => (int) ($p['milestone'] ?? 0), 'mkt_projects_id' => $id]);
            $go('/front/project.php?id=' . $id);
            break;
    }
}

// ================================================================== demanda
if ($do === 'save') {
    if ($id === 0) {
        if (!Access::canCreate()) {
            throw $denied();
        }
        $demand = new Demand();
        $next = (int) $DB->request(['SELECT' => ['MAX' => 'id AS m'], 'FROM' => Demand::getTable()])->current()['m'] + 1;
        $typeId = (int) ($p['types_id'] ?? 0);
        $newId = $demand->add([
            'name'               => trim((string) ($p['name'] ?? '')) ?: 'Sem título',
            'code'               => 'MKT-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT),
            'entities_id'        => (int) ($_SESSION['glpiactive_entity'] ?? 0),
            'requester_users_id' => (int) Session::getLoginUserID(),
            'types_id'           => Type::find($typeId) ? $typeId : 0,
            'area'               => $p['area'] ?? null,
            'objective'          => $p['objective'] ?? null,
            'audience'           => $p['audience'] ?? null,
            'deliverable'        => $p['deliverable'] ?? null,
            'context'            => $p['context'] ?? null,
            'desired_date'       => ($p['desired_date'] ?? '') ?: null,
            'status'             => Demand::STATUS_RECEIVED,
            'sent_at'            => date('Y-m-d H:i:s'),
        ]);
        if ($newId) {
            $demand->getFromDB((int) $newId);
            $demand->addEvent('created', '', Demand::STATUS_RECEIVED, 'Demanda criada');
            $demand->afterCreate();
        }
        $msg($newId ? 'Demanda criada.' : 'Não foi possível criar a demanda.', (bool) $newId);
        $back((int) $newId);
    }

    $demand = $load(true);
    $input = [];
    foreach (['area', 'objective', 'audience', 'deliverable', 'context'] as $k) {
        if (array_key_exists($k, $p)) {
            $input[$k] = $p[$k];
        }
    }
    if (trim((string) ($p['name'] ?? '')) !== '') {
        $input['name'] = trim($p['name']);
    }
    $input['desired_date'] = ($p['desired_date'] ?? '') ?: null;
    $newAssignee = null;
    if (Access::canUpdate()) {
        $input['confirmed_date']    = ($p['confirmed_date'] ?? '') ?: null;
        $input['users_id_approver'] = (int) ($p['users_id_approver'] ?? 0);
        $input['priority']          = max(1, min(5, (int) ($p['priority'] ?? 3)));
        $typeId = (int) ($p['types_id'] ?? 0);
        $input['types_id']          = Type::find($typeId) ? $typeId : 0;
        $newAssignee                = (int) ($p['users_id_assigned'] ?? 0);
    }
    if ($demand->canUpdateItem()) {
        $demand->update(['id' => $id] + $input);
        $demand->getFromDB($id);
        if ($newAssignee !== null) {
            $demand->assign($newAssignee); // grava, registra o evento, abre o pop-up de quem recebeu e roda as automações
        }
    } else {
        // solicitante complementando o briefing: sem o direito de atualização, grava direto
        $DB->update(Demand::getTable(), $input, ['id' => $id]);
        $demand->addEvent('complement', '', '', 'Briefing complementado pelo solicitante');
    }
    $msg('Demanda salva.');
    $back($id);
}

if ($do === 'transition') {
    if (!Access::canUpdate()) {
        throw $denied();
    }
    $demand = $load(true);
    $to = (string) ($p['to'] ?? '');
    $error = $demand->changeStatus($to, trim((string) ($p['note'] ?? '')));
    if ($error === null && $to === Demand::STATUS_IN_APPROVAL) {
        $demand->requestApproval();
    }
    $msg($error ?? 'Situação alterada.', $error === null);
    $back($id);
}

if ($do === 'evidence') {
    if (!Access::canUpdate()) {
        throw $denied();
    }
    $demand = $load(true);
    $name = trim((string) ($p['name'] ?? ''));
    if ($name === '') {
        $msg('Informe o nome da evidência.', false);
    } else {
        $demand->addEvidence((string) ($p['kind'] ?? 'link'), $name, trim((string) ($p['url'] ?? '')), trim((string) ($p['comment'] ?? '')));
        $msg('Evidência registrada.');
    }
    $back($id);
}

if ($do === 'accept') {
    $demand = $load(false);
    $error = $demand->acceptDelivery();
    $msg($error ?? 'Entrega aceita.', $error === null);
    $back($id);
}

if ($do === 'decide') {
    if (!Access::canApprove()) {
        throw $denied();
    }
    $demand = $load(false);
    $error = $demand->decide((string) ($p['decision'] ?? ''), trim((string) ($p['comment'] ?? '')));
    $msg($error ?? 'Decisão registrada.', $error === null);
    $back($id);
}

if ($do === 'exception') {
    if (!Access::canApprove()) {
        throw $denied();
    }
    $demand = $load(false);
    $error = $demand->completeWithException(trim((string) ($p['note'] ?? '')));
    $msg($error ?? 'Demanda concluída com exceção.', $error === null);
    $back($id);
}

if ($do === 'note') {
    $demand = $load(false);
    $body = trim((string) ($p['body'] ?? ''));
    if ($body === '') {
        $msg('Escreva a atualização.', false);
    } else {
        $demand->addNote(mb_substr($body, 0, 4000));
        $msg('Atualização registrada.');
    }
    $back($id);
}

Html::back();

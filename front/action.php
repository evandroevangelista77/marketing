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
if (str_starts_with($do, 'cfg_') || in_array($do, ['type_save', 'type_toggle', 'slo_save', 'slo_delete', 'auto_save', 'auto_toggle', 'auto_delete'], true)) {
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
            $msg($saved ? 'SLO salvo.' : 'Informe o nome e ao menos um estado final válido.', (bool) $saved);
            break;
        case 'slo_delete':
            $DB->delete(Slo::getTable(), ['id' => $id]);
            $tab = 'slos';
            $msg('SLO removido.');
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

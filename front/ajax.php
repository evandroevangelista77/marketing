<?php

/**
 * Ações do Kanban (arrastar cartão): muda a situação da demanda e devolve JSON.
 * O CSRF é validado pelo GLPI pelo cabeçalho X-Glpi-Csrf-Token das requisições AJAX.
 */

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Demand;
use GlpiPlugin\Marketing\Settings;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$reply = function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
};

if ((int) Session::getLoginUserID() <= 0) {
    $reply(401, ['ok' => false, 'error' => 'Sessão expirada.']);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $reply(405, ['ok' => false, 'error' => 'Use POST.']);
    return;
}
if (!Access::canUpdate()) {
    $reply(403, ['ok' => false, 'error' => 'Sem permissão para alterar demandas.']);
    return;
}

$demand = new Demand();
if (($_POST['do'] ?? '') !== 'transition' || !$demand->getFromDB((int) ($_POST['id'] ?? 0)) || !$demand->canUpdateItem()) {
    $reply(403, ['ok' => false, 'error' => 'Demanda indisponível.']);
    return;
}
$to = (string) ($_POST['to'] ?? '');
$error = $demand->changeStatus($to);
if ($error === null && $to === Demand::STATUS_IN_APPROVAL) {
    $demand->requestApproval();
}
if ($error !== null) {
    $reply(422, ['ok' => false, 'error' => $error]);
    return;
}
// devolve também as próximas situações possíveis e o progresso, para a visão em tabela atualizar a linha sem recarregar
$next = array_map(fn ($k) => ['key' => $k, 'label' => Demand::STATUSES[$k], 'color' => Settings::statusColor($k)], Demand::TRANSITIONS[$to] ?? []);
$reply(200, ['ok' => true, 'status' => $to, 'label' => Demand::STATUSES[$to], 'color' => Settings::statusColor($to), 'progress' => Demand::PROGRESS[$to] ?? 0, 'next' => $next]);

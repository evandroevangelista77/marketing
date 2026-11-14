<?php

/**
 * Salva a preferência do usuário: visão do Kanban (board, table).
 * Chamado por public/js/tabela.js. O CSRF é validado pelo GLPI (cabeçalho X-Glpi-Csrf-Token no 11).
 */

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Settings;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid = (int) Session::getLoginUserID();
if ($uid <= 0 || !Access::canView()) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    return;
}

$key = (string) ($_POST['key'] ?? '');
$value = (string) ($_POST['value'] ?? '');
if ($key === 'kanban_view' && in_array($value, ['board', 'table'], true)) {
    Settings::setUserPref($uid, 'kanban_view', $value);
} else {
    http_response_code(422);
    echo json_encode(['ok' => false]);
    return;
}
echo json_encode(['ok' => true, 'key' => $key, 'value' => $value]);

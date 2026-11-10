<?php

/**
 * Consulta dos pop-ups: devolve em JSON os avisos ainda não entregues ao usuário (e marca como entregues).
 * Chamada pelo public/js/alerts.js. Sem sessão: 401; sem direito: {"disabled": true}.
 */

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Alert;
use GlpiPlugin\Marketing\Settings;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid = (int) Session::getLoginUserID();
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['disabled' => true]);
    return;
}
if (!Access::canView() || !Settings::bool('popup_enabled')) {
    echo json_encode(['disabled' => true]);
    return;
}
echo json_encode([
    'interval' => Settings::pollSeconds(),
    'sound'    => Settings::bool('sound_enabled'),
    'items'    => Alert::takePending($uid, 5),
], JSON_UNESCAPED_UNICODE);

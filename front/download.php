<?php

/**
 * Baixa um entregável. Exige o direito de leitura do plugin e a visibilidade da demanda a que o arquivo pertence.
 * O arquivo nunca é aberto no navegador (sempre "attachment") e fica fora da raiz web.
 */

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Demand;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
global $DB;

$row = $DB->request(['FROM' => Demand::filesTable(), 'WHERE' => ['id' => (int) ($_GET['id'] ?? 0)], 'LIMIT' => 1])->current();
$demand = new Demand();
if (!$row || !$demand->getFromDB((int) $row['demands_id']) || !$demand->canViewItem()) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
$path = Demand::filesDir() . '/' . basename((string) $row['stored_name']);
if (!is_file($path)) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
$demand->addEvent('download', '', '', 'Download: ' . $row['name']);

$name = (string) $row['name'];
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/octet-stream');
header("Content-Disposition: attachment; filename=\"" . addcslashes(preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'arquivo', '"\\') . "\"; filename*=UTF-8''" . rawurlencode($name));
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, no-store');
readfile($path);
exit;

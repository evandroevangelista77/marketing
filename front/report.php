<?php

/** Baixa o PDF executivo com os filtros do painel (mesmos parâmetros da URL do painel). */

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Dashboard;
use GlpiPlugin\Marketing\ExecutiveReport;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$filters = Dashboard::filters($_GET);
$data = Dashboard::build($filters);
$pdf = ExecutiveReport::pdf($data, getUserName((int) Session::getLoginUserID()));

$name = 'demandas-marketing-' . date('Ymd-His') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;

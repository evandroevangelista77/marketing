<?php

/**
 * Importação de atividades internas por planilha (CSV ou Excel). Etapa 1: enviar e conferir (nada é gravado);
 * etapa 2: confirmar. Exige o direito "Cadastro interno". O CSRF é validado pelo GLPI (não chame checkCSRF aqui).
 */

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Importer;
use GlpiPlugin\Marketing\Ui;

Session::checkLoginUser();
if (!Access::canInternal()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

if (($_GET['template'] ?? '') === 'csv') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="modelo-importacao-atividades.csv"');
    header('X-Content-Type-Options: nosniff');
    echo Importer::template();
    exit;
}

$e = fn ($v) => Ui::e($v);
$action = Ui::url('/front/import.php');
$error = '';
$report = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'preview') {
        $file = $_FILES['sheet'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            $error = 'Escolha a planilha (o servidor aceita arquivos de até ' . round(\GlpiPlugin\Marketing\Demand::uploadLimit() / 1048576) . ' MB).';
        } else {
            [$rows, $readError] = Importer::read((string) $file['tmp_name'], (string) $file['name']);
            if ($readError !== '') {
                $error = $readError;
            } else {
                $opts = ['create' => !empty($_POST['create_missing']), 'skip' => !empty($_POST['skip_duplicates'])];
                $an = Importer::analyze($rows, $opts['create'], $opts['skip']);
                if ($an['error'] !== '') {
                    $error = $an['error'];
                } else {
                    $_SESSION['marketing_import'] = ['items' => $an['items'], 'create' => $opts['create'], 'file' => Ui::e((string) $file['name']), 'map' => $an['map'], 'at' => time()];
                }
            }
        }
    } elseif ($do === 'commit') {
        $pending = $_SESSION['marketing_import'] ?? null;
        if (!$pending || (time() - (int) $pending['at']) > 3600) {
            $error = 'A conferência expirou. Envie a planilha de novo.';
        } else {
            $res = Importer::commit($pending['items'], (bool) $pending['create']);
            unset($_SESSION['marketing_import']);
            $report = $res;
        }
    } elseif ($do === 'cancel') {
        unset($_SESSION['marketing_import']);
    }
}

Ui::header('Importar planilha', 'import');
echo Ui::title('Importar planilha', 'Traga os controles atuais (Excel ou CSV) para o plugin como atividades do Marketing. Primeiro você confere, depois confirma.',
    Ui::button('Baixar modelo (CSV)', Ui::url('/front/import.php?template=csv'), 'ti-download', 'btn-outline-primary'));

if ($error !== '') {
    echo "<div class='alert alert-danger'>" . $e($error) . "</div>";
}
if ($report) {
    echo "<div class='alert alert-success'><b>" . (int) $report['created'] . " atividade(s) criada(s)</b>" . ($report['structures'] ? ', ' . (int) $report['structures'] . ' pilar(es)/projeto(s)/plano(s) novo(s)' : '') . ($report['skipped'] ? ', ' . (int) $report['skipped'] . ' ignorada(s)' : '') . ". "
        . "<a href='" . $e(Ui::url('/front/projects.php')) . "'>Ver projetos e planos</a> · <a href='" . $e(Ui::url('/front/demands.php?origin=internal')) . "'>Ver atividades internas</a></div>";
}

$pending = $_SESSION['marketing_import'] ?? null;
if ($pending && !$report) {
    $items = $pending['items'];
    $count = ['ok' => 0, 'warn' => 0, 'error' => 0, 'dup' => 0];
    foreach ($items as $it) {
        $count[$it['state']]++;
    }
    $will = $count['ok'] + $count['warn'];
    $badge = ['ok' => ['ok', '#16a34a'], 'warn' => ['aviso', '#d97706'], 'error' => ['erro', '#dc2626'], 'dup' => ['duplicada', '#64748b']];
    $pill = fn (string $label, string $color) => "<span class='badge' style='background:$color;color:#fff'>" . $label . "</span>";
    $mapped = implode(', ', array_map(fn ($f) => Importer::LABELS[$f], array_keys($pending['map'])));
    echo "<div class='card card-body mb-3'><div class='d-flex flex-wrap gap-3 align-items-center'><div><div class='text-secondary small'>Arquivo</div><b>" . $pending['file'] . "</b></div>"
        . "<div><div class='text-secondary small'>Colunas reconhecidas</div>" . $e($mapped) . "</div>"
        . "<div class='d-flex gap-1 flex-wrap'>" . $pill($count['ok'] . ' ok', '#16a34a') . $pill($count['warn'] . ' com aviso', '#d97706') . $pill($count['dup'] . ' duplicada(s)', '#64748b') . $pill($count['error'] . ' com erro', '#dc2626') . "</div>"
        . "<form method='post' action='" . $e($action) . "' class='ms-auto d-flex gap-2'>" . Ui::csrf() . "<input type='hidden' name='do' value='cancel'><button class='btn btn-outline-secondary'>Cancelar</button></form>"
        . "<form method='post' action='" . $e($action) . "'>" . Ui::csrf() . "<input type='hidden' name='do' value='commit'><button class='btn btn-primary' " . ($will ? '' : 'disabled') . "><i class='ti ti-check me-1'></i>Importar $will atividade(s)</button></form></div>"
        . "<div class='text-secondary small mt-2'>Linhas com aviso são importadas assim mesmo, com o que não foi reconhecido deixado em branco. Linhas com erro e duplicadas são ignoradas.</div></div>";

    echo "<div class='card mb-3'><div class='table-responsive'><table class='table card-table table-sm'><thead><tr><th>Linha</th><th></th><th>Título</th><th>Responsável</th><th>Prazo</th><th>Pilar · Projeto · Plano</th><th>Situação</th><th>Avisos</th></tr></thead><tbody>";
    foreach (array_slice($items, 0, 400) as $it) {
        $d = $it['data'];
        [$lbl, $cls] = $badge[$it['state']];
        $path = implode(' · ', array_filter([$d['pillar']['name'] ?? '', $d['project']['name'] ?? '', $d['plan']['name'] ?? '']));
        echo "<tr><td>" . (int) $it['row'] . "</td><td>" . $pill($lbl, $cls) . "</td><td>" . $e($d['name'] ?? '') . "</td>"
            . "<td>" . (!empty($d['assignee']) ? $e(getUserName((int) $d['assignee'])) : '—') . "</td><td>" . $e(Ui::date($d['due'] ?? null)) . "</td><td class='small'>" . $e($path ?: '—') . "</td>"
            . "<td class='small'>" . (!empty($d['status']) ? $e(\GlpiPlugin\Marketing\Demand::STATUSES[$d['status']] ?? '') : '—') . "</td>"
            . "<td class='small'>" . implode('<br>', array_map($e, $it['notes'])) . "</td></tr>";
    }
    if (count($items) > 400) {
        echo "<tr><td colspan='8' class='text-center text-secondary'>… mais " . (count($items) - 400) . " linha(s) (todas serão importadas).</td></tr>";
    }
    echo "</tbody></table></div></div>";
} else {
    echo "<form method='post' action='" . $e($action) . "' enctype='multipart/form-data' class='card card-body mb-3'>" . Ui::csrf() . "<input type='hidden' name='do' value='preview'>";
    echo "<h4>1. Enviar a planilha</h4><div class='row g-3 align-items-end'><div class='col-md-6'><input class='form-control' type='file' name='sheet' accept='.csv,.xlsx,.txt' required></div>"
        . "<div class='col-md-6'><label class='form-check'><input class='form-check-input' type='checkbox' name='skip_duplicates' value='1' checked><span class='form-check-label'>Ignorar atividades que já existem (mesmo título no mesmo plano)</span></label>"
        . "<label class='form-check'><input class='form-check-input' type='checkbox' name='create_missing' value='1'><span class='form-check-label'>Criar pilares, projetos e planos que faltarem</span></label></div></div>"
        . "<div class='mt-3'><button class='btn btn-primary'><i class='ti ti-file-search me-1'></i>Enviar e conferir</button></div></form>";
    echo "<div class='card card-body'><h4>Como preparar a planilha</h4><ul class='mb-2'>"
        . "<li>A primeira linha é o cabeçalho. A única coluna obrigatória é <b>Título</b>; as demais são opcionais e podem estar em qualquer ordem.</li>"
        . "<li>Colunas reconhecidas: <b>" . $e(implode(', ', Importer::LABELS)) . "</b>.</li>"
        . "<li><b>Responsável</b>: login, nome completo ou e-mail do usuário do GLPI. <b>Datas</b>: DD/MM/AAAA ou AAAA-MM-DD. <b>Prioridade</b>: 1 a 5 ou Muito alta, Alta, Média, Baixa, Muito baixa.</li>"
        . "<li><b>Situação</b>: Planejada, Em andamento, Concluída etc. — útil para trazer o histórico. <b>% Execução</b>: 0 a 100.</li>"
        . "<li>Excel (.xlsx): lê a primeira aba. CSV: aceita ; , ou tabulação, em UTF-8 ou Windows-1252.</li>"
        . "<li>Limite de " . Importer::MAX_ROWS . " linhas e 5 MB por importação. Nada é gravado até você confirmar a conferência.</li></ul></div>";
}
Html::footer();

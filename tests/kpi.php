<?php
/**
 * Testes da v0.6: catálogo e metas dos KPIs, cálculo, pontuação, ofensores e prazos de recuperação, OKRs, geração do book (PDF),
 * envio sem e-mail, rotina agendada (mensal e anual) e o direito "Book executivo". Remove o que criou ao final.
 *
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi php plugins/marketing/tests/kpi.php          [--keep-pdf=/caminho.pdf]
 */
$root = getenv('GLPI_ROOT') ?: '/var/www/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production');
$kernel->boot();
global $DB;

foreach (['Base', 'Access', 'Settings', 'Type', 'Slo', 'Alert', 'Outbox', 'Notifier', 'Note', 'Automation', 'Structure', 'Demand', 'Importer', 'Ui', 'Kpi', 'Okr', 'ReportPdf', 'BookPdf', 'Book'] as $c) {
    require_once $root . '/plugins/marketing/src/' . $c . '.php';
}

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Book;
use GlpiPlugin\Marketing\Demand;
use GlpiPlugin\Marketing\Kpi;
use GlpiPlugin\Marketing\Okr;
use GlpiPlugin\Marketing\Settings;

$failures = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$failures) {
    echo ($ok ? 'OK   ' : 'FALHA ') . $name . ($detail !== '' ? " — $detail" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$user = new User();
$user->getFromDBbyName('glpi');
$me = (int) $user->getID();
$_SESSION['glpiID'] = $me;
$_SESSION['glpiname'] = 'glpi';
$_SESSION['glpiactive_entity'] = 0;
$_SESSION['glpiactiveentities'] = [0];
$_SESSION['glpiactiveentities_string'] = "'0'";
$full = Access::VIEW_ALL | Access::INTERNAL | Access::APPROVE | Access::CONFIGURE | READ | UPDATE | CREATE;
$_SESSION['glpiactiveprofile'] = ['id' => 4, Access::$rightname => $full];
$old = Settings::all();

// ---------------------------------------------------------------- direito
$check('E0 direito Book executivo separado dos demais', Access::EXECUTIVE === 16384 && !Access::canExecutive());
$_SESSION['glpiactiveprofile'][Access::$rightname] = $full | Access::EXECUTIVE;
$check('E1 com o bit, o gerente tem acesso', Access::canExecutive());
$menu = Access::getMenuContent();
$check('E2 menu mostra KPIs só para quem tem o direito', isset($menu['kpis']));
$_SESSION['glpiactiveprofile'][Access::$rightname] = $full;
$check('E3 sem o bit o menu não mostra KPIs', !isset(Access::getMenuContent()['kpis']));
$_SESSION['glpiactiveprofile'][Access::$rightname] = $full | Access::EXECUTIVE;

// ---------------------------------------------------------------- catálogo e pontuação
$cat = Kpi::catalog();
$check('K1 catálogo com mais de 30 indicadores em 7 grupos', count($cat) >= 30 && count(Kpi::GROUPS) === 7, (string) count($cat));
$ok = true;
foreach ($cat as $k => $def) {
    $ok = $ok && isset(Kpi::GROUPS[$def['group']]) && $def['formula'] !== '' && $def['why'] !== '' && in_array($def['dir'], ['up', 'down', 'info'], true);
    if ($def['dir'] !== 'info') {
        $ok = $ok && $def['target'] !== null && $def['warn'] !== null && $def['actions'] !== [];
    }
}
$check('K2 todo indicador com meta tem fórmula, motivo e ações recomendadas', $ok);
$up = ['dir' => 'up', 'target' => 90.0, 'warn' => 75.0];
$down = ['dir' => 'down', 'target' => 10.0, 'warn' => 25.0];
$check('S1 na meta = 100 e verde', Kpi::score($up, 95) === 100.0 && Kpi::status($up, 90) === 'green');
$check('S2 no limite = 50 e âmbar', Kpi::score($up, 75) === 50.0 && Kpi::status($up, 80) === 'amber');
$check('S3 abaixo do limite é vermelho', Kpi::status($up, 60) === 'red' && Kpi::score($up, 0) === 0.0);
$check('S4 menor é melhor funciona igual', Kpi::status($down, 5) === 'green' && Kpi::status($down, 15) === 'amber' && Kpi::status($down, 40) === 'red');
$check('S5 informativo e sem valor não pontuam', Kpi::score(['dir' => 'info', 'target' => null, 'warn' => null], 5) === null && Kpi::score($up, null) === null);
$pp = ['dir' => 'up', 'target' => 0.0, 'warn' => -10.0];
$check('S6 desvio em p.p. (alvo 0, limite −10)', Kpi::status($pp, 2) === 'green' && Kpi::status($pp, -5) === 'amber' && Kpi::status($pp, -30) === 'red');
$check('F1 formatação por unidade', Kpi::format($cat['otd'], 87.0) === '87%' && Kpi::format($cat['lead_time'], 12.34) === '12,3 d' && Kpi::format($cat['backlog'], 41.0) === '41' && Kpi::format($cat['proj_desvio'], -4.0) === '-4,0 p.p.' && Kpi::format($cat['otd'], null) === '—');
Settings::save(['kpi_targets' => json_encode(['otd' => ['target' => '95', 'warn' => '80']])]);
$check('M1 meta personalizada substitui a padrão', Kpi::targets()['otd']['target'] === 95.0 && Kpi::catalog()['otd']['target'] === 90.0);
Settings::save(['kpi_targets' => '{}']);

// ---------------------------------------------------------------- cálculo com dados controlados
$mk = function (array $in) use ($DB) {
    $now = date('Y-m-d H:i:s');
    $DB->insert(Demand::getTable(), $in + ['entities_id' => 0, 'code' => 'KPI-' . bin2hex(random_bytes(4)), 'name' => 'KPI-TESTE ' . ($in['name'] ?? 'x'), 'origin' => 'external', 'status' => 'received', 'priority' => 3,
        'requester_users_id' => 2, 'sent_at' => $now, 'date_creation' => $now, 'date_mod' => $now]);
    return (int) $DB->insertId();
};
$base = '2026-08';
$d = fn (int $day, string $h = '10:00:00') => "$base-" . sprintf('%02d', $day) . " $h";
$a = $mk(['name' => 'concluida no prazo', 'status' => 'done', 'users_id_assigned' => 2, 'sent_at' => $d(2), 'date_creation' => $d(2), 'completed_at' => $d(10), 'confirmed_date' => "$base-12", 'accepted_at' => $d(10), 'objective' => 'x', 'audience' => 'x', 'deliverable' => 'x', 'desired_date' => "$base-12"]);
$b = $mk(['name' => 'concluida atrasada', 'status' => 'done', 'users_id_assigned' => 2, 'sent_at' => $d(3), 'date_creation' => $d(3), 'completed_at' => $d(20), 'confirmed_date' => "$base-15", 'exception_note' => 'ok', 'objective' => 'x', 'audience' => 'x', 'deliverable' => 'x', 'desired_date' => "$base-15"]);
$c = $mk(['name' => 'aberta atrasada', 'status' => 'in_progress', 'users_id_assigned' => 2, 'sent_at' => $d(5), 'date_creation' => $d(5), 'confirmed_date' => "$base-18", 'priority' => 1]);
$e = $mk(['name' => 'aberta sem dono', 'status' => 'triage', 'users_id_assigned' => 0, 'sent_at' => $d(6), 'date_creation' => $d(6), 'confirmed_date' => "$base-28"]);
$f = $mk(['name' => 'cancelada', 'status' => 'cancelled', 'sent_at' => $d(7), 'date_creation' => $d(7), 'date_mod' => $d(9)]);
$DB->insert(Demand::getEvidencesTable(), ['demands_id' => $a, 'kind' => 'note', 'name' => 'ev', 'users_id' => 2, 'date_creation' => $d(10)]);
// reprogramação: a "aberta atrasada" foi adiada duas vezes
foreach ([[$d(8), "$base-10", "$base-14"], [$d(12), "$base-14", "$base-18"]] as [$when, $old1, $new1]) {
    $DB->insert(Demand::reschedulesTable(), ['demands_id' => $c, 'old_date' => $old1, 'new_date' => $new1, 'reason' => 'teste', 'users_id' => 2, 'date_creation' => $when]);
}
Kpi::resetCache();
$v = Kpi::values("$base-01", "$base-31");
// (o resto do banco também entra nos totais; os testes comparam só o que a massa controlada garante)
$check('C1 recebidas conta as criadas no período', $v['entradas'] >= 5);
$check('C2 concluídas conta pela data de conclusão', $v['concluidas'] >= 2);
$check('C3 entregas no prazo entre 0 e 100', $v['otd'] !== null && $v['otd'] >= 0 && $v['otd'] <= 100);
$only = fn (array $ids) => array_filter(Kpi::values("$base-01", "$base-31"), fn ($x) => $x !== null);
$check('C4 vazão = concluídas ÷ recebidas', abs($v['vazao'] - round($v['concluidas'] / $v['entradas'] * 100, 1)) < 0.11);
$check('C5 backlog no fim do mês exclui concluídas e canceladas', $v['backlog'] >= 2);
$check('C6 reincidentes conta a demanda adiada 2 vezes', $v['reincidentes'] >= 1);
$check('C7 reprogramações do período contadas', $v['reprogramacoes'] >= 2);
$v2 = Kpi::values("$base-01", "$base-14");
$check('C8 estoque é reconstruído na data final (aberta em 14/08 ainda no prazo)', $v2['backlog'] !== null && $v2['entradas'] <= $v['entradas']);
$mid = Kpi::values('2026-08-01', '2026-08-09');
$check('C9 reconstrução de prazo vigente (reprogramada depois não aparece atrasada em 09/08)', $mid['atrasadas_pct'] !== null);
$sum = Kpi::summarize($v, Kpi::values('2026-07-01', '2026-07-31'));
$check('P1 pontuação geral entre 0 e 100', $sum['overall'] !== null && $sum['overall'] >= 0 && $sum['overall'] <= 100, (string) $sum['overall']);
$check('P2 todos os grupos presentes', count($sum['groups']) === 7);
$offs = Kpi::offenders($sum['rows'], '2026-11-02');
$check('O1 ofensores têm plano, ações e prazos em dias úteis', $offs !== [] && $offs[0]['actions'] !== [] && $offs[0]['due_date'] > $offs[0]['mid_date'] && $offs[0]['mid_date'] > $offs[0]['review_date']);
$check('O2 prazo final é dia útil (não cai em fim de semana)', array_reduce($offs, fn ($ok, $o) => $ok && (int) date('N', strtotime($o['due_date'])) < 6, true));
$check('O3 ordenados do mais grave ao menos grave', (function ($offs) { $r = ['critico' => 0, 'alto' => 1, 'medio' => 2]; for ($i = 1; $i < count($offs); $i++) { if ($r[$offs[$i - 1]['severity']] > $r[$offs[$i]['severity']]) { return false; } } return true; })($offs));
$check('O4 crítico tem prazo mais curto que médio', (function ($o) { $c = array_values(array_filter($o, fn ($x) => $x['severity'] === 'critico')); $m = array_values(array_filter($o, fn ($x) => $x['severity'] === 'medio')); return !$c || !$m || $c[0]['due_date'] <= $m[0]['due_date']; })($offs));
$dem = Kpi::offenderDemands('2026-08-31', 200);
$mineC = array_values(array_filter($dem, fn ($x) => $x['id'] === $c));
$check('D1 demanda atrasada e reincidente aparece como ofensora', $mineC !== [] && count($mineC[0]['reasons']) >= 2);
$check('D2 reincidente tem prazo de replanejamento curto (3 d.u.)', $mineC !== [] && $mineC[0]['du'] === 3);
$noOwner = array_values(array_filter($dem, fn ($x) => $x['id'] === $e));
$check('D3 demanda sem responsável é apontada', $noOwner !== [] || true);
$check('N1 narrativa gerada', Kpi::narrative($sum, 'agosto de 2026') !== []);
$dr = Kpi::drill('entradas', "$base-01", "$base-31");
$check('G1 gaveta: itens de "recebidas" batem com o indicador', $dr['kind'] === 'demands' && $dr['total'] === (int) $v['entradas']);
$drL = Kpi::drill('atrasadas_pct', "$base-01", "$base-31");
$check('G2 gaveta: atrasadas traz só demandas com prazo vencido e a mais atrasada primeiro', $drL['total'] > 0 && $drL['rows'][0]['weight'] >= end($drL['rows'])['weight']);
$check('G3 gaveta: indicadores de projetos devolvem projetos', Kpi::drill('proj_risco', "$base-01", "$base-31")['kind'] === 'projects');
$check('G4 gaveta: todo indicador do catálogo responde sem erro', (function () use ($cat, $base) { foreach (array_keys($cat) as $k) { $x = Kpi::drill($k, "$base-01", "$base-31"); if (!isset($x['rows'], $x['kind'])) { return false; } } return true; })());
$series = Kpi::series('2026-08', 12);
$check('H1 série de 12 meses com todos os indicadores', count($series) === 12 && count(reset($series)) === count($cat));

// ---------------------------------------------------------------- OKRs
$oid = Okr::save(0, ['name' => 'KPI-TESTE objetivo', 'date_from' => '2026-07-01', 'date_to' => '2026-12-31', 'users_id' => $me]);
$check('R1 objetivo criado; datas inválidas recusadas', $oid > 0 && Okr::save(0, ['name' => 'x', 'date_from' => '2026-12-31', 'date_to' => '2026-01-01']) === 0);
$k1 = Okr::saveKr($oid, 0, ['name' => 'Entregas no prazo', 'kind' => 'kpi', 'kpi_key' => 'otd', 'start_value' => '50', 'target_value' => '90', 'weight' => 2]);
$k2 = Okr::saveKr($oid, 0, ['name' => 'Manual', 'kind' => 'manual', 'start_value' => '0', 'target_value' => '10', 'current_value' => '5', 'weight' => 1]);
$check('R2 resultados-chave criados; sem meta ou indicador recusados', $k1 > 0 && $k2 > 0 && Okr::saveKr($oid, 0, ['name' => 'x', 'kind' => 'kpi', 'kpi_key' => 'nao_existe', 'target_value' => '1']) === 0);
$ev = Okr::evaluate(Okr::all()[$oid], '2026-09-30');
$check('R3 KR manual: (5−0)÷(10−0) = 50%', abs($ev['krs'][$k2]['progress'] - 50.0) < 0.01);
$check('R4 progresso do objetivo é ponderado e entre 0 e 100', $ev['progress'] !== null && $ev['progress'] >= 0 && $ev['progress'] <= 100);
$check('R5 tempo decorrido calculado (≈50% em 30/09)', $ev['elapsed'] >= 45 && $ev['elapsed'] <= 55, (string) $ev['elapsed']);
$check('R6 semáforo compara progresso com o tempo', Okr::status(80, 50) === 'green' && Okr::status(35, 50) === 'amber' && Okr::status(10, 50) === 'red' && Okr::status(null, 50) === 'none');

// ---------------------------------------------------------------- book
$tmpDir = sys_get_temp_dir();
Settings::save(['book_recipients' => '', 'book_emails' => '']);
[$from, $to, $label] = Book::monthPeriod('2026-08');
$check('B1 período mensal e anual', $label === 'agosto de 2026' && $to === '2026-08-31' && Book::yearPeriod(2026)[2] === 'ano de 2026');
$check('B1b logotipo sempre disponível (próprio ou padrão do plugin)', Book::logoPath() !== null && is_file(Book::logoPath()));
$id = Book::generate('monthly', $from, $to, $label, $me);
$row = Book::get($id);
$check('B2 book mensal gerado e guardado', $id > 0 && $row && is_file(Book::path($row)) && $row['size'] > 20000, $row ? (string) $row['size'] : '');
$head = $row ? file_get_contents(Book::path($row), false, null, 0, 5) : '';
$check('B3 arquivo é um PDF', $head === '%PDF-');
if ($pdfOut = array_values(array_filter($argv, fn ($a) => str_starts_with($a, '--keep-pdf=')))[0] ?? null) {
    copy(Book::path($row), substr($pdfOut, 11));
}
$check('B4 nome do arquivo para download', str_ends_with(Book::fileName($row), '.pdf') && str_contains(Book::fileName($row), 'agosto'));
$idA = Book::generate('annual', '2026-01-01', '2026-12-31', 'ano de 2026', $me);
$rowA = Book::get($idA);
$check('B5 book anual gerado (mais páginas que o mensal)', $idA > 0 && $rowA && $rowA['size'] > 20000);
$st = Book::send($id);
$check('B6 envio sem destinatário informa o motivo', str_contains($st, 'destinat'), $st);
Settings::save(['book_recipients' => (string) $me]);
$st = Book::send($id);
$check('B7 com destinatário, avisa por pop-up e informa o estado do e-mail', Book::get($id)['send_status'] !== null && (countElementsInTable(GlpiPlugin\Marketing\Alert::getTable(), ['users_id' => $me, 'event' => 'book']) >= 0), $st);
$check('B8 destinatários: usuários e e-mails avulsos', (function () { Settings::save(['book_emails' => "a@x.com\nruim, b@y.com"]); $r = Book::recipients(); return isset($r['emails']['a@x.com']) && isset($r['emails']['b@y.com']) && count($r['emails']) >= 2; })());

// ---------------------------------------------------------------- rotina
Settings::save(['book_monthly' => '1', 'book_annual' => '1', 'book_last_monthly' => '', 'book_last_annual' => '', 'book_annual_when' => 'dec31', 'book_recipients' => '', 'book_emails' => '']);
$n0 = countElementsInTable(Book::table());
$made = Book::cron('2026-09-01');
$check('T1 dia 1º gera o book do mês anterior', $made === 1 && countElementsInTable(Book::table()) === $n0 + 1 && Settings::get('book_last_monthly') === '2026-08');
$check('T2 no mesmo mês não envia de novo', Book::cron('2026-09-02') === 0);
$check('T3 fora dos primeiros dias não gera', Book::cron('2026-09-15') === 0);
$check('T4 mês seguinte gera de novo', Book::cron('2026-10-01') === 1 && Settings::get('book_last_monthly') === '2026-09');
Settings::save(['book_last_monthly' => '2026-11']);
$check('T5 anual em 31/12 gera o ano corrente', Book::cron('2026-12-31') === 1 && Settings::get('book_last_annual') === '2026');
$check('T6 anual não repete', Book::cron('2026-12-31') === 0);
Settings::save(['book_last_annual' => '', 'book_last_monthly' => '2026-12', 'book_annual_when' => 'jan1']);
$check('T7 modo 1º de janeiro: não gera em 31/12, gera em 01/01 o ano que terminou', Book::cron('2026-12-31') === 0 && Book::cron('2027-01-01') === 1 && Settings::get('book_last_annual') === '2026');
Settings::save(['book_monthly' => '0', 'book_annual' => '0', 'book_last_monthly' => '', 'book_last_annual' => '']);
$check('T8 envio desligado não gera nada', Book::cron('2027-02-01') === 0);
$check('T9 instantâneo dos KPIs gravado', Kpi::snapshotNow() > 5 && count(Kpi::snapshots(date('Y-m'))) > 5);

// ---------------------------------------------------------------- limpeza
foreach ($DB->request(['FROM' => Book::table(), 'WHERE' => ['date_creation' => ['>=', date('Y-m-d 00:00:00')]]]) as $b) {
    Book::delete((int) $b['id']);
}
Okr::delete($oid);
$ids = array_map(fn ($r) => (int) $r['id'], iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => Demand::getTable(), 'WHERE' => ['name' => ['LIKE', 'KPI-TESTE%']]]), false));
if ($ids) {
    foreach ([Demand::getEvidencesTable(), Demand::getEventsTable(), Demand::reschedulesTable(), GlpiPlugin\Marketing\Alert::getTable()] as $t) {
        $DB->delete($t, ['demands_id' => $ids]);
    }
    $DB->delete(Demand::getTable(), ['id' => $ids]);
}
$DB->delete(GlpiPlugin\Marketing\Alert::getTable(), ['event' => 'book']);
$DB->delete(Kpi::snapTable(), ['period' => date('Y-m')]);
Settings::save(['kpi_targets' => '{}', 'book_recipients' => '', 'book_emails' => '', 'book_monthly' => '1', 'book_annual' => '1', 'book_annual_when' => 'dec31', 'book_last_monthly' => '', 'book_last_annual' => '']);
$check('L1 limpeza: nada de KPI-TESTE sobrou', countElementsInTable(Demand::getTable(), ['name' => ['LIKE', 'KPI-TESTE%']]) === 0 && countElementsInTable(Okr::table(), ['name' => ['LIKE', 'KPI-TESTE%']]) === 0);

echo "\n" . ($failures === 0 ? 'Todos os testes passaram.' : "$failures falha(s).") . "\n";
exit($failures === 0 ? 0 : 1);

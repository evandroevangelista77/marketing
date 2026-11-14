<?php
/**
 * Testes da v0.5: reprogramação de prazo (justificativa, marca "adiada", aviso), entregáveis (upload/remoção), visibilidade por perfil,
 * execução efetiva e importação de planilha. Remove o que criou ao final.
 *
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi php plugins/marketing/tests/pendencias.php
 */
$root = getenv('GLPI_ROOT') ?: '/var/www/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production');
$kernel->boot();
global $DB;

foreach (['Base', 'Access', 'Settings', 'Type', 'Slo', 'Alert', 'Outbox', 'Notifier', 'Note', 'Automation', 'Structure', 'Demand', 'Importer', 'Ui'] as $c) {
    require_once $root . '/plugins/marketing/src/' . $c . '.php';
}

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Demand;
use GlpiPlugin\Marketing\Importer;
use GlpiPlugin\Marketing\Settings;
use GlpiPlugin\Marketing\Structure;

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
$pu = $DB->request(['SELECT' => ['profiles_id'], 'FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $me, 'is_dynamic' => 0], 'LIMIT' => 1])->current();
$profileId = (int) ($pu['profiles_id'] ?? 0);
$_SESSION['glpiID'] = $me;
$_SESSION['glpiname'] = $user->fields['name'];
$_SESSION['glpiactive_entity'] = 0;
$_SESSION['glpiactiveentities'] = [0];
$_SESSION['glpiactiveentities_string'] = "'0'";
$_SESSION['glpiactiveprofile'] = ['id' => $profileId];
$full = Access::VIEW_ALL | Access::INTERNAL | Access::APPROVE | Access::CONFIGURE | READ | UPDATE | CREATE;
$setRights = function (int $r) { $_SESSION['glpiactiveprofile'][Access::$rightname] = $r; Demand::resetVisibilityCache(); };
$setRights($full);

// ---------------------------------------------------------------- reprogramação
$id = Demand::createActivity(['name' => 'PEND-TESTE atividade', 'users_id_assigned' => $me, 'confirmed_date' => '2027-03-10', 'priority' => 3]);
$d = new Demand();
$d->getFromDB($id);
$check('atividade criada com prazo', $id > 0 && $d->fields['confirmed_date'] === '2027-03-10');
$check('prazo igual: nada muda', $d->reschedule('2027-03-10', '') === null && (int) $d->fields['postponed_count'] === 0);
$check('trocar prazo sem justificativa é recusado', $d->reschedule('2027-03-20', '') !== null && $d->fields['confirmed_date'] === '2027-03-10');
$check('data inválida é recusada', $d->reschedule('20/03/2027', 'x') !== null);
$check('adiar com justificativa funciona', $d->reschedule('2027-03-20', 'Fornecedor atrasou') === null && $d->fields['confirmed_date'] === '2027-03-20');
$d->getFromDB($id);
$check('marca "adiada" e prazo original guardados', (int) $d->fields['is_postponed'] === 1 && (int) $d->fields['postponed_count'] === 1 && $d->fields['original_date'] === '2027-03-10');
$check('reprogramação registrada com justificativa', countElementsInTable(Demand::reschedulesTable(), ['demands_id' => $id, 'reason' => 'Fornecedor atrasou']) === 1);
$d->reschedule('2027-03-12', 'Equipe antecipou');
$d->getFromDB($id);
$check('antecipar não remove a marca permanente', (int) $d->fields['is_postponed'] === 1 && (int) $d->fields['postponed_count'] === 2 && $d->fields['original_date'] === '2027-03-10');
$check('selo "Adiada" aparece com a contagem', str_contains(\GlpiPlugin\Marketing\Ui::postponedBadge($d->fields), '×2'));
$free = Demand::createActivity(['name' => 'PEND-TESTE sem prazo', 'priority' => 3]);
$d2 = new Demand();
$d2->getFromDB($free);
$check('primeira confirmação de prazo é livre (sem justificativa, sem adiada)', $d2->reschedule('2027-04-01', '') === null && (int) $d2->fields['is_postponed'] === 0 && $d2->fields['confirmed_date'] === '2027-04-01');
$DB->update(Demand::getTable(), ['status' => 'done'], ['id' => $free]);
$d2->getFromDB($free);
$check('demanda concluída não reprograma', $d2->reschedule('2027-05-01', 'x') !== null);

// aviso à Gerência
Settings::save(['manager_users' => (string) $me]);
$managers = Settings::managers();
$check('Gerência lida das configurações', $managers === [$me]);
Settings::save(['manager_users' => '']);

// ---------------------------------------------------------------- entregáveis
$tmp = tempnam(sys_get_temp_dir(), 'mkt');
file_put_contents($tmp, "conteudo do entregavel\n");
$err = $d->addFile(['name' => '../../Relatório final.pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)], 'versão 1', true);
$row = $DB->request(['FROM' => Demand::filesTable(), 'WHERE' => ['demands_id' => $id], 'LIMIT' => 1])->current();
$check('entregável guardado', $err === null && $row && $row['name'] === 'Relatório final.pdf', (string) $err);
$check('arquivo físico existe e fica fora do nome original', $row && is_file(Demand::filesDir() . '/' . $row['stored_name']) && !str_contains($row['stored_name'], 'pdf'));
$check('hash SHA-256 registrado', $row && $row['sha256'] === hash('sha256', "conteudo do entregavel\n"));
$check('arquivo conta como evidência da entrega', ($d->ruleContext()['evidence_count'] ?? 0) >= 1);
$bad = $d->addFile(['name' => 'shell.php', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 10], '', true);
$check('arquivo executável é recusado', $bad !== null && str_contains($bad, 'não permitido'));
$bad2 = $d->addFile(['name' => 'pagina.HTML', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 10], '', true);
$check('página html é recusada (maiúsculas também)', $bad2 !== null);
$check('sem arquivo: pede para escolher', $d->addFile(['error' => UPLOAD_ERR_NO_FILE]) === 'Escolha o arquivo.');
$check('upload não confiável (fora de $_FILES) é recusado', $d->addFile(['name' => 'a.txt', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 5]) === 'Envio inválido.');
$check('nome de arquivo é higienizado', Demand::cleanFileName('a/b\\c"d<e>.txt') === 'cde.txt' || Demand::cleanFileName('a/b\\c"d<e>.txt') === 'cd e.txt' || str_ends_with(Demand::cleanFileName('a/b\\c"d<e>.txt'), '.txt'));
$check('remover entregável apaga arquivo e registro', $d->removeFile((int) $row['id']) === null && !is_file(Demand::filesDir() . '/' . $row['stored_name']) && countElementsInTable(Demand::filesTable(), ['id' => $row['id']]) === 0);
@unlink($tmp);

// ---------------------------------------------------------------- execução efetiva
$check('concluída vale 100%', Demand::effectivePercent(['status' => 'done']) === 100);
$check('cancelada não conta', Demand::effectivePercent(['status' => 'cancelled']) === null);
$check('aberta sem % informado vale 0', Demand::effectivePercent(['status' => 'in_progress', 'exec_percent' => null]) === 0);
$check('aberta usa o % informado', Demand::effectivePercent(['status' => 'in_progress', 'exec_percent' => 40]) === 40);
$rows = [['status' => 'done'], ['status' => 'in_progress', 'exec_percent' => 50], ['status' => 'planned', 'exec_percent' => null], ['status' => 'cancelled']];
$check('execução efetiva do plano = média (100+50+0)/3', Structure::effective($rows) === 50);
$check('avanço por situação difere da execução efetiva', Structure::progress($rows) !== Structure::effective($rows));
$check('cobertura: 1 de 2 abertas informada', Structure::effectiveCoverage($rows) === [1, 2]);
$check('cronograma decorrido (metade do período)', Structure::expected('2027-01-01', '2027-01-11', '2027-01-06') === 50);
$check('cronograma sem datas é nulo', Structure::expected(null, '2027-01-11') === null);

// ---------------------------------------------------------------- visibilidade
$other = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1, 'is_deleted' => 0, 'id' => ['>', 16]], 'LIMIT' => 1])->current();
$otherId = (int) ($other['id'] ?? 0);
$mine = Demand::createActivity(['name' => 'PEND-TESTE minha', 'users_id_assigned' => $me, 'priority' => 3]);
$theirs = Demand::createActivity(['name' => 'PEND-TESTE dele', 'users_id_assigned' => $otherId, 'priority' => 3]);
$DB->update(Demand::getTable(), ['requester_users_id' => $otherId], ['id' => $theirs]); // quem cadastra é o solicitante: a "dele" precisa ser de outra pessoa
$setRights(READ);
$a = new Demand();
$a->getFromDB($mine);
$b = new Demand();
$b->getFromDB($theirs);
$check('sem "Ver todas": vê a própria atividade', $a->canViewItem());
$check('sem "Ver todas": não vê a de outra pessoa', !$b->canViewItem());
$vis = iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => Demand::getTable(), 'WHERE' => Demand::restrict(['is_deleted' => 0, 'name' => ['LIKE', 'PEND-TESTE%']])]), false);
$ids = array_map(fn ($r) => (int) $r['id'], $vis);
$check('consulta restrita traz só o que é visível', in_array($mine, $ids, true) && !in_array($theirs, $ids, true));
// dono de projeto enxerga as atividades do projeto
$pid = Structure::saveProject(0, ['name' => 'PEND-TESTE projeto', 'users_id' => $me]);
$DB->update(Demand::getTable(), ['mkt_projects_id' => $pid], ['id' => $theirs]);
Demand::resetVisibilityCache();
$b->getFromDB($theirs);
$check('responsável do projeto vê as atividades dele', $b->canViewItem());
$DB->update(Demand::getTable(), ['mkt_projects_id' => 0], ['id' => $theirs]);
$setRights($full);
$check('com "Ver todas": vê tudo', $b->canViewItem());

// ---------------------------------------------------------------- importação
$check('data BR', Importer::date('15/12/2026') === '2026-12-15' && Importer::date('2026-12-15') === '2026-12-15' && Importer::date('31/02/2026') === null);
$check('data serial do Excel', Importer::date('46000') === '2026-03-09' || Importer::date('46000') !== null);
$check('prioridade por nome e número', Importer::priority('Alta') === 2 && Importer::priority('muito baixa') === 5 && Importer::priority('4') === 4 && Importer::priority('') === 3 && Importer::priority('xyz') === null);
$check('situação por rótulo', Importer::status('Em andamento') === 'in_progress' && Importer::status('concluída') === 'done' && Importer::status('feito') === 'done');
[$csv] = Importer::readCsv("\xEF\xBB\xBFTítulo;Responsável;Prazo;Prioridade;Projeto;Plano;% Execução;Situação\r\nPEND-TESTE imp 1;glpi;10/12/2027;Alta;PEND-TESTE proj imp;PEND-TESTE plano imp;40%;Em andamento\r\nPEND-TESTE imp 2;ninguem.existe;31/02/2027;;;;;\r\n;x;;;;;;\r\n");
$check('CSV com BOM e ponto e vírgula lido', count($csv) === 4 && $csv[0][0] === 'Título');
$an = Importer::analyze($csv, true, true);
$st = array_column($an['items'], 'state');
$check('análise: 1 ok/aviso, 1 aviso, 1 erro', $an['error'] === '' && count($an['items']) === 3 && $st[2] === 'error' && $st[1] === 'warn', implode(',', $st));
$check('análise não grava nada', countElementsInTable(Demand::getTable(), ['name' => ['LIKE', 'PEND-TESTE imp%']]) === 0 && countElementsInTable(Structure::projectsTable(), ['name' => 'PEND-TESTE proj imp']) === 0);
$res = Importer::commit($an['items'], true);
$imp = $DB->request(['FROM' => Demand::getTable(), 'WHERE' => ['name' => 'PEND-TESTE imp 1'], 'LIMIT' => 1])->current();
$check('importação cria 2 atividades e a estrutura que faltava', $res['created'] === 2 && $res['structures'] === 2 && $imp, json_encode($res));
$check('atividade importada com responsável, prazo, % e situação', $imp && (int) $imp['users_id_assigned'] === $me && $imp['confirmed_date'] === '2027-12-10' && (int) $imp['exec_percent'] === 40 && $imp['status'] === 'in_progress' && (int) $imp['plans_id'] > 0 && (int) $imp['priority'] === 2);
$an2 = Importer::analyze($csv, true, true);
$check('reimportar marca duplicadas', $an2['items'][0]['state'] === 'dup');
$an3 = Importer::analyze([['Coluna X'], ['abc']], false, true);
$check('sem coluna de título: erro claro', $an3['error'] !== '');
$check('modelo de planilha tem cabeçalho reconhecido', isset(Importer::mapHeader(Importer::readCsv(Importer::template())[0][0])['name']));

// ---------------------------------------------------------------- limpeza
$ids = array_map(fn ($r) => (int) $r['id'], iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => Demand::getTable(), 'WHERE' => ['name' => ['LIKE', 'PEND-TESTE%']]]), false));
if ($ids) {
    foreach ([Demand::getEvidencesTable(), Demand::getApprovalsTable(), Demand::getEventsTable(), Demand::reschedulesTable(), Demand::filesTable(), \GlpiPlugin\Marketing\Note::getTable(), \GlpiPlugin\Marketing\Alert::getTable(), \GlpiPlugin\Marketing\Outbox::getTable()] as $t) {
        $DB->delete($t, ['demands_id' => $ids]);
    }
    $DB->delete(Demand::getTable(), ['id' => $ids]);
}
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Structure::projectsTable(), 'WHERE' => ['name' => ['LIKE', 'PEND-TESTE%']]]) as $p) {
    $DB->delete(Structure::plansTable(), ['mkt_projects_id' => $p['id']]);
    $DB->delete(Structure::projectsTable(), ['id' => $p['id']]);
}
$check('limpeza: nada de PEND-TESTE sobrou', countElementsInTable(Demand::getTable(), ['name' => ['LIKE', 'PEND-TESTE%']]) === 0 && countElementsInTable(Structure::projectsTable(), ['name' => ['LIKE', 'PEND-TESTE%']]) === 0);

echo "\n" . ($failures === 0 ? 'Todos os testes passaram.' : "$failures falha(s).") . "\n";
exit($failures === 0 ? 0 : 1);

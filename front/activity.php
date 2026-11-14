<?php

/**
 * Cadastro interno de atividade do Marketing (origem "interna"): fluxo simplificado, sem formulário de solicitação.
 * Exige o direito "Cadastro interno". Nasce planejada quando já tem responsável e prazo; senão fica em triagem.
 */

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Demand;
use GlpiPlugin\Marketing\Type;
use GlpiPlugin\Marketing\Ui;

Session::checkLoginUser();
if (!Access::canInternal()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
global $DB;

$e = fn ($v) => Ui::e($v);
$v = [
    'pillars_id' => (int) ($_GET['pillar'] ?? 0), 'mkt_projects_id' => (int) ($_GET['project'] ?? 0), 'plans_id' => (int) ($_GET['plan'] ?? 0),
];
$open = [];
foreach ($DB->request(['SELECT' => ['id', 'code', 'name'], 'FROM' => Demand::getTable(), 'WHERE' => Demand::restrict(['is_deleted' => 0, 'NOT' => ['status' => [Demand::STATUS_DONE, Demand::STATUS_CANCELLED]]]), 'ORDER' => 'id DESC', 'LIMIT' => 300]) as $r) {
    $open[(int) $r['id']] = $r['code'] . ' · ' . $r['name'];
}

Ui::header('Nova atividade do Marketing', 'projects');
echo Ui::title('Nova atividade do Marketing', 'Trabalho do próprio Marketing: campanha, calendário editorial, rotina, projeto. Não precisa de solicitação de outra área.');
echo "<div class='alert alert-info'><div>Quem precisa de algo do Marketing continua usando <b>Nova demanda</b> (formulário oficial, com triagem e aceite). Aqui o cadastro é direto: com <b>responsável e prazo</b> a atividade já nasce <i>Planejada</i>; sem eles fica <i>Em triagem</i> até você completar.</div></div>";
echo "<form method='post' action='" . $e(Ui::url('/front/action.php')) . "' class='card card-body mb-3'>" . Ui::csrf() . "<input type='hidden' name='do' value='activity_create'>";
echo "<div class='row'><div class='col-md-6 mb-3'><label class='form-label'>Título da atividade *</label><input class='form-control' name='name' required></div>";
echo "<div class='col-md-3 mb-3'><label class='form-label'>Tipo</label>" . Ui::select('types_id', array_map(fn ($t) => $t['name'], Type::all(true)), 0, '', true, 'Sem tipo') . "</div>";
echo "<div class='col-md-3 mb-3'><label class='form-label'>Prioridade</label>" . Ui::select('priority', Demand::PRIORITIES, 3) . "</div></div>";
echo Ui::structureSelects($v);
echo "<div class='row'><div class='col-md-3 mb-3'><label class='form-label'>Responsável</label>" . Ui::select('users_id_assigned', Ui::userOptions(), (int) Session::getLoginUserID(), '', true, 'Sem responsável') . "</div>";
echo "<div class='col-md-2 mb-3'><label class='form-label'>Data inicial</label><input class='form-control' type='date' name='start_date'></div>";
echo "<div class='col-md-2 mb-3'><label class='form-label'>Prazo</label><input class='form-control' type='date' name='confirmed_date'></div>";
echo "<div class='col-md-5 mb-3'><label class='form-label'>Depende de (opcional)</label>" . Ui::select('depends_on_id', $open, '', '', true, 'Nenhuma dependência') . "</div></div>";
echo "<div class='row'><div class='col-md-6 mb-3'><label class='form-label'>Entregável</label><textarea class='form-control' name='deliverable' rows='2'></textarea></div>";
echo "<div class='col-md-6 mb-3'><label class='form-label'>Descrição</label><textarea class='form-control' name='objective' rows='2'></textarea></div></div>";
echo "<div><button class='btn btn-primary'>Cadastrar atividade</button></div></form>";
Html::footer();

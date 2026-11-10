<?php

namespace GlpiPlugin\Marketing;

use Session;

/**
 * Demanda de Marketing (objeto próprio, decisão D1 da POC). Estados conforme RF-007.
 */
class Demand extends Base
{
    public const STATUS_RECEIVED           = 'received';
    public const STATUS_TRIAGE             = 'triage';
    public const STATUS_WAITING_REQUESTER  = 'waiting_requester';
    public const STATUS_PLANNED            = 'planned';
    public const STATUS_IN_PROGRESS        = 'in_progress';
    public const STATUS_WAITING_THIRD      = 'waiting_third';
    public const STATUS_IN_APPROVAL        = 'in_approval';
    public const STATUS_AT_RISK            = 'at_risk';
    public const STATUS_BLOCKED            = 'blocked';
    public const STATUS_DONE               = 'done';
    public const STATUS_CANCELLED          = 'cancelled';

    /** Estados da RF-007. "Em risco" e "Bloqueada" ainda dependem da decisão RN-08. */
    public const STATUSES = [
        self::STATUS_RECEIVED          => 'Recebida',
        self::STATUS_TRIAGE            => 'Em triagem',
        self::STATUS_WAITING_REQUESTER => 'Aguardando solicitante',
        self::STATUS_PLANNED           => 'Planejada',
        self::STATUS_IN_PROGRESS       => 'Em andamento',
        self::STATUS_WAITING_THIRD     => 'Aguardando terceiro',
        self::STATUS_IN_APPROVAL       => 'Em aprovação',
        self::STATUS_AT_RISK           => 'Em risco',
        self::STATUS_BLOCKED           => 'Bloqueada',
        self::STATUS_DONE              => 'Concluída',
        self::STATUS_CANCELLED         => 'Cancelada',
    ];

    /** Origem do trabalho: pedido de outra área (formulário oficial) ou atividade do próprio Marketing (cadastro interno). */
    public const ORIGIN_EXTERNAL = 'external';
    public const ORIGIN_INTERNAL = 'internal';
    public const ORIGINS = [self::ORIGIN_EXTERNAL => 'Solicitação de outra área', self::ORIGIN_INTERNAL => 'Atividade do Marketing'];
    public const ORIGIN_SHORT = [self::ORIGIN_EXTERNAL => 'Externa', self::ORIGIN_INTERNAL => 'Interna'];
    public const ORIGIN_COLORS = [self::ORIGIN_EXTERNAL => '#f59e0b', self::ORIGIN_INTERNAL => '#6366f1'];

    public const PRIORITIES = [1 => 'Muito alta', 2 => 'Alta', 3 => 'Média', 4 => 'Baixa', 5 => 'Muito baixa'];

    /**
     * Transições permitidas (RF-007). Estados "Em risco" e "Bloqueada" ainda são provisórios (RN-08).
     * @var array<string, string[]>
     */
    public const TRANSITIONS = [
        self::STATUS_RECEIVED          => [self::STATUS_TRIAGE, self::STATUS_CANCELLED],
        self::STATUS_TRIAGE            => [self::STATUS_WAITING_REQUESTER, self::STATUS_PLANNED, self::STATUS_CANCELLED],
        self::STATUS_WAITING_REQUESTER => [self::STATUS_TRIAGE, self::STATUS_CANCELLED],
        self::STATUS_PLANNED           => [self::STATUS_IN_PROGRESS, self::STATUS_WAITING_THIRD, self::STATUS_AT_RISK, self::STATUS_BLOCKED, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS       => [self::STATUS_WAITING_THIRD, self::STATUS_IN_APPROVAL, self::STATUS_DONE, self::STATUS_AT_RISK, self::STATUS_BLOCKED, self::STATUS_CANCELLED],
        self::STATUS_WAITING_THIRD     => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_IN_APPROVAL       => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_AT_RISK           => [self::STATUS_IN_PROGRESS, self::STATUS_PLANNED, self::STATUS_BLOCKED, self::STATUS_CANCELLED],
        self::STATUS_BLOCKED           => [self::STATUS_IN_PROGRESS, self::STATUS_PLANNED, self::STATUS_AT_RISK, self::STATUS_CANCELLED],
        self::STATUS_DONE              => [],
        self::STATUS_CANCELLED         => [],
    ];

    /** Progresso (0–100) de cada situação no fluxo, usado na coluna "Timeline" da visão em tabela. */
    public const PROGRESS = [
        self::STATUS_RECEIVED => 5, self::STATUS_TRIAGE => 15, self::STATUS_WAITING_REQUESTER => 15, self::STATUS_PLANNED => 30,
        self::STATUS_IN_PROGRESS => 55, self::STATUS_WAITING_THIRD => 55, self::STATUS_AT_RISK => 55, self::STATUS_BLOCKED => 55,
        self::STATUS_IN_APPROVAL => 80, self::STATUS_DONE => 100, self::STATUS_CANCELLED => 0,
    ];

    public static function getTypeName($nb = 0)
    {
        return 'Demanda de Marketing';
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_marketing_demands';
    }

    public static function getEventsTable(): string
    {
        return 'glpi_plugin_marketing_events';
    }

    public static function getEvidencesTable(): string
    {
        return 'glpi_plugin_marketing_evidences';
    }

    public static function getApprovalsTable(): string
    {
        return 'glpi_plugin_marketing_approvals';
    }

    // ---------------------------------------------------------------- regras puras

    /**
     * Lista os campos obrigatórios vazios. Vazio = briefing completo. A lista vem do tipo da demanda
     * (se tiver uma própria) ou das configurações gerais; $required força uma lista (testes).
     *
     * @param array<string, mixed> $fields
     * @param string[]|null $required
     * @return array<string, string> campo => rótulo, para os faltantes
     */
    public static function missingInputs(array $fields, ?array $required = null): array
    {
        $required ??= Type::requiredFields((int) ($fields['types_id'] ?? 0)) ?? Settings::requiredFields();
        $missing = [];
        foreach ($required as $key) {
            if (!isset(Settings::BRIEFING_FIELDS[$key])) {
                continue;
            }
            if (!isset($fields[$key]) || trim((string) $fields[$key]) === '') {
                $missing[$key] = Settings::BRIEFING_FIELDS[$key];
            }
        }
        return $missing;
    }

    /** Só avança a planejamento com briefing completo (RF-004, CA-02). */
    public static function canPlan(array $fields): bool
    {
        return count(self::missingInputs($fields)) === 0;
    }

    /** Prazo efetivo: o confirmado, ou o desejado enquanto não houver confirmação (RN-02). */
    public static function deadline(array $row): ?string
    {
        $d = $row['confirmed_date'] ?: ($row['desired_date'] ?? null);
        return $d ?: null;
    }

    public static function isOpen(array $row): bool
    {
        return !in_array($row['status'] ?? '', [self::STATUS_DONE, self::STATUS_CANCELLED], true);
    }

    public static function isOverdue(array $row, ?string $today = null): bool
    {
        $today ??= date('Y-m-d');
        $deadline = self::deadline($row);
        return self::isOpen($row) && $deadline !== null && $deadline < $today;
    }

    /**
     * Regra de transição: devolve null se permitida, ou o motivo da recusa.
     * Não acessa o banco além da lista de campos obrigatórias das configurações.
     *
     * @param array<string, mixed> $fields
     */
    public static function transitionError(string $from, string $to, array $fields): ?string
    {
        if (!isset(self::STATUSES[$to])) {
            return 'Estado desconhecido.';
        }
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            return 'Transição de "' . (self::STATUSES[$from] ?? $from) . '" para "' . self::STATUSES[$to] . '" não é permitida.';
        }
        $internal = ($fields['origin'] ?? self::ORIGIN_EXTERNAL) === self::ORIGIN_INTERNAL;
        if ($to === self::STATUS_PLANNED) {
            // atividade do Marketing não passa pelo briefing do solicitante: basta ter responsável e prazo
            $missing = $internal ? [] : self::missingInputs($fields);
            if ($missing) {
                return 'Briefing incompleto: ' . implode(', ', $missing) . '. Solicite o complemento ao solicitante (RF-004).';
            }
            if ((int) ($fields['users_id_assigned'] ?? 0) <= 0) {
                return 'Defina o responsável antes de planejar (RF-008).';
            }
            if ($internal && empty($fields['confirmed_date'])) {
                return 'Informe o prazo da atividade antes de planejar.';
            }
        }
        if ($to === self::STATUS_IN_PROGRESS && ($fields['dependency_open'] ?? '') !== '') {
            return 'Esta atividade depende de ' . $fields['dependency_open'] . ', que ainda não foi concluída.';
        }
        if ($to === self::STATUS_IN_APPROVAL && (int) ($fields['users_id_approver'] ?? 0) <= 0) {
            return 'Defina o aprovador antes de enviar para aprovação (RF-013).';
        }
        if ($to === self::STATUS_DONE && (int) ($fields['open_children'] ?? 0) > 0 && trim((string) ($fields['exception_note'] ?? '')) === '') {
            return 'Ainda há ' . (int) $fields['open_children'] . ' atividade(s) em aberto no plano desta demanda. Conclua ou cancele as atividades antes (ou justifique a exceção).';
        }
        if ($to === self::STATUS_DONE && !$internal && trim((string) ($fields['exception_note'] ?? '')) === '') {
            // RN-05 (vale para solicitações externas; atividade interna não tem solicitante para aceitar): concluir exige evidência e aceite; a exceção exige justificativa e permissão de aprovação
            $missing = [];
            if ((int) ($fields['evidence_count'] ?? 0) <= 0) {
                $missing[] = 'evidência';
            }
            if (empty($fields['accepted_at'])) {
                $missing[] = 'aceite do solicitante';
            }
            if ($missing) {
                return 'Para concluir falta: ' . implode(' e ', $missing) . '. Registre-as ou justifique a exceção (RN-05).';
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- permissões do item

    /** Item visível só com o direito do plugin e acesso à entidade (E6, CA-07). */
    public function canViewItem(): bool
    {
        return Access::canView() && parent::canViewItem();
    }

    /** Item alterável só com o direito de atualização e acesso à entidade (CA-07). */
    public function canUpdateItem(): bool
    {
        return Access::canUpdate() && parent::canUpdateItem();
    }

    private function isRequester(): bool
    {
        return (int) ($this->fields['requester_users_id'] ?? 0) === (int) Session::getLoginUserID();
    }

    /** O solicitante pode complementar o briefing enquanto a demanda aguarda o solicitante (RF-004). */
    public function canComplement(): bool
    {
        return Access::canView() && $this->isRequester()
            && ($this->fields['status'] ?? '') === self::STATUS_WAITING_REQUESTER;
    }

    // ---------------------------------------------------------------- trilha, notas e avisos

    /** Registra um evento da demanda (trilha de mudanças, RF-011). */
    public function addEvent(string $event, string $old, string $new, string $note = ''): void
    {
        global $DB;
        $DB->insert(self::getEventsTable(), [
            'demands_id'    => (int) $this->getID(),
            'users_id'      => (int) Session::getLoginUserID(),
            'event'         => $event,
            'old_value'     => $old,
            'new_value'     => $new,
            'note'          => $note,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
    }

    private function label(): string
    {
        return ($this->fields['code'] ?? '') . ' · ' . ($this->fields['name'] ?? '');
    }

    /** Chamado depois de criar a demanda: responsável padrão da triagem e automações de criação. */
    public function afterCreate(): void
    {
        $default = $this->isInternal() ? 0 : (int) Settings::get('default_triage_user', '0');
        if ($default > 0 && (int) ($this->fields['users_id_assigned'] ?? 0) === 0) {
            $this->assign($default);
        }
        Automation::fire('created', $this->fields, ['value' => (string) ($this->fields['types_id'] ?? '')]);
    }

    /**
     * Atribui a demanda a um usuário: grava, registra o evento, abre o pop-up para quem recebeu
     * e dispara as automações de atribuição. Atribuir a quem já é o responsável não faz nada.
     */
    public function assign(int $userId): void
    {
        global $DB;
        $old = (int) ($this->fields['users_id_assigned'] ?? 0);
        if ($userId === $old) {
            return;
        }
        $DB->update(self::getTable(), ['users_id_assigned' => $userId, 'date_mod' => date('Y-m-d H:i:s')], ['id' => (int) $this->getID()]);
        $this->fields['users_id_assigned'] = $userId;
        $this->addEvent('assign', (string) $old, (string) $userId, $userId > 0 ? 'Atribuída a ' . getUserName($userId) : 'Atribuição removida');
        if ($userId > 0) {
            Notifier::send('assigned', $this->fields, [$userId], 'Nova demanda atribuída a você', $this->label());
            Automation::fire('assigned', $this->fields, ['value' => (string) $userId]);
        }
    }

    /** Atualização (comentário) da demanda; avisa responsável e solicitante, menos o autor. */
    public function addNote(string $body): int
    {
        $id = Note::add((int) $this->getID(), $body, 'note');
        $this->addEvent('note', '', '', mb_substr($body, 0, 120));
        Notifier::send('note', $this->fields, [(int) $this->fields['users_id_assigned'], (int) $this->fields['requester_users_id']], 'Nova atualização', $this->label() . ': ' . mb_substr($body, 0, 140));
        return $id;
    }

    // ---------------------------------------------------------------- fluxo

    /**
     * Muda o estado com validação, registro do evento, avisos e automações.
     * Devolve null se a mudança foi feita, ou o motivo da recusa.
     */
    public function changeStatus(string $to, string $note = ''): ?string
    {
        $from = (string) ($this->fields['status'] ?? '');
        if ($error = self::transitionError($from, $to, $this->ruleContext())) {
            return $error;
        }
        // o chamador já verificou o direito do usuário (ação de tela); o banco é gravado direto
        // para que o aprovador, que não tem o direito de atualização, possa concluir a sua decisão
        global $DB;
        $set = ['status' => $to, 'date_mod' => date('Y-m-d H:i:s')];
        if ($to === self::STATUS_DONE) {
            $set['completed_at'] = date('Y-m-d H:i:s');
        }
        $DB->update(self::getTable(), $set, ['id' => (int) $this->getID()]);
        $this->fields = array_merge($this->fields, $set);
        $this->addEvent('status', $from, $to, $note);

        $requester = (int) ($this->fields['requester_users_id'] ?? 0);
        Notifier::send('status_changed', $this->fields, [$requester], 'Situação alterada', $this->label() . ': ' . (self::STATUSES[$from] ?? $from) . ' → ' . self::STATUSES[$to]);
        if ($to === self::STATUS_WAITING_REQUESTER) {
            Notifier::send('complement', $this->fields, [$requester], 'Complemento de briefing solicitado', $this->label() . ($note !== '' ? ': ' . $note : ''));
        }
        Automation::fire('status_changed', $this->fields, ['value' => $to]);
        return null;
    }

    /** O solicitante aceita a entrega (RN-05). Não muda a situação; registra aceite e evento. */
    public function acceptDelivery(): ?string
    {
        if (!Access::canView() || !$this->isRequester()) {
            return 'Somente o solicitante aceita a entrega.';
        }
        if (in_array($this->fields['status'] ?? '', [self::STATUS_DONE, self::STATUS_CANCELLED], true)) {
            return 'A demanda já está encerrada.';
        }
        if (!empty($this->fields['accepted_at'])) {
            return 'A entrega já foi aceita.';
        }
        global $DB;
        $DB->update(self::getTable(), ['accepted_at' => date('Y-m-d H:i:s'), 'accepted_users_id' => (int) Session::getLoginUserID()], ['id' => (int) $this->getID()]);
        $this->getFromDB((int) $this->getID());
        $this->addEvent('accept', '', '', 'Entrega aceita pelo solicitante');
        return null;
    }

    /** Registra evidência (link ou nota) da entrega (RF-012). */
    public function addEvidence(string $kind, string $name, string $url, string $comment): int
    {
        global $DB;
        $DB->insert(self::getEvidencesTable(), [
            'demands_id'    => (int) $this->getID(),
            'kind'          => in_array($kind, ['link', 'note'], true) ? $kind : 'link',
            'name'          => $name,
            'url'           => $url !== '' ? $url : null,
            'comment'       => $comment,
            'users_id'      => (int) Session::getLoginUserID(),
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        $this->addEvent('evidence', '', '', 'Evidência: ' . $name);
        return (int) $DB->insertId();
    }

    /** Abre a aprovação com o aprovador definido na demanda (RF-013) e avisa o aprovador. */
    public function requestApproval(): void
    {
        global $DB;
        $DB->insert(self::getApprovalsTable(), [
            'demands_id'        => (int) $this->getID(),
            'users_id_approver' => (int) ($this->fields['users_id_approver'] ?? 0),
            'status'            => 'waiting',
            'date_creation'     => date('Y-m-d H:i:s'),
        ]);
        Notifier::send('approval_requested', $this->fields, [(int) ($this->fields['users_id_approver'] ?? 0)], 'Aprovação solicitada', $this->label());
        Automation::fire('approval_requested', $this->fields);
    }

    /**
     * Decisão do aprovador sobre a aprovação aberta. Só o aprovador indicado, com o direito de aprovação.
     * Devolve null se registrada, ou o motivo da recusa.
     */
    public function decide(string $decision, string $comment): ?string
    {
        global $DB;
        if (!Access::canApprove()) {
            return 'Sem direito de aprovação.';
        }
        if (!in_array($decision, ['accepted', 'refused'], true)) {
            return 'Decisão inválida.';
        }
        $me = (int) Session::getLoginUserID();
        $row = $DB->request(['FROM' => self::getApprovalsTable(), 'WHERE' => [
            'demands_id' => (int) $this->getID(), 'status' => 'waiting', 'users_id_approver' => $me,
        ], 'LIMIT' => 1])->current();
        if (!$row) {
            return 'Não há aprovação pendente para você nesta demanda.';
        }
        $DB->update(self::getApprovalsTable(), [
            'status' => $decision, 'comment' => $comment, 'users_id_decision' => $me, 'date_decision' => date('Y-m-d H:i:s'),
        ], ['id' => (int) $row['id']]);
        $label = $decision === 'accepted' ? 'Aprovada' : 'Recusada';
        Notifier::send('approval_decided', $this->fields, [(int) ($this->fields['users_id_assigned'] ?? 0)], 'Aprovação ' . mb_strtolower($label), $this->label() . ($comment !== '' ? ': ' . $comment : ''));
        return $this->changeStatus(self::STATUS_IN_PROGRESS, $label . ($comment !== '' ? ': ' . $comment : ''));
    }

    /**
     * Conclusão com exceção justificada (RN-05): permitida só com direito de aprovação.
     * Grava a justificativa na demanda antes de mudar o estado.
     */
    public function completeWithException(string $note): ?string
    {
        if (!Access::canApprove()) {
            return 'Somente aprovadores podem concluir com exceção.';
        }
        if (trim($note) === '') {
            return 'Justifique a exceção.';
        }
        global $DB;
        $DB->update(self::getTable(), ['exception_note' => $note], ['id' => (int) $this->getID()]);
        $this->getFromDB((int) $this->getID());
        return $this->changeStatus(self::STATUS_DONE, 'Exceção: ' . $note);
    }

    /** Dados que as regras de transição precisam (evidências, aceite, exceção). */
    public function ruleContext(): array
    {
        $count = countElementsInTable(self::getEvidencesTable(), ['demands_id' => (int) $this->getID()]);
        $open = countElementsInTable(self::getTable(), ['parent_id' => (int) $this->getID(), 'is_deleted' => 0, 'NOT' => ['status' => [self::STATUS_DONE, self::STATUS_CANCELLED]]]);
        $dependency = '';
        if ((int) ($this->fields['depends_on_id'] ?? 0) > 0) {
            $dep = new self();
            if ($dep->getFromDB((int) $this->fields['depends_on_id']) && self::isOpen($dep->fields)) {
                $dependency = $dep->fields['code'] . ' · ' . $dep->fields['name'];
            }
        }
        return $this->fields + ['evidence_count' => $count, 'open_children' => $open, 'dependency_open' => $dependency];
    }

    public function isInternal(): bool
    {
        return ($this->fields['origin'] ?? self::ORIGIN_EXTERNAL) === self::ORIGIN_INTERNAL;
    }

    /**
     * Cadastra uma atividade do Marketing (origem interna), sem formulário de solicitação. Nasce "Planejada" quando já tem
     * responsável e prazo; senão fica "Em triagem" (rascunho) até completar. Devolve o id, ou 0 se faltar o título.
     *
     * @param array<string, mixed> $in name, objective, deliverable, users_id_assigned, start_date, confirmed_date, priority,
     *                                 types_id, pillars_id, mkt_projects_id, plans_id, parent_id, depends_on_id
     */
    public static function createActivity(array $in): int
    {
        global $DB;
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 255);
        if ($name === '') {
            return 0;
        }
        $date = fn ($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
        [$pillar, $project, $plan] = Structure::normalizeLinks((int) ($in['pillars_id'] ?? 0), (int) ($in['mkt_projects_id'] ?? 0), (int) ($in['plans_id'] ?? 0));
        $assignee = max(0, (int) ($in['users_id_assigned'] ?? 0));
        $due = $date($in['confirmed_date'] ?? null);
        $typeId = (int) ($in['types_id'] ?? 0);
        $demand = new self();
        $newId = $demand->add([
            'name'               => $name,
            'code'               => 'ATV-' . strtoupper(bin2hex(random_bytes(6))), // provisório: o código final usa o id gravado
            'entities_id'        => (int) ($_SESSION['glpiactive_entity'] ?? 0),
            'origin'             => self::ORIGIN_INTERNAL,
            'requester_users_id' => (int) Session::getLoginUserID(),
            'types_id'           => Type::find($typeId) ? $typeId : 0,
            'objective'          => mb_substr(trim((string) ($in['objective'] ?? '')), 0, 4000),
            'deliverable'        => mb_substr(trim((string) ($in['deliverable'] ?? '')), 0, 4000),
            'area'               => 'Marketing',
            'start_date'         => $date($in['start_date'] ?? null),
            'desired_date'       => $due,
            'confirmed_date'     => $due,
            'priority'           => max(1, min(5, (int) ($in['priority'] ?? 3))),
            'pillars_id'         => $pillar,
            'mkt_projects_id'    => $project,
            'plans_id'           => $plan,
            'parent_id'          => max(0, (int) ($in['parent_id'] ?? 0)),
            'depends_on_id'      => max(0, (int) ($in['depends_on_id'] ?? 0)),
            'status'             => ($assignee > 0 && $due !== null) ? self::STATUS_PLANNED : self::STATUS_TRIAGE,
            'sent_at'            => date('Y-m-d H:i:s'),
        ]);
        if (!$newId) {
            return 0;
        }
        $DB->update(self::getTable(), ['code' => 'ATV-' . str_pad((string) $newId, 5, '0', STR_PAD_LEFT)], ['id' => (int) $newId]);
        $demand->getFromDB((int) $newId);
        $demand->addEvent('created', '', (string) $demand->fields['status'], 'Atividade cadastrada pelo Marketing');
        if ($assignee > 0) {
            $demand->assign($assignee);
        }
        Automation::fire('created', $demand->fields, ['value' => (string) $typeId]);
        return (int) $newId;
    }
}

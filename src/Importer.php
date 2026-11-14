<?php

namespace GlpiPlugin\Marketing;

/**
 * Importação de atividades internas a partir de planilha (CSV ou Excel .xlsx), para migrar os controles atuais.
 * Duas etapas: analyze() lê e valida sem gravar nada; commit() grava o que foi aprovado na conferência.
 * Colunas reconhecidas (pelo nome do cabeçalho, sem acento nem maiúsculas): título*, responsável, prazo, início, prioridade, tipo,
 * pilar, projeto, plano, objetivo, entrega, situação, % execução.
 */
class Importer
{
    public const MAX_ROWS = 1000;
    public const MAX_BYTES = 5242880; // 5 MB

    /** campo => apelidos de cabeçalho já normalizados por norm() */
    public const COLUMNS = [
        'name'      => ['titulo', 'atividade', 'tarefa', 'nome', 'titulo da atividade', 'demanda'],
        'assignee'  => ['responsavel', 'dono', 'executor', 'owner', 'responsavel pela atividade'],
        'due'       => ['prazo', 'data fim', 'data final', 'vencimento', 'entrega prevista', 'prazo final', 'data de entrega'],
        'start'     => ['inicio', 'data inicio', 'data inicial', 'data de inicio', 'comeco'],
        'priority'  => ['prioridade'],
        'type'      => ['tipo', 'tipo de atividade', 'categoria'],
        'pillar'    => ['pilar', 'frente', 'pilar frente'],
        'project'   => ['projeto'],
        'plan'      => ['plano', 'plano de acao'],
        'objective' => ['objetivo', 'descricao', 'detalhes'],
        'deliverable' => ['entrega', 'entregavel', 'resultado esperado'],
        'status'    => ['situacao', 'status', 'estado'],
        'percent'   => ['execucao', '% execucao', 'percentual', '% concluido', 'execucao efetiva', 'progresso', '% de execucao'],
    ];

    public const LABELS = [
        'name' => 'Título', 'assignee' => 'Responsável', 'due' => 'Prazo', 'start' => 'Início', 'priority' => 'Prioridade', 'type' => 'Tipo',
        'pillar' => 'Pilar', 'project' => 'Projeto', 'plan' => 'Plano', 'objective' => 'Objetivo', 'deliverable' => 'Entrega', 'status' => 'Situação', 'percent' => '% Execução',
    ];

    /** Modelo de planilha para baixar. */
    public static function template(): string
    {
        $rows = [
            array_values(self::LABELS),
            ['Definir públicos da campanha', 'maria.souza', '15/12/2026', '01/12/2026', 'Alta', 'Campanha', 'Geração de Demanda', 'Lançamento Produto X', 'Plano de mídia paga', 'Públicos por segmento', 'Documento de segmentação', 'Planejada', '0'],
            ['Newsletter de dezembro', '', '2026-12-20', '', 'Média', 'Conteúdo', 'Conteúdo', 'Calendário Editorial', '', '', '', 'Em andamento', '40'],
        ];
        $out = "\xEF\xBB\xBF";
        foreach ($rows as $r) {
            $out .= implode(';', array_map(fn ($c) => '"' . str_replace('"', '""', (string) $c) . '"', $r)) . "\r\n";
        }
        return $out;
    }

    // ---------------------------------------------------------------- normalização

    /** Minúsculas, sem acento e sem pontuação solta: base de toda comparação de texto. */
    public static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);
        $s = preg_replace('/[^a-z0-9%]+/', ' ', $s) ?? '';
        return trim($s);
    }

    /** Aceita AAAA-MM-DD, DD/MM/AAAA, DD-MM-AAAA, DD/MM/AA e número serial do Excel. Devolve AAAA-MM-DD ou null. */
    public static function date($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})#', $v, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $y = $y < 100 ? 2000 + $y : $y;
        } elseif (preg_match('/^\d{5}(\.\d+)?$/', $v) && (float) $v > 20000 && (float) $v < 80000) {
            return gmdate('Y-m-d', (int) round(((float) $v - 25569) * 86400));
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** 1–5 (1 = muito alta) ou o nome da prioridade. Vazio: 3 (média). null se não entendeu. */
    public static function priority($v): ?int
    {
        $n = self::norm((string) $v);
        if ($n === '') {
            return 3;
        }
        if (ctype_digit($n) && (int) $n >= 1 && (int) $n <= 5) {
            return (int) $n;
        }
        foreach (['muito alta' => 1, 'urgente' => 1, 'critica' => 1, 'alta' => 2, 'media' => 3, 'normal' => 3, 'baixa' => 4, 'muito baixa' => 5] as $k => $p) {
            if ($n === $k) {
                return $p;
            }
        }
        return null;
    }

    /** Situação (rótulo ou chave) → chave de Demand::STATUSES, ou null. */
    public static function status($v): ?string
    {
        $n = self::norm((string) $v);
        if ($n === '') {
            return null;
        }
        foreach (Demand::STATUSES as $key => $label) {
            if ($n === self::norm($label) || $n === self::norm($key)) {
                return $key;
            }
        }
        foreach (['a fazer' => 'planned', 'pendente' => 'planned', 'em execucao' => 'in_progress', 'fazendo' => 'in_progress', 'feito' => 'done', 'finalizada' => 'done', 'concluido' => 'done', 'parada' => 'blocked'] as $k => $key) {
            if ($n === $k) {
                return $key;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- leitura de arquivo

    /**
     * Lê o arquivo e devolve [linhas (lista de listas de texto), erro]. CSV (; , ou tab; UTF-8 ou Windows-1252) e .xlsx.
     *
     * @return array{0: array<int, array<int, string>>, 1: string}
     */
    public static function read(string $path, string $name): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!is_file($path) || filesize($path) === 0) {
            return [[], 'O arquivo está vazio.'];
        }
        if (filesize($path) > self::MAX_BYTES) {
            return [[], 'O arquivo passa de ' . (self::MAX_BYTES / 1048576) . ' MB. Divida a planilha.'];
        }
        if (in_array($ext, ['csv', 'txt', 'tsv'], true)) {
            return self::readCsv((string) file_get_contents($path));
        }
        if ($ext === 'xlsx') {
            if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
                return [[], 'Este servidor não lê .xlsx. Salve a planilha como CSV (separado por ponto e vírgula) e envie de novo.'];
            }
            try {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
                $reader->setReadDataOnly(true);
                $sheet = $reader->load($path)->getSheet(0);
                $rows = [];
                foreach ($sheet->toArray(null, true, false, false) as $r) {
                    $rows[] = array_map(fn ($c) => is_scalar($c) || $c === null ? trim((string) $c) : '', $r);
                }
                return [$rows, ''];
            } catch (\Throwable $e) {
                return [[], 'Não foi possível ler o Excel: ' . $e->getMessage()];
            }
        }
        return [[], 'Formato não suportado (.' . $ext . '). Envie .csv ou .xlsx.'];
    }

    /** @return array{0: array<int, array<int, string>>, 1: string} */
    public static function readCsv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $first = strtok($content, "\n") ?: '';
        $delim = ';';
        $best = -1;
        foreach ([';', ',', "\t"] as $d) {
            $c = substr_count($first, $d);
            if ($c > $best) {
                [$best, $delim] = [$c, $d];
            }
        }
        $h = fopen('php://temp', 'r+');
        fwrite($h, $content);
        rewind($h);
        $rows = [];
        while (($r = fgetcsv($h, 0, $delim, '"', '\\')) !== false) {
            if ($r === [null]) {
                continue;
            }
            $rows[] = array_map(fn ($c) => trim((string) $c), $r);
        }
        fclose($h);
        return [$rows, ''];
    }

    // ---------------------------------------------------------------- análise

    /**
     * Valida as linhas sem gravar. Cada item: row (nº da linha na planilha), data (campos resolvidos), state (ok | warn | error | dup), notes.
     *
     * @param array<int, array<int, string>> $rows primeira linha = cabeçalho
     * @return array{items: array<int, array<string, mixed>>, map: array<string, int>, error: string}
     */
    public static function analyze(array $rows, bool $createMissing, bool $skipDuplicates): array
    {
        global $DB;
        $out = ['items' => [], 'map' => [], 'error' => ''];
        // cabeçalho: procura nas 5 primeiras linhas a que contém a coluna de título
        $headerAt = null;
        foreach (array_slice($rows, 0, 5, true) as $i => $r) {
            $map = self::mapHeader($r);
            if (isset($map['name'])) {
                [$headerAt, $out['map']] = [$i, $map];
                break;
            }
        }
        if ($headerAt === null) {
            $out['error'] = 'Não encontrei a coluna "Título" (ou Atividade/Tarefa) na planilha. Use o modelo para conferir os nomes das colunas.';
            return $out;
        }
        $body = array_slice($rows, $headerAt + 1, null, true);
        if (count($body) > self::MAX_ROWS) {
            $out['error'] = 'A planilha tem ' . count($body) . ' linhas; o limite por importação é ' . self::MAX_ROWS . '.';
            return $out;
        }

        // cadastros para casar por nome
        $users = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'realname', 'firstname'], 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1, 'is_deleted' => 0]]) as $u) {
            $full = trim($u['firstname'] . ' ' . $u['realname']);
            $rev = trim($u['realname'] . ' ' . $u['firstname']);
            foreach ([$u['name'], $full, $rev, $u['realname']] as $k) {
                $users[self::norm((string) $k)][] = (int) $u['id'];
            }
        }
        foreach ($DB->request(['SELECT' => ['users_id', 'email'], 'FROM' => 'glpi_useremails']) as $m) {
            $users[self::norm((string) $m['email'])][] = (int) $m['users_id'];
        }
        $types = [];
        foreach (Type::all(true) as $t) {
            $types[self::norm((string) $t['name'])] = (int) $t['id'];
        }
        $pillars = [];
        foreach (Structure::pillars() as $p) {
            $pillars[self::norm((string) $p['name'])] = (int) $p['id'];
        }
        $projects = [];
        foreach (Structure::projects() as $p) {
            $projects[self::norm((string) $p['name'])][] = (int) $p['id'];
        }
        $plans = [];
        foreach (Structure::plans() as $p) {
            $plans[(int) $p['mkt_projects_id']][self::norm((string) $p['name'])] = (int) $p['id'];
        }
        $existing = [];
        foreach ($DB->request(['SELECT' => ['name', 'plans_id'], 'FROM' => Demand::getTable(), 'WHERE' => ['is_deleted' => 0, 'origin' => Demand::ORIGIN_INTERNAL]]) as $d) {
            $existing[(int) $d['plans_id'] . '|' . self::norm((string) $d['name'])] = true;
        }

        $seen = [];
        foreach ($body as $i => $r) {
            if (!array_filter($r, fn ($c) => trim((string) $c) !== '')) {
                continue;
            }
            $get = fn (string $f) => isset($out['map'][$f]) ? trim((string) ($r[$out['map'][$f]] ?? '')) : '';
            $notes = [];
            $state = 'ok';
            $d = ['name' => mb_substr($get('name'), 0, 255)];
            if ($d['name'] === '') {
                $out['items'][] = ['row' => $i + 1, 'data' => $d, 'state' => 'error', 'notes' => ['Sem título.']];
                continue;
            }
            // responsável
            $d['assignee'] = 0;
            if (($v = $get('assignee')) !== '') {
                $found = array_unique($users[self::norm($v)] ?? []);
                if (count($found) === 1) {
                    $d['assignee'] = (int) reset($found);
                } else {
                    $notes[] = count($found) > 1 ? "Responsável \"$v\" é ambíguo: ficará sem responsável." : "Responsável \"$v\" não encontrado: ficará sem responsável.";
                }
            }
            // datas
            foreach (['due' => 'Prazo', 'start' => 'Início'] as $f => $label) {
                $d[$f] = null;
                if (($v = $get($f)) !== '') {
                    $d[$f] = self::date($v);
                    if ($d[$f] === null) {
                        $notes[] = "$label \"$v\" não é uma data válida (use DD/MM/AAAA): ignorado.";
                    }
                }
            }
            // prioridade, tipo, situação, percentual
            $d['priority'] = self::priority($get('priority'));
            if ($d['priority'] === null) {
                $notes[] = 'Prioridade "' . $get('priority') . '" não reconhecida: usando Média.';
                $d['priority'] = 3;
            }
            $d['type'] = 0;
            if (($v = $get('type')) !== '') {
                $d['type'] = $types[self::norm($v)] ?? 0;
                if (!$d['type']) {
                    $notes[] = "Tipo \"$v\" não existe: ficará sem tipo.";
                }
            }
            $d['status'] = null;
            if (($v = $get('status')) !== '') {
                $d['status'] = self::status($v);
                if ($d['status'] === null) {
                    $notes[] = "Situação \"$v\" não reconhecida: ficará conforme as regras (planejada ou em triagem).";
                } elseif (in_array($d['status'], [Demand::STATUS_RECEIVED, Demand::STATUS_TRIAGE, Demand::STATUS_WAITING_REQUESTER], true)) {
                    $d['status'] = null;
                }
            }
            $d['percent'] = null;
            if (($v = $get('percent')) !== '') {
                $n = str_replace(['%', ','], ['', '.'], $v);
                if (is_numeric($n)) {
                    $f = (float) $n;
                    // "0,4" ou "0.4" sem o símbolo % é lido como fração (40%); "40" e "40%" são 40
                    $d['percent'] = max(0, min(100, (int) round((!str_contains($v, '%') && $f > 0 && $f < 1) ? $f * 100 : $f)));
                } else {
                    $notes[] = "% de execução \"$v\" inválido: ignorado.";
                }
            }
            $d['objective'] = mb_substr($get('objective'), 0, 4000);
            $d['deliverable'] = mb_substr($get('deliverable'), 0, 4000);

            // pilar, projeto, plano
            $pName = $get('pillar');
            $jName = $get('project');
            $lName = $get('plan');
            $d['pillar'] = ['id' => 0, 'name' => $pName];
            $d['project'] = ['id' => 0, 'name' => $jName];
            $d['plan'] = ['id' => 0, 'name' => $lName];
            if ($pName !== '') {
                $d['pillar']['id'] = $pillars[self::norm($pName)] ?? 0;
                if (!$d['pillar']['id'] && !$createMissing) {
                    $notes[] = "Pilar \"$pName\" não existe: ficará sem pilar (ou marque \"criar pilares, projetos e planos que faltarem\").";
                }
            }
            if ($jName !== '') {
                $cands = $projects[self::norm($jName)] ?? [];
                if ($d['pillar']['id']) {
                    $cands = array_values(array_filter($cands, fn ($pid) => (int) (Structure::projects()[$pid]['pillars_id'] ?? 0) === $d['pillar']['id'] || (int) (Structure::projects()[$pid]['pillars_id'] ?? 0) === 0)) ?: $cands;
                }
                $d['project']['id'] = $cands[0] ?? 0;
                if (!$d['project']['id'] && !$createMissing) {
                    $notes[] = "Projeto \"$jName\" não existe: ficará sem projeto.";
                }
            }
            if ($lName !== '') {
                $d['plan']['id'] = $d['project']['id'] ? ($plans[$d['project']['id']][self::norm($lName)] ?? 0) : 0;
                if (!$d['plan']['id'] && !$createMissing) {
                    $notes[] = "Plano \"$lName\" não existe" . ($d['project']['id'] ? ' neste projeto' : '') . ': ficará sem plano.';
                }
                if (!$d['plan']['id'] && $createMissing && $jName === '') {
                    $notes[] = "Plano \"$lName\" sem projeto: será criado como plano avulso.";
                }
            }
            if ($createMissing) {
                foreach (['pillar' => $pName, 'project' => $jName, 'plan' => $lName] as $k => $nm) {
                    if ($nm !== '' && !$d[$k]['id']) {
                        $notes[] = ucfirst(['pillar' => 'pilar', 'project' => 'projeto', 'plan' => 'plano'][$k]) . " \"$nm\" será criado.";
                    }
                }
            }
            // duplicidade: mesmo título no mesmo plano (já cadastrado ou repetido na própria planilha)
            $key = (int) $d['plan']['id'] . '|' . self::norm($d['name']);
            if ($skipDuplicates && ($existing[$key] ?? false)) {
                $state = 'dup';
                $notes[] = 'Já existe uma atividade com este título neste plano: será ignorada.';
            } elseif ($skipDuplicates && isset($seen[$key . '|' . $d['plan']['name'] . '|' . $d['project']['name']])) {
                $state = 'dup';
                $notes[] = 'Título repetido na planilha: será ignorada.';
            }
            $seen[$key . '|' . $d['plan']['name'] . '|' . $d['project']['name']] = true;
            if ($state === 'ok' && $notes) {
                $state = 'warn';
            }
            $out['items'][] = ['row' => $i + 1, 'data' => $d, 'state' => $state, 'notes' => $notes];
        }
        if (!$out['items']) {
            $out['error'] = 'Nenhuma linha de dados foi encontrada abaixo do cabeçalho.';
        }
        return $out;
    }

    /** @return array<string, int> campo => índice da coluna */
    public static function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $idx => $h) {
            $n = self::norm((string) $h);
            if ($n === '') {
                continue;
            }
            foreach (self::COLUMNS as $field => $aliases) {
                if (!isset($map[$field]) && in_array($n, $aliases, true)) {
                    $map[$field] = (int) $idx;
                    break;
                }
            }
        }
        return $map;
    }

    // ---------------------------------------------------------------- gravação

    /**
     * Grava os itens aprovados (state ok ou warn). Devolve [criadas, ignoradas, estruturas criadas].
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{created: int, skipped: int, structures: int, ids: int[]}
     */
    public static function commit(array $items, bool $createMissing): array
    {
        global $DB;
        $res = ['created' => 0, 'skipped' => 0, 'structures' => 0, 'ids' => []];
        $cache = [];
        $ensure = function (string $kind, array $d) use (&$cache, &$res, $createMissing) {
            $data = $d[$kind];
            if ($data['id'] || $data['name'] === '' || !$createMissing) {
                return (int) $data['id'];
            }
            $ck = $kind . '|' . Importer::norm($data['name']) . '|' . ($kind === 'plan' ? (int) ($cache['project|' . Importer::norm($d['project']['name'])] ?? $d['project']['id']) : '') . '|' . ($kind === 'project' ? (int) ($cache['pillar|' . Importer::norm($d['pillar']['name'])] ?? $d['pillar']['id']) : '');
            if (isset($cache[$ck])) {
                return $cache[$ck];
            }
            $id = 0;
            if ($kind === 'pillar') {
                $id = Structure::savePillar(0, ['name' => $data['name']]);
            } elseif ($kind === 'project') {
                $id = Structure::saveProject(0, ['name' => $data['name'], 'pillars_id' => (int) ($cache['pillar|' . Importer::norm($d['pillar']['name'])] ?? $d['pillar']['id']), 'users_id' => (int) \Session::getLoginUserID(), 'status' => 'active']);
            } else {
                $id = Structure::savePlan(0, ['name' => $data['name'], 'mkt_projects_id' => (int) ($cache['project|' . Importer::norm($d['project']['name'])] ?? $d['project']['id']), 'users_id' => (int) $d['assignee']]);
            }
            $res['structures'] += $id > 0 ? 1 : 0;
            return $cache[$ck] = (int) $id;
        };
        foreach ($items as $it) {
            if (!in_array($it['state'], ['ok', 'warn'], true)) {
                $res['skipped']++;
                continue;
            }
            $d = $it['data'];
            $pillar = $ensure('pillar', $d);
            $cache['pillar|' . self::norm($d['pillar']['name'])] = $pillar;
            $project = $ensure('project', $d);
            $cache['project|' . self::norm($d['project']['name'])] = $project;
            $plan = $ensure('plan', $d);
            $id = Demand::createActivity([
                'name' => $d['name'], 'objective' => $d['objective'], 'deliverable' => $d['deliverable'], 'users_id_assigned' => $d['assignee'],
                'start_date' => $d['start'], 'confirmed_date' => $d['due'], 'priority' => $d['priority'], 'types_id' => $d['type'],
                'pillars_id' => $pillar, 'mkt_projects_id' => $project, 'plans_id' => $plan,
            ]);
            if (!$id) {
                $res['skipped']++;
                continue;
            }
            $set = [];
            if ($d['percent'] !== null) {
                $set['exec_percent'] = (int) $d['percent'];
            }
            if ($d['status'] !== null) {
                $set['status'] = $d['status'];
                if ($d['status'] === Demand::STATUS_DONE) {
                    $set['completed_at'] = date('Y-m-d H:i:s');
                }
            }
            if ($set) {
                $DB->update(Demand::getTable(), $set, ['id' => $id]);
            }
            $demand = new Demand();
            if ($demand->getFromDB($id)) {
                $demand->addEvent('import', '', (string) ($set['status'] ?? ''), 'Importada de planilha (linha ' . (int) $it['row'] . ')');
            }
            $res['created']++;
            $res['ids'][] = $id;
        }
        return $res;
    }
}

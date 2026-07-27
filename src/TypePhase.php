<?php

/**
 * ProjectPlus — fases por TIPO de projeto (Etapa 9).
 *
 * O PROBLEMA: `glpi_projectstates` é lista GLOBAL e única da instância (o
 * schema do core 11.0.6 não tem `entities_id` — lição 59). Com Infra, RH,
 * Sistemas e Compras rodando projetos ao mesmo tempo a lista chega a 25–30
 * fases, e cada setor passa a ver dezenas de colunas vazias no seu Kanban.
 *
 * A SOLUÇÃO: uma tabela de MAPEAMENTO — `glpi_plugin_projectplus_typephases`
 * (`projecttypes_id`, `projectstates_id`, `ordem`). O `glpi_projectstates`
 * continua sendo a FONTE ÚNICA da definição da fase (nome, cor,
 * `is_finished`); a tabela nova só diz QUAIS fases pertencem a QUAL tipo e
 * em que ordem.
 *
 * REGRA DE LEITURA (em cascata, nesta ordem):
 *   1. o conjunto do próprio tipo;
 *   2. se o tipo não tem nenhuma linha, o conjunto PADRÃO (`projecttypes_id = 0`);
 *   3. se o padrão também está vazio, TODAS as fases da instância.
 * O passo 3 é o que garante que uma base sem configuração nenhuma continue
 * funcionando exatamente como antes da Etapa 9 (nada some da tela).
 *
 * Classe estática, sem extends — autoload PSR-4.
 */

namespace GlpiPlugin\Projectplus;

class TypePhase
{
    public const TABLE = 'glpi_plugin_projectplus_typephases';

    /** Tipo sintético do conjunto PADRÃO (vale para todo tipo sem linhas). */
    public const DEFAULT_TYPE = 0;

    /** Rótulo de cartão/linha sem tipo definido. */
    public const NO_TYPE_ID = 0;

    /**
     * Paleta do donut "Projetos por tipo": `glpi_projecttypes` não tem
     * coluna de cor, então a cor é derivada da POSIÇÃO do tipo na lista
     * (determinística — o mesmo tipo tem sempre a mesma cor).
     */
    private const TYPE_PALETTE = [
        '#065a82', '#4caf7d', '#e9a13b', '#b5535c',
        '#6b5bab', '#2f8f9d', '#c1683c', '#4a7043',
    ];

    /** @var array<int, array<int, array{name:string,color:string,is_finished:bool}>> */
    private static array $setCache = [];

    /** @var array<int, array<int, int>>|null typeId => [stateId em ordem] */
    private static ?array $rowsCache = null;

    /** @var array<int, array{name:string,color:string,is_finished:bool}>|null */
    private static ?array $allCache = null;

    /** @var array<int, string>|null */
    private static ?array $typesCache = null;

    // ------------------------------------------------------------------
    // Leitura
    // ------------------------------------------------------------------

    /**
     * TODAS as fases da instância, id => ['name','color','is_finished'].
     * Ordem alfabética (é o que `Dashboard::getStatesMap()` sempre fez).
     *
     * @return array<int, array{name:string,color:string,is_finished:bool}>
     */
    public static function allStates(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (self::$allCache !== null) {
            return self::$allCache;
        }

        $hasFinished = $DB->fieldExists('glpi_projectstates', 'is_finished');

        $map = [];
        foreach ($DB->request(['FROM' => 'glpi_projectstates', 'ORDER' => 'name']) as $s) {
            $color = trim((string) ($s['color'] ?? ''));
            $map[(int) $s['id']] = [
                'name'        => (string) $s['name'],
                'color'       => preg_match('/^#[0-9a-fA-F]{6}$/', $color)
                    ? $color : Dashboard::PHASE_DEFAULT_COLOR,
                'is_finished' => $hasFinished ? (bool) ($s['is_finished'] ?? false) : false,
            ];
        }

        self::$allCache = $map;
        return $map;
    }

    /**
     * Conteúdo cru da tabela de mapeamento: typeId => [stateId,...] na ordem
     * gravada. Fases que já não existem em `glpi_projectstates` são
     * descartadas aqui (fase excluída não deve virar coluna fantasma).
     *
     * @return array<int, array<int, int>>
     */
    public static function rows(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (self::$rowsCache !== null) {
            return self::$rowsCache;
        }

        $out = [];
        if (!$DB->tableExists(self::TABLE)) {
            self::$rowsCache = $out;
            return $out;
        }

        $known = self::allStates();
        foreach (
            $DB->request([
                'SELECT' => ['projecttypes_id', 'projectstates_id', 'ordem'],
                'FROM'   => self::TABLE,
                'ORDER'  => ['projecttypes_id', 'ordem', 'projectstates_id'],
            ]) as $row
        ) {
            $sid = (int) $row['projectstates_id'];
            if (!isset($known[$sid])) {
                continue;
            }
            $out[(int) $row['projecttypes_id']][] = $sid;
        }

        self::$rowsCache = $out;
        return $out;
    }

    /** Existe conjunto configurado para algum tipo REAL (id > 0)? */
    public static function hasCustomSets(): bool
    {
        foreach (array_keys(self::rows()) as $typeId) {
            if ((int) $typeId > 0) {
                return true;
            }
        }
        return false;
    }

    /** Ids de tipo que têm conjunto próprio gravado (inclui o 0). */
    public static function configuredTypeIds(): array
    {
        return array_map('intval', array_keys(self::rows()));
    }

    /**
     * Conjunto EFETIVO de fases de um tipo, na ordem das colunas.
     *
     * @param ?int $typeId null = todas as fases da instância (visão sem tipo)
     *
     * @return array<int, array{name:string,color:string,is_finished:bool}>
     */
    public static function statesFor(?int $typeId): array
    {
        if ($typeId === null) {
            return self::allStates();
        }

        $typeId = (int) $typeId;
        if (isset(self::$setCache[$typeId])) {
            return self::$setCache[$typeId];
        }

        $rows = self::rows();
        $ids  = $rows[$typeId] ?? [];

        // Cascata: tipo sem linhas cai no conjunto padrão; padrão vazio cai
        // em "todas as fases" (base sem configuração = comportamento antigo).
        if ($ids === [] && $typeId !== self::DEFAULT_TYPE) {
            $ids = $rows[self::DEFAULT_TYPE] ?? [];
        }
        if ($ids === []) {
            return self::$setCache[$typeId] = self::allStates();
        }

        $all = self::allStates();
        $set = [];
        foreach ($ids as $sid) {
            if (isset($all[$sid])) {
                $set[$sid] = $all[$sid];
            }
        }

        return self::$setCache[$typeId] = $set;
    }

    /** O conjunto do tipo veio da tabela (true) ou é fallback (false)? */
    public static function isMapped(int $typeId): bool
    {
        return !empty(self::rows()[$typeId]);
    }

    // ------------------------------------------------------------------
    // Tipos de projeto
    // ------------------------------------------------------------------

    /**
     * Tipos de projeto da instância: id => nome (ordem alfabética).
     *
     * @return array<int, string>
     */
    public static function projectTypes(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (self::$typesCache !== null) {
            return self::$typesCache;
        }

        $types = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => 'glpi_projecttypes',
                'ORDER'  => 'name',
            ]) as $r
        ) {
            $types[(int) $r['id']] = (string) $r['name'];
        }

        self::$typesCache = $types;
        return $types;
    }

    /** Cor determinística do tipo, para o donut "Projetos por tipo". */
    public static function typeColor(int $typeId): string
    {
        if ($typeId <= 0) {
            return Dashboard::PHASE_DEFAULT_COLOR;
        }
        $index = array_search($typeId, array_keys(self::projectTypes()), true);
        if ($index === false) {
            return Dashboard::PHASE_DEFAULT_COLOR;
        }
        return self::TYPE_PALETTE[$index % count(self::TYPE_PALETTE)];
    }

    /** Tipo de um projeto (0 = sem tipo). */
    public static function typeOfProject(int $projectId): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($projectId <= 0) {
            return 0;
        }

        $row = $DB->request([
            'SELECT' => ['projecttypes_id'],
            'FROM'   => 'glpi_projects',
            'WHERE'  => ['id' => $projectId],
            'LIMIT'  => 1,
        ])->current();

        return (int) ($row['projecttypes_id'] ?? 0);
    }

    /**
     * Quantos projetos VISÍVEIS existem por tipo. Alimenta o donut
     * "Projetos por tipo" e a escolha do tipo inicial do seletor.
     *
     * @param ?array $projectIds escopo (src/Scope.php); null = sem filtro
     *
     * @return array<int, int> typeId => quantidade
     */
    public static function projectCountByType(?array $projectIds = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Lição 16: com JOIN toda chave precisa ser qualificada; aqui não há
        // JOIN, mas mantemos o nome qualificado por consistência do WHERE.
        $where = [
            'glpi_projects.is_deleted'  => 0,
            'glpi_projects.is_template' => 0,
        ] + getEntitiesRestrictCriteria('glpi_projects');

        if ($projectIds !== null) {
            $where['glpi_projects.id'] = Scope::inList($projectIds);
        }

        // Lição 1: COUNT + GROUPBY juntos DESCARTAM os campos do SELECT no
        // iterator do GLPI 11 — traz as linhas e conta em PHP.
        $counts = [];
        foreach (
            $DB->request([
                'SELECT' => ['glpi_projects.projecttypes_id'],
                'FROM'   => 'glpi_projects',
                'WHERE'  => $where,
            ]) as $row
        ) {
            $tid          = (int) $row['projecttypes_id'];
            $counts[$tid] = ($counts[$tid] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Opções do seletor de tipo dos Kanbans: todo tipo que TEM conjunto
     * configurado ou que TEM projeto visível — mais "Sem tipo" quando há
     * projeto sem tipo. Ordem alfabética, "Sem tipo" por último.
     *
     * @return array<int, array{id:int,name:string,count:int,mapped:bool}>
     */
    public static function selectorTypes(?array $projectIds = null): array
    {
        $counts    = self::projectCountByType($projectIds);
        $types     = self::projectTypes();
        $mappedIds = self::configuredTypeIds();

        $out = [];
        foreach ($types as $id => $name) {
            $isMapped = in_array($id, $mappedIds, true);
            if (!$isMapped && empty($counts[$id])) {
                continue; // tipo sem conjunto e sem projeto: não polui o seletor
            }
            $out[] = [
                'id'     => $id,
                'name'   => $name,
                'count'  => (int) ($counts[$id] ?? 0),
                'mapped' => $isMapped,
            ];
        }

        if (!empty($counts[0])) {
            $out[] = [
                'id'     => 0,
                'name'   => __('Sem tipo', 'projectplus'),
                'count'  => (int) $counts[0],
                'mapped' => in_array(0, $mappedIds, true),
            ];
        }

        return $out;
    }

    /**
     * Tipo inicial do seletor quando a URL não traz `?type=`: o tipo com
     * MAIS projetos visíveis entre as opções do seletor (empate → o
     * primeiro da ordem alfabética). Devolve null quando não há opção
     * nenhuma — aí a tela cai na visão sem tipo (todas as fases).
     */
    public static function defaultTypeId(?array $projectIds = null): ?int
    {
        $options = self::selectorTypes($projectIds);
        if ($options === []) {
            return null;
        }

        $best      = null;
        $bestCount = -1;
        foreach ($options as $opt) {
            if ($opt['count'] > $bestCount) {
                $best      = (int) $opt['id'];
                $bestCount = (int) $opt['count'];
            }
        }

        return $best;
    }

    /**
     * `$_GET['type']` → tipo efetivo do board/painel.
     *
     * - `type=all` (ou tipo inválido, com união permitida) → null (todas as fases);
     * - `type=<n>` → o tipo pedido, desde que exista na lista do seletor;
     * - ausente → `defaultTypeId()` se há conjunto por tipo configurado,
     *   senão null (mantém o comportamento anterior à Etapa 9).
     *
     * As OPÇÕES válidas são as da instância (`selectorTypes()` sem escopo) —
     * as mesmas que a tela desenha. O ESCOPO do usuário entra apenas na
     * escolha do tipo INICIAL, para o board não abrir vazio. Misturar as duas
     * coisas faria um tipo legítimo ser recusado só porque o usuário ainda não
     * participa de nenhum projeto dele (e o roadmap é explícito: o seletor de
     * tipo é sobre VOCABULÁRIO, não sobre permissão — quem vê o quê continua
     * sendo `Access`/`Scope`).
     *
     * @param ?array $projectIds escopo do usuário (só para o tipo inicial)
     */
    public static function resolveRequestedType(?array $projectIds = null): ?int
    {
        $raw = $_GET['type'] ?? null;

        // União ("Todos os tipos") só é oferecida enquanto NÃO existe
        // conjunto por tipo: com 25 colunas o board deixa de ser legível
        // (decisão travada no ROADMAP — sem modo união).
        $unionAllowed = !self::hasCustomSets();

        if ($raw !== null && $raw !== '') {
            if ($raw === 'all') {
                return $unionAllowed ? null : self::defaultTypeId($projectIds);
            }
            if (preg_match('/^\d+$/', (string) $raw)) {
                $asked   = (int) $raw;
                $allowed = array_column(self::selectorTypes(), 'id');
                if (in_array($asked, $allowed, true)) {
                    return $asked;
                }
            }
            // Valor esquisito: cai no padrão em vez de mostrar tela vazia.
        }

        if ($unionAllowed) {
            return null;
        }

        return self::defaultTypeId($projectIds);
    }

    /**
     * Variante para as telas que SEMPRE podem cruzar departamentos (Visão
     * geral): o `?type=` é opcional e a ausência dele significa "todos os
     * tipos", nunca um tipo escolhido pelo plugin. É o oposto dos Kanbans,
     * onde o tipo é obrigatório porque a COLUNA depende dele.
     */
    public static function requestedTypeOrNull(): ?int
    {
        $raw = $_GET['type'] ?? null;
        if ($raw === null || $raw === '' || $raw === 'all' || !preg_match('/^\d+$/', (string) $raw)) {
            return null;
        }

        $asked = (int) $raw;
        return in_array($asked, array_column(self::selectorTypes(), 'id'), true) ? $asked : null;
    }

    /**
     * Mapa para o cliente: typeId => [['id','name'],...] na ordem das
     * colunas. Usado para filtrar os `<select>` de fase DO PLUGIN (modal
     * "Novo projeto" e editor de Modelos) quando o tipo muda, sem recarregar
     * a página. A chave `0` é o conjunto padrão.
     *
     * @return array<int, array<int, array{id:int,name:string}>>
     */
    public static function phasesByType(): array
    {
        $out = [];
        foreach (array_merge([self::DEFAULT_TYPE], array_keys(self::projectTypes())) as $typeId) {
            $list = [];
            foreach (self::statesFor((int) $typeId) as $sid => $s) {
                $list[] = ['id' => (int) $sid, 'name' => (string) $s['name']];
            }
            $out[(int) $typeId] = $list;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Escrita (tela de administração)
    // ------------------------------------------------------------------

    /**
     * Grava o conjunto de um tipo. A `ordem` é a POSIÇÃO no array recebido
     * (o formulário envia os checkboxes na ordem da tela), então reordenar
     * não exige campo numérico nenhum.
     *
     * Estratégia: apaga as linhas do tipo e regrava — mais simples e mais
     * seguro que diferenciar linha por linha, e a chave única
     * (`projecttypes_id`, `projectstates_id`) nunca é violada.
     *
     * @param array<int, int|string> $stateIds ids de fase na ordem desejada
     *
     * @return int quantas fases ficaram no conjunto
     */
    public static function setForType(int $typeId, array $stateIds): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return 0;
        }

        $known = self::allStates();
        $clean = [];
        foreach ($stateIds as $sid) {
            $sid = (int) $sid;
            if ($sid > 0 && isset($known[$sid]) && !in_array($sid, $clean, true)) {
                $clean[] = $sid;
            }
        }

        $DB->delete(self::TABLE, ['projecttypes_id' => $typeId]);

        $ordem = 0;
        foreach ($clean as $sid) {
            $DB->insert(self::TABLE, [
                'projecttypes_id'  => $typeId,
                'projectstates_id' => $sid,
                'ordem'            => ++$ordem,
                'date_creation'    => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                'date_mod'         => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ]);
        }

        self::resetCache();
        return count($clean);
    }

    /**
     * Copia o conjunto de um tipo para outro (botão "copiar fases de outro
     * tipo" — resolve dois tipos com o mesmo fluxo sem estrutura extra).
     * Usa o conjunto EFETIVO da origem, então copiar do "conjunto padrão"
     * também funciona.
     *
     * @return int quantas fases foram copiadas
     */
    public static function copyFrom(int $sourceTypeId, int $targetTypeId): int
    {
        if ($sourceTypeId === $targetTypeId) {
            return 0;
        }

        return self::setForType($targetTypeId, array_keys(self::statesFor($sourceTypeId)));
    }

    /** Remove o conjunto de um tipo (volta a herdar o padrão). */
    public static function clearType(int $typeId): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return false;
        }

        $ok = (bool) $DB->delete(self::TABLE, ['projecttypes_id' => $typeId]);
        self::resetCache();
        return $ok;
    }

    // ------------------------------------------------------------------
    // Diagnóstico
    // ------------------------------------------------------------------

    /**
     * Conjuntos configurados que NÃO têm nenhuma fase com `is_finished`.
     *
     * POR QUE IMPORTA: a trava "projeto com tarefa/subprojeto aberto não vai
     * para fase finalizada" (hook PRE_ITEM_UPDATE) depende de existir fase
     * finalizadora. Num conjunto sem ela a trava NUNCA dispara — e falha em
     * silêncio, que é o pior tipo de falha.
     *
     * @return array<int, array{id:int,name:string,phases:int}>
     */
    public static function setsWithoutFinished(): array
    {
        $types = self::projectTypes();
        $out   = [];

        foreach (self::configuredTypeIds() as $typeId) {
            $set   = self::statesFor($typeId);
            $found = false;
            foreach ($set as $s) {
                if (!empty($s['is_finished'])) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                continue;
            }

            $out[] = [
                'id'     => $typeId,
                'name'   => $typeId === self::DEFAULT_TYPE
                    ? __('Conjunto padrão', 'projectplus')
                    : ($types[$typeId] ?? ('#' . $typeId)),
                'phases' => count($set),
            ];
        }

        return $out;
    }

    /**
     * Resumo para a tela de administração.
     *
     * @return array{
     *   sets: array<int, array{id:int,name:string,is_default:bool,mapped:bool,
     *                          projects:int,phases:array<int,array{id:int,name:string,color:string,is_finished:bool}>}>,
     *   states: array<int, array{id:int,name:string,color:string,is_finished:bool}>
     * }
     */
    public static function adminData(): array
    {
        $counts = self::projectCountByType();
        $sets   = [];

        // Conjunto padrão primeiro — é o que vale para todo tipo sem exceção.
        foreach (array_merge([self::DEFAULT_TYPE], array_keys(self::projectTypes())) as $typeId) {
            $typeId = (int) $typeId;
            $phases = [];
            foreach (self::statesFor($typeId) as $sid => $s) {
                $phases[] = [
                    'id'          => (int) $sid,
                    'name'        => $s['name'],
                    'color'       => $s['color'],
                    'is_finished' => !empty($s['is_finished']),
                ];
            }

            $sets[] = [
                'id'         => $typeId,
                'name'       => $typeId === self::DEFAULT_TYPE
                    ? __('Conjunto padrão', 'projectplus')
                    : (self::projectTypes()[$typeId] ?? ('#' . $typeId)),
                'is_default' => $typeId === self::DEFAULT_TYPE,
                'mapped'     => self::isMapped($typeId),
                'projects'   => (int) ($counts[$typeId] ?? 0),
                'phases'     => $phases,
            ];
        }

        $states = [];
        foreach (self::allStates() as $sid => $s) {
            $states[] = [
                'id'          => (int) $sid,
                'name'        => $s['name'],
                'color'       => $s['color'],
                'is_finished' => !empty($s['is_finished']),
            ];
        }

        return ['sets' => $sets, 'states' => $states];
    }

    /** Zera os caches estáticos (usado depois de gravar). */
    public static function resetCache(): void
    {
        self::$setCache  = [];
        self::$rowsCache = null;
        self::$allCache  = null;
    }
}

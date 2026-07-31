<?php

/**
 * ProjectPlus — dicionário de tradução para o JAVASCRIPT (Etapa 6, Bloco 3b).
 *
 * O GLPI 11 NÃO tem runtime de i18n no cliente (não existe Jed/dgettext no
 * core — verificado). Como quase toda a interface do plugin é montada no
 * navegador (árvore de tarefas, Kanban, timeline, editor de modelos,
 * burndown), o único caminho é traduzir no SERVIDOR e injetar o resultado
 * como JSON na página.
 *
 * COMO FUNCIONA
 *   1. map() devolve o dicionário já traduzido para o idioma do usuário.
 *      As chamadas a __() / _n() aqui são LITERAIS de propósito: é isso que
 *      faz o xgettext (tools/update-locales.sh) enxergar as strings do JS
 *      sem precisar de um extrator específico para .js.
 *   2. scriptTag() imprime <script type="application/json" id="pp-i18n">.
 *   3. public/js/i18n.js lê esse elemento e expõe t() / tn().
 *
 * A CHAVE É O PRÓPRIO TEXTO EM PT-BR (mesma decisão do Bloco 3a: o msgid é
 * português). Consequência importante: se o dicionário não chegar à página,
 * o JS devolve a própria chave e a tela continua em PT-BR — nunca quebrada.
 *
 * REGRA AO ACRESCENTAR STRING NOVA:
 *   - toda chamada t('X') / tn('X','Y',n) no JS precisa de uma linha aqui;
 *   - tools/check-js-strings.php confere isso nos dois sentidos e falha se
 *     houver chave sobrando ou faltando.
 *
 * @license GPL-2.0-or-later
 */

namespace GlpiPlugin\Projectplus;

class I18nJs
{
    /** id do <script> que carrega o dicionário (igual em public/js/i18n.js) */
    public const DOM_ID = 'pp-i18n';

    /** separador das duas formas na chave de plural (mesma convenção do .mo) */
    public const PLURAL_SEP = "\0";

    /** já foi impresso nesta requisição? (evita duplicar o mesmo id no DOM) */
    private static bool $emitted = false;

    /**
     * Dicionário completo: ['s' => [singular…], 'p' => [plurais…]].
     *
     * 's' → texto simples: chave PT-BR => tradução.
     * 'p' → plural: "singular\0plural" => [forma de 1, forma de muitos].
     */
    public static function map(): array
    {
        return [
            's' => [
                // ---- geral / rótulos compartilhados ----
                'Tipo'                     => __('Tipo', 'projectplus'),
                'Gestor'                   => __('Gestor', 'projectplus'),
                'Responsável'              => __('Responsável', 'projectplus'),
                'Responsáveis'             => __('Responsáveis', 'projectplus'),
                'Projeto'                  => __('Projeto', 'projectplus'),
                'Projetos'                 => __('Projetos', 'projectplus'),
                'Subprojeto'               => __('Subprojeto', 'projectplus'),
                'Subprojetos'              => __('Subprojetos', 'projectplus'),
                'Tarefa'                   => __('Tarefa', 'projectplus'),
                'Tarefas'                  => __('Tarefas', 'projectplus'),
                'Fase'                     => __('Fase', 'projectplus'),
                'Prazo'                    => __('Prazo', 'projectplus'),
                'Início'                   => __('Início', 'projectplus'),
                'Fim'                      => __('Fim', 'projectplus'),
                'Salvar'                   => __('Salvar', 'projectplus'),
                'Cancelar'                 => __('Cancelar', 'projectplus'),
                'Adicionar'                => __('Adicionar', 'projectplus'),
                'Remover'                  => __('Remover', 'projectplus'),
                'Editar'                   => __('Editar', 'projectplus'),
                'Excluir'                  => __('Excluir', 'projectplus'),
                'Concluir'                 => __('Concluir', 'projectplus'),
                'Nenhuma'                  => __('Nenhuma', 'projectplus'),
                'Carregando…'              => __('Carregando…', 'projectplus'),
                'Orçamento (R$)'           => __('Orçamento (R$)', 'projectplus'),

                // ---- painel de tarefas / edição inline ----
                'Carregando tarefas…'      => __('Carregando tarefas…', 'projectplus'),
                'Erro ao carregar tarefas.' => __('Erro ao carregar tarefas.', 'projectplus'),
                'Erro ao carregar suas tarefas.' => __('Erro ao carregar suas tarefas.', 'projectplus'),
                'Nenhuma tarefa neste projeto ainda.' => __('Nenhuma tarefa neste projeto ainda.', 'projectplus'),
                'Nenhuma tarefa atribuída a você.' => __('Nenhuma tarefa atribuída a você.', 'projectplus'),
                'Nenhuma tarefa atribuída a você em aberto.' => __('Nenhuma tarefa atribuída a você em aberto.', 'projectplus'),
                'Nova tarefa…'             => __('Nova tarefa…', 'projectplus'),
                'Sem tarefa pai'           => __('Sem tarefa pai', 'projectplus'),
                'Sem responsável'          => __('Sem responsável', 'projectplus'),
                'Início planejado'         => __('Início planejado', 'projectplus'),
                'Fim planejado'            => __('Fim planejado', 'projectplus'),
                'Criar tarefa'             => __('Criar tarefa', 'projectplus'),
                'Tarefa mãe'               => __('Tarefa mãe', 'projectplus'),
                'Cálculo automático a partir das subtarefas' => __('Cálculo automático a partir das subtarefas', 'projectplus'),
                'Bloqueada por outra(s) tarefa(s) — veja 🔗' => __('Bloqueada por outra(s) tarefa(s) — veja 🔗', 'projectplus'),
                'Projeto com tarefas/subprojetos abertos — não pode ir para fase concluída' => __('Projeto com tarefas/subprojetos abertos — não pode ir para fase concluída', 'projectplus'),

                // ---- barra de prazo / badges ----
                'Sem datas planejadas — corrija o planejamento' => __('Sem datas planejadas — corrija o planejamento', 'projectplus'),
                'sem datas'                => __('sem datas', 'projectplus'),
                'Atrasado'                 => __('Atrasado', 'projectplus'),
                'Parado'                   => __('Parado', 'projectplus'),
                'No prazo'                 => __('No prazo', 'projectplus'),

                // ---- sino de alertas ----
                'Marcar como lida'         => __('Marcar como lida', 'projectplus'),
                'Nenhum alerta não lido'   => __('Nenhum alerta não lido', 'projectplus'),
                'Lidas recentemente'       => __('Lidas recentemente', 'projectplus'),

                // ---- comentários ----
                'Comentários'              => __('Comentários', 'projectplus'),
                'Carregando comentários…'  => __('Carregando comentários…', 'projectplus'),
                'Erro ao carregar comentários.' => __('Erro ao carregar comentários.', 'projectplus'),
                'Nenhum comentário ainda.' => __('Nenhum comentário ainda.', 'projectplus'),
                'editado'                  => __('editado', 'projectplus'),
                'Escreva um comentário… (Ctrl+Enter envia)' => __('Escreva um comentário… (Ctrl+Enter envia)', 'projectplus'),
                'Comentar'                 => __('Comentar', 'projectplus'),
                'Excluir este comentário?' => __('Excluir este comentário?', 'projectplus'),

                // ---- dependências ----
                'Dependências'             => __('Dependências', 'projectplus'),
                'Carregando dependências…' => __('Carregando dependências…', 'projectplus'),
                'Erro ao carregar as dependências.' => __('Erro ao carregar as dependências.', 'projectplus'),
                'aberta'                   => __('aberta', 'projectplus'),
                'concluída'                => __('concluída', 'projectplus'),
                'subtarefa'                => __('subtarefa', 'projectplus'),
                'Regra geral: subtarefa aberta bloqueia a mãe' => __('Regra geral: subtarefa aberta bloqueia a mãe', 'projectplus'),
                'Remover vínculo'          => __('Remover vínculo', 'projectplus'),
                'Bloqueada por'            => __('Bloqueada por', 'projectplus'),
                '(precisam terminar antes)' => __('(precisam terminar antes)', 'projectplus'),
                'Bloqueia'                 => __('Bloqueia', 'projectplus'),
                '(só concluem depois desta)' => __('(só concluem depois desta)', 'projectplus'),
                'É bloqueada por'          => __('É bloqueada por', 'projectplus'),
                '— sem outras tarefas neste projeto —' => __('— sem outras tarefas neste projeto —', 'projectplus'),
                'Remover este vínculo de dependência?' => __('Remover este vínculo de dependência?', 'projectplus'),

                // ---- editor de modelos ----
                'Informe o nome do modelo.' => __('Informe o nome do modelo.', 'projectplus'),
                'Adicione ao menos uma tarefa ou subprojeto.' => __('Adicione ao menos uma tarefa ou subprojeto.', 'projectplus'),
                'Dias após a data de início escolhida ao criar' => __('Dias após a data de início escolhida ao criar', 'projectplus'),
                'Duração do projeto em dias (define a data de fim)' => __('Duração do projeto em dias (define a data de fim)', 'projectplus'),
                'início (d)'               => __('início (d)', 'projectplus'),
                'duração (d)'              => __('duração (d)', 'projectplus'),
                'calcular automaticamente o %' => __('calcular automaticamente o %', 'projectplus'),
                'Descrição do projeto (opcional)' => __('Descrição do projeto (opcional)', 'projectplus'),
                'Descrição (opcional)'     => __('Descrição (opcional)', 'projectplus'),
                'Nome da tarefa'           => __('Nome da tarefa', 'projectplus'),
                'Nome do subprojeto'       => __('Nome do subprojeto', 'projectplus'),
                '+ tarefa'                 => __('+ tarefa', 'projectplus'),
                '+ subtarefa'              => __('+ subtarefa', 'projectplus'),
                '+ subprojeto'             => __('+ subprojeto', 'projectplus'),

                // ---- donuts da Visão geral ----
                'Concluída'                => __('Concluída', 'projectplus'),
                'Concluído'                => __('Concluído', 'projectplus'),
                'Concluídas'               => __('Concluídas', 'projectplus'),
                'Em andamento'             => __('Em andamento', 'projectplus'),
                'Planejado'                => __('Planejado', 'projectplus'),
                'Pendentes'                => __('Pendentes', 'projectplus'),
                'Atrasadas'                => __('Atrasadas', 'projectplus'),
                'Sem dados para exibir'    => __('Sem dados para exibir', 'projectplus'),
                'Distribuição por status'  => __('Distribuição por status', 'projectplus'),

                // ---- burndown (Relatórios) ----
                'Gráfico de burndown'      => __('Gráfico de burndown', 'projectplus'),
                'Ideal'                    => __('Ideal', 'projectplus'),
                'Real (tarefas restantes)' => __('Real (tarefas restantes)', 'projectplus'),
                'tarefas'                  => __('tarefas', 'projectplus'),
                'concluídas'               => __('concluídas', 'projectplus'),
                'restantes'                => __('restantes', 'projectplus'),
                'concluído'                => __('concluído', 'projectplus'),
                'Este projeto (e seus subprojetos) não tem tarefas.' => __('Este projeto (e seus subprojetos) não tem tarefas.', 'projectplus'),
                'Selecione um projeto para ver o burndown.' => __('Selecione um projeto para ver o burndown.', 'projectplus'),
                'Projeto não encontrado.'  => __('Projeto não encontrado.', 'projectplus'),
                'Não foi possível carregar o burndown.' => __('Não foi possível carregar o burndown.', 'projectplus'),

                // ---- Kanban de tarefas ----
                'Não foi possível carregar o Kanban.' => __('Não foi possível carregar o Kanban.', 'projectplus'),
                'Nenhuma tarefa encontrada com os filtros atuais.' => __('Nenhuma tarefa encontrada com os filtros atuais.', 'projectplus'),
                'Recolher subprojetos'     => __('Recolher subprojetos', 'projectplus'),
                'Mostrar subprojetos'      => __('Mostrar subprojetos', 'projectplus'),
                'Subtarefa da tarefa: %s'  => __('Subtarefa da tarefa: %s', 'projectplus'),

                // ---- Kanban de projetos ----
                'Não foi possível carregar o Kanban de projetos.' => __('Não foi possível carregar o Kanban de projetos.', 'projectplus'),
                'Nenhum projeto encontrado com os filtros atuais.' => __('Nenhum projeto encontrado com os filtros atuais.', 'projectplus'),
                'Subprojeto de: %s'        => __('Subprojeto de: %s', 'projectplus'),

                // ---- timeline ----
                'Não foi possível carregar os dados da timeline.' => __('Não foi possível carregar os dados da timeline.', 'projectplus'),
                'Nenhum projeto ou tarefa encontrado.' => __('Nenhum projeto ou tarefa encontrado.', 'projectplus'),
                'Projeto / tarefa'         => __('Projeto / tarefa', 'projectplus'),
                'Bloqueada — veja as dependências' => __('Bloqueada — veja as dependências', 'projectplus'),
                // TRANSLATORS: os 12 meses abreviados, em ordem, separados por "|".
                // Vai num campo só porque "jan", "fev"… isolados seriam ambíguos
                // demais para o tradutor e colidiriam com outros msgid curtos.
                'jan|fev|mar|abr|mai|jun|jul|ago|set|out|nov|dez' => __('jan|fev|mar|abr|mai|jun|jul|ago|set|out|nov|dez', 'projectplus'),

                // ---- abas nativas escondidas (hidenative*.js) ----
                // TRANSLATORS: rótulo da aba "Custos" do NÚCLEO do GLPI, no idioma
                // do usuário. É comparado com o texto da aba para escondê-la; se a
                // tradução não bater, a aba nativa reaparece.
                'Custos'                   => __('Custos', 'projectplus'),
                // TRANSLATORS: rótulo da aba "Kanban" do NÚCLEO do GLPI.
                'Kanban'                   => __('Kanban', 'projectplus'),
            ],

            'p' => [
                // "3 tarefas" no cabeçalho de cada projeto em "Minhas tarefas"
                self::pkey('%d tarefa', '%d tarefas') => [
                    _n('%d tarefa', '%d tarefas', 1, 'projectplus'),
                    _n('%d tarefa', '%d tarefas', 2, 'projectplus'),
                ],
                // title do botão "+" que expande as subtarefas
                self::pkey('%d subtarefa', '%d subtarefas') => [
                    _n('%d subtarefa', '%d subtarefas', 1, 'projectplus'),
                    _n('%d subtarefa', '%d subtarefas', 2, 'projectplus'),
                ],
                // tooltip dos pontos do burndown ("12 restantes")
                self::pkey('%d restante', '%d restantes') => [
                    _n('%d restante', '%d restantes', 1, 'projectplus'),
                    _n('%d restante', '%d restantes', 2, 'projectplus'),
                ],
            ],
        ];
    }

    /** Monta a chave composta de um plural (mesma regra usada no i18n.js). */
    public static function pkey(string $singular, string $plural): string
    {
        return $singular . self::PLURAL_SEP . $plural;
    }

    /**
     * Dicionário serializado.
     *
     * Lição nº 12: JSON embutido em <script> precisa das flags HEX — aqui o
     * conteúdo é traduzido por nós (não vem do usuário), mas as flags custam
     * nada e blindam contra um "</script>" numa tradução futura.
     */
    public static function json(): string
    {
        // A máscara de data viaja na chave 'd', FORA de map(): map() é o
        // dicionário puro e é o que tools/check-js-strings.php confere
        // chave por chave contra os .js — misturar metadado ali geraria
        // falso "chave que nenhum JS usa". O i18n.js ignora chaves que não
        // conhece, então acrescentar 'd' não quebra nada retroativamente.
        $payload = self::map();
        $payload['d'] = DateFmt::jsFormat();

        return (string) json_encode(
            $payload,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Imprime o dicionário na página. Chamado pelos front/*.php (logo depois
     * do Html::header) e pelas abas nativas que carregam JS do plugin.
     *
     * Idempotente dentro da MESMA requisição: chamar duas vezes não duplica
     * o elemento (o segundo retorno é vazio).
     */
    public static function render(): void
    {
        if (self::$emitted) {
            return;
        }
        self::$emitted = true;

        echo '<script id="' . self::DOM_ID . '" type="application/json">'
            . self::json() . '</script>';
    }

    /** Mesma coisa que render(), mas devolvendo a string (uso em templates). */
    public static function scriptTag(): string
    {
        if (self::$emitted) {
            return '';
        }
        self::$emitted = true;

        return '<script id="' . self::DOM_ID . '" type="application/json">'
            . self::json() . '</script>';
    }
}

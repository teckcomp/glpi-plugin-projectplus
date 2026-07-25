# ProjectPlus — Roadmap

**Plugin de gestão avançada de projetos para GLPI 11**
Repositório: [github.com/teckcomp/glpi-plugin-projectplus](https://github.com/teckcomp/glpi-plugin-projectplus) · Licença GPL-2.0
Versão atual: **v0.5.0-alpha** · Atualizado em 25/07/2026 (Etapa 8 concluída; **Etapa 6 em andamento — Blocos 1, 2 e 2b fechados**. Próximo: Bloco 3, i18n)

> **Ordem de execução confirmada em 19/07/2026:** Etapa 7 → Etapa 8 → Etapa 6 (por último). A Etapa 6 (refinamento/pré-produção e release v1.0.0-beta) só começa depois que 7 e 8 estiverem validadas em homologação.

---

## ✅ Etapa 0 — Fundação `concluída`

- Estrutura do plugin (setup, hooks, front, src, templates Twig, estáticos em `public/`)
- Instalação idempotente via `Install::install` (`tableExists`/`fieldExists`)
- 7 tabelas próprias: trackings, timers, templates, alerts, taskcosts, projectcosts, taskcomments
- Direito de acesso `plugin_projectplus_dashboard`
- Tela de configuração (context `plugin:projectplus`) e cron `projectplusalerts`
- Proteção de uninstall: dados mantidos por padrão, purga opcional

## ✅ Etapa 1 — Painel e visual `concluída`

- Sidebar: Visão geral, Minhas tarefas, Projetos, Tarefas, Kanban global, Custos
- 6 KPIs, donuts, tabelas de projetos/tarefas em andamento
- Árvore de tarefas com edição inline
- Modal "Novo Projeto" e feed de atividades

## ✅ Etapa 2 — Orçamento híbrido `concluída`

- Indicadores de orçamento por projeto (pai + filhos)
- Percentual consumido e alerta de limiar configurável (`budget_warn_percent`)

## ✅ Bloco 4 — Prazos e alertas `concluído`

- Barra de prazo em 3 telas (árvore de tarefas, projetos e tarefas em andamento)
- Faixas: verde/azul → amarelo 50% → laranja 75% → vermelho 90% → vermelho escuro 100%+ (excedente segue contando)
- Alertas ao gestor por limiar com deduplicação (estouro reenvia a cada 8h)
- Tarefa sem datas = barra cinza + alerta
- Sino de alertas no cabeçalho: badge de não lidas, histórico e "marcar todas"

## ✅ Bloco 5 — Custos `concluído`

- Custos por tarefa e por projeto em abas próprias "Custos (ProjectPlus)", com autor
- Fonte única: aba nativa oculta via configuração, Budget lê só as tabelas do plugin
- Migração automática dos custos nativos na instalação
- Tela "Custos": relatório consolidado por projeto (origem, autor, totais, saldo, filtro, impressão)

## ✅ Etapa 2.5 — Higiene de dados `concluída em 18/07/2026`

- [x] Diagnóstico dos dados (estados, tipos, categorias)
- [x] Modelo de 5 fases aplicado: 1. Iniciação · 2. Planejamento · 3. Execução · 4. Monitoramento · 5. Encerramento (com cores e `is_finished`)
- [x] Tipos de projeto e de tarefa criados (kit TI/MSP)
- [x] Validação visual no painel e classificação dos itens existentes
- [x] Bloco 3: chip de fase colorido na árvore de tarefas, tabelas e donut "Projetos por fase"

## ✅ Etapa 3 — Trabalho do dia a dia `concluída em 19/07/2026`

- [x] **Bloco 1** — Tela "Minhas tarefas": tarefas do usuário logado agrupadas por projeto, KPIs pessoais, hierarquia mãe/filha, edição inline com barra de prazo
- [x] **Bloco 2** — Comentários por tarefa: tabela própria, balão com contador e painel expansível, aba nativa "Comentários (ProjectPlus)", alerta no sino
- [x] **Bloco 3** — Dependências entre tarefas na tabela nativa `glpi_projecttasklinks` (finish_to_start): coluna 🔗, cadeado 🔒, painel expansível, aba nativa, regra no servidor, prevenção de ciclo/duplicata
- [x] **Bloco 4** — Ajustes de layout/UX da Visão geral: KPIs reorganizados, 4 donuts, busca nas tabelas, 🔗/💬/🔒 nas linhas
- [x] **Timeline** — Gantt somente-leitura em HTML/JS puro, escopo por usuário

## ✅ Etapa 4 — Modelos de projeto `concluída em 19/07/2026`

- [x] Tela "Modelos" na sidebar; salvar projeto como modelo (captura recursiva); editor visual; criar projeto a partir do modelo (`TemplateCloner`, offsets relativos, anti-duplicação); campos por item; JSON em `glpi_plugin_projectplus_templates.structure`. Commit `9c940a1`, tag `v0.5.0-alpha`.

## ✅ Etapa 5 — Relatórios `concluída em 19/07/2026`

- [x] **Bloco 1/1.1/1.2** — Tela "Relatórios" + CSV (Projetos/Tarefas/Custos), filtros (tarefa, gestor/responsável, fase, tipo com optgroup, período). Commit `f0eee3e`.
- [x] **Bloco 2** — Burndown por projeto (SVG puro, toggle Semana/Dia/Mês client-side; conclusão por `date_mod` quando `percent_done=100`; linha ideal por datas planejadas). Commit `a9862f1`.

## ✅ Etapa 7 — Kanban avançado `concluída em 19/07/2026`

- [x] **Bloco 1/1.1/1.2/1.3** — Board próprio (colunas=fase, swimlanes Projeto/Responsável), aba nativa "Kanban (ProjectPlus)", visibilidade direta + expandir, subprojeto como lane. Commit `6c2ac4b`.
- [x] **Bloco 2** — Arrastar-e-soltar = só FASE (2b cancelado); toda tarefa é cartão comum; trava por dependência. Commit `eaaffc5`.

## ✅ Etapa 8 — Níveis de acesso `concluída em 25/07/2026`

Papéis: **Gestor / Cliente / Técnico / Colaborador (Terceiro)** + Admin. Entidade única. Desenho travado (`etapa8-desenho-acessos-v3-final`).

- [x] **Bloco 1** — Aba "ProjectPlus" no Perfil, matriz de 4 níveis, 10 direitos + migração. **FECHADO NO GITHUB em 20/07/2026 (commit `93e2b69`).**
- [x] **Bloco 2** — Direitos de módulo: sidebar por direito, gate por front, Modelos → `plugin_projectplus_templates`, roteamento do Kanban; helper `src/Access.php`. **FECHADO NO GITHUB em 21/07/2026 (commit `b4a6473`).**
- [x] **Bloco 3** — Escopo por tela (Visão geral, Kanban, Timeline). **FECHADO NO GITHUB em 22/07/2026 (commit `5ab519d`, 11 arquivos, +394/−33).** Novo helper `src/Scope.php`. Regras: PROJETOS do escopo = onde está na **equipe do projeto** (`glpi_projectteams`), cada um por si; TAREFAS = as **suas** (equipe da tarefa). No pessoal a lista é **plana**. **INVERSÃO final (21/07): o PADRÃO é VER TUDO** (maior escopo do perfil); o botão é **"Ver só os meus"** (`?scope=mine`), sem memória de sessão.
- [x] **Bloco 4** — Cliente + Colaborador + escopo em Relatórios/Custos. **FECHADO NO GITHUB em 25/07/2026 (commit `a1c0225`, 21 arquivos, +1149/−81).**
  - **4a — Relatórios e Custos respeitando o escopo:** `Reports::projectsData/tasksData/costsData/burndownData` e `front/costs.php` passam a cruzar o filtro da tela com `src/Scope.php` (helper `Reports::combineIds`, interseção — o filtro nunca amplia o escopo). Botão "Ver só os meus" nas duas telas, preservando os filtros na URL e nos links de CSV. Com escopo ativo, a lista de custos é **plana** (sem descer para subprojetos de terceiros).
  - **4b — Kanban de PROJETOS:** novos `src/ProjectKanban.php`, `front/projectkanban.php`, `templates/projectkanban.html.twig` e `public/js/projectkanban.js`. Colunas = fases dos projetos, cartões = projetos/subprojetos (subprojeto com a tag "Subprojeto de: …", mesmo padrão da subtarefa), sem swimlanes. `front/kanban.php` redireciona para lá quando `Access::kanbanIsProjects()` — o item "Kanban" da sidebar continua único.
    - **4b.1** — botão "Kanban de projetos" no board de tarefas: sem ele, quem tem os dois Kanbans só chegaria ao board novo pela URL (a sidebar aponta para o de tarefas).
    - **4b.2** — arrastar cartão muda a **fase do projeto** (novo `ajax/project.php`, action `kanban_move`), para quem tem Projetos em UPDATE + direito nativo `project`. Cliente segue somente leitura. Mesma trava da ficha nativa (projeto com tarefa/subprojeto aberto não vai para fase finalizada), mas com **mensagem explicando** — pelo hook `PRE_ITEM_UPDATE` o campo voltava em silêncio. Sem update otimista; token rotacionado a cada resposta.
  - **4c — Cliente/Colaborador sem custos:** o painel esconde a coluna "Orçamento", o campo "Teto de orçamento" e (para o Cliente) todo o bloco de Tarefas; as abas "Custos (ProjectPlus)" de projeto e tarefa passam a exigir `plugin_projectplus_costs`; `budget.form.php` exige o direito em UPDATE. `Kanban::canAccess()` migrou do direito do Painel para `plugin_projectplus_kanban`.

## 📍 Etapa 6 — Refinamento e pré-produção `EM ANDAMENTO`

Última etapa do roadmap. Fecha com a release **v1.0.0-beta**.

- [x] **Bloco 1** — Ciclo desinstalar/reinstalar seguro. **VALIDADO em homologação e FECHADO NO GITHUB em 25/07/2026** (zip `projectplus-etapa6-bloco1-1.zip`).
  - **1a — Reconciliação de schema:** `Install::ensureSchema()` garante, em base já existente, as colunas que passaram a existir depois da criação da tabela (a guarda `tableExists` só protegia a CRIAÇÃO). Índices UNIQUE não são criados automaticamente — só reportados, porque falhariam em base com duplicata.
  - **1b — Importação dos custos nativos marcada por flag:** nova chave de configuração `costs_migrated`. Antes a condição era "tabela do plugin vazia", o que reimportava tudo em quem tivesse apagado os custos migrados de propósito.
  - **1c — Direitos preservados na desinstalação:** `ProfileRight::deleteProfileRights` passa a rodar **só** com `purge_on_uninstall` ligado — a mesma proteção que os dados já tinham. Antes, desinstalar apagava as 11 linhas de `glpi_profilerights` de todos os perfis e a reinstalação recompunha só os padrões, perdendo em silêncio o ajuste fino da Etapa 8.
  - **1d — Diagnóstico na tela de Configuração:** `Install::healthReport()` + tabela na tela — 7 tabelas (existência, nº de registros, colunas/índices faltando), os 11 direitos (em quantos perfis a linha existe e em quantos há acesso), o cron e as marcas de purga/importação. É o instrumento de conferência do próprio ciclo de reinstalação.
  - Constantes `Install::TABLES` e `Install::RIGHTS` viram fonte única (install, uninstall e diagnóstico). Removida uma chamada morta de `addRight` (ver lição 38).
- [x] **Bloco 2** — E-mail real. **VALIDADO em homologação e FECHADO NO GITHUB em 25/07/2026** (zip `projectplus-etapa6-bloco2-2.zip`). Envio confirmado ponta a ponta (SMTP do Gmail, mensagem recebida).
  - **Causa raiz do e-mail que não saía:** o construtor do `GLPIMailer` define apenas `sender` (e só se `smtp_sender` estiver preenchido) — nunca o `from`. Como `send()` chama `ensureValidity()`, a mensagem era rejeitada antes de sair, e o `catch` genérico do plugin engolia o motivo. O `from` agora vem de `Config::getEmailSender()` (from_email → admin_email), com fallback ao `$CFG_GLPI`.
  - **Canal respeitado:** `use_notifications` e `notifications_mailing` desligados no GLPI impedem o envio. Chave ausente NÃO bloqueia (evita falso negativo).
  - **Erro visível:** falhas gravam o motivo real (inclusive `GLPIMailer::getError()`) em `files/_log/projectplus.log`; o cron anota `enviado/falhou/ignorado` via `CronTask::log()`.
  - **Link no corpo:** e-mails trazem a URL da ficha nativa, montada com `url_base`.
  - **Equipe por grupo:** `notifyTaskTeam` filtrava `itemtype = 'User'` — tarefa atribuída a um GRUPO não notificava ninguém. Agora expande por `glpi_groups_users`, sem duplicar quem está nos dois. Schema de `glpi_projecttaskteams` confirmado contra o core (a pendência "VALIDAR" do código está fechada).
  - **Bug colateral corrigido (lição 44):** a `action` do formulário de Configuração usava `$_SERVER['PHP_SELF']`, que no GLPI 11 vale `/index.php` por causa do front controller — o POST caía no endpoint de inventário (`XML not well formed!`) e o botão **Salvar já estava quebrado desde sempre**. Agora a URL é montada a partir de `root_doc`.
  - Tela de Configuração ganhou o botão "Salvar e enviar e-mail de teste" e a seção **E-mail** no diagnóstico (canal, remetente, DSN com senha oculta, url_base).
- [x] **Bloco 2b** — Identidade visual (inserido a pedido, antes do i18n, para congelar o vocabulário das telas). **VALIDADO em homologação e FECHADO NO GITHUB em 25/07/2026.**
  - Marca própria: `logo.png` na raiz (o GLPI 11 a serve em `/Plugin/projectplus/Logo` quando o arquivo existe — senão mostra um quadrado com a inicial) e `public/img/projectplus-mark.svg` ao lado do título nas 9 telas. Barras de Gantt com progresso, na paleta que o plugin já usava (`#065a82` → `#16323f`, verde `#4caf7d`). Tamanho da marca na variável CSS `--pp-mark-size` (42px).
  - Menu renomeado: "Painel de Projetos" → **"Gestor de Projetos"** (4 pontos: menu, tela de Configuração e 2 textos de ajuda).
  - Itens "Calendário" e "Recursos" (marcados como "em breve") removidos dos 9 templates, junto com as regras CSS `--soon` órfãs. Eram placeholders sem rota e não constavam do roadmap.

- [ ] **Bloco 3** — i18n: extração das strings para `.po`/`.mo`, inglês como segundo idioma (PT-BR continua primeiro).
  - **Decisão de 25/07/2026:** o texto-fonte (msgid) permanece em PT-BR — as 748 chamadas `__()` não serão tocadas. Traduz-se PT-BR → inglês no `.po`.
  - **Obrigatório:** gerar TAMBÉM um `pt_BR.mo` (identidade). `Plugin::loadLang` cai em `en_GB.mo` quando não acha `.mo` para o idioma do usuário — sem o `pt_BR.mo`, todo usuário PT-BR passaria a ver a interface em inglês.
  - Volume: ~400 strings únicas (269 nos PHP + 195 nos Twig, com sobreposição). Inclui `tools/update-locales.sh` para regenerar `.pot`/`.mo`.
  - Resolver os avisos do `xgettext`: "Custo" aparece com e sem plural (`_n`), o que gettext não aceita no mesmo msgid.
- [ ] **Bloco 4** — Release `v1.0.0-beta`: CHANGELOG, tag e pacote de distribuição.
- [ ] **Bloco 5** — Submissão ao catálogo oficial do GLPI (depois da beta).

### Pendências pré-beta (baixo risco)

- `$_SERVER['PHP_SELF']` ainda é passado como 2º argumento de `Html::header()` em 9 arquivos de `front/`. Não quebra nada (afeta só o destaque de menu/breadcrumb), mas cabe padronizar na limpeza da beta.

### Decisões em aberto

- Board de projetos restrito a quem tem `projectkanban` marcado? Hoje aparece para todos que têm o Kanban de tarefas (ver 4b.1). Baixa prioridade.
- Tabela `glpi_plugin_projectplus_tasktimers` está órfã desde a Etapa 1 (o cronômetro por tarefa foi substituído pela barra de prazo). Continua sendo criada. Decidir antes da beta: parar de criá-la (bases novas ficam com 6 tabelas) ou mantê-la como está.

---

## Decisões de publicação

- PT-BR primeiro; inglês entra na Etapa 6
- Releases formais só a partir da Etapa 6 (provável v1.0.0-beta)
- Catálogo oficial do GLPI após a beta

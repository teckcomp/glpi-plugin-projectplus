# ProjectPlus — Roadmap

**Plugin de gestão avançada de projetos para GLPI 11**
Repositório: [github.com/teckcomp/glpi-plugin-projectplus](https://github.com/teckcomp/glpi-plugin-projectplus) · Licença GPL-2.0
Versão atual: **v0.5.0-alpha** · Atualizado em 25/07/2026 (Etapa 8 — Blocos 1/2/3 fechados; Bloco 4 entregue para homologação. Próximo: Etapa 6)

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

## 📍 Etapa 8 — Níveis de acesso `EM ANDAMENTO`

Papéis: **Gestor / Cliente / Técnico / Colaborador (Terceiro)** + Admin. Entidade única. Desenho travado (`etapa8-desenho-acessos-v3-final`).

- [x] **Bloco 1** — Aba "ProjectPlus" no Perfil, matriz de 4 níveis, 10 direitos + migração. **FECHADO NO GITHUB em 20/07/2026 (commit `93e2b69`).**
- [x] **Bloco 2** — Direitos de módulo: sidebar por direito, gate por front, Modelos → `plugin_projectplus_templates`, roteamento do Kanban; helper `src/Access.php`. **FECHADO NO GITHUB em 21/07/2026 (commit `b4a6473`).**
- [x] **Bloco 3** — Escopo por tela (Visão geral, Kanban, Timeline). **FECHADO NO GITHUB em 22/07/2026 (commit `5ab519d`, 11 arquivos, +394/−33).** Novo helper `src/Scope.php`. Regras: PROJETOS do escopo = onde está na **equipe do projeto** (`glpi_projectteams`), cada um por si; TAREFAS = as **suas** (equipe da tarefa). No pessoal a lista é **plana**. **INVERSÃO final (21/07): o PADRÃO é VER TUDO** (maior escopo do perfil); o botão é **"Ver só os meus"** (`?scope=mine`), sem memória de sessão.
- [x] **Bloco 4** — Cliente + Colaborador + escopo em Relatórios/Custos. Entregue em 25/07/2026 (zip `projectplus-etapa8-bloco4-1.zip`).
  - **4a — Relatórios e Custos respeitando o escopo:** `Reports::projectsData/tasksData/costsData/burndownData` e `front/costs.php` passam a cruzar o filtro da tela com `src/Scope.php` (helper `Reports::combineIds`, interseção — o filtro nunca amplia o escopo). Botão "Ver só os meus" nas duas telas, preservando os filtros na URL e nos links de CSV. Com escopo ativo, a lista de custos é **plana** (sem descer para subprojetos de terceiros).
  - **4b — Kanban de PROJETOS:** novos `src/ProjectKanban.php`, `front/projectkanban.php`, `templates/projectkanban.html.twig` e `public/js/projectkanban.js`. Colunas = fases dos projetos, cartões = projetos/subprojetos (subprojeto com a tag "Subprojeto de: …", mesmo padrão da subtarefa), sem swimlanes. `front/kanban.php` redireciona para lá quando `Access::kanbanIsProjects()` — o item "Kanban" da sidebar continua único.
    - **4b.1** — botão "Kanban de projetos" no board de tarefas: sem ele, quem tem os dois Kanbans só chegaria ao board novo pela URL (a sidebar aponta para o de tarefas).
    - **4b.2** — arrastar cartão muda a **fase do projeto** (novo `ajax/project.php`, action `kanban_move`), para quem tem Projetos em UPDATE + direito nativo `project`. Cliente segue somente leitura. Mesma trava da ficha nativa (projeto com tarefa/subprojeto aberto não vai para fase finalizada), mas com **mensagem explicando** — pelo hook `PRE_ITEM_UPDATE` o campo voltava em silêncio. Sem update otimista; token rotacionado a cada resposta.
  - **4c — Cliente/Colaborador sem custos:** o painel esconde a coluna "Orçamento", o campo "Teto de orçamento" e (para o Cliente) todo o bloco de Tarefas; as abas "Custos (ProjectPlus)" de projeto e tarefa passam a exigir `plugin_projectplus_costs`; `budget.form.php` exige o direito em UPDATE. `Kanban::canAccess()` migrou do direito do Painel para `plugin_projectplus_kanban`.

## 📍 Etapa 6 — Refinamento e pré-produção `próxima`

- Teste de e-mail real (GLPIMailer), ciclo desinstalação/reinstalação, i18n (inglês + .po/.mo), release **v1.0.0-beta**, submissão ao catálogo oficial do GLPI.

---

## Decisões de publicação

- PT-BR primeiro; inglês entra na Etapa 6
- Releases formais só a partir da Etapa 6 (provável v1.0.0-beta)
- Catálogo oficial do GLPI após a beta

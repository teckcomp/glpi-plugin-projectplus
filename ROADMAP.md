# ProjectPlus — Roadmap

**Plugin de gestão avançada de projetos para GLPI 11**
Repositório: [github.com/teckcomp/glpi-plugin-projectplus](https://github.com/teckcomp/glpi-plugin-projectplus) · Licença GPL-2.0
Versão atual: **v0.5.0-alpha** · Atualizado em 19/07/2026

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
- [x] Validação visual no painel (dropdowns e nomes de fase) e classificação dos itens existentes
- [x] Bloco 3: chip de fase colorido na árvore de tarefas, tabelas de projetos/subprojetos e tarefas em andamento + donut "Projetos por fase"

## ✅ Etapa 3 — Trabalho do dia a dia `concluída em 19/07/2026`

- [x] **Bloco 1** — Tela "Minhas tarefas": tarefas do usuário logado agrupadas por projeto, KPIs pessoais, hierarquia mãe/filha, edição inline com barra de prazo
- [x] **Bloco 2** — Comentários por tarefa: tabela própria, balão com contador e painel expansível, aba nativa "Comentários (ProjectPlus)", alerta no sino
- [x] **Bloco 3** — Dependências entre tarefas na tabela nativa `glpi_projecttasklinks` (finish_to_start): coluna 🔗, cadeado 🔒, painel expansível, aba nativa, regra no servidor (bloqueada não conclui), prevenção de ciclo/duplicata; filhos abertos bloqueiam o pai
- [x] **Bloco 4** — Ajustes de layout/UX da Visão geral: KPIs reorganizados, 4 donuts (inclui "Tarefas por Estado"), busca nas tabelas, 🔗/💬/🔒 nas linhas
- [x] **Timeline** — Gantt somente-leitura em HTML/JS puro, escopo por usuário (mesmo critério de "Minhas tarefas")

## ✅ Etapa 4 — Modelos de projeto `concluída em 19/07/2026`

- [x] **Tela "Modelos"** na sidebar, restrita a super-admin (`config` UPDATE); a atribuição por perfil a gestores foi remetida à Etapa 8
- [x] **Salvar projeto existente como modelo**: captura recursiva da árvore COMPLETA (tarefas + todos os subprojetos, com suas tarefas), incluindo atributos de cada item; o projeto de origem não é alterado
- [x] **Editor visual** (criar do zero + editar a qualquer tempo): monta a árvore de tarefas/subtarefas e subprojetos aninhados no cliente (DOM como fonte da verdade; POST tradicional do JSON)
- [x] **Criar projeto a partir do modelo** (`TemplateCloner`): clonagem completa com subprojetos recursivos; offsets de tudo relativos à data de início escolhida; anti-duplicação (dedup de tarefas por projeto e de subprojetos por pai, trava anti-ciclo); nunca toca em templates nativos, então o bug do core #21804 não se aplica
- [x] **Campos por item no modelo**, aplicados na clonagem:
  - Projeto raiz e subprojetos: início (d), duração (d), Estado, Tipo (`projecttypes_id`), Gestor, Orçamento (teto do plugin), Descrição e interruptor "calcular % automático"
  - Tarefas e subtarefas: início (d), duração (d), Estado, Tipo (`projecttasktypes_id`), Responsável (equipe da tarefa, `glpi_projecttaskteams`), Descrição e interruptor "calcular % automático"
  - Regra do percentual: só o interruptor automático/manual (`auto_percent_done`), com default ligado quando o item tem filhos
- Modelo em JSON: `{ project:{...}, tasks:[...], subprojects:[...] }` na tabela `glpi_plugin_projectplus_templates.structure`

## 🔶 Etapa 5 — Relatórios `em andamento`

- [x] **Bloco 1** — Tela "Relatórios" na sidebar (mesmo direito da Visão geral) com exportação CSV de três conjuntos de dados: Projetos (fase, tipo, gestor, % concluído, datas, orçamento), Tarefas (projeto, tarefa mãe, fase, tipo, responsáveis, datas, atraso, bloqueio) e Custos (mesma consolidação da tela Orçamento, linha a linha). Filtro por projeto raiz (+ descendentes), igual ao da tela Orçamento. CSV com `;` e BOM UTF-8 para abrir certo no Excel PT-BR.
- [x] **Bloco 1.1** — Filtros extras na tela Relatórios: Tarefa (busca por nome), Gestor/Responsável (mesmo campo para gestor de projeto e responsável de tarefa), Fase e Tipo (campo único com optgroup "Tipo de projeto"/"Tipo de tarefa", cada um só filtra sua tabela). Valem para Projetos e Tarefas; Custos continua só com o filtro de Projeto. Botão "Limpar". CSV de cada bloco reflete os filtros aplicados na tela.
- [x] **Bloco 1.2** — Filtro de Período (De/Até) na tela Relatórios, reaproveitando `Dashboard::periodCriteria` (mesma semântica da Visão geral: sobreposição de intervalo pelo planejado, itens sem data sempre entram). Vale para Projetos e Tarefas, não para Custos.
- [ ] Burndown por projeto ← RETOMAR DAQUI
- (Relatório de custos consolidado em tela já entregue no Bloco 5)

## 📍 Etapa 6 — Refinamento e pré-produção

- Teste de e-mail real via GLPIMailer
- Ciclo completo de desinstalação/reinstalação
- i18n: inglês + arquivos .po/.mo (PT-BR primeiro, conforme decisão de publicação)
- Release formal **v1.0.0-beta** no GitHub
- `plugin.xml` e submissão ao catálogo oficial do GLPI

## 📍 Etapa 7 — Kanban avançado

- Swimlanes (por projeto / responsável)
- Refinamentos de arrastar-e-soltar sobre o Kanban global

## 📍 Etapa 8 — Níveis de acesso

- Direitos granulares por **módulo/painel** do plugin: Visão geral, Minhas tarefas, Projetos, Tarefas, Kanban, Custos, Modelos, Relatórios, Alertas, Configuração
- **Permissão "ver todos os projetos"** (visão global × visão pessoal): hoje a Timeline mostra só as tarefas do usuário logado; a tela de Modelos é restrita a super-admin. Nesta etapa isso vira permissão configurável por perfil
- Quatro níveis por módulo (ver/interagir/criar/editar-excluir), matriz em Administração → Perfis
- Migração do direito único atual (`plugin_projectplus_dashboard`) para os novos direitos

> Nota: se fizer sentido restringir acesso já no lançamento público, esta etapa pode ser antecipada para antes da v1.0.0-beta (Etapa 6). Decisão em aberto.

---

## Decisões de publicação

- PT-BR primeiro; inglês entra na Etapa 6
- Releases formais só a partir da Etapa 6 (provável v1.0.0-beta)
- Catálogo oficial do GLPI após a beta

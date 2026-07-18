# ProjectPlus — Roadmap

**Plugin de gestão avançada de projetos para GLPI 11**
Repositório: [github.com/teckcomp/glpi-plugin-projectplus](https://github.com/teckcomp/glpi-plugin-projectplus) · Licença GPL-2.0
Versão atual: **v0.5.0-alpha** · Atualizado em 18/07/2026

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
- [x] Bloco 3: chip de fase colorido (cor do estado) na árvore de tarefas, tabelas de projetos/subprojetos e tarefas em andamento + donut "Projetos por fase" (respeita o filtro de período)

## 📍 Etapa 3 — Trabalho do dia a dia `em andamento`

- [x] **Bloco 1 (18/07/2026)** — Tela "Minhas tarefas": tarefas do usuário logado agrupadas por projeto, KPIs pessoais (abertas, atrasadas, sem datas, concluídas), hierarquia mãe/filha (aninhada quando a mãe também é do usuário; contexto "Mãe ›" quando não), edição inline com barra de prazo e toggle de concluídas
- [x] **Bloco 1 / fixes** — "Tarefas em andamento" do painel lista só tarefas-raiz com expansão recursiva de subtarefas (mesmo padrão de "Projetos em andamento"); regra "tarefa mãe só conclui com todas as filhas fechadas" (bloqueio na UI e no endpoint); `auto_percent_done` respeitado (campo % desabilitado, sem ✓, endpoint recusa)
- [x] **Bloco 2 (18/07/2026)** — Comentários por tarefa: tabela própria `taskcomments`, balão com contador e painel expansível na árvore de tarefas e em Minhas tarefas (Ctrl+Enter envia), aba nativa "Comentários (ProjectPlus)" na tarefa, edição/exclusão restrita ao autor (UI + servidor), alerta no sino para a equipe (dedup, reabre como não lido) + feed de atividades
- [x] **Bloco 2 / Fix 1** — Sino de alertas também na tela Minhas tarefas (mesma estrutura da Visão geral; endpoint já filtra por destinatário)
- [x] **Bloco 3 (18/07/2026)** — Dependências entre tarefas (bloqueia / bloqueada por): tabela nativa `glpi_projecttasklinks` (só finish_to_start), coluna 🔗 com contador e painel expansível na árvore de tarefas e em Minhas tarefas, cadeado 🔒 em tarefa bloqueada, aba nativa "Dependências (ProjectPlus)", regra no servidor (bloqueada não conclui com bloqueadora aberta), prevenção de ciclo e duplicata, vínculos só no mesmo projeto
- [x] **Bloco 3 / Fix 1** — Regra geral "filhos abertos bloqueiam o pai": subtarefas abertas bloqueiam a mãe (itens implícitos no painel/aba, sem remoção); projeto com filhos abertos mostra 🔒 e não pode ir para fase finalizada (`is_finished`) — hook `PRE_ITEM_UPDATE`, vale na ficha nativa
- [x] **Bloco 4 (18/07/2026) — Ajustes de layout/UX da Visão geral**: KPIs reorganizados (sai "Recursos alocados", entra "Tarefas em atraso"; ordem Projetos → Projetos em atraso → Tarefas → Tarefas em atraso → Progresso médio → Projetos concluídos); linha com 4 donuts (Projetos por Status, Projetos por fase, Tarefas por status e o novo Tarefas por Estado, por `glpi_projectstates`); "Projetos em andamento" e "Tarefas em andamento" em largura total com campo de busca (filhos e painéis seguem o pai no filtro); 🔗/💬/🔒 nas linhas de "Tarefas em andamento" (inclusive subtarefas expandidas); removidos "Progresso dos projetos", "Atividades recentes" e os itens de menu Projetos/Tarefas; "Custos" renomeado para "Orçamento" no menu
- [x] **Bloco 4 / Fix 1** — `front/dashboard.php` passa `task_state_chart` ao template (variável ausente virava `null` no JSON e a exceção no donut derrubava toda a inicialização do JS); donuts dinâmicos blindados contra payload não-array
- [ ] Timeline em HTML/JS puro, fluxo contínuo (tira o "em breve" da sidebar)

## 📍 Etapa 4 — Modelos de projeto

- Tela de modelos na sidebar
- Criar projeto a partir de modelo (`TemplateCloner` pronto; contorna bug do core #21804)

## 📍 Etapa 5 — Relatórios

- Exportação CSV das tabelas do painel
- Burndown por projeto
- (Relatório de custos consolidado já entregue no Bloco 5)

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
- Quatro níveis por módulo, no padrão do GLPI: **ver** (READ), **interagir** (UPDATE), **criar** (CREATE) e **editar/excluir** (PURGE)
- Matriz de permissões na aba do plugin em **Administração → Perfis** (cada perfil marca o que pode em cada módulo)
- Migração do direito único atual (`plugin_projectplus_dashboard`) para os novos direitos, preservando o acesso de quem já usa
- Painel passa a esconder/desabilitar o que o perfil não pode (sidebar, botões, edição inline)

> Nota: se fizer sentido restringir acesso já no lançamento público, esta etapa pode ser antecipada para antes da v1.0.0-beta (Etapa 6). Decisão em aberto.

---

## Decisões de publicação

- PT-BR primeiro; inglês entra na Etapa 6
- Releases formais só a partir da Etapa 6 (provável v1.0.0-beta)
- Catálogo oficial do GLPI após a beta

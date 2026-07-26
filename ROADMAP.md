# ProjectPlus — Roadmap

**Plugin de gestão avançada de projetos para GLPI 11**
Repositório: [github.com/teckcomp/glpi-plugin-projectplus](https://github.com/teckcomp/glpi-plugin-projectplus) · Licença GPL-2.0
Versão atual: **v0.5.0-alpha** · Atualizado em 26/07/2026 (Etapa 8 concluída; **Etapa 6 em andamento — Blocos 1, 2, 2b, 3a e 3b fechados; i18n COMPLETO (servidor + cliente)**. Próximo: Bloco 4, release v1.0.0-beta. **Etapa 9 planejada** — fases por tipo de projeto, depois da beta)

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

- [x] **Bloco 3a** — i18n do que é renderizado no servidor (PHP + Twig). **VALIDADO em homologação e FECHADO NO GITHUB em 25/07/2026** (zip `projectplus-etapa6-bloco3a-1.zip`).
  - **Decisão mantida:** o texto-fonte (msgid) permanece em PT-BR — nenhuma das 705 chamadas `__()`/`_n()` teve o texto alterado. Traduz-se PT-BR → inglês no `.po`.
  - **427 strings únicas** catalogadas (269 extraídas dos `.php`, 192 dos `.twig`, com sobreposição). `locales/en_GB.po` e `locales/pt_BR.po`, ambos 427/427 traduzidos e sem entradas *fuzzy*.
  - **`pt_BR.mo` de identidade (msgstr = msgid) é obrigatório**, não opcional: `Plugin::loadLang()` cai em `en_GB.mo` quando não encontra o `.mo` do idioma do usuário. Sem ele, todo usuário em Português (Brasil) passaria a ver a interface em inglês. Confirmado lendo o core 11.0.6.
  - **`tools/extract-twig-strings.php`** (novo): o `xgettext` não lê Twig e ignoraria 192 strings. O extrator gera um fragmento `.pot` que é unido ao dos PHP com `msgcat`. Escrito em PHP de propósito — servidor GLPI sempre tem PHP.
  - **`tools/update-locales.sh`** (novo): pipeline completo (xgettext → extrator Twig → msgcat → msgmerge → msgfmt), idempotente. Escreve um cabeçalho `.pot` limpo, porque o `msgcat` funde os dois cabeçalhos num só, marcado como *fuzzy* e cheio de `#-#-#-#-#`. Para o `pt_BR.po` roda `msgen` depois do `msgmerge`, de modo que string nova entre já como identidade.
  - **Três defeitos corrigidos no caminho:**
    - `Custo` e `Comentário` apareciam com `_n()` em uns lugares e `__()` em outros — o gettext recusa o mesmo msgid nas duas formas. Uniformizados para `_n(…, 1, …)` em 3 arquivos PHP e 3 templates (texto exibido não muda).
    - `__('Sim')` / `__('Não')` em `src/Config.php` (8 ocorrências) usavam o domínio **do core**, cujos msgid são em inglês — nunca traduziriam, em idioma nenhum. Trocados por `__('Yes')` / `__('No')`.
    - `Prazo: 50% consumido` (e 75/90) eram marcados como `php-format` pelo `xgettext` (o `% c` parece uma diretiva), o que deixava as entradas *fuzzy* e, portanto, **fora do `.mo`**. Reescritos como `Prazo consumido: 50%`.
  - Validado por harness de runtime que carrega os `.mo` compilados e confere o *lookup* (1706 asserções, 0 falhas), por conferência de cobertura (696/696 chamadas do código presentes no catálogo compilado) e por teste de idempotência do script.
- [x] **Bloco 3b** — i18n do JavaScript. **VALIDADO em homologação em 26/07/2026** (zip `projectplus-etapa6-bloco3b-1.zip`, 27 arquivos). Com ele a Etapa 6 fecha o i18n: servidor (3a) + cliente (3b).
  - **Premissa corrigida no levantamento:** não eram ~55 strings, eram **123** (95 só em `projectplus.js`). O catálogo passou de 427 para **511 strings únicas** — 84 novas; ~30 das strings do JS já existiam no catálogo do 3a e foram reaproveitadas.
  - **Como funciona:** o GLPI 11 não tem runtime de i18n no cliente, então a tradução acontece no **servidor**. `src/I18nJs.php` (novo) monta o dicionário do idioma do usuário com chamadas `__()`/`_n()` **literais** — é isso que faz o `xgettext` do pipeline do 3a enxergar as strings do JS **sem precisar de extrator para `.js`**. `I18nJs::render()` imprime `<script type="application/json" id="pp-i18n">` e `public/js/i18n.js` (novo) expõe `t()` / `tn()` / `tlist()`.
  - **A chave é o próprio texto em PT-BR** (mesma decisão de msgid do 3a). Consequência: dicionário ausente ⇒ o JS devolve a chave e a tela fica em português. Nunca em branco, nunca quebrada. Nos `.js` as funções chamam-se `__()` e `_n()`, como no PHP — `t()` colidiria com o parâmetro `t` dos vários `forEach` de tarefa.
  - **Injeção em 10 pontos:** os 9 `front/*.php` (logo após `Html::header`) e `src/KanbanTab.php`. A aba nativa entra por AJAX na ficha do projeto, que não passa por nenhum `front/` do plugin — sem a linha lá, o board da aba ficaria em PT-BR para um usuário em inglês. `render()` é idempotente por requisição.
  - **`public/js/i18n.js` é o primeiro do `add_javascript`** em `setup.php`: é ele que registra `window.ProjectPlusI18n`.
  - **`tools/check-js-strings.php`** (novo): confere os dois sentidos — chave usada no JS que falta no dicionário é **erro**; chave no dicionário que nenhum `.js` usa é **aviso**; tradução com aspas duplas nos `.po` é **erro** (o texto entra em atributo HTML montado por concatenação). Hoje: 123/123, zero avisos.
  - **Dois defeitos corrigidos no caminho:**
    - `hidenativecosts.js` / `hidenativekanban.js` escondiam a aba nativa comparando o **texto** da aba (`/^Custos\s*\d*$/`). O rótulo vem do **core** e muda com o idioma: em inglês a aba vira "Costs", o filtro deixava de casar e **a aba nativa reaparecia** — o plugin perdia o papel de fonte única de custos. Agora comparam contra uma lista de rótulos (PT + EN + o traduzido, quando o dicionário está na página). *Limitação conhecida:* num terceiro idioma a aba nativa de Custos continua coberta pelo filtro por `href*="ProjectCost"`, mas a de Kanban voltaria a aparecer.
    - `escapeHtml()` de `projectplus.js` era `textNode` + `innerHTML`, que **não escapa aspas** — e boa parte das chamadas alimenta atributos (`title=`, `placeholder=`). Uma tarefa chamada `Trocar "switch" do rack` partia o atributo ao meio. Trocado pela implementação por regex já usada em `timeline.js`.
  - **`Plural-Forms` mudou de `(n > 1)` para `(n != 1)`** nos dois catálogos e no gerador: com a regra antiga, zero usava o singular ("0 tarefa"). Só o cabeçalho mudou; as 511 traduções ficaram intactas.
  - **Fora de escopo, registrado:** o **formato de data** continua `dd/mm/aaaa` fixo no JS (`fmtBr`, `formatDate`, `formatDateTime`) e não segue a preferência de formato do GLPI. Vale como item da beta.
  - Validado por **968 asserções automatizadas**: sincronia dicionário↔JS (123 chaves), `msgfmt --check --check-format --check-header` (511/511, zero *fuzzy* nos dois idiomas), harness de runtime dos `.mo` (894), ponte PHP→JSON→JS (7) e teste em DOM headless com jsdom rodando os JS de verdade **contra o catálogo `en_GB` real** (67), incluindo o caso do nome com aspas.
- [ ] **Bloco 4** — Release `v1.0.0-beta`: CHANGELOG, tag e pacote de distribuição.
- [ ] **Bloco 5** — Submissão ao catálogo oficial do GLPI (depois da beta).

### Pendências pré-beta (baixo risco)

- `$_SERVER['PHP_SELF']` ainda é passado como 2º argumento de `Html::header()` em 9 arquivos de `front/`. Não quebra nada (afeta só o destaque de menu/breadcrumb), mas cabe padronizar na limpeza da beta.
- **Formato de data fixo no JavaScript** (`dd/mm/aaaa`): `fmtBr` em `timeline.js` e `formatDate`/`formatDateTime` em `projectplus.js` não seguem a preferência de formato do GLPI. Aparece na timeline, no sino de alertas e na coluna de fim das linhas de subprojeto. Descoberto no Bloco 3b e deixado fora dele de propósito (é formatação, não tradução).

### Decisões em aberto

- Board de projetos restrito a quem tem `projectkanban` marcado? Hoje aparece para todos que têm o Kanban de tarefas (ver 4b.1). Baixa prioridade.
- Tabela `glpi_plugin_projectplus_tasktimers` está órfã desde a Etapa 1 (o cronômetro por tarefa foi substituído pela barra de prazo). Continua sendo criada. **Decidir na Etapa 9**, que já mexe no schema: parar de criá-la (bases novas ficam com 6 tabelas + a nova de fases = 7) ou mantê-la.

---

## 🔜 Etapa 9 — Fases por tipo de projeto `PLANEJADA` (depois da v1.0.0-beta)

**O problema.** `glpi_projectstates` é uma lista **global e única** da instância — o schema do core 11.0.6 **não tem `entities_id`**, então nem separando por entidade dá para ter vocabulários de fase diferentes. Hoje o `Dashboard::getStatesMap()` lê a tabela inteira e alimenta Kanban de tarefas, Kanban de projetos, Timeline, os donuts da Visão geral e o filtro de Relatórios.

Com Infra, RH, Sistemas e Compras rodando projetos ao mesmo tempo (*Contratação*, *Desenvolvimento*, *Contratos*…), a lista chega a 25–30 fases: cada setor vê dezenas de colunas vazias no seu Kanban e o donut "Projetos por fase" mistura todos os vocabulários.

**A solução — uma tabela de mapeamento.**

`glpi_plugin_projectplus_typephases`

| campo | papel |
|---|---|
| `projecttypes_id` | o tipo de projeto (**0 = conjunto padrão**) |
| `projectstates_id` | a fase que pertence a esse tipo |
| `ordem` | posição da coluna |

Chave única em (`projecttypes_id`, `projectstates_id`). O `glpi_projectstates` **continua sendo a fonte única** da definição de cada fase (nome, cor, `is_finished`) — a tabela nova só diz *quais* fases pertencem a *qual* tipo e em que ordem.

**Regra de leitura:** tipo **sem nenhuma linha** cai no conjunto padrão (`projecttypes_id = 0`). Só se configura a exceção — Infra continua com as 5 fases de hoje sem ninguém tocar em nada.

**Escopo do trabalho**

- `Dashboard::getStatesMap()` passa a receber o tipo. É o **gargalo único** por onde as 6 telas passam — a mudança se propaga sozinha.
- **Seletor de tipo obrigatório** nos dois Kanbans, **sem modo "união"**: com 25 colunas o board deixa de ser legível. Visão que cruza departamentos é **Visão geral / Timeline / Relatórios**, que não dependem de vocabulário de coluna. A aba "Kanban (ProjectPlus)" da ficha nativa não precisa de seletor — já tem um projeto único em contexto.
- Tela de administração dos mapeamentos, com botão **"copiar fases de outro tipo"** (resolve o caso de dois tipos com o mesmo fluxo sem estrutura extra).
- Filtro por conjunto nos seletores de fase que **são do plugin**: modal "Novo Projeto" (`dashboard.html.twig`) e editor de Modelos (`Templates::getEditorRefData`).
- **Donut adaptativo** na Visão geral: sem tipo selecionado, vira "Projetos por tipo".
- **Verificação no diagnóstico da Configuração:** todo tipo configurado precisa de ao menos uma fase com `is_finished`, senão a trava "projeto com filhos abertos não vai para fase finalizada" nunca dispara — e falha em silêncio.
- **Migração:** insere as 5 fases atuais como `projecttypes_id = 0`. No dia seguinte ninguém vê diferença.

**Limite conhecido, sem solução pelo plugin:** o campo "Estado" da **ficha nativa** do projeto é do core e continua listando todas as fases da instância. Mitigação é convenção de nomenclatura por área (`INFRA · 1. Iniciação`, `RH · Triagem`, `DEV · Backlog`), que deixa o dropdown nativo legível e agrupado. Como a coluna `ordem` passa a ordenar de verdade, o prefixo numérico "1. ", "2. " deixa de ser obrigatório — vira só leitura humana.

**Não se sobrepõe à Etapa 8:** quem vê o quê já é resolvido por `Access`/`Scope` (equipe do projeto). O seletor de tipo é sobre **vocabulário**, não sobre permissão.


---

## Decisões de publicação

- PT-BR primeiro; inglês entra na Etapa 6 (Bloco 3a: catálogos `en_GB` e `pt_BR` em `locales/`)
- Releases formais só a partir da Etapa 6 (provável v1.0.0-beta)
- Catálogo oficial do GLPI após a beta

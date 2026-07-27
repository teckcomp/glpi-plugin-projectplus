# Changelog — ProjectPlus

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/).

## [1.1.0-beta] — 2026-07-26

Etapa 9: cada tipo de projeto passa a ter seu próprio conjunto de fases e sua
própria ordem de colunas. Nasceu de um problema concreto — `glpi_projectstates`
é uma lista global e única da instância, então com Infra, RH, Sistemas e Compras
rodando projetos ao mesmo tempo a lista chega a 25–30 fases e cada setor vê
dezenas de colunas vazias no seu Kanban.

### Adicionado
- **Fases por tipo de projeto**: nova tela em Configurações define quais fases
  cada tipo usa e em que ordem elas aparecem como colunas. Tipo sem configuração
  usa o **conjunto padrão** — só a exceção precisa ser configurada. Inclui
  "copiar fases de outro tipo", para dois tipos que seguem o mesmo fluxo.
- **Seletor de tipo nos dois Kanbans**: o board mostra as fases daquele tipo e os
  itens daquele tipo. A opção "Todos os tipos" continua disponível enquanto
  nenhum tipo tiver conjunto próprio.
- **Campo Tipo no modal "Novo projeto"**, com o campo Estado listando apenas as
  fases do conjunto escolhido. O mesmo vale para o editor de Modelos.
- **Donut adaptativo na Visão geral**: sem tipo selecionado ele mostra "Projetos
  por tipo" (misturar os vocabulários de todos os setores num gráfico de fases
  não diz nada); escolhendo um tipo, volta a ser "Projetos por fase". A Visão
  geral também ganhou filtro por tipo.
- **Diagnóstico**: aponta conjunto que não tem nenhuma fase marcada como
  finalizada — nele a trava "projeto com tarefa ou subprojeto aberto não vai para
  fase finalizada" nunca dispararia, e falharia em silêncio.

### Alterado
- A ordem das colunas passa a vir da configuração, e não mais da ordem
  alfabética. O prefixo numérico no nome da fase ("1. ", "2. ") deixa de ser
  necessário para ordenar — vira só leitura humana.
- A tabela `glpi_plugin_projectplus_tasktimers`, órfã desde a Etapa 1, **não é
  mais criada** em instalações novas. Bases existentes não são alteradas: a
  tabela continua sendo removida pela purga e passa a ser reportada como órfã no
  diagnóstico.
- Na atualização, as fases que a instância já usa são copiadas para o conjunto
  padrão. **No dia seguinte ninguém vê diferença.**

- **Filtro por tipo na Timeline**, com "Todos os tipos" sempre disponível. Serve
  para reduzir o volume de linhas do Gantt quando vários setores rodam projetos
  ao mesmo tempo. Ao filtrar, a indentação se reajusta: um subprojeto cujo pai
  saiu do filtro sobe de nível em vez de ficar indentado sob um pai invisível.

### Segurança
- **Removida a ação `data` de `ajax/dashboard_data.php`.** Ela devolvia o painel
  completo — projetos, tarefas, orçamento e responsáveis — sem aplicar a camada
  de escopo, e por ser também o `default` do `switch` respondia a qualquer nome
  de ação inválido. Verificado em execução: com um perfil de escopo pessoal, a
  tela mostrava 1 projeto e 4 tarefas enquanto o endpoint devolvia 3 e 12.
  Nenhum JavaScript do plugin usava essa ação. Os demais endpoints, que recebem
  um id, ainda dependem do guard de escopo descrito em `SECURITY.md`.
- Adicionado `SECURITY.md` e uma seção de **limitações conhecidas** no `README`:
  o escopo do plugin é aplicado nas telas, não nos endpoints, e o plugin é
  suportado apenas em instalação de **entidade única** nesta versão.

### Corrigido (auditoria de pré-publicação)
- **Charset e collation das tabelas eram fixos em `utf8mb4`.** Numa instalação
  atualizada de versões antigas do GLPI (`use_utf8mb4 = false`), o core usa
  `utf8_unicode_ci` e as tabelas do plugin nasciam incompatíveis, gerando erro
  1267 ("Illegal mix of collations") em comparações de texto entre tabela do
  plugin e do core. Passou a usar `DBConnection::getDefaultCharset()`,
  `getDefaultCollation()` e `getDefaultPrimaryKeySignOption()`.
- `Scope::managedProjects()` não restringia entidade nem excluía projetos-modelo.
- A Timeline carregava a tabela `glpi_projecttasks` inteira na memória do PHP e
  filtrava em laço; passou a restringir aos projetos já visíveis.

### Corrigido
- O formulário de período da Visão geral perdia o modo "Ver só os meus" ao
  aplicar o filtro.
- O donut "Tarefas por Estado" respeitava o filtro de tipo nas contagens, mas
  continuava listando as fases em ordem alfabética do vocabulário inteiro da
  instância. Passou a seguir a ordem configurada para o tipo.

## [1.0.0-beta] — 2026-07-26

Consolidação das etapas 7, 8 e 6. Primeira versão candidata a produção: interface
em dois idiomas, acesso configurável por perfil, Kanban próprio e ciclo de
instalação/desinstalação seguro.

### Adicionado
- **Níveis de acesso por perfil**: aba "ProjectPlus" no Perfil com matriz de 4 níveis (Ver / Interagir / Criar / Excluir) e 11 direitos próprios, um por módulo. Dois direitos de **escopo** ("ver projetos que gerencia" e "ver todos"): o padrão é o maior escopo do perfil, e o botão **"Ver só os meus"** reduz ao pessoal em todas as telas, sem memória de sessão. Papéis contemplados: Gestor, Cliente, Técnico e Colaborador.
- **Kanban de tarefas próprio**: colunas por fase, swimlanes por projeto ou responsável, subprojeto como raia, e substituição da aba nativa por "Kanban (ProjectPlus)" na ficha do projeto. Arrastar move **apenas a fase**, com trava por dependência.
- **Kanban de projetos**: board com projetos e subprojetos como cartões; arrastar muda a fase do projeto, com a mesma trava da ficha nativa — mas agora com mensagem explicando o motivo.
- **Escopo em Relatórios e Custos**: o filtro da tela é cruzado com o escopo por interseção, de modo que um filtro nunca amplia o que o usuário pode ver.
- **Interface em português e inglês**: 516 strings catalogadas em `locales/`, cobrindo PHP, templates Twig e JavaScript. Como o GLPI 11 não tem runtime de tradução no cliente, o dicionário do idioma do usuário é injetado pelo servidor.
- **Envio de e-mail funcionando de fato**, respeitando o canal de notificações do GLPI, com link para a ficha no corpo da mensagem, expansão de grupos na equipe da tarefa, registro de falhas em `files/_log/projectplus.log` e botão de e-mail de teste.
- **Diagnóstico da instalação** na tela de Configuração: as 7 tabelas (existência, registros, colunas e índices faltando), os 11 direitos, o estado de cada perfil administrador, o cron e as marcas de purga e importação.
- **Identidade visual própria**: logo no card de plugins e marca ao lado do título nas telas.

### Alterado
- **Datas seguem a preferência de formato do usuário no GLPI** em vez de `dd/mm/aaaa` fixo. Como o GLPI 11 oferece apenas `YYYY-MM-DD`, `DD-MM-YYYY` e `MM-DD-YYYY`, as datas passam a usar hífen. Quem vinha do formato brasileiro deve escolher **DD-MM-YYYY** em Preferências.
- Menu renomeado de "Painel de Projetos" para **"Gestor de Projetos"**.
- Acesso a **Modelos** deixou de depender do direito nativo `config` e passou a ter direito próprio, configurável por perfil.
- Reinstalar o plugin agora **reconcilia** o que já existe — colunas acrescentadas em versões posteriores e direitos de perfis administradores — em vez de apenas criar o que falta.

### Corrigido
- **O botão Salvar da tela de Configuração nunca funcionou** até aqui: a `action` do formulário usava `$_SERVER['PHP_SELF']`, que no front controller do GLPI 11 vale `/index.php`, e o POST caía no endpoint de inventário respondendo `XML not well formed!`.
- **E-mails não saíam**: o construtor do `GLPIMailer` define apenas `sender`, nunca `from`, e a mensagem era rejeitada em silêncio antes do envio.
- **Tarefa atribuída a um grupo não notificava ninguém**, porque a equipe da tarefa era filtrada só por usuário.
- **Desinstalar apagava os direitos de todos os perfis**, e a reinstalação recompunha apenas os padrões — perdendo em silêncio o ajuste fino por perfil.
- **Perfis administradores ficavam com acesso somente de leitura** aos módulos, sem que nenhuma reinstalação corrigisse.
- **Datas exibidas com um dia de atraso** em fusos a oeste de Greenwich (a coluna de fim das linhas de subprojeto), por interpretação UTC de data sem hora no JavaScript.
- **As abas nativas de Custos e Kanban reapareciam quando o usuário trocava de idioma**, porque eram escondidas comparando o texto do rótulo.
- **Nome com aspas quebrava atributos HTML** montados no cliente (`title`, `placeholder`).
- Contagem no plural exibia "0 tarefa" em vez de "0 tarefas".
- Fim das mensagens de depreciação no log a cada carregamento de página, causadas por `Plugin::getWebDir()`.

### Removido
- Itens de menu "Calendário" e "Recursos", que eram marcadores sem rota.

## [0.5.0-alpha] — 2026-07-17

Primeira versão pública. Consolidação das etapas 0–5 do desenvolvimento.

### Adicionado
- **Painel de Projetos** (Ferramentas): visão geral com 6 KPIs, filtro de período, donuts de status de projetos e tarefas, tabela de projetos em andamento com expansão de subprojetos, gráfico de progresso e feed de atividades.
- **Painel de tarefas por projeto**: árvore com edição inline de percentual, estado (sincronizado com o Kanban), datas, criação rápida com responsável e conclusão em um clique.
- **Modal "Novo projeto"** com estado, prioridade, gestor, datas e teto de orçamento.
- **Orçamento com teto híbrido**: teto opcional em qualquer nível; consolidação pai + descendentes; barras de consumo; alertas de limiar (configurável) e estouro.
- **Barra de contagem de prazo** (tarefas, projetos e "Tarefas em andamento"): verde (começo real) / azul (planejado), amarela 50%, laranja 75%, vermelha 90%, vermelho escuro no estouro; alertas aos gestores por limiar com reenvio a cada 8h no estouro; alerta de tarefa sem datas planejadas.
- **Custos com autor**: abas "Custos (ProjectPlus)" no projeto e na tarefa; custos de tarefa consomem o orçamento; migração automática dos custos nativos na instalação; ocultação (configurável) da aba Custos nativa.
- **Relatório de custos consolidados**: tela "Custos" na sidebar, por projeto, com origem, autor, totais, saldo do teto, filtro e impressão.
- **Sino de alertas** com contador de não lidos e histórico de lidas recentes; cron horário `projectplusalerts`; e-mail opcional.
- **Proteção de desinstalação**: dados mantidos por padrão (expurgo completo opcional em Configurações).

### Removido
- Cronômetro ▶/■ por tarefa (substituído pela barra de contagem de prazo).

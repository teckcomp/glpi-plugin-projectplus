# Changelog — ProjectPlus

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/).

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

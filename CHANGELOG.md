# Changelog — ProjectPlus

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/).

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

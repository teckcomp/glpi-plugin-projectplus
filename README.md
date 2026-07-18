# ProjectPlus — Plugin para GLPI

Camada extra de **gestão de projetos** para o GLPI 11: painel executivo com KPIs, orçamento com teto e alertas, barra de contagem de prazo com escalonamento de cores, custos por projeto e por tarefa com autor, sino de alertas e relatório de custos consolidados — tudo **sem alterar nenhuma tabela nativa** do GLPI.

> **Status:** `v0.5.0-alpha` — em desenvolvimento ativo, já em uso em ambiente de homologação. Feedbacks e issues são muito bem-vindos!

## ✨ Funcionalidades

### Painel de Projetos (Ferramentas → Painel de Projetos)
- **Visão geral** com 6 KPIs (projetos ativos, progresso médio, tarefas pendentes, recursos alocados, projetos atrasados, concluídos no mês) e filtro de período;
- Donuts de **Projetos por Status** e **Tarefas por status**;
- Tabela de **Projetos em andamento** com progresso, última atividade, situação, orçamento, prazo decorrido e prazo final — com expansão de subprojetos;
- **Painel de tarefas por projeto**: árvore de tarefas com edição inline de %, estado (sincronizado com o Kanban), datas, criação rápida de tarefa com responsável e conclusão em um clique;
- Gráfico de **Progresso dos projetos** e feed de **Atividades recentes**;
- Modal de **Novo projeto** (com estado, prioridade, gestor, datas e teto de orçamento).

### Barra de contagem de prazo
- Em **três lugares**: árvore de tarefas, linha do projeto e "Tarefas em andamento";
- Calcula quanto do período planejado já foi consumido (começo **real** quando preenchido — barra verde; senão o planejado — azul);
- Escalonamento de cores com **alertas automáticos aos gestores**: amarela aos 50%, laranja aos 75%, vermelha aos 90% e vermelho escuro no estouro — com reenvio a cada 8h até a conclusão;
- Tarefa sem datas planejadas: barra cinza + alerta pedindo correção do planejamento.

### Orçamento
- **Teto híbrido**: teto opcional em qualquer projeto; o gasto do pai consolida o de todos os descendentes (teto do pai = orçamento global da árvore; tetos nos filhos = sub-orçamentos);
- Barras de consumo no painel + alertas de orçamento (limiar configurável e estouro).

### Custos com autor (fonte única)
- Aba **"Custos (ProjectPlus)"** no projeto **e** na tarefa — todo lançamento registra quem lançou;
- Custos de tarefas consomem o orçamento do projeto automaticamente;
- Migração automática dos lançamentos da aba Custos nativa na instalação (os registros nativos ficam intactos no banco);
- A aba Custos nativa é ocultada por padrão (configurável) — desativando o plugin ou a opção, ela volta;
- Tela **Custos** na sidebar: relatório de custos consolidados por projeto (origem, autor, totais, saldo do teto), com filtro e impressão.

### Alertas
- **Sino** no painel com contador de não lidos e histórico de lidas recentes;
- Cron horário: tarefas atrasadas, prazos se aproximando, projetos parados, limiares de prazo e de orçamento;
- E-mail opcional (mailer nativo do GLPI) além do sino.

## 📋 Requisitos

- GLPI **11.0.x**
- PHP **8.2+**

## 🚀 Instalação

```bash
cd /var/www/html/glpi/plugins
git clone https://github.com/teckcomp/glpi-plugin-projectplus.git projectplus
chown -R www-data:www-data projectplus
```

Depois, no GLPI: **Configurar → Plugins → ProjectPlus → Instalar → Ativar**.

> A pasta do plugin **precisa** se chamar `projectplus`.

Na instalação, o plugin:
- cria apenas tabelas próprias (prefixo `glpi_plugin_projectplus_`) — nenhuma tabela nativa é alterada;
- migra automaticamente os custos já existentes na aba Custos nativa para a aba do plugin (uma única vez);
- registra o direito `plugin_projectplus_dashboard` (READ para perfis com leitura de projetos) e o cron `projectplusalerts` (horário).

## ⚙️ Configuração

**Configurar → Plugins → ProjectPlus** (ou Configurações na sidebar do painel):

| Opção | Padrão | Descrição |
|---|---|---|
| Dias sem atividade para "parado" | 7 | Marca o projeto como parado e alerta o gestor |
| Antecedência de alerta de prazo | 2 dias | Aviso de "vence em breve" para a equipe da tarefa |
| Enviar e-mails | Sim | Além do sino de alertas |
| Painel primeiro no menu | Sim | Reordena o menu Ferramentas |
| Alerta de orçamento | 80% | % do teto que dispara o aviso |
| Ocultar aba Custos nativa | Sim | Fonte única de custos nas abas do ProjectPlus |
| Apagar dados ao desinstalar | Não | Por padrão os dados sobrevivem à desinstalação |

## 🗺️ Roadmap

- [ ] "Minhas tarefas" na sidebar
- [ ] Comentários por tarefa
- [ ] Dependências entre tarefas
- [ ] Timeline (fluxo contínuo)
- [ ] Modelos de projeto (criação via modelo, contornando o bug core #21804)
- [ ] Relatórios (CSV, impressão, burndown)
- [ ] Kanban avançado com swimlanes (colunas = fase, raias = categoria)
- [ ] Internacionalização (inglês + `.po/.mo`)

## 🏗️ Arquitetura

O ProjectPlus é uma **camada sobre o GLPI nativo**: projetos e tarefas continuam sendo os nativos (`glpi_projects`, `glpi_projecttasks`), editáveis pelo Kanban e telas padrão. O plugin adiciona tabelas próprias para indicadores, alertas, custos e modelos — e some sem deixar rastro se desativado.

## 📄 Licença

[GPL-2.0](LICENSE) — © [Teckcomp I.T. Services](https://www.teckcomp.com.br)

## 🔗 Veja também

- [QR Service](https://github.com/teckcomp/glpi-plugin-qrservice) — abertura anônima de chamados via QR Code

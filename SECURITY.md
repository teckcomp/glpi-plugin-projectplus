# Política de segurança — ProjectPlus

## Versões suportadas

| Versão | Suportada |
|---|---|
| 1.1.x-beta | ✅ |
| < 1.1 | ❌ |

## Limitação conhecida (v1.1.0-beta)

O escopo de visibilidade (`src/Scope.php`) é aplicado nas telas do plugin,
mas **não** nos endpoints AJAX (`ajax/`) nem nos manipuladores de formulário
(`front/*.form.php`). Um usuário autenticado com o direito
`plugin_projectplus_dashboard` (READ) pode chamar um endpoint com um id
arbitrário e ler subprojetos, tarefas, comentários e dependências fora do
escopo dele. Em instalação multi-entidade, três desses pontos (`tasks`,
`taskcomments`, `taskdeps`) também não verificam `entities_id`, e a leitura
atravessa a fronteira de entidade.

A ação `action=data` do `ajax/dashboard_data.php`, que devolvia o painel
completo sem escopo algum — e respondia também a qualquer nome de ação
inválido, por ser o `default` — foi REMOVIDA na v1.1.0-beta. Era código
morto: nenhum JavaScript do plugin a chamava.

Isto está documentado de propósito, não é uma vulnerabilidade a ser
reportada em sigilo — está no README, no ROADMAP e é o próximo item de
trabalho. Até lá:

- use em instalação de **entidade única**;
- conceda `plugin_projectplus_dashboard` apenas a quem já poderia ver todos
  os projetos da instância.

## Como reportar uma falha

Para qualquer OUTRO problema de segurança, **não abra issue pública**.
Escreva para o mantenedor pelo e-mail do perfil da organização no GitHub
(<https://github.com/teckcomp>) com:

- versão do plugin, do GLPI e do PHP;
- passos para reproduzir;
- impacto observado.

O compromisso é responder em até 5 dias úteis e publicar a correção junto
com o crédito de quem reportou, se assim desejar.

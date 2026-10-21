<?php

namespace GlpiPlugin\Projectplus;

use Glpi\DBAL\QuerySubQuery;

/**
 * Bloco M-1 (21/10/2026) — respeitar a lixeira nativa do GLPI.
 *
 * Até aqui NENHUMA consulta de tarefa do plugin filtrava
 * glpi_projecttasks.is_deleted (coluna nova do GLPI 11): tarefa mandada para a
 * lixeira pela ficha nativa continuava no painel, Kanban, Minhas tarefas,
 * Timeline, Relatórios, burndown, custos, dependências, alertas e na captura
 * de Modelo.
 *
 * "Tarefa viva" = fora da lixeira E o projeto dela fora da lixeira. A segunda
 * metade é o que permite mandar um PROJETO para a lixeira (M-2) sem tocar nas
 * tarefas: elas somem de todas as telas e voltam juntas quando o projeto é
 * restaurado pela lixeira nativa.
 *
 * Uso: `array_merge($where, Trash::liveTasks())`. As chaves são sempre
 * qualificadas com a tabela (lição 16, consultas com JOIN) e a subconsulta vai
 * aninhada sob chave numérica, para não colidir com um filtro de
 * `projects_id` que a consulta já tenha (lição 35).
 */
class Trash
{
    public static function liveTasks(string $table = 'glpi_projecttasks'): array
    {
        return [
            $table . '.is_deleted' => 0,
            [
                $table . '.projects_id' => new QuerySubQuery([
                    'SELECT' => 'id',
                    'FROM'   => 'glpi_projects',
                    'WHERE'  => ['is_deleted' => 0],
                ]),
            ],
        ];
    }
}

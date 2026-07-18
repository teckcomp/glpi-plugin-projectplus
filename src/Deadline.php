<?php

namespace GlpiPlugin\Projectplus;

/**
 * Barra de contagem de prazo (Bloco 4-revisado).
 *
 * Calcula quanto do período planejado já foi consumido, para tarefas
 * e projetos. Janela de cálculo:
 *   - começo REAL quando preenchido (barra verde), senão o planejado (azul);
 *   - fim é sempre o planejado.
 *
 * Régua de cores / limiares de alerta:
 *   < 50%  -> green (começo real) | blue (planejado)
 *   >= 50% -> yellow   (alerta aos gestores)
 *   >= 75% -> orange   (alerta)
 *   >= 90% -> red      (alerta)
 *   >= 100%-> dark     (alerta com reenvio a cada 8h até concluir)
 *
 * Sem datas suficientes -> state 'none' (barra cinza + alerta de correção).
 * Item concluído        -> state 'done' (sem barra, sem alertas).
 *
 * O percentual NÃO é limitado a 100 em 'percent' (o excedente segue
 * contando para os relatórios da Etapa 5); 'display' é o valor 0-100
 * usado na largura da barra.
 */
class Deadline
{
    /**
     * @param ?string $planStart  data planejada de começo (Y-m-d H:i:s)
     * @param ?string $realStart  data real de começo (prioridade, barra verde)
     * @param ?string $planEnd    data planejada de fim
     * @param int     $percentDone percent_done do item (>=100 => 'done')
     *
     * @return array{state: string, percent: int, display: int, label: ?string, real: bool}
     */
    public static function compute(
        ?string $planStart,
        ?string $realStart,
        ?string $planEnd,
        int $percentDone = 0
    ): array {
        $isReal   = !empty($realStart);
        $startStr = $isReal ? $realStart : $planStart;

        if ($percentDone >= 100) {
            return ['state' => 'done', 'percent' => 0, 'display' => 0, 'label' => null, 'real' => $isReal];
        }

        if (empty($startStr) || empty($planEnd)) {
            return ['state' => 'none', 'percent' => 0, 'display' => 0, 'label' => null, 'real' => $isReal];
        }

        $start = strtotime($startStr);
        $end   = strtotime($planEnd);
        $now   = time();

        if ($end <= $start) {
            // Janela degenerada (fim <= começo): estourada se o fim já passou
            $pct = ($now >= $end) ? 101 : 0;
        } else {
            $pct = (int) floor((($now - $start) / ($end - $start)) * 100);
        }
        $pct     = max(0, $pct);
        $display = min(100, $pct);

        if ($pct >= 100) {
            $state = 'dark';
        } elseif ($pct >= 90) {
            $state = 'red';
        } elseif ($pct >= 75) {
            $state = 'orange';
        } elseif ($pct >= 50) {
            $state = 'yellow';
        } else {
            $state = $isReal ? 'green' : 'blue';
        }

        $label = $display . '%';
        if ($pct >= 100 && $now > $end) {
            $label = '+' . self::formatOverdue($now - $end);
        }

        return [
            'state'   => $state,
            'percent' => $pct,
            'display' => $display,
            'label'   => $label,
            'real'    => $isReal,
        ];
    }

    /**
     * Tempo excedente humano: "3d 6h", "5h", "<1h".
     */
    public static function formatOverdue(int $seconds): string
    {
        $days  = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);

        if ($days > 0) {
            return $days . 'd' . ($hours > 0 ? ' ' . $hours . 'h' : '');
        }
        if ($hours > 0) {
            return $hours . 'h';
        }
        return '<1h';
    }
}

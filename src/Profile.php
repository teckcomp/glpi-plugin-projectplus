<?php

namespace GlpiPlugin\Projectplus;

use CommonDBTM;
use CommonGLPI;
use Html;
use Profile as GlpiProfile;
use Session;

/**
 * ProjectPlus — aba "ProjectPlus" na tela de Perfil (Administração → Perfis).
 * Etapa 8, Bloco 1.
 *
 * Desenha a matriz de checkboxes com os direitos granulares do plugin
 * (um por módulo + os dois direitos de escopo), reaproveitando o
 * componente NATIVO do GLPI (Profile::displayRightsChoiceMatrix). O
 * salvamento vai pelo FORMULÁRIO NATIVO do Perfil (post para
 * Profile::getFormURL() com name="update"): como os direitos já existem em
 * glpi_profilerights (criados no Install), o core os reconhece e grava.
 *
 * Não tem tabela própria: os valores moram na tabela nativa
 * glpi_profilerights, um registro por direito/perfil.
 *
 * IMPORTANTE (escopo do Bloco 1): esta aba só EXIBE e GRAVA a matriz. O
 * gate das telas por esses direitos é o Bloco 2 — aqui nenhum
 * comportamento de tela do plugin muda.
 */
class Profile extends CommonDBTM
{
    /** Só quem pode editar perfis vê/salva esta aba. */
    public static $rightname = 'profile';

    public static function getTypeName($nb = 0)
    {
        return __('ProjectPlus', 'projectplus');
    }

    /**
     * Lista canônica dos direitos do plugin exibidos na matriz.
     * Colunas por módulo: Ver / Interagir / Criar / Excluir (só as que
     * fazem sentido para cada linha). Os dois escopos são liga/desliga.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getAllRights(): array
    {
        $crud = [
            READ   => __('Ver', 'projectplus'),
            UPDATE => __('Interagir', 'projectplus'),
            CREATE => __('Criar', 'projectplus'),
            DELETE => __('Excluir', 'projectplus'),
        ];

        return [
            [
                'label'  => __('Painel (Visão geral)', 'projectplus'),
                'field'  => 'plugin_projectplus_dashboard',
                'rights' => [READ => __('Ver', 'projectplus')],
            ],
            [
                'label'  => __('Escopo: ver projetos que gerencia', 'projectplus'),
                'field'  => 'plugin_projectplus_seemanaged',
                'rights' => [READ => __('Ver', 'projectplus')],
            ],
            [
                'label'  => __('Escopo: ver todos os projetos', 'projectplus'),
                'field'  => 'plugin_projectplus_seeall',
                'rights' => [READ => __('Ver', 'projectplus')],
            ],
            [
                'label'  => __('Projetos', 'projectplus'),
                'field'  => 'plugin_projectplus_projects',
                'rights' => $crud,
            ],
            [
                'label'  => __('Tarefas', 'projectplus'),
                'field'  => 'plugin_projectplus_tasks',
                'rights' => $crud,
            ],
            [
                'label'  => __('Kanban (tarefas)', 'projectplus'),
                'field'  => 'plugin_projectplus_kanban',
                'rights' => [
                    READ   => __('Ver', 'projectplus'),
                    UPDATE => __('Interagir', 'projectplus'),
                ],
            ],
            [
                'label'  => __('Kanban de projetos (Cliente)', 'projectplus'),
                'field'  => 'plugin_projectplus_projectkanban',
                'rights' => [READ => __('Ver', 'projectplus')],
            ],
            [
                'label'  => __('Custos / Orçamento', 'projectplus'),
                'field'  => 'plugin_projectplus_costs',
                'rights' => [
                    READ   => __('Ver', 'projectplus'),
                    UPDATE => __('Interagir', 'projectplus'),
                ],
            ],
            [
                'label'  => __('Relatórios', 'projectplus'),
                'field'  => 'plugin_projectplus_reports',
                'rights' => [READ => __('Ver', 'projectplus')],
            ],
            [
                'label'  => __('Modelos', 'projectplus'),
                'field'  => 'plugin_projectplus_templates',
                'rights' => $crud,
            ],
            [
                'label'  => __('Alertas (sino)', 'projectplus'),
                'field'  => 'plugin_projectplus_alerts',
                'rights' => [READ => __('Ver', 'projectplus')],
            ],
        ];
    }

    /**
     * Valor MÁXIMO de cada direito, derivado da própria matriz
     * (Etapa 6, Bloco 4b).
     *
     * É o OR dos bits que a linha oferece: um módulo com
     * Ver/Interagir/Criar/Excluir vale 15; um liga/desliga vale 1
     * (READ). Derivar de `getAllRights()` evita ter uma segunda lista
     * de máximos para sair de sincronia com a matriz — quem acrescentar
     * uma coluna nova na matriz já a ganha aqui de graça.
     *
     * @return array<string,int> nome do direito => bits máximos
     */
    public static function getMaxRights(): array
    {
        $max = [];
        foreach (self::getAllRights() as $row) {
            $bits = 0;
            foreach (array_keys($row['rights']) as $bit) {
                $bits |= (int) $bit;
            }
            $max[(string) $row['field']] = $bits;
        }
        return $max;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof GlpiProfile && (int) $item->getID() > 0) {
            return self::createTabEntry(self::getTypeName());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof GlpiProfile) {
            $self = new self();
            $self->showRightsMatrix((int) $item->getID());
        }
        return true;
    }

    /**
     * Renderiza a matriz de direitos do plugin para um perfil, dentro do
     * formulário nativo do Perfil (o próprio core grava ao salvar).
     *
     * NÃO chamar de "showForm": CommonDBTM já declara
     * showForm($ID, array $options = []) e uma assinatura diferente
     * gera Compile Error ao carregar a classe.
     */
    public function showRightsMatrix(int $profiles_id): void
    {
        $profile = new GlpiProfile();
        $profile->getFromDB($profiles_id);

        $canedit = Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]);

        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . $profile->getFormURL() . "'>";
        }

        $profile->displayRightsChoiceMatrix(self::getAllRights(), [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => __('ProjectPlus — acessos por módulo', 'projectplus'),
        ]);

        if ($canedit) {
            echo "<div class='center'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo "</div>";
            Html::closeForm();
        }
        echo "</div>";
    }
}

<?php

namespace GlpiPlugin\Projectplus;

use Html;
use Session;

/**
 * Bloco L (21/10/2026) — filtros favoritos por usuário e por tela.
 *
 * Decisão do Claudio: o favorito "vira o padrão" — ao entrar numa tela SEM
 * nada na URL, ela abre com os filtros favoritados. Qualquer parâmetro na URL
 * (filtro escolhido na hora, link de foco ?project=, "Ver tudo") vence.
 *
 * Dois tipos de filtro:
 *  - `qs`: os que viajam na URL e o servidor lê ($_GET) — aplicados por
 *    redirect antes do header (sem "piscar" a tela sem filtro);
 *  - `ui`: os que vivem só no navegador (Projeto/Responsável e "concluídas"
 *    dos Kanbans, Tipo/Projeto/concluídas de Minhas tarefas) — aplicados no
 *    HTML inicial pelo Twig, que o JS de cada tela já lê ao iniciar.
 *
 * Guardado no servidor (vale em qualquer computador), tabela
 * glpi_plugin_projectplus_userprefs, uma linha por (usuário, tela). Sem a
 * tabela (plugin ainda não reinstalado) tudo vira no-op e o botão some.
 */
class Favorite
{
    public const TABLE = 'glpi_plugin_projectplus_userprefs';

    /** Tela => chaves aceitas (lista branca). */
    public const SCREENS = [
        'dashboard'     => ['qs' => ['from', 'until', 'type', 'scope'], 'ui' => []],
        'mytasks'       => ['qs' => [], 'ui' => ['type', 'project', 'done']],
        'kanban'        => ['qs' => ['type', 'scope'], 'ui' => ['lane', 'done']],
        'projectkanban' => ['qs' => ['type', 'scope'], 'ui' => ['done']],
        'timeline'      => ['qs' => ['type', 'scope'], 'ui' => []],
        'reports'       => ['qs' => ['from', 'until', 'project', 'state', 'task', 'typefilter', 'user', 'scope'], 'ui' => []],
        'costs'         => ['qs' => ['project', 'scope'], 'ui' => []],
    ];

    public static function available(): bool
    {
        /** @var \DBmysql $DB */
        global $DB;
        static $ok = null;
        if ($ok === null) {
            $ok = $DB->tableExists(self::TABLE);
        }
        // O-1e (26/10/2026): Visão do cliente não favorita filtros — o botão
        // some nas telas e um favorito antigo deixa de ser reaplicado.
        return $ok && !Access::isClient();
    }

    /**
     * Lista branca + valores curtos e simples. Valor vazio sai (é o mesmo
     * que "sem filtro"). Ordena as chaves: a comparação ★/☆ é por igualdade.
     *
     * @return array{qs: array<string,string>, ui: array<string,string>}
     */
    public static function normalize(string $screen, array $qs, array $ui): array
    {
        $def = self::SCREENS[$screen] ?? ['qs' => [], 'ui' => []];
        $clean = static function (array $in, array $keys): array {
            $out = [];
            foreach ($keys as $k) {
                if (!array_key_exists($k, $in) || !is_scalar($in[$k])) {
                    continue;
                }
                $v = trim((string) $in[$k]);
                if ($v === '' || mb_strlen($v) > 64 || !preg_match('/^[\w\-.:]*$/u', $v)) {
                    continue;
                }
                $out[$k] = $v;
            }
            ksort($out);
            return $out;
        };
        return ['qs' => $clean($qs, $def['qs']), 'ui' => $clean($ui, $def['ui'])];
    }

    /** @return array{qs: array, ui: array}|null */
    public static function get(string $screen, ?int $userId = null): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $userId = $userId ?? (int) Session::getLoginUserID();
        if (!isset(self::SCREENS[$screen]) || $userId <= 0 || !self::available()) {
            return null;
        }
        foreach (
            $DB->request([
                'SELECT' => ['filters'],
                'FROM'   => self::TABLE,
                'WHERE'  => ['users_id' => $userId, 'screen' => $screen],
                'LIMIT'  => 1,
            ]) as $row
        ) {
            $data = json_decode((string) ($row['filters'] ?? ''), true);
            if (!is_array($data)) {
                return null;
            }
            $n = self::normalize($screen, (array) ($data['qs'] ?? []), (array) ($data['ui'] ?? []));
            return ($n['qs'] === [] && $n['ui'] === []) ? null : $n;
        }
        return null;
    }

    /** Grava (substitui) o favorito da tela. Devolve o que ficou gravado. */
    public static function save(string $screen, array $qs, array $ui, ?int $userId = null): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $userId = $userId ?? (int) Session::getLoginUserID();
        if (!isset(self::SCREENS[$screen]) || $userId <= 0 || !self::available()) {
            return null;
        }
        $n = self::normalize($screen, $qs, $ui);
        if ($n['qs'] === [] && $n['ui'] === []) {
            self::clear($screen, $userId);
            return null;
        }
        $json = json_encode($n);
        $now  = date('Y-m-d H:i:s');
        $where = ['users_id' => $userId, 'screen' => $screen];
        $exists = false;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => self::TABLE, 'WHERE' => $where, 'LIMIT' => 1]) as $r) {
            $exists = true;
        }
        if ($exists) {
            $DB->update(self::TABLE, ['filters' => $json, 'date_mod' => $now], $where);
        } else {
            $DB->insert(self::TABLE, $where + ['filters' => $json, 'date_creation' => $now, 'date_mod' => $now]);
        }
        return $n;
    }

    public static function clear(string $screen, ?int $userId = null): bool
    {
        /** @var \DBmysql $DB */
        global $DB;
        $userId = $userId ?? (int) Session::getLoginUserID();
        if (!isset(self::SCREENS[$screen]) || $userId <= 0 || !self::available()) {
            return false;
        }
        $DB->delete(self::TABLE, ['users_id' => $userId, 'screen' => $screen]);
        return true;
    }

    /**
     * Chamado no topo do front/ da tela, ANTES do Html::header: entrando sem
     * nada na URL e com favorito de URL, redireciona para a tela filtrada.
     */
    public static function applyRedirect(string $screen, string $path): void
    {
        if (!empty($_GET)) {
            return;
        }
        $fav = self::get($screen);
        if ($fav === null || $fav['qs'] === []) {
            return;
        }
        Html::redirect(Url::to($path) . '?' . http_build_query($fav['qs']));
    }

    /** Valor de um filtro `ui` favoritado (ou null). */
    public static function ui(string $screen, string $key): ?string
    {
        $fav = self::get($screen);
        return $fav['ui'][$key] ?? null;
    }

    /**
     * "Ver tudo"/"Limpar" levam à tela SEM filtro de propósito: marcam
     * `scope=all` (Scope::mode trata qualquer valor diferente de "mine" como
     * o maior escopo) para a URL não ficar vazia e o favorito não ser
     * reaplicado por cima da escolha do usuário.
     */
    public static function markAll(array $query): array
    {
        if (!isset($query['scope'])) {
            $query['scope'] = 'all';
        }
        return $query;
    }

    /** Dados do botão ☆/★ para o Twig (partial _favorite.html.twig). */
    public static function payload(string $screen): array
    {
        $saved   = self::get($screen);
        $current = self::normalize($screen, $_GET, []);
        return [
            'available' => self::available() && isset(self::SCREENS[$screen]),
            'screen'    => $screen,
            'url'       => Url::to('ajax/favorite.php'),
            'csrf'      => Session::getNewCSRFToken(),
            'saved'     => $saved,
            'current'   => $current['qs'],
        ];
    }
}

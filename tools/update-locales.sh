#!/usr/bin/env bash
#
# ProjectPlus — regenera os catálogos de tradução (locales/).
#
# O QUE ELE FAZ, NESTA ORDEM:
#   1. extrai as strings dos .php  (xgettext)
#   2. extrai as strings dos .twig (tools/extract-twig-strings.php — o xgettext
#      NÃO sabe ler Twig, e quase metade do texto do plugin está lá)
#   3. une os dois num único locales/projectplus.pot
#   4. atualiza os .po existentes contra o .pot novo (msgmerge)
#   5. compila cada .po em .mo (msgfmt)
#
# IMPORTANTE — pt_BR.mo é OBRIGATÓRIO mesmo sendo idêntico ao msgid:
# Plugin::loadLang() cai em en_GB.mo quando não encontra o .mo do idioma do
# usuário. Sem locales/pt_BR.mo, todo usuário em Português (Brasil) veria a
# interface em INGLÊS. O pt_BR.po é de identidade (msgstr = msgid).
#
# Requisitos: gettext (xgettext, msgcat, msgmerge, msgfmt) e php-cli.
#   Debian/Ubuntu:  apt install gettext php-cli
#
# Uso (a partir de qualquer lugar):
#   bash tools/update-locales.sh
#
# @license GPL-2.0-or-later

set -euo pipefail

DOMAIN="projectplus"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOCALES="${ROOT}/locales"
TMP="$(mktemp -d)"
trap 'rm -rf "${TMP}"' EXIT

cd "${ROOT}"

for bin in xgettext msgcat msgmerge msgfmt php; do
    command -v "${bin}" >/dev/null 2>&1 || {
        echo "ERRO: '${bin}' nao encontrado. Instale: apt install gettext php-cli" >&2
        exit 1
    }
done

mkdir -p "${LOCALES}"

VERSION="$(grep -oE "PLUGIN_PROJECTPLUS_VERSION', *'[^']+'" setup.php | grep -oE "'[^']+'$" | tr -d "'" || echo "0.0.0")"

echo "== 1/5  strings dos PHP (xgettext) =="
find src front ajax setup.php hook.php -name '*.php' | sort > "${TMP}/php-files.txt"
xgettext \
    --from-code=UTF-8 \
    --language=PHP \
    --keyword=__:1,2t \
    --keyword=_n:1,2,4t \
    --keyword=_x:1c,2,3t \
    --keyword=_nx:1c,2,3,5t \
    --package-name="ProjectPlus" \
    --package-version="${VERSION}" \
    --msgid-bugs-address="https://github.com/teckcomp/glpi-plugin-projectplus/issues" \
    --add-comments=TRANSLATORS \
    --sort-output \
    -o "${TMP}/php.pot" \
    -f "${TMP}/php-files.txt"

echo "== 2/5  strings dos templates Twig =="
php tools/extract-twig-strings.php templates "${TMP}/twig.pot" "${DOMAIN}"

echo "== 3/5  uniao -> locales/${DOMAIN}.pot =="
msgcat --sort-output -o "${TMP}/merged.pot" "${TMP}/php.pot" "${TMP}/twig.pot"

# O msgcat junta os DOIS cabecalhos num so, marcado como "fuzzy" e cheio de
# "#-#-#-#-#". Descartamos esse primeiro bloco e escrevemos um cabecalho limpo.
{
    cat <<POTHEADER
# ProjectPlus — catalogo de strings traduziveis (modelo).
# Copyright (C) Teckcomp I.T. Services
# Distribuido sob a mesma licenca do plugin ProjectPlus (GPL-2.0-or-later).
#
# ATENCAO: o msgid esta em PORTUGUES DO BRASIL de proposito. As traducoes
# levam de pt_BR para o outro idioma. Ver ROADMAP, Etapa 6 / Bloco 3.
#
# Gerado por tools/update-locales.sh — NAO EDITAR A MAO.
#
msgid ""
msgstr ""
"Project-Id-Version: ProjectPlus ${VERSION}\\n"
"Report-Msgid-Bugs-To: https://github.com/teckcomp/glpi-plugin-projectplus/issues\\n"
"POT-Creation-Date: $(date -u '+%Y-%m-%d %H:%M+0000')\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Plural-Forms: nplurals=2; plural=(n > 1);\\n"
POTHEADER
    # tudo depois da primeira linha em branco = as entradas, sem o cabecalho
    awk 'BEGIN { skip = 1 } skip && /^$/ { skip = 0; next } !skip { print }' "${TMP}/merged.pot"
} > "${LOCALES}/${DOMAIN}.pot"

echo "   $(($(grep -c '^msgid ' "${LOCALES}/${DOMAIN}.pot") - 1)) string(s) traduziveis"

echo "== 4/5  atualizando os .po =="
shopt -s nullglob
for po in "${LOCALES}"/*.po; do
    name="$(basename "${po}")"
    msgmerge --quiet --update --backup=none --no-wrap "${po}" "${LOCALES}/${DOMAIN}.pot"

    # pt_BR e um catalogo de IDENTIDADE (msgstr = msgid). Strings novas entram
    # vazias no msgmerge; o msgen preenche so as vazias, sem tocar no resto.
    if [ "${name}" = "pt_BR.po" ]; then
        msgen --no-wrap -o "${TMP}/pt_BR.filled.po" "${po}"
        mv "${TMP}/pt_BR.filled.po" "${po}"
    fi

    echo "   ${name}: $(msgfmt --statistics -o /dev/null "${po}" 2>&1 | head -1)"
done

echo "== 5/5  compilando os .mo =="
for po in "${LOCALES}"/*.po; do
    mo="${po%.po}.mo"
    msgfmt --check-format -o "${mo}" "${po}"
    echo "   $(basename "${mo}")"
done

if [ ! -f "${LOCALES}/pt_BR.mo" ]; then
    echo "AVISO: locales/pt_BR.mo NAO existe — usuarios em Portugues (Brasil) verao" >&2
    echo "       a interface em ingles por causa do fallback do Plugin::loadLang()." >&2
fi

echo "pronto."

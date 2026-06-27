#!/usr/bin/env bash
# Lance Google Chrome Canary avec le port CDP 9222 pour le runner PK X.
#
# Pourquoi Canary : c'est un navigateur SÉPARÉ de ton Chrome principal
# (binaire, profil et icône différents). Chrome interdit le pilotage CDP sur
# le profil par défaut de Chrome stable, mais Canary a son propre profil par
# défaut → CDP y est autorisé sans --user-data-dir.
#
# Par défaut la fenêtre est poussée hors-champ (pas de focus steal, pas de
# capture de curseur). Pour le 1er lancement (login X unique), passe PK_VISIBLE=1.
set -euo pipefail

PORT="${PK_CDP_PORT:-9222}"
CANARY="/Applications/Google Chrome Canary.app/Contents/MacOS/Google Chrome Canary"
PROFILE="${PK_CANARY_PROFILE:-$HOME/Library/Application Support/Google/Chrome Canary/PK-Runner}"

if [[ ! -x "$CANARY" ]]; then
	echo "❌ Chrome Canary introuvable: $CANARY"
	echo "   Installe-le: brew install --cask google-chrome@canary"
	exit 1
fi

ARGS=(
	--remote-debugging-port="$PORT"
	--user-data-dir="$PROFILE"
	--no-first-run
	--no-default-browser-check
	--disable-features=Translate
)

if [[ "${PK_VISIBLE:-0}" != "1" ]]; then
	# Fenêtre minuscule hors-champ : pas de vol de focus, pas de capture souris.
	Args_window=( --window-position=-32000,-32000 --window-size=1,1 )
	ARGS+=( "${Args_window[@]}" )
fi

exec "$CANARY" "${ARGS[@]}" "$@"

#!/usr/bin/env bash
# Lance Google Chrome Canary avec le port CDP 9222 pour le runner PK X.
#
# Pourquoi Canary : c'est un navigateur SÉPARÉ de ton Chrome principal
# (binaire, profil et icône différents). Chrome interdit le pilotage CDP sur
# le profil par défaut de Chrome stable, mais Canary a son propre profil par
# défaut → CDP y est autorisé sans --user-data-dir.
#
# La fenêtre fait par défaut 600x900 (visible, pour suivre les publications),
# en haut à gauche. Dimensions/position surchargeables : PK_WINDOW_W, PK_WINDOW_H,
# PK_WINDOW_X, PK_WINDOW_Y.
set -euo pipefail

PORT="${PK_CDP_PORT:-9222}"
CANARY="/Applications/Google Chrome Canary.app/Contents/MacOS/Google Chrome Canary"
PROFILE="${PK_CANARY_PROFILE:-$HOME/Library/Application Support/Google/Chrome Canary/PK-Runner}"
WIN_W="${PK_WINDOW_W:-600}"
WIN_H="${PK_WINDOW_H:-900}"
WIN_X="${PK_WINDOW_X:-80}"
WIN_Y="${PK_WINDOW_Y:-80}"

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
	--window-position="$WIN_X,$WIN_Y"
	--window-size="$WIN_W,$WIN_H"
)

exec "$CANARY" "${ARGS[@]}" "$@"

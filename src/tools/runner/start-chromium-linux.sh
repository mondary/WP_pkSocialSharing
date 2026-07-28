#!/usr/bin/env bash
# Lance Chromium headful sur Xvfb (affichage virtuel) avec CDP pour le runner PK X.
#
# Pourquoi headful sur Xvfb plutôt que --headless : un vrai Chromium headful a un
# fingerprint de navigateur desktop normal → indiscernable. --headless (même =new)
# laisse des traces que X détecte (navigator.webdriver, features manquantes).
#
# Lancement auto : par le service systemd pk-chromium-cdp.service, ou manuellement.
# Variables : PK_CHROME_PROFILE, PK_CDP_PORT (9222), PK_DISPLAY (:99), PK_CHROME_BIN.
set -euo pipefail

PROFILE="${PK_CHROME_PROFILE:-/var/lib/x-runner/profile}"
PORT="${PK_CDP_PORT:-9222}"
DISPLAY_NUM="${PK_DISPLAY:-:99}"
BIN="${PK_CHROME_BIN:-chromium}"

# Résolution du binaire Chromium (selon la distro le nom change)
if ! command -v "$BIN" >/dev/null 2>&1; then
	for alt in chromium-browser chromium google-chrome google-chrome-stable; do
		if command -v "$alt" >/dev/null 2>&1; then BIN="$alt"; break; fi
	done
fi
if ! command -v "$BIN" >/dev/null 2>&1; then
	echo "❌ Chromium introuvable. Installe-le : sudo apt install -y chromium" >&2
	exit 1
fi

# Xvfb : affichage virtuel pour un vrai Chromium headful (anti-détection).
export DISPLAY="${DISPLAY:-$DISPLAY_NUM}"
if ! pgrep -x Xvfb >/dev/null 2>&1; then
	if ! command -v Xvfb >/dev/null 2>&1; then
		echo "❌ Xvfb introuvable. Installe-le : sudo apt install -y xvfb" >&2
		exit 1
	fi
	Xvfb "$DISPLAY_NUM" -screen 0 1280x720x24 -ac +extension RANDR >/dev/null 2>&1 &
	sleep 1
fi

# Gestionnaire de fenêtres léger (rendu plus propre, optionnel)
if command -v openbox >/dev/null 2>&1 && ! pgrep -x openbox >/dev/null 2>&1; then
	( openbox >/dev/null 2>&1 & ) 2>/dev/null || true
fi

mkdir -p "$PROFILE"
exec "$BIN" \
	--remote-debugging-port="$PORT" \
	--user-data-dir="$PROFILE" \
	--no-first-run \
	--no-default-browser-check \
	--disable-gpu \
	--disable-dev-shm-usage \
	--no-sandbox \
	--disable-features=Translate \
	--window-size=600,900 \
	"$@"

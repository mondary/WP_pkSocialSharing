#!/usr/bin/env bash
# Lance Chromium sur serveur Linux avec CDP. Deux modes :
#   PK_HEADLESS=0 (défaut pour le 1er lancement = init session X via VNC/noVNC)
#   PK_HEADLESS=1 (headless pour usage cron quotidien)
set -euo pipefail

PROFILE="${PK_CHROME_PROFILE:-/var/lib/x-runner/profile}"
PORT="${PK_CDP_PORT:-9222}"
HEADLESS="${PK_HEADLESS:-1}"
BIN="${PK_CHROME_BIN:-chromium-browser}"

if ! command -v "$BIN" >/dev/null 2>&1; then
	for alt in chromium google-chrome google-chrome-stable; do
		if command -v "$alt" >/dev/null 2>&1; then BIN="$alt"; break; fi
	done
fi

ARGS=(
	--remote-debugging-port="$PORT"
	--user-data-dir="$PROFILE"
	--no-first-run
	--no-default-browser-check
	--disable-gpu
	--disable-dev-shm-usage
)

if [[ "$HEADLESS" == "1" ]]; then
	ARGS+=(--headless=new --no-sandbox)
else
	ARGS+=(--no-sandbox)
fi

mkdir -p "$PROFILE"
exec "$BIN" "${ARGS[@]}" "$@"

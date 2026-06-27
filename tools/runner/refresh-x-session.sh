#!/usr/bin/env bash
# Re-copie la session X (cookies) du profil Chrome principal vers le profil dédié CDP.
# À lancer si X déconnecte le profil dédié (~1 an) ou après un logout X.
# Chrome doit être complètement fermé (Cmd+Q) avant de lancer ce script.
set -euo pipefail

MAIN="$HOME/Library/Application Support/Google/Chrome"
DEDIC="$HOME/Library/Application Support/Google/Chrome/PK-CDP-Profile"

if pgrep -f "Google Chrome.app/Contents/MacOS/Google Chrome" >/dev/null 2>&1; then
	echo "⚠️  Chrome tourne encore. Quitte-le complètement (Cmd+Q) puis relance ce script."
	exit 1
fi

[[ -d "$DEDIC/Default" ]] || { echo "❌ Profil dédié absent: $DEDIC"; echo "   Lance d'abord start-chrome-macos.sh avec PK_CHROME_PROFILE pour le créer."; exit 1; }

cp "$MAIN/Default/Cookies" "$DEDIC/Default/Cookies" && echo "✅ Cookies copiés"
cp "$MAIN/Default/Login Data" "$DEDIC/Default/Login Data" 2>/dev/null && echo "✅ Login Data copié" || true
cp "$MAIN/Default/Web Data" "$DEDIC/Default/Web Data" 2>/dev/null && echo "✅ Web Data copié" || true

echo ""
echo "Session X copiée. Relance le Chrome dédié :"
echo "  PK_CHROME_PROFILE=\"$DEDIC\" $(dirname "$0")/start-chrome-macos.sh"

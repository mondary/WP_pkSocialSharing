#!/usr/bin/env bash
# Re-copie la session X (cookies) du profil par défaut de Chrome Canary vers le
# profil dédié au runner (PK-Runner). À lancer si X déconnecte le profil dédié
# (~1 an) ou après un logout X.
# Chrome Canary doit être complètement fermé (Cmd+Q) avant de lancer ce script.
set -euo pipefail

CANARY_BASE="$HOME/Library/Application Support/Google/Chrome Canary"
MAIN="$CANARY_BASE/Default"
DEDIC="$CANARY_BASE/PK-Runner"

if pgrep -f "Google Chrome Canary.app/Contents/MacOS/Google Chrome Canary" >/dev/null 2>&1; then
	echo "⚠️  Chrome Canary tourne encore. Quitte-le complètement (Cmd+Q) puis relance ce script."
	exit 1
fi

[[ -d "$DEDIC/Default" ]] || { echo "❌ Profil dédié absent: $DEDIC"; echo "   Lance d'abord start-chrome-macos.sh pour le créer, connecte-toi à X une fois."; exit 1; }

cp "$MAIN/Cookies" "$DEDIC/Default/Cookies" && echo "✅ Cookies copiés"
cp "$MAIN/Login Data" "$DEDIC/Default/Login Data" 2>/dev/null && echo "✅ Login Data copié" || true
cp "$MAIN/Web Data" "$DEDIC/Default/Web Data" 2>/dev/null && echo "✅ Web Data copié" || true

echo ""
echo "Session X copiée. Relance Chrome Canary via le runner :"
echo "  ./tools/runner/start-chrome-macos.sh"
echo "  (ou: launchctl load ~/Library/LaunchAgents/com.pk.chrome-canary-cdp.plist)"

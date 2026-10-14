#!/bin/bash
# Check quotidien des partages WordPress (LinkedIn, X, Medium).
# Notification macOS uniquement en cas de probleme. Log : ~/.local/log/pk-sharecheck.log
REPO="/Users/clm/Documents/GitHub/PROJECTS/WP_PKSocialSharing"
OUT=$("$REPO/src/tools/runner/runnerctl.sh" check 2>&1)
RESULT=$(echo "$OUT" | grep "^RESULT:" | tail -1)
LOG="$HOME/.local/log/pk-sharecheck.log"
mkdir -p "$(dirname "$LOG")"
{
    echo "[$(date "+%Y-%m-%d %H:%M")] $RESULT"
    echo "$OUT"
    echo "---"
} >> "$LOG"
if echo "$OUT" | grep -q "RESULT: PROBLEMES"; then
    MSG=$(echo "$OUT" | grep "  - " | head -3 | tr "\n" " " | sed "s/\"/'/g" | cut -c1-180)
    osascript -e "display notification \"${MSG}\" with title \"PK SocialSharing\" subtitle \"Partages manquants\"" 2>/dev/null
fi

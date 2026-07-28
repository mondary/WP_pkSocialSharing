#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLIST="$HOME/Library/LaunchAgents/com.pk.runner-menubar.plist"
INSTALL_DIR="$HOME/.local/share/pk-runner-menubar"
USER_ID="$(id -u)"

python3 -m pip install --user -r "$SCRIPT_DIR/requirements.txt"
mkdir -p "$HOME/Library/LaunchAgents" "$HOME/.local/log" "$INSTALL_DIR"
cp "$SCRIPT_DIR/app.py" "$INSTALL_DIR/app.py"
cp "$SCRIPT_DIR/../runnerctl.sh" "$INSTALL_DIR/runnerctl.sh"
chmod +x "$INSTALL_DIR/runnerctl.sh"
sed \
    -e "s|__PYTHON__|$(command -v python3)|g" \
    -e "s|__APP__|$INSTALL_DIR/app.py|g" \
    -e "s|__HOME__|$HOME|g" \
    "$SCRIPT_DIR/com.pk.runner-menubar.plist" > "$PLIST"
launchctl bootout "gui/$USER_ID" "$PLIST" 2>/dev/null || true
launchctl bootstrap "gui/$USER_ID" "$PLIST"
echo "Controleur PK lance dans la menubar."

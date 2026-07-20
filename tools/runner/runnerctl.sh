#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
X_PLIST="$HOME/Library/LaunchAgents/com.pk.x-runner.plist"
MEDIUM_PLIST="$HOME/Library/LaunchAgents/com.pk.medium-runner.plist"
USER_ID=$(id -u)

case "${1:-status}" in
    start)
        echo "Démarrage des runners..."
        launchctl bootstrap gui/$USER_ID "$X_PLIST" 2>/dev/null || true
        launchctl bootstrap gui/$USER_ID "$MEDIUM_PLIST" 2>/dev/null || true
        sleep 2
        $0 status
        ;;
    stop)
        echo "Arrêt des runners..."
        launchctl bootout gui/$UID/com.pk.x-runner 2>/dev/null || true
        launchctl bootout gui/$UID/com.pk.medium-runner 2>/dev/null || true
        sleep 1
        echo "Runners arrêtés."
        ;;
    restart)
        $0 stop
        sleep 2
        $0 start
        ;;
    status)
        echo "Statut launchd :"
        launchctl list | grep -E "com.pk\.(x-runner|medium-runner|chrome-canary-cdp)" || echo "Aucun runner chargé."
        echo ""
        echo "Processus actifs :"
        ps aux | grep -E "pk-(x-runner|medium-runner)" | grep -v grep || echo "Aucun processus."
        ;;
    logs)
        case "${2:-all}" in
            x) tail -f ~/.local/log/pk-x-runner.log ~/.local/log/pk-x-runner.err 2>/dev/null ;;
            medium) tail -f ~/.local/log/pk-medium-runner.log ~/.local/log/pk-medium-runner.err 2>/dev/null ;;
            all) tail -f ~/.local/log/pk-*-runner.log ~/.local/log/pk-*-runner.err 2>/dev/null ;;
            *) echo "Usage: $0 logs [x|medium|all]" ;;
        esac
        ;;
    *)
        echo "Usage: $0 {start|stop|restart|status|logs [x|medium|all]}"
        exit 1
        ;;
esac
#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
X_PLIST="$HOME/Library/LaunchAgents/com.pk.x-runner.plist"
MEDIUM_PLIST="$HOME/Library/LaunchAgents/com.pk.medium-runner.plist"
KILL_SWITCH="$HOME/.config/pk-runners.disabled"
USER_ID=$(id -u)

case "${1:-status}" in
    start)
        echo "Démarrage des runners..."
        rm -f "$KILL_SWITCH"
        launchctl bootstrap gui/$USER_ID "$X_PLIST" 2>/dev/null || true
        launchctl bootstrap gui/$USER_ID "$MEDIUM_PLIST" 2>/dev/null || true
        sleep 2
        $0 status
        ;;
    stop)
        echo "Arrêt des runners..."
        mkdir -p "$(dirname "$KILL_SWITCH")"
        touch "$KILL_SWITCH"
        launchctl bootout gui/$UID/com.pk.x-runner 2>/dev/null || true
        launchctl bootout gui/$UID/com.pk.medium-runner 2>/dev/null || true
        sleep 1
        echo "Runners arrêtés. Kill switch actif."
        ;;
    restart)
        $0 stop
        sleep 2
        $0 start
        ;;
    run-x)
        if [[ -f "$KILL_SWITCH" ]]; then
            echo "Kill switch actif. Démarre les runners avant de lancer la queue X."
            exit 1
        fi
        launchctl kickstart -k "gui/$USER_ID/com.pk.x-runner"
        echo "Runner X déclenché immédiatement."
        ;;
    run-medium)
        if [[ -f "$KILL_SWITCH" ]]; then
            echo "Kill switch actif. Démarre les runners avant de lancer la queue Medium."
            exit 1
        fi
        launchctl kickstart -k "gui/$USER_ID/com.pk.medium-runner"
        echo "Runner Medium déclenché immédiatement."
        ;;
    status)
        if [[ -f "$KILL_SWITCH" ]]; then
            echo "Kill switch : ACTIF ($KILL_SWITCH)"
        else
            echo "Kill switch : inactif"
        fi
        echo "Statut launchd :"
        launchctl list | grep -E "com.pk\.(x-runner|medium-runner)" || echo "Aucun runner chargé."
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
    enable)
        rm -f "$KILL_SWITCH"
        echo "Kill switch retiré. Utilise '$0 start' pour charger les runners."
        ;;
    *)
        echo "Usage: $0 {start|stop|restart|run-x|run-medium|status|logs [x|medium|all]|enable}"
        exit 1
        ;;
esac

#!/usr/bin/env bash
# Installation turnkey du PK X Runner sur Debian/Ubuntu headless.
#
# Sur le serveur :
#   git clone <repo> /tmp/pk  &&  sudo bash /tmp/pk/tools/runner/install-debian.sh
#   (ou: scp tools/runner/ puis sudo bash install-debian.sh)
#
# Fait : paquets (nodejs chromium xvfb), user système, copie vers /opt/pk-x-runner,
#        config interactive, units systemd (Chromium CDP + runner + timer), démarrage.
set -euo pipefail

RUNNER_USER="x-runner"
RUNNER_DIR="/opt/pk-x-runner"
CONF_DIR="/etc/pk-x-runner"
PROFILE_DIR="/var/lib/x-runner/profile"
LOG_FILE="/var/log/pk-x-runner.log"
SRC_DIR="$(cd "$(dirname "$0")" && pwd)"

[[ $EUID -eq 0 ]] || { echo "▶ Lance en root : sudo bash $0"; exit 1; }
[[ -f "$SRC_DIR/pk-x-runner.js" ]] || { echo "▶ $SRC_DIR ne contient pas pk-x-runner.js — lance depuis tools/runner/"; exit 1; }

echo "=== 1/7 Paquets (nodejs, chromium, xvfb) ==="
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nodejs npm chromium xvfb

echo "=== 2/7 Utilisateur système $RUNNER_USER ==="
if ! id -u "$RUNNER_USER" >/dev/null 2>&1; then
	useradd --system --no-create-home --shell /usr/sbin/nologin "$RUNNER_USER"
fi

echo "=== 3/7 Copie du runner vers $RUNNER_DIR ==="
mkdir -p "$RUNNER_DIR"
cp -a "$SRC_DIR"/. "$RUNNER_DIR"/
rm -rf "$RUNNER_DIR/node_modules" "$RUNNER_DIR/install-debian.sh"
( cd "$RUNNER_DIR" && npm install --omit=dev >/dev/null 2>&1 ) || { echo "▶ npm install a échoué"; exit 1; }

echo "=== 4/7 Config ($CONF_DIR/config.json) ==="
mkdir -p "$CONF_DIR" "$PROFILE_DIR" "$(dirname "$LOG_FILE")"
chown -R "$RUNNER_USER:$RUNNER_USER" "$PROFILE_DIR"
CONF="$CONF_DIR/config.json"
if [[ ! -f "$CONF" ]]; then
	# Valeurs vides par défaut (le HTTP 403 du WAF exige un User-Agent navigateur côté runner
	# Node, mais le runner en ajoute déjà un ; ici on ne stocke que l'essentiel).
	read -rp "▶ URL WordPress (https://ton-site.com) : " WP_URL
	read -rp "▶ Runner token (WP Admin → PK SocialSharing → X → Runner) : " TOKEN
	WP_URL="${WP_URL%%/}"
	cat > "$CONF" <<EOF
{
  "wp_url": "$WP_URL",
  "runner_token": "$TOKEN",
  "browser_url": "http://127.0.0.1:9222",
  "autoclick_override": null,
  "human_delay_ms_min": 1500,
  "human_delay_ms_max": 4000,
  "click_timeout_ms": 12000
}
EOF
	chmod 640 "$CONF"; chown root:"$RUNNER_USER" "$CONF"
	echo "  Config écrite."
else
	echo "  Config existante conservée."
fi
touch "$LOG_FILE"; chown "$RUNNER_USER:$RUNNER_USER" "$LOG_FILE"

echo "=== 5/7 Units systemd ==="
sub() { sed -e "s|__USER__|$RUNNER_USER|g" -e "s|__RUNNER_DIR__|$RUNNER_DIR|g" "$1" > "$2"; }
sub "$SRC_DIR/com.pk.chromium-cdp.service" /etc/systemd/system/pk-chromium-cdp.service
sub "$SRC_DIR/com.pk.x-runner.service"     /etc/systemd/system/pk-x-runner.service
cp "$SRC_DIR/com.pk.x-runner.timer"        /etc/systemd/system/pk-x-runner.timer
chmod +x "$RUNNER_DIR"/start-chromium-linux.sh "$RUNNER_DIR"/start-chrome-macos.sh 2>/dev/null || true

echo "=== 6/7 Démarrage ==="
systemctl daemon-reload
systemctl enable --now pk-chromium-cdp.service
systemctl enable --now pk-x-runner.timer
sleep 4
echo "  Chromium CDP : $(systemctl is-active pk-chromium-cdp.service)"
echo "  Runner timer : $(systemctl is-active pk-x-runner.timer)"
echo "  CDP joignable: $(curl -s --max-time 3 http://127.0.0.1:9222/json/version >/dev/null 2>&1 && echo OK || echo 'EN COURS')"

echo ""
echo "=== 7/7 Initialisation ONE-TIME de la session X ==="
echo "Chromium tourne headful sur Xvfb (:99). Pour te connecter à X la 1re fois :"
echo ""
echo "  Depuis ton Mac :"
echo "    ssh -L 9222:127.0.0.1:9222 $(logname 2>/dev/null || echo USER)@$(hostname -I 2>/dev/null | awk '{print $1}' || echo 'SERVEUR')"
echo "  Puis ouvre dans Chrome : http://127.0.0.1:9222  →  onglet →  va sur x.com  →  connecte-toi"
echo ""
echo "  La session persiste dans $PROFILE_DIR (le runner la réutilise)."
echo ""
echo "Suivi :"
echo "  Logs runner   : tail -f $LOG_FILE"
echo "  Statut queue  : voir WP Admin → PK SocialSharing → X → Runner"
echo "  Forcer un run  : sudo systemctl start pk-x-runner.service"

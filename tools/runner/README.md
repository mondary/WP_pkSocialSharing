# PK X Runner (CDP)

Publie sur X **sans crédits API** en pilotant **ton vrai Chrome** via le Chrome
DevTools Protocol (CDP). Le navigateur est le tien → fingerprint humain → pas de
ban. Même code sur macOS et Linux.

```
WP plugin  ──GET /next──►  ce runner  ──CDP:9222──►  ton Chrome (session X)
           ◄──POST /done──            ◄──clic tweet──
```

## Pourquoi CDP et pas Playwright/Puppeteer classique

- `puppeteer.launch()` démarre un Chromium automatisé → `navigator.webdriver`, fingerprint de build → **X détecte et bannit**.
- `puppeteer-core.connect()` se **branche** sur ton Chrome déjà ouvert via CDP → **indistinguable d'un humain**.

On utilise `puppeteer-core` (pas `puppeteer`) : aucun Chromium téléchargé, il exige un Chrome lancé séparément.

## Anti-doublon / anti-spam (géré côté plugin)

| Mécanisme | Effet |
|---|---|
| `META_X_SHARED_AT` | un post partagé ne revient jamais dans `/next` |
| Claim 15 min | un post « pris » ne peut pas être repris par un run concurrent |
| Plafond quotidien (5/jour) | au-delà, `/next` répond `queue vide` |
| 1 post / run | le runner traite un seul article puis s'arrête |

Donc zéro risque de double-post ou de rafale, même si le cron se déclenche deux fois.

---

## Setup macOS

### 1. Installer Node.js + dépendances

```bash
brew install node
cd tools/runner
npm install
```

### 2. Récupérer le token runner

WP Admin → PK SocialSharing → onglet **X** → carte « Runner navigateur » :
- Cocher **Activer le runner**
- Cliquer **Générer / Régénérer**, copier le token

### 3. Configurer le runner

```bash
mkdir -p ~/.config
cp tools/runner/config.example.json ~/.config/pk-x-runner.json
$EDITOR ~/.config/pk-x-runner.json
#   wp_url        = https://ton-site.com
#   runner_token  = <le token copié>
#   browser_url   = http://127.0.0.1:9222
```

### 4. Lancer Chrome en mode pilotable (profil dédié)

```bash
chmod +x tools/runner/start-chrome-macos.sh
./tools/runner/start-chrome-macos.sh
```

Une fenêtre Chrome s'ouvre (profil neuf). **Au premier lancement seulement** :
connecte-toi à ton compte X (`x.com`) normalement dans cette fenêtre. Ferme-la.
Les relances suivantes réutilisent automatiquement la session (cookies persistés
dans `~/Library/Application Support/Google/Chrome/PK-CDP-Profile`).

> Le profil est **dédié** (séparé de ton Chrome principal) pour pouvoir tourner en
> parallèle sans conflit, et pour être copiable sur Linux plus tard.

### 5. Test manuel

```bash
node tools/runner/pk-x-runner.js
tail -f ~/.local/log/pk-x-runner.log
```

Si un article est en attente X : un nouvel onglet s'ouvre dans le Chrome dédié,
le tweet est publié automatiquement, puis l'onglet se ferme.

### 6. Automatiser avec launchd (toutes les 15 min)

```bash
RUNNER_DIR="$(pwd)/tools/runner"
sed "s|__RUNNER_DIR__|$RUNNER_DIR|g" tools/runner/com.pk.x-runner.plist \
  > ~/Library/LaunchAgents/com.pk.x-runner.plist

launchctl load ~/Library/LaunchAgents/com.pk.x-runner.plist
launchctl list | grep com.pk.x-runner
```

Arrêter :
```bash
launchctl unload ~/Library/LaunchAgents/com.pk.x-runner.plist
```

> launchd ne déclenche le runner que si ton Mac est allumé. Le plugin garde la
> queue en mémoire donc rien n'est perdu : le prochain run prend le relais.

---

## Setup Linux headless (serveur)

### 1. Installer Node.js + Chromium + dépendances

```bash
# Debian/Ubuntu
sudo apt install -y nodejs npm chromium-browser
# ou, si chromium absent du paquet :
# sudo snap install chromium

cd tools/runner
sudo mkdir -p /opt/pk-x-runner
sudo cp -r . /opt/pk-x-runner/
cd /opt/pk-x-runner
sudo npm install --omit=dev
```

### 2. Config + profil

```bash
sudo mkdir -p /etc/pk-x-runner /var/lib/x-runner/profile
sudo cp config.example.json /etc/pk-x-runner/config.json
sudo $EDITOR /etc/pk-x-runner/config.json   # wp_url + runner_token

sudo chown -R x-runner:x-runner /var/lib/x-runner  # créer l'user d'abord (voir bas)
```

### 3. Initialiser la session X (1 fois)

Comme Chromium tournera headless, il faut se connecter à X une fois. Deux options :

**Option A — tunnel SSH + Chromium headful** (recommandé)

```bash
# Sur le serveur, lance Chromium en mode fenêtré (headful) sur le display :0 via X fwd
PK_HEADLESS=0 PK_CHROME_PROFILE=/var/lib/x-runner/profile \
  ./start-chromium-linux.sh &

# Depuis ton Mac, forward le port CDP + un affichage
ssh -L 9222:127.0.0.1:9222 user@serveur
# Puis dans Chrome sur ton Mac : ouvre http://127.0.0.1:9222 → onglet X → connecte-toi
```

**Option B — copie du profil macOS**

```bash
scp -r ~/Library/Application\ Support/Google/Chrome/PK-CDP-Profile user@serveur:/var/lib/x-runner/profile
```
⚠️ Si l'IP du serveur diffère beaucoup de ton IP domicile, X peut demander une
re-validation (pas un ban). Préfère l'option A si possible.

### 4. Lancer Chromium headless en service systemd

Créer `/etc/systemd/system/chromium-cdp.service` :

```ini
[Unit]
Description=Chromium headless avec CDP pour PK X Runner
After=network-online.target

[Service]
Type=simple
User=x-runner
Environment=PK_HEADLESS=1
Environment=PK_CHROME_PROFILE=/var/lib/x-runner/profile
ExecStart=/opt/pk-x-runner/start-chromium-linux.sh
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now chromium-cdp.service
curl -s http://127.0.0.1:9222/json/version | head   # vérif
```

### 5. Planifier le runner

```bash
RUNNER_DIR=/opt/pk-x-runner
sudo sed -e "s|__RUNNER_DIR__|$RUNNER_DIR|g" \
         -e "s|__USER__|x-runner|g" \
         /opt/pk-x-runner/com.pk.x-runner.service \
         > /etc/systemd/system/com.pk.x-runner.service
sudo cp /opt/pk-x-runner/com.pk.x-runner.timer /etc/systemd/system/

sudo systemctl daemon-reload
sudo systemctl enable --now com.pk.x-runner.timer
systemctl list-timers | grep pk-x-runner
```

Logs : `tail -f /var/log/pk-x-runner.log`

---

## Configuration avancée (`~/.config/pk-x-runner.json`)

| Champ | Défaut | Rôle |
|---|---|---|
| `wp_url` | — | URL WordPress (sans slash final) |
| `runner_token` | — | Token généré dans l'admin WP |
| `browser_url` | `http://127.0.0.1:9222` | Endpoint CDP de ton Chrome |
| `autoclick_override` | `null` | `true`/`false` pour forcer le clic auto (sinon suit la config WP) |
| `human_delay_ms_min` | `1500` | Délai aléatoire min avant clic (paraître humain) |
| `human_delay_ms_max` | `4000` | Délai aléatoire max avant clic |
| `click_timeout_ms` | `12000` | Attente max de confirmation du post |

## Endpoints REST (tous avec header `X-PK-Runner-Token`)

| Méthode | URL | Rôle |
|---|---|---|
| `GET`  | `/wp-json/pksocialsharing/v1/x-browser/next`   | Prochain article + intent URL |
| `POST` | `/wp-json/pksocialsharing/v1/x-browser/done`   | Marque `post_id` partagé |
| `POST` | `/wp-json/pksocialsharing/v1/x-browser/release`| Libère le claim |
| `GET`  | `/wp-json/pksocialsharing/v1/x-browser/status` | Compteur du jour, dernier run |

## Dépannage

| Symptôme | Cause / fix |
|---|---|
| `ERREUR CDP: Chrome CDP inaccessible` | Chrome/Chromium pas lancé, ou pas sur le port 9222. Lance `start-chrome-*.sh`. |
| `bouton tweet introuvable` | X a changé son DOM, ou tu n'es pas connecté (session X expirée). Rouvre Chrome, reconnecte-toi. |
| `confirmation post absente` | Le tweet a peut-être été publié mais le signal n'est pas détecté. Vérifie ton compte X manuellement ; le claim est libéré donc le post ne sera pas retenté (anti-doublon). |
| `403` sur `/next` | Runner désactivé dans WP, ou `runner_token` erroné. |
| `daily_cap` | Plafond quotidien atteint — revient demain, ou monte-le dans WP. |

## Sécurité

- Token vérifié en timing-safe (`hash_equals`) côté WP, 403 si désactivé/erroné.
- Le runner ne touche qu'aux endpoints `/x-browser/*`, jamais au reste de WP.
- Le profil Chrome dédié isole ta session X de ton navigateur principal.
- CDP n'écoute que `127.0.0.1` (jamais exposé à l'extérieur).

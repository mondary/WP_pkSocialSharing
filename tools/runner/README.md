# PK X Runner (CDP)

Publie sur X **sans crédits API** en pilotant **ton vrai Chrome Canary** via le Chrome
DevTools Protocol (CDP). Le navigateur est le tien → fingerprint humain → pas de
ban. Même code sur macOS et Linux.

```
WP plugin  ──GET /next──►  ce runner  ──CDP:9222──►  Chrome Canary (session X)
            ◄──POST /done──            ◄──clic tweet──
```

## Pourquoi Chrome Canary (et pas Chrome stable)

**Canary est le navigateur dédié aux automatisations.** Binaire, profil et icône
séparés de ton Chrome stable — tu peux donc **bosser normalement sur ton Chrome
principal** pendant que le runner publie dans Canary : pas de vol de focus, pas de
capture de curseur (la fenêtre Canary est poussée hors-champ).

En bonus, Chrome stable interdit le pilotage CDP sur son profil par défaut, alors que
Canary a son propre profil par défaut → CDP autorisé nativement.

À installer une fois : `brew install --cask google-chrome@canary`.

## Pourquoi CDP et pas Playwright/Puppeteer classique

- `puppeteer.launch()` démarre un Chromium automatisé → `navigator.webdriver`, fingerprint de build → **X détecte et bannit**.
- `puppeteer-core.connect()` se **branche** sur ton Chrome Canary déjà ouvert via CDP → **indistinguable d'un humain**.

On utilise `puppeteer-core` (pas `puppeteer`) : aucun Chromium téléchargé, il exige un navigateur lancé séparément.

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

### 1. Installer Node.js + Chrome Canary + dépendances

```bash
brew install node
brew install --cask google-chrome@canary   # navigateur dédié à l'automatisation
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

### 4. Lancer Chrome Canary en mode pilotable (1er lancement)

```bash
chmod +x tools/runner/start-chrome-macos.sh
./tools/runner/start-chrome-macos.sh
```

`start-chrome-macos.sh` lance **Google Chrome Canary** (pas Chrome stable) sur le
port CDP 9222, avec un profil dédié `~/Library/Application Support/Google/Chrome Canary/PK-Runner`.
La fenêtre fait **600x900** par défaut (visible en haut à gauche, pour suivre les
publicutions). Surchargeable : `PK_WINDOW_W`, `PK_WINDOW_H`, `PK_WINDOW_X`, `PK_WINDOW_Y`.

Une fenêtre Canary s'ouvre (profil neuf). **Au premier lancement seulement** :
connecte-toi à ton compte X (`x.com`) dans cette fenêtre, puis ferme-la. Les relances
suivantes réutilisent la session (cookies persistés dans le profil Canary dédié).

> Canary est séparé de ton Chrome principal : le runner publie en arrière-plan sans
> te déranger pendant que tu bosses. Pour le lancer auto au démarrage, voir le plist
> `com.pk.chrome-canary-cdp.plist` à l'étape 6.

### 5. Test manuel

```bash
node tools/runner/pk-x-runner.js
tail -f ~/.local/log/pk-x-runner.log
```

Si un article est en attente X : un nouvel onglet s'ouvre dans le Chrome dédié,
le tweet est publié automatiquement, puis l'onglet se ferme.

### 6. Automatiser avec launchd (Chrome Canary + runner)

Deux plists à charger : (a) **Chrome Canary** en CDP persistant au login, (b) le
**runner** qui déclenche aux heures de publication (10:05, 11:05, 12:05, 13:05, 14:05 —
5 déclenchements/jour alignés sur le plafond du plugin).

```bash
# (a) Chrome Canary toujours prêt sur le port 9222
cp tools/runner/com.pk.chrome-canary-cdp.plist ~/Library/LaunchAgents/
launchctl load ~/Library/LaunchAgents/com.pk.chrome-canary-cdp.plist

# (b) Le runner aux heures de publication
RUNNER_DIR="$(pwd)/tools/runner"
sed "s|__RUNNER_DIR__|$RUNNER_DIR|g" tools/runner/com.pk.x-runner.plist \
  > ~/Library/LaunchAgents/com.pk.x-runner.plist
launchctl load ~/Library/LaunchAgents/com.pk.x-runner.plist

launchctl list | grep com.pk
```

Arrêter :
```bash
launchctl unload ~/Library/LaunchAgents/com.pk.x-runner.plist
launchctl unload ~/Library/LaunchAgents/com.pk.chrome-canary-cdp.plist
```

> launchd ne déclenche le runner que si ton Mac est allumé. Le plugin garde la
> queue en mémoire donc rien n'est perdu : le prochain run prend le relais.

---

## Setup Linux headless (Debian/Ubuntu serveur)

> ⚠️ **IP datacenter** : l'IP d'un serveur diffère de ton IP maison → X peut
> re-valider la session (challenge, pas forcément ban). Préfère un VPS à IP
> résidentielle. Le runner tourne en **Chromium headful sur Xvfb** (anti-détection,
> jamais `--headless`).

### Méthode rapide — script turnkey

```bash
git clone <repo> /tmp/pk && sudo bash /tmp/pk/tools/runner/install-debian.sh
```

Installe tout (Node + Chromium + Xvfb, user, config, services systemd). Reste ensuite
l'init de la session X (étape 3). Méthode manuelle détaillée ci-dessous.

### 1. Installer Node.js + Chromium + Xvfb

```bash
sudo apt install -y nodejs npm chromium xvfb
cd tools/runner
sudo mkdir -p /opt/pk-x-runner && sudo cp -a . /opt/pk-x-runner/
cd /opt/pk-x-runner && sudo npm install --omit=dev
```

### 2. Config + profil

```bash
sudo mkdir -p /etc/pk-x-runner /var/lib/x-runner/profile
sudo cp config.example.json /etc/pk-x-runner/config.json
sudo $EDITOR /etc/pk-x-runner/config.json   # wp_url + runner_token

sudo chown -R x-runner:x-runner /var/lib/x-runner  # créer l'user d'abord (voir bas)
```

### 3. Initialiser la session X (1 fois)

Chromium tourne **headful sur Xvfb** (affichage virtuel, anti-détection). Pour le
premier login X, tunnel le port CDP vers ton Mac :

```bash
# Depuis ton Mac
ssh -L 9222:127.0.0.1:9222 user@serveur
# Puis ouvre dans Chrome : http://127.0.0.1:9222 → un onglet → va sur x.com → connecte-toi
```

La session persiste dans `/var/lib/x-runner/profile` et est réutilisée à chaque run.

> ⚠️ Si l'IP du serveur diffère de ton IP maison, X peut re-valider la session
> (challenge email/téléphone, pas un ban). Un VPS à IP résidentielle limite ce risque.

### 4. Lancer Chromium (headful Xvfb) en service systemd

L'unit est fournie dans `com.pk.chromium-cdp.service` (démarre Chromium sur Xvfb :99
avec CDP 9222, `Restart=always`) :

```bash
sudo sed -e "s|__USER__|x-runner|g" -e "s|__RUNNER_DIR__|/opt/pk-x-runner|g" \
  /opt/pk-x-runner/com.pk.chromium-cdp.service \
  > /etc/systemd/system/pk-chromium-cdp.service
sudo systemctl daemon-reload
sudo systemctl enable --now pk-chromium-cdp.service
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
| `ERREUR CDP: Chrome CDP inaccessible` | Chrome Canary pas lancé, ou pas sur le port 9222. Lance `start-chrome-macos.sh` (ou charge `com.pk.chrome-canary-cdp.plist`). |
| `bouton tweet introuvable` | X a changé son DOM, ou tu n'es pas connecté (session X expirée). Rouvre Chrome, reconnecte-toi. |
| `confirmation post absente` | Le tweet a peut-être été publié mais le signal n'est pas détecté. Vérifie ton compte X manuellement ; le claim est libéré donc le post ne sera pas retenté (anti-doublon). |
| `403` sur `/next` | Runner désactivé dans WP, ou `runner_token` erroné. |
| `daily_cap` | Plafond quotidien atteint — revient demain, ou monte-le dans WP. |

## Sécurité

- Token vérifié en timing-safe (`hash_equals`) côté WP, 403 si désactivé/erroné.
- Le runner ne touche qu'aux endpoints `/x-browser/*`, jamais au reste de WP.
- Le profil Chrome dédié isole ta session X de ton navigateur principal.
- CDP n'écoute que `127.0.0.1` (jamais exposé à l'extérieur).

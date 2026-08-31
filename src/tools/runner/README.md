# PK X Runner (ego-browser)

Publie sur X **sans crédits API** en pilotant **ego-browser (EgoLite)** — le navigateur
conçu pour les agents. Le runner publie dans un **espace de navigation isolé** qui
hérite de la session X de ton navigateur principal : pas de fenêtre qui squatte ton
écran, pas de vol de focus, pas de navigateur dédié à maintenir.

```
WP plugin  ──GET /next──►  ce runner  ──ego-browser──►  EgoLite (session X héritée)
            ◄──POST /done──            ◄──clic tweet──
```

## Pourquoi ego-browser (EgoLite)

**EgoLite est un vrai Chromium avec ta session.** Les espaces de tâches (task spaces)
sont des contextes de navigation isolés : l'agent a ses propres onglets et sa souris
virtuelle, mais **hérite de tes logins** — X voit ton vrai profil, pas un Chromium
automatisé. Ton navigateur principal n'est jamais perturbé.

## Pourquoi pas Playwright/Puppeteer classique

- `puppeteer.launch()` démarre un Chromium automatisé → `navigator.webdriver`, fingerprint de build → **X détecte et bannit**.
- ego-browser pilote un vrai Chromium avec ta session utilisateur → **indistinguable d'un humain**.

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

### 1. Installer Node.js + ego-browser

```bash
brew install node
```

ego-browser (EgoLite) doit être installé : commande `ego-browser` disponible dans le `PATH`
(voir https://ego-browser). Aucune dépendance npm — plus de `npm install` nécessaire.

### 2. Récupérer le token runner

WP Admin → PK SocialSharing → onglet **X** → carte « Runner navigateur » :
- Cocher **Activer le runner**
- Cliquer **Générer / Régénérer**, copier le token

### 3. Configurer le runner

```bash
mkdir -p ~/.config
cp src/tools/runner/config.example.json ~/.config/pk-x-runner.json
$EDITOR ~/.config/pk-x-runner.json
#   wp_url        = https://ton-site.com
#   runner_token  = <le token copié>
#   ego_bin       = null (auto: ~/.local/bin/ego-browser, sinon chemin absolu)
```

### 4. Session X

La session X est **celle de ton navigateur principal**, héritée par les espaces de
tâches EgoLite. Si X déconnecte, reconnecte-toi dans ton navigateur habituel — rien
à faire côté runner.

### 5. Test manuel

```bash
node src/tools/runner/pk-x-runner.js
tail -f ~/.local/log/pk-x-runner.log
```

Si un article est en attente X : EgoLite ouvre un onglet dans son espace de tâches,
le tweet est publié automatiquement, puis l'onglet se ferme.

### 6. Automatiser avec launchd (runner à la demande)

Les fichiers launchd sont pré-configurés dans `~/.local/config/`. Utiliser `src/tools/runner/runnerctl.sh` pour la gestion :

```bash
# Installer les services launchd (une seule fois)
./src/tools/runner/runnerctl.sh start

# Voir le statut
./src/tools/runner/runnerctl.sh status

# Suivre les logs en temps réel
./src/tools/runner/runnerctl.sh logs

# Redémarrer les services
./src/tools/runner/runnerctl.sh restart
```

Le runner X se déclenche aux heures de publication (10:05, 11:05, 12:05, 13:05, 14:05 —
5 déclenchements/jour alignés sur le plafond du plugin). EgoLite n'est sollicité que
lorsqu'un article est réellement à publier.

### 7. Contrôleur menubar macOS

```bash
./src/tools/runner/menubar/install.sh
```

Un point 🟢 (runners actifs) ou 🔴 (kill switch actif) apparaît dans la menubar.
Un clic gauche bascule directement l'état. Un clic droit ouvre les commandes et les logs.
`STOP TOUT` crée `~/.config/pk-runners.disabled` et arrête les services.

> launchd ne déclenche le runner que si ton Mac est allumé. Le plugin garde la
> queue en mémoire donc rien n'est perdu : le prochain run prend le relais.

---

> ⚠️ **Legacy** : les runners actuels reposent sur ego-browser (macOS). La section
> Linux ci-dessous décrit l'ancien moteur CDP/Chromium-Xvfb, conservée pour référence.

## Setup Linux headless (Debian/Ubuntu serveur) — LEGACY

> ⚠️ **IP datacenter** : l'IP d'un serveur diffère de ton IP maison → X peut
> re-valider la session (challenge, pas forcément ban). Préfère un VPS à IP
> résidentielle. Le runner tourne en **Chromium headful sur Xvfb** (anti-détection,
> jamais `--headless`).

### Méthode rapide — script turnkey

```bash
git clone <repo> /tmp/pk && sudo bash /tmp/pk/src/tools/runner/install-debian.sh
```

Installe tout (Node + Chromium + Xvfb, user, config, services systemd). Reste ensuite
l'init de la session X (étape 3). Méthode manuelle détaillée ci-dessous.

### 1. Installer Node.js + Chromium + Xvfb

```bash
sudo apt install -y nodejs npm chromium xvfb
cd src/tools/runner
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
| `ego_bin` | `null` | Chemin absolu d'`ego-browser` (auto-détecté si `null`) |
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
| `spawn ego-browser` / ego introuvable | `ego-browser` absent du PATH — installe EgoLite ou renseigne `ego_bin` dans la config. |
| `bouton tweet introuvable` | X a changé son DOM, ou tu n'es pas connecté (session X expirée). Reconnecte-toi à x.com dans ton navigateur principal. |
| `confirmation post absente` | Le tweet a peut-être été publié mais le signal n'est pas détecté. Vérifie ton compte X manuellement ; le claim est libéré donc le post ne sera pas retenté (anti-doublon). |
| `403` sur `/next` | Runner désactivé dans WP, ou `runner_token` erroné. |
| `daily_cap` | Plafond quotidien atteint — revient demain, ou monte-le dans WP. |

## Sécurité

- Token vérifié en timing-safe (`hash_equals`) côté WP, 403 si désactivé/erroné.
- Le runner ne touche qu'aux endpoints `/x-browser/*`, jamais au reste de WP.
- Les espaces de tâches EgoLite isolent les onglets de l'agent ; ta session X reste dans ton navigateur principal.

---

## Runner Medium

`pk-medium-runner.js` réutilise ego-browser et ta session Medium (héritée de ton navigateur principal). Il ouvre
`https://medium.com/p/import`, colle l'URL de l'article WordPress puis clique sur **Importer**.
Il ne requiert pas l'ancienne API Medium.

1. Dans `WP Admin > PK SocialSharing > Medium > Runner navigateur Medium`, active la queue et génère le token.
2. Copie `src/tools/runner/medium-config.example.json` vers `~/.config/pk-medium-runner.json`, puis renseigne `wp_url` et `runner_token`.
3. Connecte-toi une fois à `medium.com` dans ton navigateur principal (session héritée par EgoLite).
4. Lance le runner en daemon (voir ci-dessous).

### Mode daemon (macOS launchd)

Le runner boucle en continu : il interroge `/medium-browser/next` toutes les 30 secondes, publie l'article dès qu'il est en queue, puis retourne attendre. Tu cliques **Publier maintenant** dans WordPress → dans les 30 secondes l'article est importé et publié sur Medium, sans rien relancer.

Les fichiers launchd sont déjà générés dans `~/.local/config/`. Pour les installer :

```bash
# Installer les services launchd (un seul fois)
cp ~/.local/config/com.pk.*.plist ~/Library/LaunchAgents/
launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.pk.x-runner.plist
launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.pk.medium-runner.plist
```

Gestion simple via `src/tools/runner/runnerctl.sh` :

```bash
./src/tools/runner/runnerctl.sh status    # Voir les services et processus
./src/tools/runner/runnerctl.sh logs      # Suivre les logs (Ctrl+C pour quitter)
./src/tools/runner/runnerctl.sh logs x    # Logs du runner X uniquement
./src/tools/runner/runnerctl.sh restart   # Redémarrer les deux runners
```

Logs : `~/.local/log/pk-x-runner.log` et `~/.local/log/pk-medium-runner.log`

Comportement :
- claim de 15 minutes par article (évite deux runners sur le même post) ;
- plafond quotidien configurable côté WordPress ;
- en cas d'erreur, l'onglet en échec est fermé et l'article reste réservé 15 min avant une nouvelle tentative ;
- `autopublish: true` par défaut — clique Importer **puis** Publish dans la modal Medium. Mettre `false` pour ne créer que le brouillon ;
- décocher « Le runner clique automatiquement sur Importer » dans l'admin pour garder une validation humaine finale.

Endpoints Medium, tous protégés par `X-PK-Runner-Token` :

| Méthode | URL | Rôle |
|---|---|---|
| `GET` | `/wp-json/pksocialsharing/v1/medium-browser/next` | Prochain article et URL à importer |
| `POST` | `/wp-json/pksocialsharing/v1/medium-browser/done` | Marque l'import traité |
| `POST` | `/wp-json/pksocialsharing/v1/medium-browser/release` | Libère le claim |
| `GET` | `/wp-json/pksocialsharing/v1/medium-browser/status` | État de la queue |

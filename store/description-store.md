# PK SocialSharing — Dossier du store

Matériel de présentation de l'application.
Captures : `store/screenshots/`.

---

## Nom

**PK SocialSharing**

## Tagline

**Vos articles publiés automatiquement sur six réseaux sociaux.**
*Your posts auto-published to six social networks.*

## Description courte

FR :
> Publiez automatiquement vos articles sur LinkedIn, X, Facebook, Instagram, Threads et Medium.

EN :
> Auto-share your posts to LinkedIn, X, Facebook, Instagram, Threads, and Medium.

## Description longue

### FR

**PK SocialSharing** est un plugin WordPress qui publie automatiquement ou manuellement vos articles sur six réseaux. Fini le copier-coller : chaque passage en `publish` déclenche le partage, avec tableau de bord de suivi et fallback navigateur quand l'API fait défaut.

#### ✨ Fonctionnalités clés
- **Publication automatique** — partage déclenché au passage d'un article en `publish`, plus partage manuel depuis l'admin
- **6 réseaux pris en charge** — LinkedIn, X (Twitter), Facebook, Instagram, Threads et Medium
- **Tableau de bord** — articles planifiés/publiés, statuts de partage et liens des posts sociaux
- **Colonne « Partages »** — icônes réseau grisées ou actives dans la liste des articles WordPress
- **Retry automatique** — WP-Cron toutes les 5 minutes pour les partages en attente + fallback WP-CLI
- **Connexion Meta centralisée** — OAuth, token longue durée, détection Page Facebook et compte Instagram
- **Runner navigateur X** — queue REST + plafond quotidien, zéro crédit API, via ego-browser dans un espace isolé
- **Runner navigateur Medium** — import via `medium.com/p/import` en mode daemon, sans API
- **Traduction automatique par réseau** — langue cible par onglet, plusieurs providers LLM, cache par contenu
- **Contrôleur macOS** — pilotage des runners depuis la menubar

### EN

**PK SocialSharing** is a WordPress plugin that publishes your posts automatically or manually to six networks. No more copy-pasting: every transition to `publish` triggers the share, with a tracking dashboard and a browser fallback when the API fails.

#### ✨ Key features
- **Automatic publishing** — sharing triggered when a post reaches `publish`, plus manual sharing from the admin
- **6 supported networks** — LinkedIn, X (Twitter), Facebook, Instagram, Threads, and Medium
- **Dashboard** — scheduled/published posts, share statuses, and social post links
- **"Shares" column** — grey or active network icons in the WordPress posts list
- **Automatic retry** — WP-Cron every 5 minutes for pending shares + WP-CLI fallback
- **Centralized Meta setup** — OAuth, long-lived token, Facebook Page and Instagram account detection
- **X browser runner** — REST queue + daily cap, zero API credits, via ego-browser in an isolated space
- **Medium browser runner** — import through `medium.com/p/import` in daemon mode, no API
- **Per-network auto-translation** — target language per tab, several LLM providers, content-hash cache
- **macOS controller** — drive the runners from the menu bar

## Captures

À capturer — campagne captures en cours.

## Offre

- **Modèle** : Open Source (GPLv2 or later)
- **Prix** : Gratuit

## Plateformes

- WordPress.org — soumis (slug `pk-socialsharing`, scan `Pass`, statut « Awaiting Review » au 2026-06-16) ; URL publique : —

## Liens

- **Repo** : —

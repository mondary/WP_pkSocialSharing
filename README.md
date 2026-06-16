# PK LinkedIn Auto Publish (WordPress)

Plugin WordPress “maison” pour publier automatiquement sur LinkedIn, X, Facebook, Instagram, Threads et Medium à la publication d’un article.

**Version actuelle :** `0.80`

## 📁 Structure du dépôt

```
.
├── src/
│   └── pk-linkedin-autopublish/        # Code du plugin
└── extension/                           # Anciens builds zip ignores par git
```

## 🚀 Installation / mise à jour

1. Copier le dossier `src/pk-linkedin-autopublish/` dans `wp-content/plugins/pk-linkedin-autopublish/`
2. Activer ou recharger le plugin
3. WP Admin → **WP PK SocialSharing**

Le plugin expose aussi une route de synchronisation REST sous `pksocialsharing/v1/sync-plugin` pour pousser les fichiers source sans zip quand le plugin est déjà installé.

## 🔑 Pré-requis LinkedIn (API)

Ce plugin utilise :

- OAuth2 (Authorization Code)
- Création de posts : endpoint `rest/posts` (et `v2/ugcPosts` pour le mode article/OpenGraph)
- Upload image : endpoint `rest/images?action=initializeUpload`

Pour que ça marche, il faut une app LinkedIn configurée avec :

- **Client ID** / **Client Secret**
- **Redirect URI** (recommandé : l’URL indiquée dans la page de réglages du plugin)
- Les permissions/scopes nécessaires (ex: `w_member_social`, et pour une Page : `w_organization_social`)

⚠️ LinkedIn peut restreindre l’accès à certaines APIs selon le type d’app / validation : si la connexion fonctionne mais que la publication échoue, c’est souvent un problème de permissions.

## 🔑 Pré-requis X

La publication X via l’API dépend des crédits du compte développeur X. Si X renvoie `HTTP 402`, la publication API est bloquée tant que le compte n’a pas de crédits actifs.

Le plugin propose plusieurs voies :

- automatique immédiat à la publication
- retry WP-Cron toutes les 5 minutes pour les articles publiés non encore partagés
- fallback cron serveur/WP-CLI : `wp pksocialsharing retry --network=x --limit=20`
- `Publier maintenant` : publication via l’API X, nécessite des crédits
- `Publier via navigateur` : ouvre `x.com/intent/tweet` avec le texte prérempli, sans consommer de crédits API

## 🔑 Pré-requis Medium

Medium utilise un **integration token**. Dans l’onglet Medium du plugin :

- coller le token Medium
- laisser le plugin détecter le User ID via `/v1/me`, ou le renseigner manuellement
- choisir le statut : `public`, `draft` ou `unlisted`

Le post Medium reprend le contenu HTML de l’article WordPress et renseigne l’URL canonique vers l’article original.

## ⚙️ Réglages importants

- **Author URN** : `urn:li:person:...` (profil) ou `urn:li:organization:...` (Page)
- **Visibilité** : PUBLIC / LOGGED_IN / CONNECTIONS
- **Types de contenu** : cocher `post` (et/ou autres post types publics)
- **Lien court** : utilise `wp_get_shortlink()` si disponible
- **X** : le texte publié peut être personnalisé, et l’interface affiche clairement si l’API est bloquée par des crédits insuffisants
- **Medium** : publication via token, avec URL canonique WordPress

## 🧪 Test rapide

Dans la page de réglages du plugin :

1. Connecter LinkedIn
2. Choisir un article publié
3. Cliquer “Publier maintenant” pour LinkedIn
4. Pour X, cliquer `Publier maintenant` si l’API a des crédits, sinon vérifier le retry cron/CLI ou utiliser `Publier via navigateur`
5. Pour Medium, configurer le token puis cliquer `Publier maintenant` dans l’onglet Medium

## 🧩 Notes

- Le partage “automatique” se déclenche au passage en statut `publish`.
- En cas d’erreur, le plugin n’empêche pas WordPress de publier : il logge un message via `error_log()`.
- Les alertes d’état apparaissent dans l’admin global WordPress et dans la barre admin quand une connexion est en erreur.

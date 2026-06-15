# PK LinkedIn Auto Publish (WordPress)

Plugin WordPress “maison” pour publier automatiquement sur LinkedIn et X à la publication d’un article (image mise en avant + extrait + lien).

**Build actuel :** `extension/pk-linkedin-autopublish-v0.64.zip`

## 📁 Structure du dépôt

```
.
├── src/
│   └── pk-linkedin-autopublish/        # Code du plugin
└── extension/
    └── pk-linkedin-autopublish-v0.64.zip
```

## 🚀 Installation (sur ton WordPress)

1. WP Admin → **Extensions** → **Ajouter** → **Téléverser une extension**
2. Choisir `extension/pk-linkedin-autopublish-v0.64.zip`
3. Activer le plugin
4. WP Admin → **Réglages → LinkedIn Auto Publish**

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

Le plugin propose deux modes :

- `Publier maintenant` : publication via l’API X, nécessite des crédits
- `Publier via navigateur` : ouvre `x.com/intent/tweet` avec le texte prérempli, sans consommer de crédits API

## ⚙️ Réglages importants

- **Author URN** : `urn:li:person:...` (profil) ou `urn:li:organization:...` (Page)
- **Visibilité** : PUBLIC / LOGGED_IN / CONNECTIONS
- **Types de contenu** : cocher `post` (et/ou autres post types publics)
- **Lien court** : utilise `wp_get_shortlink()` si disponible
- **X** : le texte publié peut être personnalisé, et l’interface affiche clairement si l’API est bloquée par des crédits insuffisants

## 🧪 Test rapide

Dans la page de réglages du plugin :

1. Connecter LinkedIn
2. Choisir un article publié
3. Cliquer “Publier maintenant” pour LinkedIn
4. Pour X, cliquer `Publier maintenant` si l’API a des crédits, sinon `Publier via navigateur`

## 🧩 Notes

- Le partage “automatique” se déclenche au passage en statut `publish`.
- En cas d’erreur, le plugin n’empêche pas WordPress de publier : il logge un message via `error_log()`.
- Les alertes d’état apparaissent dans l’admin global WordPress et dans la barre admin quand une connexion est en erreur.

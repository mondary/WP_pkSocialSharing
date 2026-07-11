# apple-translator

Helper Swift natif pour la traduction via **Apple FoundationModels** (macOS 26+).
Aucun serveur, aucune dépendance externe, aucune API key : le modèle est chargé
on-device par le système.

Utilisé par le plugin **PK SocialSharing** comme provider de traduction
`apple_native` pour traduire automatiquement les titres/extraits lors du partage
multi-réseaux multi-langues.

## Pré-requis

- macOS 26+ (Tahoe) — requis pour le framework `FoundationModels`
- Xcode 26+ (ou juste les Command Line Tools)
- Apple Silicon recommandé (M1+)

## Compilation

```bash
chmod +x build.sh
./build.sh
```

Produit `apple-translator` (binaire) à côté de `main.swift`.

## Test manuel

```bash
echo '{"text":"Bienvenue sur mon blog.","source":"fr","target":"en"}' \
  | ./apple-translator
```

Sortie stdout (JSON) :

```json
{"text":"Welcome to my blog.","model":"apple-foundation-model","ms":842}
```

En cas d'erreur, stderr contient `{"error":"..."}` et le code sortie est non nul.

## Branchement avec le plugin

Dans **WP Admin → PK SocialSharing → Dashboard → Traduction** :

1. Provider : `Apple FoundationModels (macOS 26+, local)`
2. Chemin du binaire : `/chemin/absolu/vers/tools/translator/apple-translator/apple-translator`
3. Cliquer **Tester la traduction** pour valider.

Le plugin appelle ensuite le binaire pour chaque partage dont la langue cible
diffère de la langue source. Le résultat est mis en cache en post_meta
(invalidation par hash du contenu) — 1 traduction par version d'article.

## Sécurité

- Le binaire ne lit que stdin et n'écrit que stdout/stderr.
- Aucune donnée n'est envoyée à un serveur distant.
- Le modèle tourne on-device via le framework système FoundationModels.

## Recompilation après mise à jour macOS

Si Apple majore l'API FoundationModels, recompiler suffit :

```bash
./build.sh
```

Aucune action côté WordPress nécessaire (le plugin détecte le binaire à chaque
appel).

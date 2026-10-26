# Changelog

## [2026.10.30] - 2026-10-26

### Added
- LinkedIn : image de partage personnalisable par article via la méta `_pkliap_linkedin_image` (ID d'attachment). Utile quand le GIF d'origine n'a aucune frame exploitable (cas ATOMIC AGENT).

## [2026.10.29] - 2026-10-26

### Fixed
- LinkedIn : les images mises en avant au format GIF sont converties en JPEG (première image) avant upload. LinkedIn refuse les GIF ; le post garde ainsi un grand visuel au lieu d'échouer.

## [2026.10.28] - 2026-10-26

### Added
- X : la traduction Gemini reçoit un budget de caractères calculé pour tenir dans 280 avec le lien ; vérification de la sortie et repli propre, plus de troncature.

### Changed
- LinkedIn : preset « Texte puis lien » en simple retour à la ligne (plus de ligne vide), template personnalisé synchronisé.

## [2026.10.27] - 2026-10-26

### Added
- LinkedIn : option explicite « carte ARTICLE cliquable » / « grande image IMAGE non cliquable », avec les limites LinkedIn affichées.
- LinkedIn : presets de disposition du texte (texte puis lien, lien puis texte, titre + texte + lien, titre + lien + texte).

### Fixed
- Carte ARTICLE : repli sans vignette si LinkedIn refuse l'image (GIF, traitement échoué) au lieu de bloquer le partage.

## [2026.10.26] - 2026-10-26

### Fixed
- LinkedIn : partage ARTICLE via `/rest/posts` avec vignette uploadée. L'API ne scrape plus l'Open Graph ; sans vignette explicite la carte s'affichait sans visuel.

## [2026.10.25] - 2026-10-26

### Changed
- LinkedIn : lien en fin de post, texte complet (limite 2900, extrait 1200, coupe aux frontières de phrase) et carte Open Graph cliquable.
- Dry-run : aperçu complet du texte généré (1200 caractères).

## [2026.10.24] - 2026-10-26

### Added
- Medium : le runner navigateur importe une page traduite (titre + corps HTML) dans la langue choisie, anglais par défaut.
- Route signée `GET /medium-browser/article` avec cache de traduction par article et fallback sur le contenu original.

### Fixed
- Traduction HTML par lots pour les providers à limite de taille (MyMemory).
- Écritures d'options sérialisées (verrou MySQL) : les runners X/Medium ne perdent plus les compteurs et statuts concurrents.
- Garde-fou langue sur X : `/x-browser/next` retarde la publication quand la traduction échoue au lieu de tweeter en français.
- Repli multi-provider pour la traduction des textes (gemini → zai → opencode → deepl → mymemory) et cache invalidé quand le provider change.

## [2026.10.01] - 2026-10-08

### Fixed
- Évite les balises Open Graph/Twitter dupliquées lorsque PK SEO Head est actif.

## [0.10] - 2026-10-07
### Added
- Initial project scaffold

# Changelog

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

=== PK LinkedIn Auto Publish ===
Contributors: pk
Tags: linkedin, social, autopublish
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.51
License: GPLv2 or later

Publie automatiquement vos articles WordPress sur LinkedIn lors de la publication.

== Description ==

Fonctionnalités :

* Publication automatique à la mise en ligne d’un article
* Utilise l’image mise en avant + extrait + lien (shortlink WP si disponible)
* Support du partage sur un profil (urn:li:person:*) ou une page (urn:li:organization:*)
* Page de réglages dans Réglages → LinkedIn Auto Publish

== Installation ==

1. Téléversez le dossier `pk-linkedin-autopublish` dans `wp-content/plugins/`
2. Activez le plugin
3. Configurez votre app LinkedIn (Client ID / Secret, Redirect URI) puis connectez-vous depuis la page de réglages.

== Notes ==

LinkedIn impose des contraintes d’accès à l’API (scopes, validation d’application). Assurez-vous que votre app a bien les permissions nécessaires.

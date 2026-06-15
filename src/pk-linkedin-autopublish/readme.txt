=== PK LinkedIn Auto Publish ===
Contributors: pk
Tags: linkedin, facebook, instagram, threads, x, twitter, social, autopublish
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.73
License: GPLv2 or later

Publie automatiquement vos articles WordPress sur LinkedIn, X, Facebook, Instagram et Threads lors de la publication.

== Description ==

Fonctionnalites :

* Publication automatique sur 5 reseaux : LinkedIn, X (Twitter), Facebook, Instagram, Threads
* Traitement immediat a la publication (ne depend pas de WP-Cron)
* Image mise en avant + extrait + lien (shortlink WP si disponible)
* Personnalisation independante par reseau : prefixe, suffixe, template, ordre du contenu
* Support profil LinkedIn (urn:li:person:*) ou page (urn:li:organization:*)
* Publication X via l'API ou via le navigateur si les credits API X sont epuises
* Facebook : publication sur une Page via le Graph API (Page Access Token)
* Instagram : publication de posts image via l'API Instagram Graph
* Threads : publication via l'API Threads
* Guide de depannage integre dans les reglages (erreurs 403, tokens expires)
* Journal de debug interne visible dans l'admin
* Page de reglages dans Reglages > WP PK SocialSharing

== Installation ==

1. Televersez le dossier `pk-linkedin-autopublish` dans `wp-content/plugins/`
2. Activez le plugin
3. Configurez les reseaux souhaites depuis l'onglet correspondant dans la page de reglages

== Notes ==

* LinkedIn impose des contraintes d'acces a l'API (scopes, validation d'application).
* La publication X via l'API necessite des credits sur le compte developpeur X. Si X renvoie HTTP 402, utilisez "Publier via navigateur" ou rechargez les credits.
* Facebook et Instagram necessitent un Page Access Token Meta avec les permissions `pages_show_list`, `pages_read_engagement` et `pages_manage_posts`.
* Les tokens Meta expirent generalement apres 60 jours. Un guide de regeneration est affiche automatiquement en cas d'erreur.

== Changelog ==

= 0.73 =
* Publication immediate pour Facebook, Instagram et Threads (plus uniquement via WP-Cron)
* Ajout de la methode `maybe_recheck_network_errors` pour rafraichir les erreurs a l'ouverture d'un onglet
* Carte de depannage Facebook amelioree : couvre les erreurs 403 (permissions) et 400 (token expire)
* Instructions elargies : distinction Token utilisateur / Page Token, rappel Instagram
* Permissions Facebook corrigees dans les instructions (ajout de `pages_read_engagement`)
* Support Threads complet (API, reglages, publication, tests)

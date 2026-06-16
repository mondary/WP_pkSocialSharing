=== PK LinkedIn Auto Publish ===
Contributors: pk
Tags: linkedin, facebook, instagram, threads, medium, x, twitter, social, autopublish
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.80
License: GPLv2 or later

Publie automatiquement vos articles WordPress sur LinkedIn, X, Facebook, Instagram, Threads et Medium lors de la publication.

== Description ==

Fonctionnalites :

* Publication automatique sur 6 reseaux : LinkedIn, X (Twitter), Facebook, Instagram, Threads, Medium
* Traitement immediat a la publication + retry WP-Cron toutes les 5 minutes + commande WP-CLI
* Image mise en avant + extrait + lien (shortlink WP si disponible)
* Personnalisation independante par reseau : prefixe, suffixe, template, ordre du contenu
* Support profil LinkedIn (urn:li:person:*) ou page (urn:li:organization:*)
* Publication X via l'API ou via le navigateur si les credits API X sont epuises
* Facebook : publication sur une Page via le Graph API (Page Access Token)
* Instagram : publication de posts image via l'API Instagram Graph
* Threads : publication via l'API Threads
* Medium : publication via integration token, contenu HTML et canonical URL WordPress
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
* Si WP-Cron est desactive, ajoutez un cron serveur avec `wp pksocialsharing retry --network=x --limit=20`.
* Facebook et Instagram necessitent un Page Access Token Meta avec les permissions `pages_show_list`, `pages_read_engagement` et `pages_manage_posts`.
* Les tokens Meta expirent generalement apres 60 jours. Un guide de regeneration est affiche automatiquement en cas d'erreur.
* Medium necessite un integration token disponible dans les reglages Medium du compte.

== Changelog ==

= 0.80 =
* Colonne Partages deplacee a droite apres Date
* Remplacement des initiales par des icones reseaux grises ou colorees selon le statut
* Ajout du permalink Threads pour les nouveaux partages quand l'API le renvoie

= 0.79 =
* Ajout d'une colonne Partages dans la liste des articles pour voir les statuts et liens publies

= 0.78 =
* Facebook publie maintenant l'image mise en avant via l'endpoint photos quand elle existe
* Fallback conserve le partage lien classique quand aucun visuel n'est disponible

= 0.77 =
* Reglages Facebook alignes sur Instagram avec lien Graph Explorer et requete a lancer dans les champs
* Suppression du bloc Depannage Facebook redondant

= 0.76 =
* Interface Facebook simplifiee pour supprimer les doublons d'aide
* Message d'erreur Meta reduit a l'action utile: regenerer le Page Access Token

= 0.75 =
* Suppression de la synchronisation automatique du token Facebook/Instagram
* Tokens Meta désormais stockés et modifiés séparément pour éviter les écrasements croisés
* Version plugin montee a 0.75

= 0.74 =
* Ajout du partage Medium via API officielle (`/v1/me` et `/v1/users/{id}/posts`)
* Ajout d'un retry WP-Cron recurrent toutes les 5 minutes pour les partages non effectues
* Ajout de la commande WP-CLI `wp pksocialsharing retry --network=x --limit=20`
* Version plugin montee a 0.74

= 0.73 =
* Publication immediate pour Facebook, Instagram et Threads (plus uniquement via WP-Cron)
* Ajout de la methode `maybe_recheck_network_errors` pour rafraichir les erreurs a l'ouverture d'un onglet
* Carte de depannage Facebook amelioree : couvre les erreurs 403 (permissions) et 400 (token expire)
* Instructions elargies : distinction Token utilisateur / Page Token, rappel Instagram
* Permissions Facebook corrigees dans les instructions (ajout de `pages_read_engagement`)
* Support Threads complet (API, reglages, publication, tests)

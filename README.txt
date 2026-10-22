Audit Médias - 2.3.2
=====================
Auteur : Benfou - https://www.benfou.fr/
Correctif de détection : shortcodes, arrière-plans et URL CDN.

INSTALLATION / AUDIT A REFAIRE
-----------------------------
Installer le ZIP en remplaçant la version existante (dossier audit-medias).
Les réglages et les tables ne sont pas supprimés lors de la mise à jour.
Cette version change le moteur de recherche des références : un NOUVEL AUDIT
COMPLET est nécessaire. Tableau de bord > Recommencer l'analyse.
L'actualisation des images produits ne relit pas le contenu WPBakery et ne suffit pas.
Les anciens résultats restent consultables, mais les filtres d'absence et les actions
de suppression sont bloqués tant que le nouvel audit n'est pas terminé.
Les protections sont aussi appliquées côté serveur : une ancienne page ouverte
ou une confirmation forcée ne contourne pas ce contrôle.
La progression du nouveau scan reste reprise par lots comme auparavant.

DETECTIONS AJOUTEES
-------------------
- Lecture des attributs de shortcodes sans les exécuter (shortcode_parse_atts).
- bg_image, background_image, variantes responsive, video_image/poster,
  image/image_id/image_url, listes images/image_ids/gallery_ids, etc.
- Exemples couverts : vc_row, vc_column, vc_single_image, vc_gallery,
  vc_images_carousel, image_with_animation ; les attributs image explicites
  sont aussi reconnus dans d'autres shortcodes.
- Les attributs génériques id, width, zindex, columns, etc. ne deviennent pas
  automatiquement des IDs de médias. gallery id est un ID parent, pas une image.
- Une référence par ID n'est retenue que si le média existe dans l'index.
- Analyse des URL CSS url(...) dans les contenus et métadonnées, notamment
  _wpb_shortcodes_custom_css / _wpb_post_custom_css, JSON et données sérialisées.
- Les objets sérialisés ne sont jamais instanciés lors du décodage.
- La source, le shortcode et l'attribut sont enregistrés ; les liens publics
  existants des pages/produits restent disponibles dans les utilisations.

URL CDN
-------
- Jetpack/Photon i0.wp.com à i3.wp.com : identification du domaine d'origine
  et du chemin local, indépendamment de fit, resize, w, ssl et autres paramètres.
- URL absolues, relatives, sans protocole, HTML/JSON échappé, URL encodées.
- Proxy avec URL source dans url/src/image/image_url/img/u ; chemins de
  transformation Cloudflare cdn-cgi/image lorsque la source est identifiable.
- Conservation des vrais noms de fichiers et de leurs extensions. Aucune
  fusion certaine de deux images uniquement parce que leur nom se ressemble.
- Pour un hôte CDN inconnu conservant le chemin uploads, l'association reste
  PROBABLE : elle exclut par prudence la famille des filtres sans référence,
  mais n'est pas présentée comme une preuve certaine de l'origine.
- Les CDN remplaçant aussi la racine du chemin peuvent être déclarés par
  le filtre amf_upload_url_bases (tableau de bases URL publiques).
  Exemple pour développeur :
  add_filter( 'amf_upload_url_bases', function( $bases ) {
      $bases[] = 'https://images.example.test/media';
      return $bases;
  } );
  Déclarer uniquement une base qui pointe réellement vers les uploads du site.

FAMILLES ET SECURITE
-------------------
Une référence d'attachment ou une URL trouvée sur un seul fichier protège
la famille des résultats « Sans référence ». Le correctif 2.3.1 est conservé.
Une utilisation par ID ne gonfle pas artificiellement le compteur URL des
sous-fichiers : elle est affichée dans les utilisations de la famille.
La présence de métadonnées de tailles n'est pas une preuve d'affichage.
Il n'y a aucune suppression automatique, requête externe ou exécution de shortcode.
Les suppressions manuelles existantes restent définitives : sauvegarder avant usage.

LIMITES
-------
L'analyse porte sur les références enregistrées ; ce n'est pas un navigateur
qui exécute le site. Pas de parcours exhaustif de CSS externes, code PHP/JS
ou tables propriétaires. Les CDN aux URLs opaques et non réversibles ne sont
pas devinés. « Aucune référence détectée » n'est jamais une garantie
absolue de non-utilisation. Les erreurs/avertissements du scan doivent être lus.
La version a été testée localement, pas sur la base de données du site client.

HISTORIQUE (les instructions ci-dessus priment pour la 2.3.2)
===========================================================

Audit Médias - 2.3.1
=====================
Correctif : les filtres « Sans autre référence (URLs incluses) » et « Sans référence détectée »
portent sur toute la famille. Une seule URL trouvée sur un fichier original, une miniature
ou une conversion JPG/PNG/WebP/AVIF suffit à exclure la famille de ces filtres.
La famille affiche les occurrences URL même quand aucune autre association n'a été résolue.
Tri par références et compteurs mis à jour ; aucune suppression automatique.
Les preuves proviennent du dernier audit complet. Une déclaration dans les métadonnées
WordPress n'est pas à elle seule une preuve d'utilisation actuelle sur une page publique.
Un résultat « sans référence détectée » n'est pas une preuve absolue de non-utilisation
(notamment pour thèmes/plugins dynamiques, feuilles CSS ou serveurs/CDN externes).
La mise à jour utilise les valeurs du scan existant. Les modifications depuis le dernier
audit exigent un nouveau scan pour les actualiser.

Audit Médias - 2.3.0
=====================
Auteur : Benfou
Site : https://www.benfou.fr/
Dossier et slug : audit-medias
WordPress 6.2 minimum ; PHP 7.4 minimum.

INSTALLATION DEPUIS LA VERSION 2.2.0
----------------------------------
Dans Extensions > Ajouter une extension > Téléverser, choisir le ZIP puis
remplacer la version existante. Aucun changement de dossier n'est requis.
Les analyses, fichiers indexés, réglages et journaux AMF existants sont
conservés. La migration ajoute uniquement une table de travail du plugin :
{prefix}amf_product_images, ainsi que des options amf_product_image_*.

Il n'est PAS nécessaire de recommencer l'inventaire de uploads pour utiliser
la nouvelle interface. À la première ouverture des onglets Médias ou Fichiers
physiques, laisser terminer la vérification distincte des images produits.
Les filtres d'absence attendent sa fin pour ne pas afficher de faux négatifs.
Une analyse complète reste nécessaire pour actualiser les AUTRES références
ou inventorier des fichiers ajoutés/supprimés depuis le dernier audit.

NOUVELLE LECTURE DES TABLEAUX
---------------------------
Média | Images produits | Autres références | Fichiers | Taille | Actions

Images produits : rôle explicite (image principale, galerie, variation),
nom du produit et lien vers le site dans un nouvel onglet. Pour une variation,
WooCommerce fournit, lorsque disponibles, les attributs dans son lien.
Les brouillons/produits privés sont identifiés ; un aperçu remplace le lien
public lorsque c'est approprié et que l'administrateur y est autorisé.

Autres références : emplacements détectés lors du dernier audit complet,
avec leur date affichée au-dessus du tableau. Une description produit ne
compte pas comme une image de galerie. Articles, pages, Elementor, taxonomies,
marques, champs personnalisés et options restent dans cette colonne.
Un réglage ou un modèle interne sans emplacement public connu n'obtient pas
un lien public inventé. Les rapprochements probables sont signalés.

L'absence d'une affectation WooCommerce ne prouve pas que le média est
inutilisé partout. Combiner « Absentes des images produits » et
« Aucune autre référence détectée » pour préparer un contrôle manuel.
Ces filtres ne constituent jamais une autorisation de suppression.

Le rattachement historique post_parent reste dans les détails et n'est pas
une utilisation actuelle. Les révisions ne sont pas présentées comme des
emplacements actifs dans ces deux nouvelles colonnes ; les données de
l'audit général restent conservées.

VÉRIFICATION INDÉPENDANTE DES IMAGES PRODUITS
---------------------------------------------
Le bouton « Actualiser les images produits » lit les valeurs natives
_thumbnail_id et _product_image_gallery des produits et variations,
indépendamment de uploads. Le traitement est progressif, au plus 250 lignes
par requête, avec une pause coopérative entre les lignes après environ
4 secondes. Ce n'est pas une garantie de durée maximale d'une requête SQL.

Les produits et variations en corbeille sont exclus, ainsi que les variations
d'un parent en corbeille. Une variation sans image propre ne crée pas de
fausse affectation distincte de l'image principale du parent.

La vérification est préparée dans une génération temporaire et n'est
validée qu'à la fin. Une interruption peut être reprise. Une erreur de
lecture, un champ natif mal formé ou des modifications détectées pendant
le traitement empêchent de valider une absence.

Les changements de produits et de leurs champs d'images via les hooks
WordPress invalident l'ancien résultat. La page suivante relance la
vérification si nécessaire. Une importation modifiant directement le SQL
sans déclencher les hooks n'est pas détectable par ce mécanisme : utiliser
le bouton Actualiser. Une page déjà ouverte ne suit pas en direct les
changements effectués dans un autre onglet ; la recharger ou actualiser.
Le processus est piloté par le navigateur, sans cron permanent.

Périmètre : affectations natives enregistrées dans WordPress, pas simulation
du rendu visuel. Des galeries fournies dynamiquement par du code, un stockage
WooCommerce personnalisé, des services externes ou une table propriétaire
peuvent échapper à cette vérification. Le scanner général continue à
rechercher les références des extensions dans contenus/meta/termes/options.

FAMILLES ET FICHIERS TECHNIQUES
------------------------------
Une ligne principale représente une famille. Le dépliage montre d'abord
ses utilisations. Un second volet fermé affiche les fichiers physiques :
originaux, miniatures, sauvegardes, anciens formats et conversions.
Ces fichiers sont chargés à la demande, 100 par page dans ce volet.
Cette pagination ne limite PAS l'étendue de la suppression d'une famille.
Une recherche portant sur un seul dérivé conserve le compte et la taille
de toute sa famille. Les liens « Voir le fichier » ouvrent les fichiers
concernés. Un aperçu que le navigateur ne sait pas lire affiche un message
sans modifier le fichier ni les résultats de détection.

Les tris, filtres, compteurs en haut à droite et le nombre de lignes par
page sont conservés. Les réglages restent dans l'onglet Réglages.
L'onglet WooCommerce porte sur la QUALITÉ des images (absentes, trop petites,
tailles manquantes), selon le dernier audit complet. La vérification rapide
des affectations ne recalcule pas ces diagnostics de qualité.

SUPPRESSION MANUELLE : DÉFINITIVE
----------------------------------
L'analyse et l'actualisation des affectations ne modifient aucun média ni
produit. Seules les tables/options du plugin sont alimentées.
La suppression doit être activée explicitement dans Réglages.

Les boutons et actions groupées conservent les protections de la version 2.2 :
scan complet requis, droits administrateur, nonce, contrôle des chemins,
fichiers modifiés depuis le scan, originaux partagés et familles ambiguës.
Une famille rattachée à un média supprime aussi l'entrée de médiathèque.
Les familles rattachées/utilisées demandent une confirmation renforcée et
la saisie de SUPPRIMER. Les références natives WooCommerce sont relues
côté serveur avant l'opération. En cas d'échec de cette lecture, elle est
annulée. Une suppression forcée peut casser un produit ou un autre contenu.

Une sauvegarde base de données + uploads est indispensable avant nettoyage.
La suppression couvre les fichiers de la famille inventoriés dans l'audit
et ceux pris en charge par WordPress pour le média supprimé ; le plugin ne
peut garantir l'exhaustivité des fichiers ajoutés après l'inventaire.
Les actions groupées restent séquentielles et journalisées.

VALIDATION DE CETTE LIVRAISON
----------------------------
Syntaxe des 11 fichiers PHP et du JavaScript contrôlée.
36 contrôles fonctionnels automatisés sur fixtures : images principales,
galeries partagées, variations, remplacements, historique, erreurs, reprise,
changement pendant la vérification, absence de WooCommerce et filtres.
Tests interactifs de l'interface, des liens, du chargement des détails et
des contrôles AJAX avec Chromium et des API WordPress/WooCommerce simulées.
Requêtes exécutées sur un banc SQLite adapté ; ce n'est pas un test
complet sur WordPress/WooCommerce avec MySQL/MariaDB ni sur le site client.
Contrôler d'abord une image témoin remplacée sur un produit avant nettoyage.

RESSOURCES TECHNIQUES
---------------------
https://woocommerce.github.io/code-reference/classes/WC-Product.html
https://woocommerce.github.io/code-reference/classes/WC-Product-Variation.html
https://developer.wordpress.org/reference/functions/check_ajax_referer/

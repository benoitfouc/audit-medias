# Historique des versions — Audit Médias

Versions classées de la plus récente à la plus ancienne. Les dates des versions historiques non documentées ne sont pas ajoutées.

## 2.3.10 - 23 octobre 2026
- Correction du lien « Relancer l’analyse » : ouverture du tableau de bord et véritable redémarrage AJAX après confirmation, avec lien sécurisé par nonce WordPress.
- Affichage de « Étape X/8 » en complément de la barre de progression de l’étape en cours.
- Vue d’ensemble enrichie : médias référencés, fichiers hors médiathèque, familles à examiner, raccourcis et date du dernier audit.
- Bouton « Parcourir les médias » en vert Benfou \`#C0FA39\` après la fin d’une analyse.
- Références Elementor : modèles Header/Footer prioritaires parmi les deux emplacements visibles, sans perdre les autres références ; kits et réglages mieux identifiés.
- Journal des modifications plus compact, avec un lien vers l’historique complet sur GitHub.
- Moteur d’analyse, règles de suppression, base de données et résultats existants conservés ; aucun nouvel audit imposé si l’audit précédent reste compatible.

## 2.3.9 - 23 octobre 2026
- « Ancien rattachement WordPress » toujours visible dans les détails d’une famille, avec le contenu associé et des liens de vérification lorsqu’ils existent.
- Rappel qu’un rattachement historique est un indice, et non une preuve d’utilisation actuelle.
- Logo SVG Benfou affiché sans cartouche blanc sur le bandeau gris \`#EBEBEB\`.
- Aucune modification des règles de suppression, filtres, analyses ou réglages.

## 2.3.8 - 23 octobre 2026
- En-tête gris clair \`#EBEBEB\` avec titre, sous-titre et numéro de version foncés pour un meilleur contraste.
- Filtres « Utilisation » et « Association des fichiers » sur la même ligne que la recherche, sans volet « Filtres avancés ».
- Recherche, tris, actions groupées et anciens liens filtrés conservés ; aucun nouvel audit requis.

## 2.3.7 - 23 octobre 2026
- Lien « Ouvrir Audit Médias » depuis la liste des extensions.
- Interface Benfou noire, blanche et grise, avec touches de vert limitées ; icône personnalisée et boutons mieux alignés.
- Journal des modifications rendu en HTML : titres, listes, gras, liens et code.
- Deux filtres de médias au lieu de trois ; « Sans utilisation détectée » vérifie toute la famille, y compris URL et images produits, et écarte les associations ambiguës.
- WooCommerce : suppression de deux sélecteurs redondants, tout en incluant produits, variations et tous les rôles d’image.
- Réglages, résultats, actions groupées et protections de suppression conservés. L’absence de référence détectée ne garantit pas la non-utilisation.

## 2.3.6 - 22 octobre 2026
- Remplacement de l’API REST GitHub par un manifeste JSON statique public, avec source de secours et sans jeton.
- Contrôles du slug, du canal stable, du tag, de l’URL officielle, de la taille et du SHA-256 du ZIP avant extraction.
- Cache positif d’une heure, cache négatif de dix minutes et prise en compte de \`Retry-After\`.
- Publication du canal statique après vérification publique du ZIP ; archives publiées non écrasées.
- Outil temporaire de transition pour les versions 2.3.3 à 2.3.5, sans installation forcée.
- Moteur d’analyse, interface, réglages et sécurités de suppression inchangés.

## 2.3.5 - 22 octobre 2026
- Intégration de la charte Benfou : logo, noir et blanc, avec accent \`#C0FA39\`.
- Diagnostic des mises à jour : cache, dates, réponses HTTP, quota et version proposée par WordPress.
- Vérification manuelle possible même si le contrôle WordPress.org échoue, sans toucher aux autres mises à jour.
- Publication et tests renforcés ; outil de diagnostic temporaire pour les versions précédentes.
- Aucun changement du moteur d’analyse, de la base de données ou des suppressions.

## 2.3.4 - 22 octobre 2026
- Interface allégée : en-tête compact et onglet Médias centré sur les familles de fichiers.
- Inventaire WordPress et informations techniques conservés dans Réglages → Technique / diagnostic, avec anciennes URL accessibles.
- Filtres avancés repliables et navigation WooCommerce conditionnelle à la présence de WooCommerce.
- Actions groupées au-dessus et au-dessous du tableau, synchronisées et verrouillées pendant une suppression.
- Recherche manuelle des mises à jour dans les réglages, protégée par droits et nonce, sans installation automatique.
- Aucun changement de schéma ou de moteur d’analyse ; résultats compatibles conservés.

## 2.3.3 - 22 octobre 2026
- Présentation, filtres et tableaux modernisés.
- Suppression des attachments davantage protégée : vérification des dérivés référencés par URL et des fichiers ajoutés depuis le scan.
- Première détection des nouvelles GitHub Releases publiques via WordPress \`Update URI\`, sans jeton.

## 2.3.2 - 22 octobre 2026
- Détection améliorée des shortcodes, des arrière-plans WPBakery et des URL CDN, notamment Jetpack Photon.
- Nouvel audit exigé après la modification du moteur de détection.

## 2.3.1
- Correction des familles contenant des références URL vers des fichiers dérivés.

## 2.3.0
- Refonte de l’interface et vérification indépendante des affectations WooCommerce.

## 2.2.x
- Gestion des rattachements historiques WooCommerce.

## 2.1.x
- Actions groupées et tri des colonnes.

## 2.0.x
- Généralisation de l’audit, regroupement par familles et suppressions manuelles.

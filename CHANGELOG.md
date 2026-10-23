# Historique des versions — Audit Médias

# Audit Médias v2.3.10

## Contrôle et progression des audits
- Le lien « Relancer l’analyse » d'un audit obsolète ouvre le tableau de bord et déclenche le vrai redémarrage AJAX après confirmation ; le lien est protégé par un nonce WordPress.
- Affichage « Étape X/8 » pendant les scans, en complément du compteur et de la barre propres à chaque étape.
- Vue d’ensemble enrichie avec médias référencés, fichiers hors médiathèque, familles non associées à examiner, raccourcis utiles et date du dernier audit.
- Après une analyse terminée, le bouton « Parcourir les médias » utilise une touche de vert Benfou `#C0FA39`.

## Références et journal
- Priorité aux modèles Elementor Header/Footer dans les deux emplacements immédiatement visibles. Les autres références restent consultables, sans modification des comptages ni du moteur de détection.
- Identification plus claire des réglages et kits Elementor.
- Dans « Afficher les détails » d'une mise à jour, titres du journal moins hauts et lien vers l'historique complet du dépôt GitHub.

Cette version ne modifie ni les règles de suppression, ni le schéma de base de données, ni les résultats enregistrés. Aucun nouvel audit n'est imposé depuis la version 2.3.9, sauf si un audit antérieur était déjà obsolète.

# Audit Médias v2.3.9

## Rattachement historique toujours visible
- Dans le détail d'une famille, « Ancien rattachement WordPress » devient un encadré permanent et plus lisible, avec le contenu autrefois associé et, si disponible, des liens pour le contrôler.
- Un ancien rattachement WordPress est un **indice de contexte**, pas une preuve d'utilisation actuelle. La distinction entre rattachement historique et vraie référence est conservée.

## En-tête Benfou
- Le logo vectoriel original est désormais présenté directement sur le fond gris `#EBEBEB`, sans cadre ni fond blanc ajouté ; le fichier SVG est déjà transparent.

Le moteur d'audit, la détection des références, la sécurité des suppressions, les filtres, les réglages et les résultats existants ne changent pas. Aucun nouvel audit requis depuis 2.3.8.

## 2.3.8 - 23 octobre 2026

### Confort de lecture
- En-tête gris clair `#EBEBEB` avec titres, sous-titre et numéro de version foncés et lisibles, sans toucher aux alertes de suppression.
- Les deux filtres **Utilisation** et **Association des fichiers** figurent maintenant sur une seule ligne avec la recherche, sans panneau « Filtres avancés ».
- Les filtres et anciennes URL restent fonctionnels, de même que la recherche, les tris et les actions groupées.
- Aucune modification du moteur d'audit, de la base de données ou de la sécurité des suppressions. Aucun nouvel audit exigé depuis 2.3.7.

## 2.3.7 - 23 octobre 2026

### Une interface plus simple
- Accès direct **Ouvrir Audit Médias** depuis la liste des extensions.
- Présentation Benfou principalement noire, blanche et grise ; vert réservé à quelques accents.
- Icône Benfou pour les informations et mises à jour WordPress.
- Journal des modifications formaté en HTML : titres, listes, liens, gras et code.
- Boutons avec texte centré verticalement, y compris **Parcourir les médias**.

### Trouver les médias à contrôler
- Deux filtres : utilisation et lien avec la médiathèque, au lieu de trois.
- **Sans utilisation détectée** vérifie les images produits, les autres références et les URL de tous les fichiers de la famille. Les associations ambiguës sont exclues.
- Les anciens liens filtrés restent reconnus et signalés.
- WooCommerce inclut toujours les produits, les variations et tous les rôles d'image ; retrait des deux sélecteurs redondants.

Les réglages, résultats, actions groupées et protections de suppression sont conservés. Aucun nouvel audit imposé depuis 2.3.6.

**Important :** aucune utilisation détectée n'est pas une garantie de non-utilisation. Vérifier les résultats et sauvegarder avant toute suppression.
Le journal HTML et l'icône sont fournis par le client installé : ils apparaissent après installation de la 2.3.7, pas rétroactivement dans le client 2.3.6.

## 2.3.6 — 22 octobre 2026
- Remplacement de l’API REST GitHub par un manifeste statique public avec une source de secours. Aucun jeton sur les sites.
- Validation du manifeste et du ZIP : slug, canal stable, tag, URL officielle, taille et SHA-256 avant extraction.
- Cache positif d’une heure, cache négatif de dix minutes et respect de Retry-After.
- Publication atomique du canal statique après vérification publique du ZIP. Archives publiées immuables.
- Outil de transition temporaire pour proposer la vraie mise à jour aux versions 2.3.3 à 2.3.5, sans modifier leurs fichiers ni forcer l’installation.
- Interface Benfou, moteur, réglages, résultats et protections de suppression inchangés.

## 2.3.5 — 22 octobre 2026
- Identité Benfou : logo original, noir/blanc et accent #C0FA39, styles limités aux écrans du plugin.
- Diagnostic des mises à jour : cache, dates, HTTP, quota, erreurs et version proposée par WordPress.
- Vérification manuelle GitHub même si WordPress.org échoue, sans installation forcée ni modification des autres offres.
- Publication reproductible, tests renforcés et outil de diagnostic temporaire pour les anciennes installations.
- Aucun changement du moteur, de la base, des suppressions ou des actions groupées.

## 2.3.4 — 22 octobre 2026
- Interface simplifiée : en-tête compact, onglet Médias centré sur les familles et suppression des explications répétées.
- L'inventaire WordPress reste accessible dans Réglages > Technique / diagnostic, avec conservation des anciennes URL et des fiches détaillées.
- Horodatages techniques, statistiques détaillées et périmètre d'analyse regroupés dans le diagnostic. Aucun journal supplémentaire activé.
- Filtres avancés repliables ; le filtre actif reste visible. Colonnes et navigation WooCommerce masquées quand WooCommerce est absent.
- Actions groupées en haut ET en bas du tableau, sélections synchronisées et verrouillage pendant une suppression.
- Les confirmations renforcées et les protections côté serveur sont inchangées.
- Recherche manuelle de mises à jour dans les réglages, avec contrôle des droits et nonce, sans installation automatique.
- Aucun changement de schéma, de réglages enregistrés ou de moteur d'analyse. Un audit compatible existant reste utilisable.

## 2.3.3 — 22 octobre 2026
- Présentation et tableaux modernisés.
- Protection de la suppression des dérivés référencés par URL et des nouveaux fichiers déclarés depuis le scan.
- Détection des mises à jour via les GitHub Releases publiques et Update URI, sans jeton.

## 2.3.2 — 22 octobre 2026
- Amélioration des shortcodes, arrière-plans WPBakery et URL CDN / Jetpack Photon.
- Audit complet exigé pour les anciens résultats.

## 2.3.1
- Correction des familles avec références URL sur les dérivés.

## 2.3.0
- Refonte UX et vérification indépendante WooCommerce.

## 2.2.x
- Gestion des rattachements historiques WooCommerce.

## 2.1.x
- Actions groupées et tri.

## 2.0.x
- Familles de fichiers et suppressions manuelles.

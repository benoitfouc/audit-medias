# Validation locale — 2.3.4

## Base de travail

Code de `main` au commit `88e5e94ea2f87d21ca4c7c92beaeb55e394607da`.
Les fichiers du paquet 2.3.3 ont ete rapproches des SHA de blobs GitHub avant modification.

## Verifications executees

- Syntaxe de tous les fichiers PHP avec PHP 8.4.24 et JavaScript avec Node 22.
- 16 scenarios PHP de rendu avec dependances WordPress simulees : pages principales, inventaire avance, absence de WooCommerce, mode lecture seule, ancien audit, audit en cours, premiere installation, filtres avances et echappement HTML.
- Verifications HTML : IDs uniques, deux barres d'actions, colonnes coherentes sans WooCommerce, maintien des neuf reglages de cadence et des controles de suppression.
- 9 verifications Chromium sur des pages de test : selections haut/bas, annulation, confirmation renforcee, absence de double lancement, file sequentielle, erreur serveur simulee, panneaux repliables et absence de debordement sur mobile.
- Tests du client de mise a jour 2.3.3 ET du client 2.3.4 avec une reponse GitHub 2.3.4 simulee : selection du ZIP exact, cache positif/negatif, rejet des preversions et des URL non autorisees, echappement du changelog.
- Controle des droits et du nonce de la nouvelle recherche manuelle. Aucun changement des preferences de mises a jour automatiques.
- Neuf fichiers du moteur et de la securite de suppression, ainsi que toutes les methodes AJAX de l'administration, sont conserves octet pour octet.

## Limites

Il ne s'agit pas d'un test sur le WordPress / MySQL du client. La base de donnees et les requetes HTTP des fixtures sont simulees. Aucun media reel n'a ete supprime. Les captures de controle utilisent un habillage WordPress simplifie, pas tous les styles ou extensions d'un site de production.

La proposition puis l'installation reelle de 2.3.4 depuis un site en 2.3.3 et MainWP restent a valider apres la publication de la release.

## Publication

Le ZIP doit contenir seulement le dossier `audit-medias/`, avec le fichier principal, `README.txt`, `assets/` et `includes/`.
La version de l'en-tete, `AMF_VERSION`, le tag `v2.3.4` et le nom `audit-medias-v2.3.4.zip` doivent correspondre.
Ne pas installer manuellement le ZIP sur le site pilote avant le test de detection depuis 2.3.3.

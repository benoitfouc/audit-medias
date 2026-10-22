# Publication des versions — Audit Médias

## Préparer une version

1. Effectuer les changements sur une branche, les relire et les tester.
2. Mettre à jour l'en-tête `Version:` et `AMF_VERSION` dans `audit-medias.php`.
3. Compléter `CHANGELOG.md`, `README.txt` et le badge de version de `README.md`.
4. Vérifier la syntaxe PHP/JavaScript et les fonctions sensibles sur un site de préproduction.
5. Fusionner les changements validés sur `main`.

## Publier une release

1. Dans [Releases](../../releases), cliquer sur **Draft a new release**.
2. Créer un tag correspondant exactement à la version, par exemple `v2.3.3`, sur le bon commit.
3. Publier comme version stable, et non comme préversion.
4. Vérifier l'onglet **Actions**, le succès du workflow de fabrication et la présence de `audit-medias-v2.3.3.zip`.
5. Vérifier que le ZIP contient **un seul dossier racine** `audit-medias/`.
6. Tester l'installation ou le remplacement sur un site pilote avant toute diffusion.

**Attention :** le plugin v2.3.2 ne propose pas encore de vérification automatique des mises à jour depuis GitHub. Cette fonction nécessitera une version ultérieure, installée au moins une fois sur les sites concernés.

Ne pas publier de secrets, fichiers clients, bases de données ni données personnelles. Inspecter aussi l'historique Git avant de rendre un dépôt public.

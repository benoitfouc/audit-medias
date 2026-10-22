# Publication des versions — Audit Médias

## Préparer une version

1. Effectuer les changements sur une branche, les relire et les tester.
2. Mettre à jour l'en-tête `Version:` et `AMF_VERSION` dans `audit-medias.php`.
3. Compléter `CHANGELOG.md`, `README.txt` et le badge de version de `README.md`.
4. Vérifier la syntaxe PHP/JavaScript et les fonctions sensibles sur un site de préproduction.
5. Fusionner les changements validés sur `main`.

## Publier une release

1. Dans [Releases](../../releases), cliquer sur **Draft a new release**.
2. Créer un **nouveau tag préfixé par `v`**, par exemple **`v2.3.3`**, sur le bon commit (`2.3.3` seul est invalide).
3. Publier comme version stable, et non comme préversion.
4. Vérifier l'onglet **Actions**, le succès du workflow de fabrication et la présence de `audit-medias-v2.3.3.zip`.
5. Vérifier que le ZIP contient **un seul dossier racine** `audit-medias/`.
6. Tester l'installation ou le remplacement sur un site pilote avant toute diffusion.

**Attention :** La première installation de la v2.3.3 est manuelle. Les versions ultérieures peuvent être détectées depuis WordPress grâce à `Update URI` et aux GitHub Releases publiques.

**Téléchargement correct :** utiliser exclusivement l'asset **`audit-medias-vX.Y.Z.zip`** créé par GitHub Actions. Les liens automatiques **Source code (zip)** et **Source code (tar.gz)** ne conviennent **pas** à l'installation ou à la mise à jour de WordPress. Si aucun asset n'est présent, **ne pas publier/déployer** cette release : ouvrir **Actions**, contrôler les logs, et vérifier que le tag commence bien par `v`.

Ne pas publier de secrets, fichiers clients, bases de données ni données personnelles. Inspecter aussi l'historique Git avant de rendre un dépôt public.

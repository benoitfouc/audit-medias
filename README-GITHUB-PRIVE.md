# Audit Medias - depot GitHub prive Benfou

## 1. Creation du depot dans GitHub Desktop (Windows)

1. Choisissez **File > New repository**. Nom : `audit-medias`.
2. Selectionnez l'emplacement local. Cliquez sur **Create repository**.
3. Copiez **le contenu de ce dossier** dans le repertoire local du nouveau depot (le fichier `audit-medias.php` doit etre a sa racine).
4. Dans GitHub Desktop, entrez `Publication initiale 2.3.2`, cliquez sur **Commit to main/master**.
5. Cliquez sur **Publish repository** et cochez **Keep this code private**.
6. Verifiez sur GitHub que le badge du depot indique **Private**.

Ne publiez jamais de jeton GitHub, cle API, mot de passe ou contenu de `wp-config.php` dans le depot.

## 2. Publier une version

La version de depart `2.3.2` est deja definie dans `audit-medias.php`.

1. Dans GitHub web, depot > **Releases** > **Draft a new release**.
2. Creez le tag `v2.3.2` depuis la branche contenant votre code.
3. Publiez la release (pas en prerelease).
4. L'action GitHub `Publier un ZIP WordPress` cree et ajoute l'asset `audit-medias-v2.3.2.zip` a cette release.
5. Ce ZIP contient exactement le dossier racine `audit-medias/`, necessaire a WordPress.

Pour la prochaine version, augmentez **Version:** et `AMF_VERSION` dans `audit-medias.php` avant le commit, puis creez un nouveau tag de meme version (`v2.3.3`, etc.).

## 3. Attention : le depot prive ne suffit pas aux mises a jour WordPress

Les releases privees GitHub sont inaccessibles sans authentification. GitHub Desktop synchronise le code et GitHub Actions fabrique le ZIP, mais **les sites WordPress ne recevront pas de notifications avant l'ajout d'un verificateur de mises a jour**.

Deux distributions possibles :

- **Acces GitHub prive depuis chaque site** : un jeton finement limite, avec autorisation `Contents: Read-only` sur le seul depot `audit-medias`, est renseigne via une configuration locale sur chaque site. Ne jamais mettre ce jeton dans le code, le depot, ou le ZIP.
- **Passerelle de mises a jour Benfou (recommande sur un parc de plusieurs sites)** : seul le serveur Benfou connait le jeton GitHub ; les clients contactent un endpoint de mises a jour avec une autorisation propre a chaque site. Le code reste prive dans GitHub. Cela exige un petit composant serveur supplementaire et une modification unique du plugin WordPress.

Avant l'activation des mises a jour distantes, choisir la methode et tester sur un site pilote. Conserver la possibilite de revenir au ZIP precedent.

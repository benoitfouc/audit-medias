# Canal de mises a jour statique

Le client WordPress ne contacte jamais api.github.com. Le manifeste officiel se trouve a :

- https://raw.githubusercontent.com/benoitfouc/audit-medias/updates/update.json
- secours : https://github.com/benoitfouc/audit-medias/releases/latest/download/audit-medias-update.json

Les deux sources utilisent HTTPS et les fichiers publics de GitHub, pas le quota REST de 60 requetes/heure/IP. Les services statiques ont leurs propres limites et peuvent aussi etre indisponibles. Les restrictions WP_HTTP_BLOCK_EXTERNAL restent applicables.

Le JSON annonce uniquement une release stable dont le ZIP a ete produit et verifie publiquement. Le client refuse un slug, une URL ou un tag inattendu. L'empreinte SHA-256 detecte une corruption du paquet ; ce n'est pas une signature independante du compte GitHub, qui reste la racine de confiance. Proteger ce compte et les droits d'ecriture du depot.

## Publication

Apres tests et fusion, creer une release avec un tag vX.Y.Z. Le workflow release.yml controle le code, construit le ZIP reproductible, ajoute audit-medias-update.json a la release, verifie les octets telechargeables puis avance la branche updates. Ne jamais supprimer cette branche de distribution. Les reutilisations du workflow ne remplacent pas de binaires existants et une ancienne version ne fait pas reculer le canal.

Le workflow propose aussi un lancement manuel (Actions > Publication du ZIP et du manifeste statique > Run workflow), avec un tag stable existant.

## GitHub Pages facultatif

La branche updates ne contient que update.json, index.html et .nojekyll. Elle peut etre publiee via Settings > Pages > Deploy from a branch > updates / (root). L'activation n'est pas necessaire au fonctionnement : le client lit directement le fichier statique GitHub. Les commits realises avec GITHUB_TOKEN ne declenchent pas automatiquement une reconstruction Pages ; le miroir Pages ne doit donc pas etre configure comme source du client sans un workflow de deploiement Pages adapte.

## Transition

Le ZIP benfou-transition-audit-medias-v1.0.0.zip embarque exactement le meme client statique que le plugin. Il n'est pas un simple diagnostic : lorsqu'il est actif sur 2.3.3 a 2.3.5, il remplace uniquement les deux callbacks API d'Audit Medias par la source statique officielle. Les autres extensions et preferences automatiques sont conservees. Le bouton protégé par droits et nonce relance WordPress et merge uniquement l'offre authentique Audit Medias si necessaire. L'outil ne touche aucun fichier et ne lance aucune installation. A partir de 2.3.6, il n'enregistre plus de callbacks de mise a jour et doit etre supprime.

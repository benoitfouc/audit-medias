# Politique de sécurité — Audit Médias

La sécurité des fichiers WordPress et la fiabilité des suppressions sont prioritaires.

## Signaler une vulnérabilité

**N'ouvrez pas d'issue publique exposant une vulnérabilité exploitable.**

Utilisez le **signalement privé de vulnérabilités GitHub** dans l'onglet **Security**, s'il est activé. Sinon, contactez le mainteneur via [benfou.fr](https://www.benfou.fr/).

Indiquez la version du plugin, les prérequis, les étapes de reproduction et l'impact observé. Ne transmettez pas de secrets, de mots de passe, ni de données de clients dans un ticket public.

## Recommandations

- Sauvegarder la base de données **et** `wp-content/uploads` avant suppression.
- Garder les suppressions désactivées hors intervention supervisée.
- Vérifier manuellement les références avant de supprimer des familles de fichiers.
- Tenir WordPress, PHP et les extensions à jour.
- Tester les nouvelles versions sur un site pilote.

**Limite :** « aucune référence détectée » ne garantit jamais qu'un média est inutilisé.

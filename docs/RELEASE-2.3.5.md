# Audit Médias v2.3.5

## Identité Benfou
- Logo vectoriel officiel, noir / blanc / gris et touches vert #C0FA39.
- Boutons, navigation, tableaux, filtres et progression harmonisés ; suppressions toujours rouges.
- Police Montserrat prioritaire si disponible localement, sinon police système. Aucun fichier de police ni chargement Google Fonts dans le paquet. La signature du logo reste vectorielle.

## Mises à jour
- Diagnostic repliable : code HTTP, dates, cache, version GitHub, proposition WordPress et quota API.
- Messages distincts pour erreur réseau, quota GitHub, JSON invalide et ZIP officiel manquant.
- Recherche manuelle avec droits et nonce : renouvelle le cache, sans installation automatique.
- La recherche manuelle interroge GitHub même si le contrôle WordPress.org échoue ; seule l'offre Audit Médias est synchronisée.
- Les préférences d'installation automatique ne sont pas modifiées.

Le moteur d'audit, les protections de suppression, les réglages et les résultats existants sont conservés. Aucun nouvel audit imposé depuis la 2.3.4.

**Installation manuelle :** uniquement `audit-medias-v2.3.5.zip`, jamais `Source code (zip)`. Dossier : `audit-medias/`.
Un ancien client 2.3.3/2.3.4 ne bénéficie pas du nouveau diagnostic avant installation. La publication ne corrige pas à distance son cache ni une restriction réseau.

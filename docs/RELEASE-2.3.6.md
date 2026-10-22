# Audit Médias v2.3.6

## Correction des mises à jour sur les hébergements mutualisés
- La vérification ne passe plus par api.github.com : elle lit un manifeste JSON statique public.
- Source principale : branche updates du dépôt, servie par raw.githubusercontent.com. Secours : manifeste joint à la dernière release publique.
- Aucun jeton sur les sites. Aucun contournement des restrictions réseau configurées dans WordPress.
- Cache positif d'une heure ; échec pendant dix minutes, avec respect de Retry-After lorsque fourni.
- Validation stricte du slug, du canal stable, du tag, de l'URL officielle et de l'empreinte. Taille et SHA-256 du ZIP vérifiés avant extraction lors d'une mise à jour distante.
- La publication produit le ZIP et le manifeste, vérifie leur accès public puis actualise le canal statique. Une archive déjà publiée n'est jamais remplacée.

La charte Benfou, les tableaux, le moteur de scan, les actions groupées et les protections de suppression sont inchangés. Aucun nouvel audit requis depuis 2.3.5. Les réglages d'installation automatique ne sont pas modifiés.

## Depuis 2.3.3 / 2.3.4 / 2.3.5 bloquée par l'API
Installer l'extension temporaire **Benfou - Transition des mises a jour Audit Medias**, puis ouvrir **Outils > Transition Audit Médias** et rechercher la mise à jour. Elle remplace uniquement la source du client Audit Médias, n'installe rien et ne modifie pas les fichiers du plugin.
Effectuer ensuite la mise à jour proposée dans WordPress, puis désactiver et supprimer l'outil de transition. Il devient inactif automatiquement à partir de 2.3.6.

Installation de secours : utiliser uniquement **audit-medias-v2.3.6.zip**, jamais **Source code (zip)**. Sauvegarder avant déployer.

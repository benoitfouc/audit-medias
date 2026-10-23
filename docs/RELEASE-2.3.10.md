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

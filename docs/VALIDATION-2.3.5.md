# Validation 2.3.5

Base : main 6569f8ad9c08290ae2570ece14f3e0c86f5f1d4c / ZIP officiel 2.3.4, SHA256 0c956017befdeb90f52634379163f5bc453253f952a6e61ac6953e58febb32f2.

- Syntaxe PHP 8.4.24 locale et JavaScript Node 22.
- 59 assertions du client de mise à jour avec HTTP/WordPress simulés : cache, URLs canoniques, réponses invalides, quotas, droits, nonce, échappement, absence d'installation et préservation des autres plugins.
- 17 rendus PHP, dont absence de WooCommerce, lecture seule, anciens audits, première utilisation et diagnostic d'erreur.
- Contrôles Chromium desktop et mobile : logo, absence d'erreur JavaScript, synchronisation des sélections et actions haut/bas, défilement horizontal sans débordement du corps de page.
- 10 fichiers des moteurs, de la base, des suppressions et des références, les méthodes AJAX de l'administration et admin.js inchangés.
- Build ZIP reproductible avec un seul dossier racine audit-medias ; aucun test, workflow ou fichier de police embarqué.

Limites : fonctions WordPress, base et HTTP simulés. Aucun test sur le site de production, aucune suppression de média réel. Les captures utilisent un habillage WordPress minimal, pas les styles de toutes les extensions des clients. La cause précise du retard de détection sur le site pilote reste à relever sur ce site.

# Validation 2.3.6

Base : main 31a4d635061b438e88abac868fcd93bf9b22ecea et ZIP officiel 2.3.5 (SHA-256 b1ff4192381fa773dd99a3150e6d8e6b64a8383e9020aa5fdfcfa6efea2873f1).

Tests locaux PHP 8.4 : 84 assertions du client statique, du cache, des erreurs, des URLs, du checksum et du maintien des autres offres. 37 assertions de transition reparties sur 2.3.3, 2.3.5, 2.3.6, 2.3.7 et un mauvais dossier. Les fonctions WordPress et HTTP sont simulees ; aucun serveur client ni media reel touche.

18 fichiers du moteur et de presentation sont verifies octet pour octet contre 2.3.5 par tests/protected-files.json. Aucun changement de schema ou de reglages du scan. Les caches du nouveau transport sont distincts des anciens caches API.

La CI execute la syntaxe, ces tests et les 17 scenarios de rendu existants sur PHP 7.4, 8.2, 8.4 et 8.5. Les tests des paquets verifient la structure, le manifeste et le contenu du pont temporaire. Le workflow de publication verifie les octets des assets telechargeables avant de promouvoir le manifeste statique.

Limites : aucun test de mise a jour sur le WordPress/MySQL ou MainWP de production du client. Le succes des simulations et de la publication ne demontre pas que les permissions de fichiers, le pare-feu et les filtres tiers du site permettent l'installation. Le premier passage 2.3.3 -> 2.3.6 reste a tester apres sauvegarde.

Le SHA-256 est un controle d'integrite, pas une signature independante de GitHub. Aucune police distribuee et aucune installation automatique activee.

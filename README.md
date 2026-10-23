<div align="center">

# Audit Médias

### Comprendre vos images avant de décider quoi supprimer.

**Médiathèque WordPress · Références · Familles de fichiers · Nettoyage contrôlé**

![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-21759B?logo=wordpress&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)
![Version](https://img.shields.io/badge/version-2.3.10-C0FA39)

Développé et maintenu par **[Benfou](https://www.benfou.fr/)**

[Fonctionnalités](#fonctionnalités) · [Installation](#installation) · [Sécurité](#suppression-et-sécurité) · [Versions](../../releases)

</div>

---

## Pourquoi Audit Médias ?

Un site WordPress accumule des originaux, miniatures, conversions et sauvegardes. **La présence d'une image en médiathèque ne prouve pas qu'elle est utilisée ; son absence ne prouve pas qu'elle est inutile.**

Audit Médias rapproche les enregistrements WordPress et les images de `wp-content/uploads`, recherche leurs utilisations et regroupe les variantes en familles.

> [!IMPORTANT]
> **Aucune référence détectée n'est jamais une garantie de non-utilisation.** Contrôlez les résultats avant toute suppression.

## Fonctionnalités

| Domaine | Fonctionnalités |
| --- | --- |
| Inventaire | Médias WordPress et fichiers physiques, y compris les fichiers orphelins |
| Familles | Originaux, miniatures, conversions WebP/AVIF et sauvegardes |
| Références | Identifiants, URL, contenus, métadonnées, taxonomies et options |
| Constructeurs | Gutenberg, Elementor, WPBakery et nombreuses images d'arrière-plan |
| WooCommerce | Images principales, galeries, variations et diagnostics d'images |
| CDN | Certaines réécritures identifiables, notamment Jetpack Photon |
| Interface | Recherche, filtres, tris, pagination, aperçus et utilisations dépliables |
| Traitement | Analyse AJAX par lots, pause et reprise |
| Nettoyage | Suppressions manuelles, confirmations renforcées et journalisation |

**Une référence sur un seul fichier protège toute sa famille.** Les simples déclarations de tailles et les rattachements historiques ne sont pas assimilés à des utilisations actuelles.

## Une interface centrée sur l'essentiel

Depuis la **2.3.4**, l'onglet **Médias** regroupe les familles de fichiers. Les actions groupées sont accessibles au-dessus et au-dessous du tableau, avec sélection synchronisée.

Les réglages fins, dates techniques, statistiques détaillées et limites de détection sont disponibles dans **Réglages > Technique / diagnostic**. L'ancien inventaire WordPress y reste accessible, notamment pour les médias dont tous les fichiers ont disparu. Les anciennes URL sont conservées.

## Installation

1. Dans [GitHub Releases](../../releases), télécharger uniquement l'asset **`audit-medias-vX.Y.Z.zip`**.
2. Dans WordPress : **Extensions > Ajouter une extension > Téléverser une extension**.
3. Installer puis activer. Pour une installation existante, choisir **Remplacer l'extension actuelle**.
4. Ouvrir **Outils > Audit Médias**, lancer une analyse complète puis examiner les utilisations.

> [!CAUTION]
> **Ne pas installer `Source code (zip)` ou `Source code (tar.gz)`.** Ces archives GitHub peuvent créer une deuxième copie du plugin. Le ZIP installable contient un unique dossier racine **`audit-medias/`**, jamais un dossier suffixé par la version.

Si l'asset n'apparaît pas, vérifier [GitHub Actions](../../actions) et le tag, qui doit commencer par **`v`**.

**Prérequis déclarés :** WordPress 6.2 et PHP 7.4 minimum. WooCommerce et les constructeurs de pages sont facultatifs.

## Identité Benfou

Logo original fourni par Benfou, interface claire noir/blanc et accent `#C0FA39`. Les alertes et suppressions gardent leurs couleurs de sécurité. Aucun chargement de police distante : Montserrat lorsqu’elle est disponible localement, sinon police système. [Détails](docs/BRANDING.md).

## Mises à jour

**Depuis la 2.3.6, les sites lisent un manifeste statique public, sans API REST GitHub ni jeton.** Les informations sont publiées après vérification du ZIP. La taille et le SHA-256 sont contrôlés avant extraction lors des mises à jour distantes. [Fonctionnement et transition depuis 2.3.3](docs/STATIC-UPDATES.md).

La **2.3.3** a introduit la détection des releases publiques via le mécanisme natif WordPress `Update URI`, sans jeton GitHub sur les sites. Elle doit être installée une première fois pour recevoir les propositions suivantes.

À partir de la **2.3.4**, **Réglages > Rechercher une mise à jour** renouvelle les métadonnées de mise à jour. Cette action **n'installe rien** et ne change pas les préférences de mises à jour automatiques du site.

La **2.3.5** affiche un diagnostic dans les réglages : version locale, cache, réponse HTTP, quota GitHub et proposition enregistrée par WordPress. La vérification manuelle interroge aussi GitHub si le contrôle WordPress.org échoue auparavant. Cela ne prouve pas la cause d’un blocage sur un site donné.

[Historique](CHANGELOG.md) · [Procédure de publication](docs/PUBLICATION.md) · [Validation 2.3.6](docs/VALIDATION-2.3.6.md)

## Suppression et sécurité

Les suppressions sont désactivées par défaut, réservées aux utilisateurs autorisés et protégées par nonce. Les contrôles côté serveur vérifient le scan, les chemins, les fichiers apparus depuis l'analyse et les confirmations requises. Les opérations sont journalisées.

> [!CAUTION]
> Les suppressions sont **définitives**. Sauvegarder la base de données et le dossier `uploads`, puis tester sur un site pilote. Aucun fichier n'est supprimé automatiquement à la fin d'un scan.

La détection n'est pas exhaustive : contenus dynamiques, CSS/JavaScript externes, tables propres à certaines extensions et URL CDN opaques peuvent échapper à l'audit.

Pour signaler une vulnérabilité, utiliser la [procédure confidentielle](SECURITY.md), pas une issue publique exploitable. Les suggestions et anomalies non sensibles peuvent être proposées dans les [Issues](../../issues).

---

<div align="center">**Audit Médias** · Conçu et maintenu par **[Benfou](https://www.benfou.fr/)**</div>


## Version 2.3.7


### Une interface plus simple
- Accès direct **Ouvrir Audit Médias** depuis la liste des extensions.
- Présentation Benfou principalement noire, blanche et grise ; vert réservé à quelques accents.
- Icône Benfou pour les informations et mises à jour WordPress.
- Journal des modifications formaté en HTML : titres, listes, liens, gras et code.
- Boutons avec texte centré verticalement, y compris **Parcourir les médias**.

### Trouver les médias à contrôler
- Deux filtres : utilisation et lien avec la médiathèque, au lieu de trois.
- **Sans utilisation détectée** vérifie les images produits, les autres références et les URL de tous les fichiers de la famille. Les associations ambiguës sont exclues.
- Les anciens liens filtrés restent reconnus et signalés.
- WooCommerce inclut toujours les produits, les variations et tous les rôles d'image ; retrait des deux sélecteurs redondants.

Les réglages, résultats, actions groupées et protections de suppression sont conservés. Aucun nouvel audit imposé depuis 2.3.6.

**Important :** aucune utilisation détectée n'est pas une garantie de non-utilisation. Vérifier les résultats et sauvegarder avant toute suppression.
Le journal HTML et l'icône sont fournis par le client installé : ils apparaissent après installation de la 2.3.7, pas rétroactivement dans le client 2.3.6.

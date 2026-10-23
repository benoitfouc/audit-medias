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

Depuis la **2.3.6**, Audit Médias utilise le système natif de mises à jour WordPress et un [manifeste statique public](docs/STATIC-UPDATES.md), sans API REST GitHub ni jeton à configurer sur les sites. L’intégrité des ZIP est vérifiée avant leur extraction lors des mises à jour distantes.

Les nouvelles versions sont proposées automatiquement par WordPress et MainWP lors des vérifications habituelles. Une vérification immédiate reste possible dans **Audit Médias → Réglages → Mises à jour** ; elle n’installe rien et ne change pas les préférences de mise à jour automatique.

[Documentation des mises à jour](docs/STATIC-UPDATES.md) · [Procédure de publication](docs/PUBLICATION.md) · [Toutes les releases](../../releases)

## Historique des versions

| Version | Date | Principales évolutions |
| --- | --- | --- |
| 2.3.10 | 23 octobre 2026 | Relance réelle des audits ; progression par étapes ; vue d’ensemble enrichie ; références Elementor mieux hiérarchisées. |
| 2.3.9 | 23 octobre 2026 | Rattachement historique toujours visible ; logo Benfou transparent. |
| 2.3.8 | 23 octobre 2026 | En-tête gris lisible ; filtres des médias côte à côte. |
| 2.3.7 | 23 octobre 2026 | Charte Benfou épurée ; filtres et WooCommerce simplifiés ; journal HTML ; icône et boutons améliorés. |
| 2.3.6 | 22 octobre 2026 | Mises à jour par manifeste public, sans quota API GitHub ; vérification SHA-256 des ZIP. |
| 2.3.5 | 22 octobre 2026 | Identité Benfou ; diagnostic approfondi des mises à jour. |
| 2.3.4 | 22 octobre 2026 | Interface recentrée sur les familles ; informations techniques déplacées ; actions groupées haut et bas. |
| 2.3.3 | 22 octobre 2026 | Interface et tableaux modernisés ; suppression plus sûre ; premières mises à jour GitHub. |
| 2.3.2 | 22 octobre 2026 | Meilleure détection WPBakery, images de fond et URL CDN. |
| 2.3.1 | Date non documentée | Correction du filtrage des familles avec références URL sur les dérivés. |
| 2.3.0 | Date non documentée | Refonte UX et vérification indépendante des affectations WooCommerce. |
| 2.2.x | Date non documentée | Gestion des rattachements historiques WooCommerce. |
| 2.1.x | Date non documentée | Actions groupées et tri. |
| 2.0.x | Date non documentée | Familles de fichiers et suppressions manuelles. |

**Détails de chaque version :** [consulter le changelog complet](CHANGELOG.md).

## Suppression et sécurité

Les suppressions sont désactivées par défaut, réservées aux utilisateurs autorisés et protégées par nonce. Les contrôles côté serveur vérifient le scan, les chemins, les fichiers apparus depuis l'analyse et les confirmations requises. Les opérations sont journalisées.

> [!CAUTION]
> Les suppressions sont **définitives**. Sauvegarder la base de données et le dossier `uploads`, puis tester sur un site pilote. Aucun fichier n'est supprimé automatiquement à la fin d'un scan.

La détection n'est pas exhaustive : contenus dynamiques, CSS/JavaScript externes, tables propres à certaines extensions et URL CDN opaques peuvent échapper à l'audit.

Pour signaler une vulnérabilité, utiliser la [procédure confidentielle](SECURITY.md), pas une issue publique exploitable. Les suggestions et anomalies non sensibles peuvent être proposées dans les [Issues](../../issues).

---

<p align="center"><strong>Audit Médias</strong> · Conçu et maintenu par <strong><a href="https://www.benfou.fr/">Benfou</a></strong></p>

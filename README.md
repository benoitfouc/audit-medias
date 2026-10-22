<div align="center">

# 🔎 Audit Médias

### Identifiez les images utilisées et les fichiers inutiles de votre site WordPress.

**Audit de la médiathèque · Références réelles · Familles de fichiers · Suppression manuelle contrôlée**

[![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)
![Version](https://img.shields.io/badge/version-2.3.3-4d7c00)
![Maintenance](https://img.shields.io/badge/maintenance-active-2ea44f)

**Développé et maintenu par [Benfou](https://www.benfou.fr/)**

[✨ Fonctionnalités](#-fonctionnalités) · [📦 Installation](#-installation) · [🔐 Sécurité](#-suppression-et-sécurité) · [🏷️ Versions](#️-versions-et-mises-à-jour)

</div>

---

## Pourquoi Audit Médias ?

Un site WordPress accumule progressivement des originaux, miniatures, conversions, sauvegardes et fichiers parfois oubliés. **Un fichier absent de la médiathèque n'est pas forcément inutilisé ; inversement, un média encore présent en médiathèque n'est pas forcément affiché sur le site.**

Audit Médias rapproche les enregistrements WordPress et les fichiers de `wp-content/uploads`, recherche leurs utilisations, puis regroupe les variantes en **familles** pour faciliter une vérification avant nettoyage.

> [!IMPORTANT]
> **« Aucune référence détectée » n'est jamais une preuve absolue de non-utilisation.** Les références générées dynamiquement, certaines extensions et des fichiers externes peuvent échapper à l'audit.

## ✨ Fonctionnalités

| Domaine | Fonctionnalités |
| --- | --- |
| 🗂️ **Médiathèque WordPress** | Attachments, métadonnées, originaux et tailles déclarées |
| 💾 **Fichiers physiques** | Analyse récursive des images dans `uploads`, y compris les fichiers orphelins |
| 🧩 **Familles de fichiers** | Regroupement des originaux, miniatures, versions WebP/AVIF, variantes et sauvegardes |
| 🔗 **Références** | Recherche par identifiant WordPress, chemin, URL et références dans les contenus et réglages |
| 🏗️ **Constructeurs** | Gutenberg, Elementor, WPBakery et détection de nombreuses images d'arrière-plan |
| 🛒 **WooCommerce (optionnel)** | Images principales, galeries, variations et incohérences de fichiers |
| 🌐 **URL / CDN** | Reconnaissance de certaines réécritures d'URL, notamment Jetpack Photon |
| 📊 **Administration** | Tableau de bord, filtres, recherche, tris, pagination, prévisualisations et détails |
| ⚙️ **Performance** | Scan AJAX par lots paramétrables, avec pause et reprise |
| 🛡️ **Nettoyage** | Suppressions manuelles, confirmations renforcées et journalisation |

**Règle de protection :** lorsqu'une référence réelle est détectée sur **un seul fichier** d'une famille, toute la famille doit être considérée comme référencée. Les simples déclarations de tailles dans les métadonnées ne constituent pas une preuve d'utilisation.

## 🧭 Comment l'utiliser ?

1. Installer et activer l'extension.
2. Ouvrir **Outils → Audit Médias** dans WordPress.
3. Lancer un audit complet et laisser les lots se terminer.
4. Examiner les onglets **Médias**, **Fichiers physiques**, **WooCommerce**, **Erreurs / avertissements** et **Réglages**.
5. Contrôler les références et les fichiers candidats, puis effectuer une sauvegarde avant toute suppression.

L'audit lui-même est **non destructif** : aucun fichier n'est supprimé à la fin d'un scan.

## 📦 Installation

Le ZIP installable doit contenir le dossier racine `audit-medias/`, avec `audit-medias.php` à l'intérieur.

1. Télécharger le ZIP dans les [**GitHub Releases**](../../releases), après publication d'une version.
2. Dans WordPress, ouvrir **Extensions → Ajouter une extension → Téléverser une extension**.
3. Installer et activer le plugin.
4. Pour une installation existante, **remplacer la version en place** : ne pas créer un second dossier de plugin.

| Compatibilité déclarée | Minimum |
| --- | --- |
| WordPress | 6.2 |
| PHP | 7.4 |
| WooCommerce | Facultatif |
| Elementor / WPBakery | Facultatifs |

## 🔐 Suppression et sécurité

- La suppression est **désactivée par défaut** et nécessite une activation volontaire.
- Les actions AJAX sont protégées par des contrôles de droits administrateur et de nonce.
- Les suppressions nécessitent un audit terminé ; des contrôles supplémentaires sont effectués côté serveur.
- Les chemins sont vérifiés pour empêcher la suppression de fichiers hors de `uploads`.
- Les familles référencées, rattachées ou ambiguës nécessitent une confirmation renforcée.
- Les suppressions sont consignées dans un journal.

> [!CAUTION]
> Les suppressions sont **définitives** et peuvent casser du contenu si une référence échappe à l'analyse. Sauvegardez **la base de données et le dossier uploads** et privilégiez un site de préproduction pour les nettoyages importants.

Une revue du code ne constitue pas un audit de sécurité exhaustif. Pour signaler une vulnérabilité de façon confidentielle : [**SECURITY.md**](SECURITY.md).

## 🔍 Limites de la détection

Le plugin inspecte principalement les données enregistrées dans WordPress. Il n'exécute pas le rendu du site et ne peut garantir l'identification des images insérées par du PHP/JavaScript externe, des CSS hors base de données, des CDN à URL opaque ou des extensions stockant leurs données dans des tables propriétaires.

Les rattachements historiques à des articles et les métadonnées de tailles ne sont pas assimilés automatiquement à des utilisations actives.

## 🏷️ Versions et mises à jour

- [**Historique des versions**](CHANGELOG.md)
- [**Procédure de publication**](docs/PUBLICATION.md)
- [**Releases téléchargeables**](../../releases)

Les versions sont identifiées par des tags comme `v2.3.2` ou `v2.3.3`. GitHub Actions prépare le ZIP WordPress à partir du tag publié.

> [!NOTE]
> La v2.3.3 intègre la détection des nouvelles GitHub Releases publiques avec le mécanisme natif WordPress. Cette version doit être installée une première fois manuellement pour activer cette fonction.ndre le dépôt public simplifie leur distribution, mais un client de mise à jour devra être intégré à l'extension avant que WordPress et MainWP puissent proposer les nouvelles versions directement.

## 💬 Retours et contributions

Les idées et anomalies non sensibles peuvent être signalées dans les [**Issues**](../../issues). **Ne publiez pas de vulnérabilité exploitable dans une issue publique** : utilisez la [procédure de signalement privé](SECURITY.md).

---

<div align="center">

**Audit Médias**, une extension développée et maintenue par **[Benfou](https://www.benfou.fr/)**

*Comprendre vos médias avant de décider quoi supprimer.*

</div>

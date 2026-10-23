# Validation 2.3.7 - 23 octobre 2026

Base : 2.3.6, commit ff593ab687a8f9df74b84dfaba65b437a6aedbb4.
Le transfert prepare precedemment etait incomplet. Les changements ont ete
reconstruits sur cette base, sans reprendre de contenu endommage.

## Controles locaux executes
- Syntaxe PHP 8.4.24 et JavaScript Node 22.
- 84 assertions du client statique et du controle des empreintes.
- 50 assertions presentation, journal HTML, filtres et compatibilite des anciens liens.
- 37 assertions de transition, y compris inactivite en 2.3.6+ et mauvais dossier.
- 21 scenarios de rendu avec dependances WordPress simulees.
- Chromium hors reseau : selecteurs reduits, selection haut/bas synchronisee,
  annulation conservee, centrage vertical et couleurs du bouton principal,
  ancien filtre visible, absence de debordement de page a 390 px et pas d'erreur JS.
- Captures desktop/mobile relues. Habillage WordPress simplifie et donnees simulees.
- Verification du ZIP reproductible, des chemins, de l'identite des sources,
  de l'absence de polices distribuees et des empreintes des fichiers proteges.

## Preservation
Les fichiers du moteur de scan, de base, de detection et de suppression,
le client statique et le JavaScript existant n'ont pas ete modifies.
Les seuls changements dans la vue SQL ajoutent les conditions du filtre
simplifie. Les anciennes conditions restent en place pour les anciens liens.
Aucune migration ou reinitialisation des donnees. Aucun indicateur de
compatibilite WordPress non teste ajoute.

## Limites
Aucun acces au WordPress/MySQL de production ni suppression de medias reelle.
Le CI execute ensuite ces tests sur PHP 7.4, 8.2, 8.4 et 8.5 avant publication.
Le rendu HTML des notes et l'icone sont fournis par le client installe ;
la 2.3.6 ne peut pas les afficher retroactivement avant sa mise a jour.

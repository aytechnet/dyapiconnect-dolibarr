# DyaPiConnect pour [Dolibarr ERP CRM](https://www.dolibarr.org)

DyaPiConnect relie votre Dolibarr aux micro-services [DyaPi](https://dyapi.io), qui interconnectent
vos logiciels e-commerce / réservation avec Dolibarr et d'autres plateformes.

## E-commerce &harr; Dolibarr

Synchronisez commandes, factures, expéditions, stocks et paiements entre Dolibarr et un e-commerce
(PrestaShop, WooCommerce…).
Choisissez un mode (Minimaliste, Proposition, Facture, E-Commerce) pour pré-activer les bons modules
Dolibarr.

## Facturation électronique (Factur-X)

À la validation d'une facture client, DyaPiConnect demande à DyaPi de transformer son PDF en facture
**Factur-X (PDF/A-3, EN 16931)** conforme et la réenregistre dans la facture. L'identité vendeur
(raison sociale, SIREN/SIRET, TVA, adresse, IBAN) est récupérée automatiquement depuis votre Dolibarr
au rattachement et tenue à jour — vous la gérez dans Dolibarr, jamais en double. La transmission vers
une plateforme agréée (PDP) est l'étape suivante, pour l'obligation française (réception sept. 2026,
émission 2026–2027).

## Confidentialité

Vos identifiants DyaPi (clé d'API, secret) restent sur ce serveur et ne sont jamais affichés.
Connectez-vous via **Rattacher à mon compte DyaPi** depuis la page de configuration du module : vous
vous connectez sur DyaPi et confirmez le rattachement.

## Traductions

Les traductions peuvent être complétées en éditant les fichiers du répertoire *langs*.

## Licences

### Code principal

GPLv3. Voir le fichier COPYING pour plus d'informations.

### Documentation

Tous les textes et readmes sont sous licence GFDL.

Éditeur : [Aytechnet](https://www.aytechnet.fr/).

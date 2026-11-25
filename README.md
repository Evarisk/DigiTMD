# DoliTMD sur [DOLIBARR ERP CRM](https://www.dolibarr.org)

## Informations

- Numéro du module : 436303
- Dernière mise à jour : 12/11/2026
- Éditeur : [Evarisk](https://www.evarisk.com)
- Modules requis : Saturne, Tiers, Tickets ; module Inbox pour la réception des mails
- Thème : eldy (thème moderne de Saturne)
- Licence : GPLv3
- Disponible sur : Windows - MacOS - Linux

### Version

- Version : 23.0.0
- PHP : 7.4.33
- Compatibilité : Dolibarr 23.0.0 - 24.0.0
- Saturne Framework : 23.7.0

## Liens

- Support & Assistance : [Forum www.dolibarr.fr](https://www.dolibarr.fr) / Par mail à contact@evarisk.com
- Demo : [Demo DoliTMD](https://www.demodoli.digirisk.com) - ID: demo - Password: demo
- Documentation : [Wiki DoliTMD](https://wiki.dolibarr.org/index.php/Module_DoliTMD)
- Projet GitHub : [Projet DoliTMD](https://github.com/Evarisk/DoliTMD/projects?query=is%3Aopen)
- D'autres modules développés par Evarisk disponibles sur [DoliStore.com](https://www.dolistore.com)

## Fonctionnalités

### Demandes TMD reçues par mail

Chaque mail reçu dans la Réception de la boîte choisie dans la configuration (boîte du module Inbox) crée :

- un **ticket** attribué à la ou aux personnes choisies dans la configuration (la première de la liste dans
  « Assigné à », toutes comme utilisateurs contact support du ticket), avec le texte du mail, ses pièces jointes
  (onglet Documents) et le mail lui-même (onglet Mails, lien Inbox) ;
- le **tiers** du ticket : celui qui a l'adresse de l'expéditeur, sinon celui d'un contact qui a cette adresse
  (le contact est alors ajouté au ticket comme contact client) ;
- une **demande TMD** (`TMD2611-0001`...) liée au ticket.

C'est la tâche planifiée « DoliTMD : mails de la boîte TMD » (toutes les 5 minutes) qui s'en charge : elle récupère
les nouveaux mails de la boîte (sans attendre la tâche de synchronisation d'Inbox), puis traite chaque mail qui n'a pas
encore de demande. Une seule demande par mail (Message-ID unique) ; seuls les mails reçus depuis le choix de la boîte
sont pris, jamais l'historique. Les tâches planifiées de Dolibarr doivent tourner sur le serveur (cron).

Le déclencheur `INBOX_MAIL_RECEIVED` (traitement immédiat à chaque synchronisation d'Inbox) est prêt mais désactivé
dans `core/triggers/` tant que le module Inbox ne l'envoie pas.

### Réponses du client

Un mail qui répond à une demande ne crée ni demande ni ticket : il devient un message du ticket de la demande (avec
ses pièces jointes, rattachées à ce message, et le mail lié dans l'onglet Mails). Il est reconnu au Message-ID de la
demande ou d'un mail déjà rangé dans la demande (une réponse, le mail d'une demande fusionnée) dans ses références, au
numéro de suivi Dolibarr du ticket (mail écrit depuis le ticket), ou à la référence du ticket (`[TS2611-0043]`) ou de
la demande dans son objet. La liste montre le nombre de réponses devant l'objet.

Sur la fiche, le bloc « Demande du client » montre le mail puis les messages du ticket avec leurs pièces jointes
(aperçu, téléchargement). **Tout télécharger**, au-dessus des pièces jointes d'un message, les récupère d'un coup : une
archive ZIP s'il y en a plusieurs, le fichier lui-même s'il n'y en a qu'une.

Un mail du fil d'une demande qui parle d'autre chose est une **nouvelle demande** : un client écrit souvent sa
nouvelle demande en répondant dans Outlook à l'ancienne et en changeant l'objet. L'objet du mail doit reprendre
celui de la demande (« RE: », « TR: », « RELANCE », les balises entre crochets et la ponctuation ne comptent pas) sans
autre numéro, ou citer la référence de la demande ou de son ticket : « DEMANDE DGD X1234567 PANAMA » écrit en
réponse à « DEMANDE DGD X7654321 COLOMBIE » crée sa demande. Quand la boîte n'est qu'en copie, le mail reste un
message du ticket du fil, quel que soit son objet.

### Fusionner deux demandes, sortir un message en demande

Quand le tri s'est trompé, on corrige depuis la fiche, sans mail envoyé :

- **Fusionner** (action de masse de la liste) : on coche au moins deux demandes, puis on choisit celle à garder (la
  plus ancienne est proposée). Le mail, les messages et les fichiers du ticket, les documents et les contacts du ticket
  des autres arrivent dans la demande gardée, qui garde sa référence, son état, son échéance et son numéro de suivi ;
  l'ID nomeref, la catégorie, le tiers et l'expéditeur qui lui manquaient sont repris des autres. Les autres demandes
  sont supprimées et leurs tickets annulés (avec une note). Ensuite, les réponses à leurs fils, leurs fiches et leurs
  liens de suivi mènent à la demande gardée.
- **Nouvelle demande** (lien sur une réponse reçue du bloc « Demande du client ») : pour une nouvelle demande écrite en
  répondant à une ancienne sans changer l'objet. Le message, ses pièces jointes et son mail (objet, expéditeur, personnes
  en copie) deviennent une demande à part, avec son propre ticket ; l'objet proposé est celui du mail, modifiable. Les
  réponses à ce mail vont dans la nouvelle demande ; une note privée de l'ancienne dit où il est parti.

### Délais et retards

Chaque demande reçoit une échéance : date de réception plus le délai de la configuration (15 jours par défaut,
0 = pas d'échéance, modifiable sur la fiche). Une demande pas faite dont l'échéance est passée est **en retard** :
badge rouge dans la liste et sur la fiche, carte et filtre rapide « En retard ».

### Facturation

Action de masse **Créer les factures** : une facture brouillon par tiers, une ligne par demande (service choisi dans la
configuration, à son prix pour le tiers ; référence de la demande, objet et ticket en description). Les demandes passent
à Facturé et gardent leur facture ; une facture brouillon supprimée les remet à Fait. Une demande sans tiers, déjà
sur une facture ou erronée est laissée de côté.

### Documents et page de suivi client

Chaque demande a un onglet **Documents** (PDF ou tout autre fichier) et une **page de suivi publique**, sans compte :
l'état de la demande (reçue, en cours de traitement, traitée), ses dates, la référence à rappeler et ses documents à
télécharger. Tous les fichiers de l'onglet Documents y sont visibles (l'onglet le rappelle).

- La page s'ouvre avec le **numéro de suivi** de la demande (`XXXX-XXXX-XXXX-XXXX`, 16 caractères tirés au hasard) :
  lien direct dans les mails automatiques, ou numéro saisi sur la page (`public/tmd/tracking.php?entity=1`).
  Un numéro inconnu ne dit rien de plus qu'« aucune demande ».
- Les documents ne sont servis qu'avec ce numéro, et seulement ceux de la demande. Le bouton « Télécharger » les
  télécharge tous, PDF et images compris : la visionneuse du navigateur n'entre pas en jeu.
- Sur la fiche : le numéro, le lien de la page (à copier) et le bouton « Page de suivi ». L'ID nomeref n'y
  apparaît jamais.

### Mails automatiques

Désactivés par défaut, activés un par un dans la configuration, écrits à partir de leur modèle (Configuration > Emails >
Modèles, type « Demandes TMD ») et envoyés par la boîte mail des demandes TMD (sinon par la configuration mail de
Dolibarr) :

- **Accusé de réception** au client à la réception (référence du ticket, échéance, lien de sa page de suivi), dans la
  conversation de son mail ;
- **Demande traitée** au client quand la demande passe à Fait, avec le lien de sa page de suivi ;
- **Rappel des retards**, chaque matin, à chaque personne assignée au ticket.

Depuis la fiche, relus avant l'envoi (destinataires, copies, objet, texte, documents) :

- **Envoyer un message** : une question ou une pièce manquante pendant le traitement, dans la conversation de la
  demande ; la demande garde son état. Modèle « Message au client » (objet et formule de politesse) ;
- **Envoyer « demande traitée »** : les documents de la demande joints, puis la demande passe à Fait.

Les mails envoyés au client sont écrits dans les messages du ticket. L'ID nomeref, interne, n'est
jamais proposé aux modèles.

### Liste des demandes TMD

Menu DoliTMD (liste générique de Saturne) : tiers, état, suivi, ID nomeref de la demande, ticket,
objet (et l'expéditeur, colonne à ajouter ; sans tiers connu, l'adresse s'affiche dans la colonne Tiers).

- **État** (En attente, Sous-traitance, Fait, Facturé, Demande erronée) : choisi directement dans la liste, aux couleurs
  de son badge. **Demande erronée** : un mail qui n'est pas une demande ; grisée dans la liste, jamais en retard ni
  facturée, son ticket est annulé ; sur sa page de suivi, le client la voit « Clôturée ».
- **Suivi** : deux pictos, Fait et Facturé, changés d'un clic. Facturé implique Fait (cocher Facturé coche Fait,
  décocher Fait décoche Facturé) ; l'état suit.
- **ID nomeref** : saisi directement dans la liste (Entrée enregistre, Échap annule). Vide, il prend tout seul le nom
  complet, sans l'extension, du premier document de l'onglet Documents dont le nom contient un identifiant nomeref
  (« PB2 ADR BULGARIE X1667682.pdf » → PB2 ADR BULGARIE X1667682) ; ce document donne aussi la catégorie si elle est
  vide. Un ID réduit au seul identifiant (règle précédente) est remplacé par le nom de son document.
- **Catégorie** (ADR, IATA, IMDG) : reprise de l'objet du mail, sinon de son texte (avion, bateau, navire en plus),
  sinon du **pays d'envoi** : hors d'Europe la route ne passe pas, ce n'est pas de l'ADR, la demande passe en IATA
  (« DEMANDE DGD X1234567 PANAMA »). Le pays cité dans l'objet compte d'abord : un pays d'Europe seul n'y décide rien
  (le texte n'est pas lu, l'adresse d'une signature ne compte pas). Les demandes sans catégorie sont relues par la
  tâche planifiée.
- **Ticket** : une demande qui passe à Fait (ou Facturé) ferme son ticket (résolu), une demande erronée l'annule ;
  remise En attente, elle le rouvre.
- **Actions de masse** : Passer à Facturé, Passer à Fait, Passer en sous-traitance, Remettre en attente, Passer en
  demande erronée (avec confirmation), Fusionner, export, suppression.
- Au-dessus : le nombre de demandes par état (mis à jour en direct) et les filtres rapides.

Une demande peut aussi être saisie à la main (Nouvelle demande TMD).

### Configuration

Configuration > Modules > DoliTMD : la boîte mail des demandes TMD et les personnes à qui les tickets sont attribués.

## Traductions

- Français
- Anglais

## Installation

### Méthode 1 :

- Depuis le menu "Déployer/Installer un module externe" de Dolibarr :
- Glisser l'archive ZIP 'module_DoliTMD-X.Y.Z' et cliquer sur "ENVOYER FICHIER"
- Activer le module dans la liste des Modules/Applications installés

### Méthode 2 :

- Dans le dossier "dolibarr/htdocs/custom" copier la ligne suivante (le dossier doit s'appeler `dolitmd`) :
```
git clone https://github.com/Evarisk/DigiTMD.git dolitmd
```

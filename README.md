# DoliTMD sur [DOLIBARR ERP CRM](https://www.dolibarr.org)

## Informations

- Numéro du module : 436303
- Version : 1.1.0
- Dernière mise à jour : 12/11/2026
- Éditeur : [Evarisk](https://www.evarisk.com)
- Compatibilité : Dolibarr 20+ ; modules Saturne, Tiers, Tickets ; module Inbox pour la réception des mails
- Thème : eldy (thème moderne de Saturne)
- Licence : GPLv3
- Disponible sur : Windows - MacOS - Linux

## Liens

- Support & Assistance : [Forum www.dolibarr.fr](https://www.dolibarr.fr) / Par mail à contact@evarisk.com
- Demo : [Demo DoliTMD](https://www.demodoli.digirisk.com) - ID: demo - Password: demo
- Documentation : [Wiki DoliTMD](https://wiki.dolibarr.org/index.php/Module_DoliTMD)
- Projet GitHub : [Projet DoliTMD](https://github.com/Evarisk/DoliTMD/projects?query=is%3Aopen)
- D'autres modules développés par Evarisk disponibles sur [DoliStore.com](https://www.dolistore.com)

## Fonctionnalités

### Demandes TMD reçues par mail

Chaque mail reçu dans la Réception de la boîte choisie dans la configuration (boîte du module Inbox) crée :

- un **ticket** attribué à la personne choisie dans la configuration, avec le texte du mail, ses pièces jointes
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
ses pièces jointes, et le mail lié dans l'onglet Mails). Il est reconnu au Message-ID de la demande dans ses
références, au numéro de suivi Dolibarr du ticket (mail écrit depuis le ticket), ou à la référence du ticket
(`[TS2611-0043]`) ou de la demande dans son objet. La liste montre le nombre de réponses devant l'objet.

### Délais et retards

Chaque demande reçoit une échéance : date de réception plus le délai de la configuration (15 jours par défaut,
0 = pas d'échéance, modifiable sur la fiche). Une demande pas faite dont l'échéance est passée est **en retard** :
badge rouge dans la liste et sur la fiche, carte et filtre rapide « En retard ».

### Facturation

Action de masse **Créer les factures** : une facture brouillon par tiers, une ligne par demande (service choisi dans la
configuration, à son prix pour le tiers ; référence de la demande, objet et ticket en description). Les demandes passent
à Facturé et gardent leur facture ; une facture brouillon supprimée les remet à Fait. Une demande sans tiers ou déjà
sur une facture est laissée de côté.

### Documents et page de suivi client

Chaque demande a un onglet **Documents** (PDF ou tout autre fichier) et une **page de suivi publique**, sans compte :
l'état de la demande (reçue, en cours de traitement, traitée), ses dates, la référence à rappeler et ses documents à
télécharger. Tous les fichiers de l'onglet Documents y sont visibles (l'onglet le rappelle).

- La page s'ouvre avec le **numéro de suivi** de la demande (`XXXX-XXXX-XXXX-XXXX`, 16 caractères tirés au hasard) :
  lien direct dans les mails automatiques, ou numéro saisi sur la page (`public/tmd/tracking.php?entity=1`).
  Un numéro inconnu ne dit rien de plus qu'« aucune demande ».
- Les documents ne sont servis qu'avec ce numéro, et seulement ceux de la demande ; PDF et images s'ouvrent dans le
  navigateur, les autres fichiers se téléchargent.
- Sur la fiche : le numéro, le lien de la page (à copier) et le bouton « Page de suivi ». L'ID nomeref n'y
  apparaît jamais.

### Mails automatiques

Désactivés par défaut, activés un par un dans la configuration, écrits à partir de leur modèle (Configuration > Emails >
Modèles, type « Demandes TMD ») et envoyés par la boîte mail des demandes TMD (sinon par la configuration mail de
Dolibarr) :

- **Accusé de réception** au client à la réception (référence du ticket, échéance, lien de sa page de suivi), dans la
  conversation de son mail ;
- **Demande traitée** au client quand la demande passe à Fait, avec le lien de sa page de suivi ;
- **Rappel des retards**, chaque matin, à la personne assignée.

Les mails envoyés au client sont écrits dans les messages du ticket. L'ID nomeref, interne, n'est
jamais proposé aux modèles.

### Liste des demandes TMD

Menu DoliTMD (liste générique de Saturne) : tiers, état, suivi, ID nomeref de la demande, ticket,
objet (et l'expéditeur, colonne à ajouter ; sans tiers connu, l'adresse s'affiche dans la colonne Tiers).

- **État** (En attente, Fait, Facturé) : choisi directement dans la liste, aux couleurs de son badge.
- **Suivi** : deux pictos, Fait et Facturé, changés d'un clic. Facturé implique Fait (cocher Facturé coche Fait,
  décocher Fait décoche Facturé) ; l'état suit.
- **ID nomeref** : saisi directement dans la liste (Entrée enregistre, Échap annule).
- **Ticket** : une demande qui passe à Fait (ou Facturé) ferme son ticket (résolu) ; remise En attente, elle le rouvre.
- **Actions de masse** : Passer à Facturé, Passer à Fait, Remettre en attente (avec confirmation), export, suppression.
- Au-dessus : le nombre de demandes par état (mis à jour en direct) et les filtres rapides.

Une demande peut aussi être saisie à la main (Nouvelle demande TMD).

### Configuration

Configuration > Modules > DoliTMD : la boîte mail des demandes TMD et la personne à qui les tickets sont attribués.

## Traductions

- Français
- Anglais

## Installation

### Méthode 1 :

- Depuis le menu "Déployer/Installer un module externe" de Dolibarr :
- Glisser l'archive ZIP 'module_DoliTMD-X.Y.Z' et cliquer sur "ENVOYER FICHIER"
- Activer le module dans la liste des Modules/Applications installés

### Méthode 2 :

- Dans le dossier "dolibarr/htdocs/custom" copier la ligne suivante :
``` 
git clone https://github.com/Evarisk/DoliTMD.git
```

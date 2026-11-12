# CHANGELOG dolitmd FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.1.0

- Demandes TMD reçues par mail : un mail reçu sur la boîte TMD (module Inbox) crée un ticket attribué à la personne
  de la configuration, lié au tiers ou au contact de l'expéditeur, avec ses pièces jointes, et une demande TMD.
- Liste des demandes TMD (liste générique de Saturne) : tiers, état choisi dans la liste, colonne Suivi (pictos Fait et
  Facturé changés d'un clic), ID nomeref modifiable dans la liste, nombre de demandes par état et
  filtres rapides ; actions de masse Passer à Facturé, Passer à Fait, Remettre en attente.
- Le ticket suit la demande : fermé (résolu) quand elle est faite, rouvert quand elle est remise en attente.
- Réponses du client rattachées au ticket de la demande (références, numéro de suivi, référence dans l'objet), sans
  nouvelle demande ; nombre de réponses dans la liste.
- Échéance (délai réglable, 15 jours par défaut), badge « En retard », carte et filtre rapide.
- Action de masse « Créer les factures » : une facture brouillon par tiers, une ligne par demande (service de la
  configuration) ; facture supprimée = demandes remises à Fait.
- Mails automatiques désactivés par défaut, avec leur modèle : accusé de réception, demande traitée, rappel quotidien
  des retards à la personne assignée.
- Onglet Documents sur chaque demande, et page de suivi publique pour le client (état, dates, documents à
  télécharger), ouverte par un numéro de suivi aléatoire ; son lien part dans les mails automatiques.
- Fiche d'une demande TMD (saisie à la main, modification, suppression).
- Configuration : boîte mail des demandes TMD, personne à qui les tickets sont attribués.
- Tâche planifiée (toutes les 5 minutes) qui récupère les nouveaux mails de la boîte TMD et les traite ; le
  déclencheur INBOX_MAIL_RECEIVED est prêt mais désactivé tant que le module Inbox ne l'envoie pas.
- Lien vers la facture d'une demande facturée dans la colonne Suivi de la liste.
- Tickets attribués à plusieurs personnes à la fois (la première dans « Assigné à », toutes comme utilisateurs contact
  support du ticket) ; le rappel des retards part à chacune.

## 1.0

Initial version

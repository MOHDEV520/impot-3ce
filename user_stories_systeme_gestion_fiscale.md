# 📘 USER STORIES — SYSTÈME DE GESTION FISCALE (OFFLINE)

Ce document traduit le **plan du système de gestion fiscale** en **User Stories**, afin de faciliter :
- la compréhension métier
- la priorisation du développement
- la validation fonctionnelle

Les User Stories sont organisées **par rôle** et **par module**.

---

## 👤 RÔLE : AGENT FISCAL

### 🔐 Authentification & accès

**US-AG-01**  
En tant qu’**agent**, je veux **me connecter avec mon identifiant et mot de passe** afin d’accéder uniquement à mon espace de travail.

**US-AG-02**  
En tant qu’**agent**, je veux **voir uniquement mes clients** afin de garantir la confidentialité des données.

---

### 📂 Portefeuille clients

**US-AG-03**  
En tant qu’**agent**, je veux **voir la liste de mes clients** afin de gérer leur situation fiscale.

**US-AG-04**  
En tant qu’**agent**, je veux **voir l’état mensuel de chaque client (complet / incomplet)** afin de prioriser mon travail.

---

### 📅 Gestion mensuelle

**US-AG-05**  
En tant qu’**agent**, je veux **sélectionner un mois et une année** afin de saisir les données fiscales correspondantes.

**US-AG-06**  
En tant qu’**agent**, je veux **travailler mois par mois** afin de garantir la cohérence des déclarations fiscales.

---

### 📦 Achats fournisseurs

**US-AG-07**  
En tant qu’**agent**, je veux **saisir les achats fournisseurs par relevé** afin de faciliter la gestion des officines.

**US-AG-08**  
En tant qu’**agent**, je veux **saisir des factures individuelles si nécessaire** afin de gérer tous les cas possibles.

**US-AG-09**  
En tant qu’**agent**, je veux **voir le total des achats HT, TVA et TTC par fournisseur** afin de préparer la TVA.

---

### 💸 Dépenses courantes

**US-AG-10**  
En tant qu’**agent**, je veux **enregistrer les dépenses par nature** afin de suivre les charges de l’entreprise.

**US-AG-11**  
En tant qu’**agent**, je veux **voir le total des dépenses du mois** afin d’évaluer le résultat provisoire.

---

### 🧮 TVA

**US-AG-12**  
En tant qu’**agent**, je veux **paramétrer le statut TVA du client (non exonéré, exonéré partiel, exonéré 100 %)** afin d’appliquer les bons calculs.

**US-AG-13**  
En tant qu’**agent**, je veux que la **TVA soit calculée automatiquement** selon le CA et le taux applicable (18 % ou 5 %).

**US-AG-14**  
En tant qu’**agent**, je veux **visualiser la TVA collectée, la TVA déductible et la TVA à payer** afin de préparer la déclaration.

---

### 💼 Impôts sur salaires

**US-AG-15**  
En tant qu’**agent**, je veux **saisir la masse salariale mensuelle** afin de calculer les impôts sur salaires.

**US-AG-16**  
En tant qu’**agent**, je veux que le système **calcule automatiquement CF, ITS et TL** afin d’éviter les erreurs.

---

### 🏠 Impôts sur location

**US-AG-17**  
En tant qu’**agent**, je veux **indiquer si le client est soumis à l’impôt sur location** afin d’activer ou non cette section.

**US-AG-18**  
En tant qu’**agent**, je veux que le système **calcule IRF, TVA location et TF** afin de connaître l’impôt dû.

---

### 📊 Impôt sur le chiffre d’affaires

**US-AG-19**  
En tant qu’**agent**, je veux **saisir le chiffre d’affaires mensuel** afin de calculer la CSS.

**US-AG-20**  
En tant qu’**agent**, je veux que la **CSS soit calculée automatiquement à 0,5 % du CA**.

---

### 📎 Annexes & exonérations

**US-AG-21**  
En tant qu’**agent**, je veux **joindre des annexes justificatives** afin de prouver les exonérations fiscales.

**US-AG-22**  
En tant qu’**agent**, je veux que le système **refuse une exonération sans annexe** afin d’éviter les risques fiscaux.

---

### 📊 Tableau de bord mensuel

**US-AG-23**  
En tant qu’**agent**, je veux **voir un tableau de bord mensuel récapitulatif** afin d’avoir une vision globale de la situation fiscale.

**US-AG-24**  
En tant qu’**agent**, je veux **connaître le total des impôts à payer pour le mois** afin de préparer la déclaration SIGTAS.

**US-AG-25**  
En tant qu’**agent**, je veux **valider un mois comme prêt pour déclaration** afin de clôturer le travail mensuel.

---

## 👤 RÔLE : ADMINISTRATEUR / SUPERVISEUR

**US-AD-01**  
En tant qu’**administrateur**, je veux **créer et gérer les comptes agents** afin d’organiser le cabinet.

**US-AD-02**  
En tant qu’**administrateur**, je veux **attribuer des clients aux agents** afin de répartir le travail.

**US-AD-03**  
En tant qu’**administrateur**, je veux **consulter tous les tableaux de bord** afin de superviser l’activité du cabinet.

---

## 👤 RÔLE : CABINET (GLOBAL)

**US-CAB-01**  
En tant que **cabinet**, je veux **générer des états fiscaux mensuels** afin d’avoir une traçabilité complète.

**US-CAB-02**  
En tant que **cabinet**, je veux **sauvegarder les données fiscales** afin d’éviter toute perte d’information.

**US-CAB-03**  
En tant que **cabinet**, je veux **restaurer une sauvegarde** afin de reprendre le travail en cas de problème.

---

## 🧭 RÈGLES MÉTIER TRANSVERSALES

- Toute donnée est **mensuelle**
- Un mois validé est **en lecture seule**
- Une exonération sans annexe est **non valide**
- Chaque agent ne voit que ses clients

---

## ✅ CONCLUSION

Ces User Stories couvrent :
- l’ensemble des fonctionnalités métier
- les règles fiscales réelles
- les contraintes offline

Elles peuvent être utilisées directement pour :
- planification agile
- développement par itérations
- tests fonctionnels


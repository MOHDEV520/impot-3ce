# 📘 PLAN COMPLET — SYSTÈME DE GESTION FISCALE (OFFLINE)

## 1. Contexte général
Ce document décrit le **plan fonctionnel et technique** d’un système de gestion fiscale destiné à un **cabinet d’expertise**, avec :
- Gestion **multi-agents**
- Gestion **multi-clients** (officines majoritaires mais non exclusives)
- Application **100 % hors ligne (offline)**
- Technologies : **HTML, CSS, JavaScript, PHP, SQLite**
- Système **transportable** avec **sauvegarde et partage inter-ordinateurs**

---

## 2. Objectifs du système

- Centraliser la gestion fiscale des clients
- Permettre à chaque agent de gérer son portefeuille clients
- Assurer la saisie mensuelle des données de gestion
- Calculer correctement les impôts
- Générer un tableau de bord fiscal mensuel
- Préparer les déclarations à saisir dans SIGTAS
- Sécuriser et archiver les données fiscales

---

## 3. Organisation générale

### 3.1 Agents
- Chaque agent dispose d’un **compte personnel**
- Connexion par identifiant et mot de passe
- Chaque agent gère **plusieurs clients**
- Accès strictement limité à son portefeuille

### 3.2 Clients
- Officines (≈ 90 %)
- Autres entreprises (commerce, services, industrie, ONG, etc.)
- Chaque client dispose d’un **dossier fiscal unique**
- Chaque client est rattaché à **un agent principal**

---

## 4. Compte de gestion du client

Le compte de gestion est la **base centrale** du système.

Il contient, par **mois et par année** :
- Achats fournisseurs
- Dépenses courantes
- Salaires
- Chiffre d’affaires
- Sections fiscales activées

---

## 5. Sections fiscales (indépendantes)

Chaque impôt dispose de **sa propre section**.

### 5.1 Section TVA

#### Paramétrage par client
- Non exonéré
- Exonéré partiellement
- Exonéré à 100 %
- Taux applicable : 18 % ou 5 %

#### Règles de calcul
- **Non exonéré** :
  - TVA = CA global × 18 % (ou 5 %)
- **Exonéré partiel** :
  - CA taxable = CA global − CA exonéré
  - TVA = CA taxable × taux
- **Exonéré 100 %** :
  - TVA = 0
  - CA global = CA exonéré

#### Résultat
- TVA collectée
- TVA déductible
- TVA à payer ou crédit TVA

---

### 5.2 Section Impôts sur Salaires

Taxes appliquées :
- CF : 3,5 % de la masse salariale
- ITS : selon barème légal
- TL : 1 % de la masse salariale

---

### 5.3 Section Impôts sur Location (si applicable)

Base : valeur locative mensuelle

Taxes :
- IRF : 12 %
- TVA sur location : 18 %
- TF : 3 %

Activation par client : Oui / Non

---

### 5.4 Section Impôt sur le Chiffre d’Affaires

- CSS : 0,5 % du chiffre d’affaires mensuel

---

## 6. Section Achats

- Gestion par **mois**
- Fournisseurs multiples
- Saisie par :
  - Relevés fournisseurs (prioritaires)
  - Factures individuelles

Données :
- Montant HT
- TVA
- Montant TTC

Les achats alimentent directement :
- La TVA déductible
- Le tableau de bord

---

## 7. Section Dépenses

- Dépenses classées **par nature**
- Natures paramétrables :
  - Loyer
  - Eau / Électricité
  - Téléphone / Internet
  - Transport
  - Autres charges

Chaque dépense est rattachée à :
- Un client
- Un mois

---

## 8. Tableau de bord mensuel

Le tableau de bord est une **vue consolidée**.

Il affiche :
- Achats par fournisseur
- Total achats
- Dépenses par nature
- Résumé TVA
- Résumé impôts sur salaires
- Résumé impôts sur location
- CSS
- **Total des impôts du mois**

Statut :
- En préparation
- Prêt pour déclaration SIGTAS

---

## 9. Récapitulatif des impôts à payer

- TVA
- CF
- ITS
- TL
- IRF
- TVA sur location
- TF
- CSS

Chaque impôt est affiché avec :
- Base
- Taux
- Montant

---

## 10. Annexes — Justification des exonérations

### Principe fondamental
> **Aucune exonération sans annexe justificative**

### Types d’annexes

#### Annexe A — Exonération TVA
- Arrêté ministériel
- Convention avec l’État
- Décision DGI

#### Annexe B — Taux réduit (5 %)
- Texte légal
- Activité concernée

#### Annexe C — Exonération impôts sur location
- Contrat de bail
- Attestation administrative

#### Annexe D — Aménagements salariaux
- Décision administrative
- Régime spécial

Chaque annexe contient :
- Base légale
- Période de validité
- Impôt concerné

---

## 11. Workflow Agent → Client → Mois

1. Connexion agent
2. Sélection du client
3. Sélection du mois
4. Saisie achats
5. Saisie dépenses
6. Saisie CA
7. Calcul TVA
8. Calcul autres impôts
9. Tableau de bord
10. Validation

---

## 12. Technologies retenues

- Interface : HTML5, CSS3, Bootstrap / Tailwind
- Interactions : JavaScript
- Backend : PHP
- Base de données : SQLite
- Serveur local : XAMPP Portable

---

## 13. Sauvegarde & partage

### Sauvegarde
- Sauvegarde automatique du fichier SQLite
- Sauvegarde manuelle à la demande

### Partage
- Copie du fichier `.db` via clé USB ou réseau local
- Règle stricte : **un seul poste actif à la fois**

---

## 14. Évolutivité

- Ajout de nouveaux impôts par section
- Ajout de nouveaux secteurs d’activité
- Migration future vers une version en ligne

---

## 15. Conclusion

Ce plan définit un **système fiscal professionnel, fiable et conforme**, parfaitement adapté :
- aux cabinets d’expertise
- aux exigences fiscales réelles
- à un usage hors ligne sécurisé


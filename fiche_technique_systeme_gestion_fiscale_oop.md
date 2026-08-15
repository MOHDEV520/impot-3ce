# 📘 FICHE TECHNIQUE
## SYSTÈME DE GESTION FISCALE OFFLINE – APPROCHE OBJET (OOP)

---

## 1. Présentation générale

Cette fiche technique décrit l’**architecture technique**, les **fonctions clés** et l’**approche de programmation orientée objet (POO / OOP)** du système de gestion fiscale destiné à un **cabinet d’expertise**.

Le système est :
- **100 % offline**
- **Multi-agents / multi-clients**
- **Transportable**
- Développé avec **HTML, CSS, JavaScript, PHP, SQLite**

---

## 2. Objectifs techniques

- Structurer le code pour qu’il soit **maintenable** et **évolutif**
- Séparer clairement la logique métier, les données et l’interface
- Implémenter une logique fiscale fiable et traçable
- Faciliter l’ajout de nouveaux impôts ou modules
- Garantir la sécurité logique des données en environnement offline

---

## 3. Architecture technique globale

### 3.1 Type d’architecture

👉 **Architecture MVC légère (Model – View – Controller)**

- **Model** : logique métier & accès données (POO)
- **View** : HTML / CSS / JS
- **Controller** : orchestration des actions utilisateur

---

## 4. Approche Programmation Orientée Objet (OOP)

### Principes appliqués

- Encapsulation
- Responsabilité unique (SRP)
- Réutilisabilité
- Extensibilité

Chaque entité métier est représentée par une **classe PHP**.

---

## 5. Modèle objet (Classes principales)

### 5.1 Classe Agent

**Responsabilité** : gérer l’authentification et le portefeuille clients.

**Attributs** :
- id
- nom
- email
- motDePasse
- statut

**Fonctions (méthodes)** :
- seConnecter()
- seDeconnecter()
- getClients()
- aAccesClient()

---

### 5.2 Classe Client

**Responsabilité** : représenter une entreprise suivie fiscalement.

**Attributs** :
- id
- nom
- typeActivite
- IFU
- regimeFiscal
- agentId

**Fonctions** :
- getParametresFiscaux()
- getCompteGestion(mois, annee)
- estSoumisTVA()

---

### 5.3 Classe ParametresFiscaux

**Responsabilité** : centraliser les règles fiscales par client.

**Attributs** :
- typeTVA (non, partielle, totale)
- tauxTVA (18 %, 5 %)
- salairesActifs
- locationActive
- cssActive

**Fonctions** :
- calculerTVA()
- calculerImpotsSalaires()
- calculerImpotsLocation()
- calculerCSS()

---

### 5.4 Classe Fournisseur

**Responsabilité** : gérer les fournisseurs.

**Attributs** :
- id
- nom
- type

**Fonctions** :
- getAchats()

---

### 5.5 Classe Achat

**Responsabilité** : représenter un achat fournisseur.

**Attributs** :
- id
- clientId
- fournisseurId
- mois
- annee
- montantHT
- montantTVA
- montantTTC
- typeDocument

**Fonctions** :
- estDeductibleTVA()

---

### 5.6 Classe Depense

**Responsabilité** : représenter une dépense courante.

**Attributs** :
- id
- clientId
- mois
- annee
- nature
- montant

**Fonctions** :
- estDeductible()

---

### 5.7 Classe CompteGestionMensuel

**Responsabilité** : centraliser toutes les données d’un client pour un mois.

**Attributs** :
- clientId
- mois
- annee
- achats[]
- depenses[]
- salaires
- chiffreAffaires

**Fonctions** :
- calculerTotauxAchats()
- calculerTotauxDepenses()
- genererTableauBord()

---

### 5.8 Classe Impot (classe abstraite)

**Responsabilité** : définir le comportement commun des impôts.

**Fonctions communes** :
- calculer()
- getBase()
- getTaux()
- getMontant()

---

### 5.9 Classes Impôts spécifiques (héritage)

- ImpotTVA
- ImpotSalaire
- ImpotLocation
- ImpotCA (CSS)

Chaque classe implémente sa propre logique de calcul.

---

### 5.10 Classe Annexe

**Responsabilité** : gérer les justificatifs d’exonération.

**Attributs** :
- id
- clientId
- typeImpot
- reference
- dateValidite

**Fonctions** :
- estValide()

---

## 6. Fonctions transversales du système

- verifierConnexionAgent()
- verifierAccesClient()
- verrouillerMois()
- genererPDF()
- sauvegarderBase()
- restaurerSauvegarde()

---

## 7. Gestion de la base de données (SQLite)

- Accès via une classe Database
- Connexion unique
- Requêtes préparées
- Transactions pour opérations sensibles

---

## 8. Sécurité logique (offline)

- Sessions PHP
- Cloisonnement par agent
- Journal des actions (logs)
- Mois validé = lecture seule

---

## 9. Sauvegarde & transport

- Fichier SQLite unique
- Sauvegarde automatique
- Sauvegarde manuelle
- Copie USB / réseau local

---

## 10. Avantages de l’approche OOP

- Code clair et structuré
- Facilité de maintenance
- Ajout simple de nouveaux impôts
- Réutilisation des composants
- Alignement avec la logique métier fiscale

---

## 11. Conclusion

Cette fiche technique fournit une **base solide pour un développement professionnel**, structuré et durable du système de gestion fiscale, en exploitant pleinement la **programmation orientée objet en PHP**.


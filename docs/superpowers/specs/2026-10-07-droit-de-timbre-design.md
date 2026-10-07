# Droit de timbre sur les paiements en espèces — Design

**Date :** 2026-10-07
**Statut :** en attente de relecture

## Objectif

Ajouter un nouvel impôt mensuel, le **droit de timbre** (art. 397 du CGI), à 3CE FISCUS.
L'agent saisit le total encaissé en espèces dans le mois ; l'application calcule
automatiquement le droit de timbre, l'ajoute au total des impôts du mois et l'affiche
dans les récapitulatifs et le rapport annuel.

Le droit de timbre **ne figure sur aucune déclaration officielle** (il est édité par le
centre des impôts) : pas de formulaire SIGTAS, pas de numéro de ligne, pas de page
`declaration-*.php`.

## Ce que l'utilisateur a demandé vs hypothèses

**Demandé :**
- Barème (art. 397, simplifié à la demande de l'utilisateur), uniquement sur les
  paiements en espèces :
  - < 1 000 F → 40 F
  - 1 000 à 10 000 F → 120 F
  - 10 000 à 50 000 F → 240 F
  - > 50 000 F → `round(montant / 50 000 × 160)` — division exacte, arrondi au franc
- Saisie du **montant total encaissé en espèces** du mois, calcul automatique,
  avec possibilité de saisir directement le montant du timbre.
- **Choix validé :** la formule au-delà de 50 000 F est proportionnelle, sans base de
  240 F. Elle produit une baisse juste au-dessus de 50 000 F (50 000 → 240 ;
  50 001 → 160 ; retour à 240 à 75 000) — accepté par l'utilisateur.

**Hypothèses (à confirmer à la relecture) :**
- **Bornes :** borne haute incluse. 1 000 → 120 ; 10 000 → 120 ; 10 001 → 240 ;
  50 000 → 240 ; 60 000 → 192 ; 100 000 → 320.
- **Approximation assumée :** la loi s'applique *par titre* ; appliquer le barème au
  **total** mensuel donne un montant inférieur au timbre réel dès qu'il y a plusieurs
  reçus (20 × 5 000 F : 2 400 F réels contre 320 F calculés). D'où le champ
  « montant saisi » qui prime sur le calcul.
- Module désactivé par défaut pour tous les clients (activation par client).
- Montant arrondi au franc (`round()` en PHP, `Math.round()` en JS).

## Modèle de référence

On suit le modèle de la **Taxe Touristique** (commit `7f1577e`), le dernier impôt ajouté :
calcul inline dans `pages/impots.php` (PHP + JS), écriture directe dans `impots_mensuels`,
recalcul à l'affichage dans `recapitulatif.php` / `recap-paiements.php`, somme SQL dans
`rapport-annuel.php`. `CalculateurImpots` n'est **pas** modifié (il ignore déjà RAS et
Taxe Touristique — dette existante, hors périmètre).

## Composants

### 1. Base de données (`config/database.php`, `database/schema.sql`)
Ajout via `ajouterColonneSiManquante()` (MySQL et SQLite), et dans `schema.sql` pour les
installations neuves :

| Table | Colonne | Définition |
|---|---|---|
| `parametres_fiscaux` | `timbre_actif` | `TINYINT(1) DEFAULT 0` |
| `compte_gestion_mensuel` | `timbre_encaissements_especes` | `DECIMAL(15,2) DEFAULT 0` |
| `compte_gestion_mensuel` | `timbre_montant_manuel` | `DECIMAL(15,2) DEFAULT NULL` |
| `impots_mensuels` | `droit_timbre` | `DECIMAL(15,2) DEFAULT 0` |

`timbre_montant_manuel` est `NULL` quand non saisi (distingue « pas de saisie » de
« saisi à 0 »).

### 2. Barème centralisé (`classes/Impot.php`)
Deux méthodes statiques sur `Impot`, à côté de `calculerRetenueSourceBIC()` :

- `Impot::calculerDroitTimbre(float $montant): float` — barème pur. `$montant <= 0` → 0.
- `Impot::droitTimbreMensuel(?array $params, ?array $compte): float` — 0 si
  `timbre_actif` est faux ; sinon `timbre_montant_manuel` s'il n'est pas `NULL` ;
  sinon `calculerDroitTimbre(timbre_encaissements_especes)`.

Toutes les pages PHP passent par `droitTimbreMensuel()` — une seule source de vérité.

### 3. Paramètres client (`classes/Client.php`, `pages/client-nouveau.php`, `pages/client-edit.php`)
Case à cocher « Droit de timbre (paiements en espèces) » à côté de « Taxe Touristique » ;
`timbre_actif` ajouté à l'`UPDATE`/`INSERT` de `parametres_fiscaux`.

### 4. Saisie mensuelle (`classes/CompteGestionMensuel.php`, `pages/impots.php`)
- `CompteGestionMensuel` : propriétés, getters/setters, chargement, sauvegarde et
  `toArray()` pour les deux nouveaux champs.
- `impots.php` (si `timbre_actif`) :
  - option « Droit de timbre » dans le sélecteur de type d'impôt + section dédiée :
    champ « Total encaissé en espèces », champ « Montant du timbre saisi (facultatif) »,
    montant calculé affiché en direct, rappel du barème et note sur l'approximation ;
  - mini-carte « Droit de timbre » dans la synthèse ;
  - inclus dans `$totalImpots` (affichage) et `$total_new` (sauvegarde) ;
  - `droit_timbre` ajouté à l'`UPDATE`/`INSERT` de `impots_mensuels`.
- JS : `calculerDroitTimbreJS(montant)`, copie conforme du barème PHP, utilisée par
  `recalcTimbre()` et par `updateSummary()` pour le total en direct.

### 5. Affichage
- `recapitulatif.php`, `recap-paiements.php` : ligne « Droit de timbre » (si actif),
  ajoutée à `$totalImpots`.
- `rapport-annuel.php` : `SUM(droit_timbre)` ajouté au total annuel et ligne dans le
  tableau des impôts.

### 6. Transfert entre installations
Aucun changement : `transfer_helpers.php` copie les lignes en `SELECT *` / colonnes
génériques, et les colonnes sont créées au démarrage par la migration.

## Gestion des erreurs
- Saisies invalides ou vides → 0 (même normalisation `str_replace([' ', ','], ['', '.'])`
  que les autres montants de `impots.php`).
- Montants négatifs → ramenés à 0.
- Client sans `timbre_actif` → aucune UI timbre, contribution 0 au total.
- Mois antérieurs sans données timbre → `droit_timbre` = 0, aucun plantage.

## Vérification
Pas de suite de tests dans le projet :
1. Script CLI jetable (scratchpad) vérifiant `calculerDroitTimbre()` sur
   0, 999, 1 000, 10 000, 10 001, 50 000, 50 001, 60 000, 75 000, 100 000 → attendus
   0, 40, 120, 120, 240, 240, 160, 192, 240, 320.
2. `php -l` sur chaque fichier modifié.
3. Test manuel via le serveur local (`php -S`), admin connecté : activer le timbre sur un
   client, saisir un mois (calcul auto puis montant manuel), vérifier le total en direct,
   la sauvegarde, `recapitulatif.php`, `recap-paiements.php`, `rapport-annuel.php`, et
   qu'un client sans timbre est inchangé.

## Hors périmètre
- Saisie reçu par reçu (liste des paiements en espèces).
- Mode de paiement sur achats/dépenses.
- Mise à jour de `CalculateurImpots` (dette existante : RAS, Taxe Touristique absentes).

# Droit de timbre — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter le droit de timbre (paiements en espèces) comme impôt mensuel activable par client, calculé dans `impots.php` et repris dans les récapitulatifs et le rapport annuel.

**Architecture:** Même modèle que la Taxe Touristique (commit `7f1577e`) : activation dans `parametres_fiscaux`, saisie dans `compte_gestion_mensuel`, montant écrit dans `impots_mensuels` par `pages/impots.php`, recalculé à l'affichage par `recapitulatif.php` / `recap-paiements.php`, sommé par `rapport-annuel.php`. Le barème vit une seule fois en PHP (`Impot::calculerDroitTimbre`) avec une copie JS conforme pour l'affichage en direct.

**Tech Stack:** PHP 8.0 (XAMPP `C:/xampp/php/php.exe`), PDO MySQL/SQLite via `Database`, Tailwind (classes déjà compilées), JS vanilla.

**Spec:** `docs/superpowers/specs/2026-10-07-droit-de-timbre-design.md`

## Global Constraints

- Tout le code, les noms et les libellés sont en **français**.
- Barème : `<= 0` → 0 ; `< 1 000` → 40 ; `<= 10 000` → 120 ; `<= 50 000` → 240 ; `> 50 000` → `round(montant / 50 000 × 160)`.
- Montant final arrondi au franc (`round()` PHP / `Math.round()` JS).
- `timbre_montant_manuel` `NULL` = pas de saisie (calcul auto) ; une valeur (y compris 0) prime sur le calcul.
- Module désactivé par défaut (`timbre_actif DEFAULT 0`) ; inactif ⇒ contribution 0 partout, même si des montants sont stockés.
- SQL uniquement via `Database` avec paramètres préparés ; nouvelles colonnes via `ajouterColonneSiManquante()` + `database/schema.sql` ; pas de syntaxe MySQL-only.
- Pas de colonne `*_ligneNNN` : le timbre n'est sur aucune déclaration officielle.
- `CalculateurImpots` n'est pas modifié.
- Ne jamais lancer de script CLI qui écrit dans la base : la base SQLite CLI est la vraie base de l'utilisateur (`%APPDATA%/IMPOT-3CE`).

## Review Focus

1. **Champ manuel vidé vs saisi à 0** — vide ⇒ retour au calcul auto ; « 0 » ⇒ timbre à 0. (Tests Tâche 1 : `lireMontantSaisi('')` → `null`, `droitTimbreMensuel` avec manuel `0.0` → 0.)
2. **Saisie avec espaces/virgule** (« 1 250 000 », « 75000,50 », espace insécable) — doit être lue correctement, pas tronquée à 1. (Tests Tâche 1 sur `lireMontantSaisi`.)
3. **Mois sans compte de gestion** (`fetchOne` renvoie `false` dans `recap-paiements.php`) — aucun plantage (régression déjà corrigée en `debed35` pour la RAS). (Test Tâche 1 : `droitTimbreMensuel(null, null)` → 0 ; appels Tâche 5 avec `?: null`.)
4. **Changer de type d'impôt avant d'enregistrer** — `changerType()` recharge la page ; les montants timbre non enregistrés doivent survivre via l'URL. (Étape de vérification Tâche 4.)
5. **Module désactivé avec données stockées / montants négatifs** — 0 au total, pas de valeur négative. (Tests Tâche 1.)

---

### Task 1: Barème et lecture des montants (`classes/Impot.php`)

**Files:**
- Modify: `classes/Impot.php` (insérer après la fin de `calculerRetenueSourceBIC()`, juste avant le docblock `Convertir en tableau` / `public function toArray()` de la classe abstraite, ~ligne 196)
- Test: `C:\Users\MOH\AppData\Local\Temp\claude\C--xampp-htdocs-IMPOT-3CE\6a47bc85-9240-464d-94ed-a018ca660025\scratchpad\test_droit_timbre.php` (jetable, hors dépôt)

**Interfaces:**
- Produces:
  - `Impot::calculerDroitTimbre(float $montant): float`
  - `Impot::lireMontantSaisi($brut): ?float` — `null` si absent/vide/non numérique, sinon `max(0, valeur)`
  - `Impot::droitTimbreMensuel(?array $params, ?array $compte): float` — lit `$params['timbre_actif']`, `$compte['timbre_encaissements_especes']`, `$compte['timbre_montant_manuel']`

- [ ] **Step 1: Écrire le test qui échoue**

Créer le fichier de test (chemin ci-dessus) :

```php
<?php
require 'C:/xampp/htdocs/IMPOT 3CE/classes/Impot.php';

$echecs = 0;
function verifier(string $libelle, $obtenu, $attendu): void {
    global $echecs;
    if ($obtenu !== $attendu) {
        $echecs++;
        echo "ECHEC  $libelle : obtenu " . var_export($obtenu, true) . ", attendu " . var_export($attendu, true) . "\n";
    } else {
        echo "ok     $libelle\n";
    }
}

// Barème
$cas = [
    [-5000, 0.0], [0, 0.0], [999, 40.0], [1000, 120.0], [10000, 120.0], [10001, 240.0],
    [50000, 240.0], [50001, 160.0], [60000, 192.0], [75000, 240.0], [100000, 320.0], [1000000, 3200.0],
];
foreach ($cas as [$montant, $attendu]) {
    verifier("calculerDroitTimbre($montant)", Impot::calculerDroitTimbre((float) $montant), $attendu);
}

// Lecture des saisies
verifier("lireMontantSaisi(null)", Impot::lireMontantSaisi(null), null);
verifier("lireMontantSaisi('')", Impot::lireMontantSaisi(''), null);
verifier("lireMontantSaisi('   ')", Impot::lireMontantSaisi('   '), null);
verifier("lireMontantSaisi('abc')", Impot::lireMontantSaisi('abc'), null);
verifier("lireMontantSaisi('0')", Impot::lireMontantSaisi('0'), 0.0);
verifier("lireMontantSaisi('1 250 000')", Impot::lireMontantSaisi('1 250 000'), 1250000.0);
verifier("lireMontantSaisi('75000,50')", Impot::lireMontantSaisi('75000,50'), 75000.5);
verifier("lireMontantSaisi(nbsp)", Impot::lireMontantSaisi("1\u{00A0}000"), 1000.0);
verifier("lireMontantSaisi('-300')", Impot::lireMontantSaisi('-300'), 0.0);

// Montant mensuel
$actif = ['timbre_actif' => 1];
verifier("mensuel null/null", Impot::droitTimbreMensuel(null, null), 0.0);
verifier("mensuel actif sans compte", Impot::droitTimbreMensuel($actif, null), 0.0);
verifier("mensuel inactif avec données", Impot::droitTimbreMensuel(['timbre_actif' => 0], ['timbre_encaissements_especes' => 100000, 'timbre_montant_manuel' => 500]), 0.0);
verifier("mensuel auto", Impot::droitTimbreMensuel($actif, ['timbre_encaissements_especes' => 100000, 'timbre_montant_manuel' => null]), 320.0);
verifier("mensuel manuel prime", Impot::droitTimbreMensuel($actif, ['timbre_encaissements_especes' => 100000, 'timbre_montant_manuel' => 2400]), 2400.0);
verifier("mensuel manuel 0 prime", Impot::droitTimbreMensuel($actif, ['timbre_encaissements_especes' => 100000, 'timbre_montant_manuel' => 0]), 0.0);
verifier("mensuel manuel chaîne DB", Impot::droitTimbreMensuel($actif, ['timbre_encaissements_especes' => '0.00', 'timbre_montant_manuel' => '1250.40']), 1250.0);
verifier("mensuel manuel négatif", Impot::droitTimbreMensuel($actif, ['timbre_montant_manuel' => -100]), 0.0);
verifier("mensuel colonnes absentes", Impot::droitTimbreMensuel($actif, []), 0.0);

echo $echecs === 0 ? "\nTOUT OK\n" : "\n$echecs ECHEC(S)\n";
exit($echecs === 0 ? 0 : 1);
```

- [ ] **Step 2: Lancer le test, vérifier qu'il échoue**

Run: `"C:/xampp/php/php.exe" "C:/Users/MOH/AppData/Local/Temp/claude/C--xampp-htdocs-IMPOT-3CE/6a47bc85-9240-464d-94ed-a018ca660025/scratchpad/test_droit_timbre.php"`
Expected: erreur fatale `Call to undefined method Impot::calculerDroitTimbre()`.

- [ ] **Step 3: Implémenter**

Dans `classes/Impot.php`, après l'accolade fermante de `calculerRetenueSourceBIC()` :

```php
    /**
     * Droit de timbre sur les paiements en espèces (Art. 397, barème simplifié)
     * < 1 000 F : 40 F ; 1 000 à 10 000 F : 120 F ; 10 000 à 50 000 F : 240 F ;
     * au-delà : montant / 50 000 x 160 (arrondi au franc).
     */
    public static function calculerDroitTimbre(float $montant): float
    {
        if ($montant <= 0) {
            return 0.0;
        }
        if ($montant < 1000) {
            return 40.0;
        }
        if ($montant <= 10000) {
            return 120.0;
        }
        if ($montant <= 50000) {
            return 240.0;
        }
        return (float) round($montant / 50000 * 160);
    }

    /**
     * Lire un montant saisi ("1 250 000", "75000,50") : null si vide ou non numérique
     */
    public static function lireMontantSaisi($brut): ?float
    {
        if ($brut === null) {
            return null;
        }
        $nettoye = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim((string) $brut));
        if ($nettoye === '' || !is_numeric($nettoye)) {
            return null;
        }
        return max(0.0, (float) $nettoye);
    }

    /**
     * Droit de timbre du mois : 0 si inactif, montant saisi s'il existe, sinon barème
     * appliqué au total encaissé en espèces.
     */
    public static function droitTimbreMensuel(?array $params, ?array $compte): float
    {
        if (!$params || empty($params['timbre_actif']) || !$compte) {
            return 0.0;
        }
        $manuel = $compte['timbre_montant_manuel'] ?? null;
        if ($manuel !== null && $manuel !== '') {
            return (float) round(max(0.0, (float) $manuel));
        }
        return self::calculerDroitTimbre((float) ($compte['timbre_encaissements_especes'] ?? 0));
    }
```

- [ ] **Step 4: Relancer le test**

Run: même commande qu'à l'étape 2, puis `"C:/xampp/php/php.exe" -l classes/Impot.php`
Expected: toutes les lignes `ok`, `TOUT OK`, code de sortie 0 ; `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add classes/Impot.php
git commit -m "feat(timbre): barème du droit de timbre sur paiements en espèces"
```

---

### Task 2: Colonnes et persistance mensuelle (`config/database.php`, `database/schema.sql`, `classes/CompteGestionMensuel.php`)

**Files:**
- Modify: `config/database.php` (tableau `$colonnesCompte` ~l.455-457, tableau `$colonnesParams` ~l.483, bloc « 6. Colonne Taxe Touristique » ~l.517-518)
- Modify: `database/schema.sql` (`parametres_fiscaux` ~l.73, `compte_gestion_mensuel` ~l.155, `impots_mensuels` ~l.336-339)
- Modify: `classes/CompteGestionMensuel.php` (propriétés ~l.73, getters ~l.216, setters ~l.384, hydratation ~l.526, `UPDATE` ~l.574 et ~l.607, `toArray()` ~l.1033)

**Interfaces:**
- Produces: colonnes `parametres_fiscaux.timbre_actif`, `compte_gestion_mensuel.timbre_encaissements_especes`, `compte_gestion_mensuel.timbre_montant_manuel`, `impots_mensuels.droit_timbre` ; méthodes `CompteGestionMensuel::getTimbreEncaissementsEspeces(): float`, `getTimbreMontantManuel(): ?float`, `setTimbreEncaissementsEspeces(float $m): self`, `setTimbreMontantManuel(?float $m): self` ; clés `toArray()` `timbre_encaissements_especes` et `timbre_montant_manuel`.

- [ ] **Step 1: Migration auto (`config/database.php`)**

Dans `$colonnesCompte`, après `'taxe_touristique_ligne520' => 'DECIMAL(15,2) DEFAULT 0',` :

```php
            'timbre_encaissements_especes' => 'DECIMAL(15,2) DEFAULT 0',
            'timbre_montant_manuel' => 'DECIMAL(15,2) DEFAULT NULL',
```

Dans `$colonnesParams`, après `'taxe_touristique_actif' => 'TINYINT(1) DEFAULT 0',` :

```php
            'timbre_actif' => 'TINYINT(1) DEFAULT 0',
```

Après `$this->ajouterColonneSiManquante('impots_mensuels', 'taxe_touristique', ...);` :

```php

        // 7. Colonne Droit de timbre (paiements en espèces, Art. 397)
        $this->ajouterColonneSiManquante('impots_mensuels', 'droit_timbre', 'DECIMAL(15,2) DEFAULT 0');
```

- [ ] **Step 2: Schéma des installations neuves (`database/schema.sql`)**

Dans `parametres_fiscaux`, après `taxe_touristique_actif BOOLEAN DEFAULT 0,` :
```sql
    timbre_actif BOOLEAN DEFAULT 0,
```
Dans `compte_gestion_mensuel`, après `taxe_touristique_ligne520 DECIMAL(15,2) DEFAULT 0,` :
```sql
    timbre_encaissements_especes DECIMAL(15,2) DEFAULT 0,
    timbre_montant_manuel DECIMAL(15,2) DEFAULT NULL,
```
Dans `impots_mensuels`, après `taxe_touristique DECIMAL(15,2) DEFAULT 0,` :
```sql

    -- Droit de timbre (paiements en espèces, Art. 397)
    droit_timbre DECIMAL(15,2) DEFAULT 0,
```
Vérifier aussi si `database/schema_mysql.sql` et `database/install_rapide.sql` contiennent `taxe_touristique` (`grep -n taxe_touristique database/*.sql`) ; si oui, ajouter les mêmes colonnes au même endroit dans ces fichiers.

- [ ] **Step 3: `CompteGestionMensuel`**

Propriétés, après `private float $taxeTouristiqueLigne520 = 0; ...` :
```php
    private float $timbreEncaissementsEspeces = 0; // Total encaissé en espèces (base du droit de timbre)
    private ?float $timbreMontantManuel = null; // Droit de timbre saisi (prime sur le calcul), null = calcul auto
```
Getters, après `getTaxeTouristiqueLigne520()` :
```php
    public function getTimbreEncaissementsEspeces(): float { return $this->timbreEncaissementsEspeces; }
    public function getTimbreMontantManuel(): ?float { return $this->timbreMontantManuel; }
```
Setters, après `setTaxeTouristiqueLigne520()` :
```php
    public function setTimbreEncaissementsEspeces(float $m): self { $this->timbreEncaissementsEspeces = round(max(0, $m), 2); return $this; }
    public function setTimbreMontantManuel(?float $m): self { $this->timbreMontantManuel = $m === null ? null : round(max(0, $m), 2); return $this; }
```
Hydratation, après `$this->taxeTouristiqueLigne520 = (float) ($data['taxe_touristique_ligne520'] ?? 0);` :
```php
        $this->timbreEncaissementsEspeces = (float) ($data['timbre_encaissements_especes'] ?? 0);
        $this->timbreMontantManuel = isset($data['timbre_montant_manuel']) ? (float) $data['timbre_montant_manuel'] : null;
```
Requête `UPDATE` de `sauvegarder()` : remplacer
```php
                taxe_touristique_type = ?, taxe_touristique_ligne510 = ?, taxe_touristique_ligne520 = ?,
```
par
```php
                taxe_touristique_type = ?, taxe_touristique_ligne510 = ?, taxe_touristique_ligne520 = ?,
                timbre_encaissements_especes = ?, timbre_montant_manuel = ?,
```
et dans le tableau de paramètres, remplacer
```php
            $this->taxeTouristiqueType, $this->taxeTouristiqueLigne510, $this->taxeTouristiqueLigne520,
```
par
```php
            $this->taxeTouristiqueType, $this->taxeTouristiqueLigne510, $this->taxeTouristiqueLigne520,
            $this->timbreEncaissementsEspeces, $this->timbreMontantManuel,
```
`toArray()`, après `'taxe_touristique_ligne520' => $this->taxeTouristiqueLigne520,` :
```php
            'timbre_encaissements_especes' => $this->timbreEncaissementsEspeces,
            'timbre_montant_manuel' => $this->timbreMontantManuel,
```

- [ ] **Step 4: Vérifier**

Run: `for f in config/database.php classes/CompteGestionMensuel.php; do "C:/xampp/php/php.exe" -l "$f"; done`
Expected: `No syntax errors detected` ×2.
Puis compter les `?` et les paramètres de l'`UPDATE` : `grep -c` ne suffit pas — relire la requête et vérifier que les 2 nouveaux `?` sont à la même position que les 2 nouvelles valeurs (juste après les 3 de la taxe touristique).
La création effective des colonnes est vérifiée à la Tâche 6 (démarrage du serveur local).

- [ ] **Step 5: Commit**

```bash
git add config/database.php database/*.sql classes/CompteGestionMensuel.php
git commit -m "feat(timbre): colonnes et persistance du droit de timbre"
```

---

### Task 3: Activation par client (`classes/Client.php`, `pages/client-nouveau.php`, `pages/client-edit.php`)

**Files:**
- Modify: `classes/Client.php` (`UPDATE parametres_fiscaux` ~l.405 et paramètres ~l.428)
- Modify: `pages/client-edit.php` (~l.68, ~l.106, ~l.150, case à cocher ~l.384-387)
- Modify: `pages/client-nouveau.php` (~l.44, ~l.81, ~l.127, case à cocher ~l.361-364)

**Interfaces:**
- Consumes: colonne `parametres_fiscaux.timbre_actif` (Tâche 2)
- Produces: `$params['timbre_actif']` (int 0/1) enregistré par `Client::setParametresFiscaux()`

- [ ] **Step 1: `Client::setParametresFiscaux()`**

Dans la requête, remplacer `taxe_touristique_actif = ?,` par :
```php
                taxe_touristique_actif = ?,
                timbre_actif = ?,
```
Dans le tableau, remplacer `$params['taxe_touristique_actif'] ?? 0,` par :
```php
            $params['taxe_touristique_actif'] ?? 0,
            $params['timbre_actif'] ?? 0,
```

- [ ] **Step 2: `client-edit.php`**

- Valeurs initiales, après `'taxe_touristique_actif' => $parametres['taxe_touristique_actif'] ?? '0',` :
  ```php
      'timbre_actif' => $parametres['timbre_actif'] ?? '0',
  ```
- Lecture POST, après `'taxe_touristique_actif' => isset($_POST['taxe_touristique_actif']) ? '1' : '0',` :
  ```php
          'timbre_actif' => isset($_POST['timbre_actif']) ? '1' : '0',
  ```
- Enregistrement, après `'taxe_touristique_actif' => (int) $donnees['taxe_touristique_actif'],` :
  ```php
                      'timbre_actif' => (int) $donnees['timbre_actif'],
  ```
- Case à cocher, juste après le `</label>` de la case `taxe_touristique_actif` :
  ```php
                          <label class="flex items-center">
                              <input type="checkbox" name="timbre_actif" value="1" <?= $donnees['timbre_actif'] == '1' ? 'checked' : '' ?>
                                     class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500">
                              <span class="ml-2 text-sm text-gray-600">Droit de timbre (paiements en espèces)</span>
                          </label>
  ```

- [ ] **Step 3: `client-nouveau.php`**

Mêmes 4 ajouts, avec ces différences : valeur initiale `'timbre_actif' => '0',` après `'taxe_touristique_actif' => '0',` ; la case à cocher compare avec `=== '1'` :
```php
                        <label class="flex items-center">
                            <input type="checkbox" name="timbre_actif" value="1" <?= $donnees['timbre_actif'] === '1' ? 'checked' : '' ?>
                                   class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500">
                            <span class="ml-2 text-sm text-gray-600">Droit de timbre (paiements en espèces)</span>
                        </label>
```
Lecture POST : `'timbre_actif' => isset($_POST['timbre_actif']) ? '1' : '0',` ; enregistrement : `'timbre_actif' => (int) $donnees['timbre_actif'],`.

- [ ] **Step 4: Vérifier**

Run: `for f in classes/Client.php pages/client-edit.php pages/client-nouveau.php; do "C:/xampp/php/php.exe" -l "$f"; done`
Expected: `No syntax errors detected` ×3. (Test navigateur à la Tâche 6.)

- [ ] **Step 5: Commit**

```bash
git add classes/Client.php pages/client-edit.php pages/client-nouveau.php
git commit -m "feat(timbre): activation du droit de timbre par client"
```

---

### Task 4: Saisie et calcul dans `pages/impots.php`

**Files:**
- Modify: `pages/impots.php`

**Interfaces:**
- Consumes: `Impot::calculerDroitTimbre`, `Impot::lireMontantSaisi`, `Impot::droitTimbreMensuel` (Tâche 1) ; getters/setters timbre de `CompteGestionMensuel` (Tâche 2) ; `$parametresFiscaux['timbre_actif']` (Tâche 3)
- Produces: `impots_mensuels.droit_timbre` renseigné à chaque enregistrement ; type d'impôt `timbre` (URL `?type=timbre`) ; ids DOM `timbre_encaissements_especes`, `timbre_montant_manuel`, `timbre_val_calcule`, `timbre_val_net`, `summary-timbre`

- [ ] **Step 1: Chargement des paramètres et des saisies**

Après `$taxeTouristiqueActif = ...;` (~l.66) :
```php
$timbreActif = $parametresFiscaux ? (int)($parametresFiscaux['timbre_actif'] ?? 0) : 0;
```
Après la ligne `$taxeTouristiqueLigne520 = ...;` (~l.120) :
```php

// Droit de timbre (paiements en espèces) - chargé depuis POST/GET ou base
$timbreEncaissementsEspeces = Impot::lireMontantSaisi($_POST['timbre_encaissements_especes'] ?? $_GET['timbre_encaissements_especes'] ?? null)
    ?? $compteGestion->getTimbreEncaissementsEspeces();
if (isset($_POST['timbre_montant_manuel']) || isset($_GET['timbre_montant_manuel'])) {
    $timbreMontantManuel = Impot::lireMontantSaisi($_POST['timbre_montant_manuel'] ?? $_GET['timbre_montant_manuel']);
} else {
    $timbreMontantManuel = $compteGestion->getTimbreMontantManuel();
}
```

- [ ] **Step 2: Calcul d'affichage et total**

Remplacer :
```php
// Total général
$totalImpots = $tvaNette + $cf + $tl + $its + $tf + $irf + $css + $tvaLocation + $ras + $taxeTouristique;
```
par :
```php
// Droit de timbre : montant saisi s'il existe, sinon barème sur le total encaissé en espèces
$droitTimbreCalcule = Impot::calculerDroitTimbre($timbreEncaissementsEspeces);
$droitTimbre = Impot::droitTimbreMensuel(
    ['timbre_actif' => $timbreActif],
    ['timbre_encaissements_especes' => $timbreEncaissementsEspeces, 'timbre_montant_manuel' => $timbreMontantManuel]
);

// Total général
$totalImpots = $tvaNette + $cf + $tl + $its + $tf + $irf + $css + $tvaLocation + $ras + $taxeTouristique + $droitTimbre;
```
Après `if ($typeImpot === 'taxe_touristique' && !$taxeTouristiqueActif) $typeImpot = 'tva';` :
```php
if ($typeImpot === 'timbre' && !$timbreActif) $typeImpot = 'tva';
```

- [ ] **Step 3: Enregistrement (bloc `if ($action === 'valider')`)**

Après les 3 lignes `$postTaxeTouristique...` :
```php

            // Droit de timbre
            $postTimbreEncaissements = Impot::lireMontantSaisi($_POST['timbre_encaissements_especes'] ?? null) ?? 0.0;
            $postTimbreManuel = Impot::lireMontantSaisi($_POST['timbre_montant_manuel'] ?? null);
```
Dans la chaîne de setters, remplacer `->setTaxeTouristiqueLigne520($postTaxeTouristiqueLigne520)` par :
```php
                          ->setTaxeTouristiqueLigne520($postTaxeTouristiqueLigne520)
                          ->setTimbreEncaissementsEspeces($postTimbreEncaissements)
                          ->setTimbreMontantManuel($postTimbreManuel)
```
Remplacer :
```php
            $total_new = $tva_nette_new + $cf_new + $tl_new + $postIts + $tf_new + $irf_new + $css_new + $tva_loc_new + $ras_new + $taxe_touristique_new;
```
par :
```php
            $droit_timbre_new = Impot::droitTimbreMensuel(
                ['timbre_actif' => $timbreActif],
                ['timbre_encaissements_especes' => $postTimbreEncaissements, 'timbre_montant_manuel' => $postTimbreManuel]
            );

            $total_new = $tva_nette_new + $cf_new + $tl_new + $postIts + $tf_new + $irf_new + $css_new + $tva_loc_new + $ras_new + $taxe_touristique_new + $droit_timbre_new;
```
Requête `UPDATE impots_mensuels` : remplacer `ras = ?, taxe_touristique = ?, total_impots = ?,` par `ras = ?, taxe_touristique = ?, droit_timbre = ?, total_impots = ?,` et dans son tableau remplacer `$ras_new, $taxe_touristique_new, $total_new, $existingImpots['id']` par `$ras_new, $taxe_touristique_new, $droit_timbre_new, $total_new, $existingImpots['id']`.
Requête `INSERT` : remplacer
```php
                $sqlImpots = "INSERT INTO impots_mensuels (client_id, compte_gestion_id, mois, annee, tva_a_payer, cf, its, tl, irf, tf, css, tva_location, ras, taxe_touristique, total_impots)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $db->insert($sqlImpots, [$clientId, $compteGestion->getId(), $mois, $annee, $tva_nette_new, $cf_new, $postIts, $tl_new, $irf_new, $tf_new, $css_new, $tva_loc_new, $ras_new, $taxe_touristique_new, $total_new]);
```
par
```php
                $sqlImpots = "INSERT INTO impots_mensuels (client_id, compte_gestion_id, mois, annee, tva_a_payer, cf, its, tl, irf, tf, css, tva_location, ras, taxe_touristique, droit_timbre, total_impots)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $db->insert($sqlImpots, [$clientId, $compteGestion->getId(), $mois, $annee, $tva_nette_new, $cf_new, $postIts, $tl_new, $irf_new, $tf_new, $css_new, $tva_loc_new, $ras_new, $taxe_touristique_new, $droit_timbre_new, $total_new]);
```

- [ ] **Step 4: Option du sélecteur et section de saisie**

Après le bloc `<?php if ($taxeTouristiqueActif): ?> <option value="taxe_touristique" ...> <?php endif; ?>` du `<select id="typeImpot">` :
```php
                            <?php if ($timbreActif): ?>
                            <option value="timbre" <?= $typeImpot === 'timbre' ? 'selected' : '' ?>>Droit de Timbre</option>
                            <?php endif; ?>
```
Juste après le `<?php endif; ?>` qui ferme la section `section-taxe_touristique` (avant `<!-- Section RAS ...`) :
```php

            <!-- Section Droit de Timbre - paiements en espèces (Art. 397) -->
            <?php if ($timbreActif): ?>
            <div id="section-timbre" class="<?= $typeImpot !== 'timbre' ? 'hidden' : '' ?>">
                <div class="bg-white rounded-xl shadow-sm border overflow-hidden mb-6">
                    <table class="tva-form">
                        <thead>
                            <tr>
                                <th class="col-ligne">Réf.</th>
                                <th>Désignation</th>
                                <th class="col-montant">Montant</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="row-section"><td colspan="3"><i class="fas fa-stamp mr-1"></i> Droit de Timbre sur les paiements en espèces (Art. 397)</td></tr>
                            <tr>
                                <td class="td-ligne">A</td>
                                <td>Total encaissé en espèces dans le mois</td>
                                <td class="td-montant"><input type="text" inputmode="decimal" name="timbre_encaissements_especes" id="timbre_encaissements_especes" class="input-manual" style="width:150px;" value="<?= $timbreEncaissementsEspeces > 0 ? htmlspecialchars((string) $timbreEncaissementsEspeces) : '' ?>" oninput="recalcTimbre()"></td>
                            </tr>
                            <tr>
                                <td class="td-ligne">B</td>
                                <td>Droit de timbre calculé <span class="text-xs font-normal text-slate-500">(&lt; 1 000 F : 40 F · jusqu'à 10 000 F : 120 F · jusqu'à 50 000 F : 240 F · au-delà : montant ÷ 50 000 × 160)</span></td>
                                <td class="td-montant" id="timbre_val_calcule"><?= formatMontant($droitTimbreCalcule) ?></td>
                            </tr>
                            <tr>
                                <td class="td-ligne">C</td>
                                <td>Montant du timbre saisi <span class="text-xs font-normal text-slate-500">(facultatif — remplace le calcul ; le barème appliqué au total peut être inférieur au timbre réel quand il y a plusieurs reçus)</span></td>
                                <td class="td-montant"><input type="text" inputmode="decimal" name="timbre_montant_manuel" id="timbre_montant_manuel" class="input-manual" style="width:150px;" value="<?= $timbreMontantManuel !== null ? htmlspecialchars((string) $timbreMontantManuel) : '' ?>" oninput="recalcTimbre()"></td>
                            </tr>
                            <tr class="row-result">
                                <td class="td-ligne" style="background:#dcfce7; color:#16a34a;">=</td>
                                <td style="color:#16a34a;">Droit de Timbre à Payer <span class="text-xs font-normal">(C si saisi, sinon B)</span></td>
                                <td class="td-montant" id="timbre_val_net" style="font-size:16px; color:#16a34a;"><?= formatMontant($droitTimbre) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
```
Vérifier que l'icône `fa-stamp` existe dans la copie locale : `grep -c "fa-stamp" assets/vendor/fontawesome/css/all.min.css` ≥ 1 ; sinon utiliser `fa-receipt`.

- [ ] **Step 5: Mini-carte de synthèse**

Après le bloc `<!-- Taxe Touristique --> ... <?php endif; ?>` de la synthèse :
```php

                <!-- Droit de Timbre -->
                <?php if ($timbreActif): ?>
                <div class="bg-white rounded-lg border-2 border-slate-200 p-4">
                    <div class="flex items-center mb-2">
                        <i class="fas fa-check-square text-green-500 mr-2"></i>
                        <span class="font-semibold text-slate-700">Droit de Timbre</span>
                    </div>
                    <div class="text-lg font-bold text-slate-800"><span id="summary-timbre"><?= formatMontant($droitTimbre) ?></span> F</div>
                </div>
                <?php endif; ?>
```

- [ ] **Step 6: JavaScript**

Dans `updateSummary()`, remplacer :
```js
        const total = tva + cf + tl + its + tf + irf + css + tvaLocFinal + ras + taxeTouristique;
```
par :
```js
        const droitTimbre = <?= $timbreActif ? '1' : '0' ?> ? parseValue('timbre_val_net') : 0;
        const sTimbre = getEl('summary-timbre'); if (sTimbre) sTimbre.textContent = fmt(droitTimbre);
        const total = tva + cf + tl + its + tf + irf + css + tvaLocFinal + ras + taxeTouristique + droitTimbre;
```
Après la fonction `recalcTaxeTouristique()` :
```js

    // Droit de timbre : copie conforme de Impot::calculerDroitTimbre()
    function calculerDroitTimbreJS(montant) {
        if (montant <= 0) return 0;
        if (montant < 1000) return 40;
        if (montant <= 10000) return 120;
        if (montant <= 50000) return 240;
        return Math.round(montant / 50000 * 160);
    }

    function recalcTimbre() {
        const calcule = calculerDroitTimbreJS(parseInput('timbre_encaissements_especes'));
        const elManuel = getEl('timbre_montant_manuel');
        const manuelBrut = elManuel ? elManuel.value.replace(/[\s\u00A0\u202F]/g, '').replace(',', '.') : '';
        const net = (manuelBrut === '' || isNaN(parseFloat(manuelBrut))) ? calcule : Math.max(0, Math.round(parseFloat(manuelBrut)));
        const elCalc = getEl('timbre_val_calcule'); if (elCalc) elCalc.textContent = fmt(calcule);
        const elNet = getEl('timbre_val_net'); if (elNet) elNet.textContent = fmt(net);
        updateSummary();
    }

    // Conserver les saisies timbre dans l'URL lors d'un changement de type ou de marge
    function conserverTimbre(url) {
        let el;
        el = getEl('timbre_encaissements_especes'); if (el) url.searchParams.set('timbre_encaissements_especes', el.value);
        el = getEl('timbre_montant_manuel'); if (el) url.searchParams.set('timbre_montant_manuel', el.value);
    }
```
Note : `parseInput` utilise `\s` (qui couvre l'espace insécable en JS) — pas de changement nécessaire.
Dans `recalcAll()`, après `if (typeof recalcTaxeTouristique === 'function') recalcTaxeTouristique();` :
```js
        if (typeof recalcTimbre === 'function') recalcTimbre();
```
Dans `changerType()` **et** dans `changerMargeGlobal()`, juste après chaque appel `conserverLocLignes(url);` :
```js
        conserverTimbre(url);
```
(Vérifier avec `grep -n "conserverLocLignes(url)" pages/impots.php` qu'il n'existe pas d'autre fonction de navigation qui l'appelle ; si oui, y ajouter aussi `conserverTimbre(url);`.)

- [ ] **Step 7: Vérifier**

Run: `"C:/xampp/php/php.exe" -l pages/impots.php`
Expected: `No syntax errors detected`.
Puis relire les deux requêtes `impots_mensuels` : `UPDATE` = 13 `?` pour 13 valeurs (12 montants + `id`) ; `INSERT` = 16 colonnes, 16 `?`, 16 valeurs.
(Test navigateur — y compris le point 4 de la Review Focus — à la Tâche 6.)

- [ ] **Step 8: Commit**

```bash
git add pages/impots.php
git commit -m "feat(timbre): saisie et calcul du droit de timbre dans la page impôts"
```

---

### Task 5: Récapitulatifs et rapport annuel

**Files:**
- Modify: `pages/recapitulatif.php` (~l.232-246)
- Modify: `pages/recap-paiements.php` (~l.188-194 et tableau ~l.463-468)
- Modify: `pages/rapport-annuel.php` (~l.146, ~l.162-163, ~l.450)

**Interfaces:**
- Consumes: `Impot::droitTimbreMensuel(?array, ?array)` (Tâche 1) ; colonnes/clés `timbre_*` (Tâche 2) ; `impots_mensuels.droit_timbre` (Tâche 4)

- [ ] **Step 1: `recapitulatif.php`**

Vérifier que `Impot.php` est chargé (`grep -n "Impot.php" pages/recapitulatif.php` — la page appelle déjà `Impot::calculerRetenueSourceBIC`). Après le bloc `$taxeTouristique = ...;` (2h) :
```php

// 2i. Droit de timbre (paiements en espèces), si activé pour ce client
$droitTimbre = Impot::droitTimbreMensuel($parametresFiscaux ?: null, $compteGestion ?: null);
```
Remplacer :
```php
$totalImpots = $tvaNette + $cf + $tl + $its + $irf + $tf + $css + $tvaLocation + $ras + $taxeTouristique;
```
par :
```php
$totalImpots = $tvaNette + $cf + $tl + $its + $irf + $tf + $css + $tvaLocation + $ras + $taxeTouristique + $droitTimbre;
```

- [ ] **Step 2: `recap-paiements.php`**

Vérifier `grep -n "Impot.php\|Impot::" pages/recap-paiements.php` ; si `Impot.php` n'est pas inclus, ajouter `require_once __DIR__ . '/../classes/Impot.php';` à côté des autres `require_once` de classes en tête de fichier. Après le bloc `$taxeTouristique = ...;` (~l.189-191) :
```php

// Droit de timbre (paiements en espèces), si activé pour ce client
$timbreActif = $parametres ? (int)($parametres['timbre_actif'] ?? 0) : 0;
$droitTimbre = Impot::droitTimbreMensuel($parametres ?: null, $compteGestion ?: null);
```
Remplacer :
```php
$totalImpots = $tvaNet + $cf + $tl + $its + $css + $irf + $tf + $tvaLocation + $ras + $taxeTouristique;
```
par :
```php
$totalImpots = $tvaNet + $cf + $tl + $its + $css + $irf + $tf + $tvaLocation + $ras + $taxeTouristique + $droitTimbre;
```
Dans le tableau, après le bloc `<?php if ($taxeTouristiqueActif): ?> ... <?php endif; ?>` et avant `<tr class="total-row">` :
```php
                    <?php if ($timbreActif): ?>
                    <tr>
                        <td>Droit de Timbre</td>
                        <td class="<?= $droitTimbre == 0 ? 'zero' : '' ?>"><?= formatMontant($droitTimbre) ?></td>
                    </tr>
                    <?php endif; ?>
```

- [ ] **Step 3: `rapport-annuel.php`**

Dans la requête annuelle, après `SUM(taxe_touristique) as taxe_touristique_total,` :
```php
            SUM(droit_timbre) as droit_timbre_total,
```
Remplacer :
```php
$taxeTouristiqueAnnuel = (float)($impotsAnnuels['taxe_touristique_total'] ?? 0);
$totalImpotsAnnuel = $tvaAnnuel + $cfAnnuel + $itsImpotsAnnuel + $tlAnnuel + $irfAnnuel + $tvaLocationAnnuel + $tfAnnuel + $cssAnnuel + $taxeTouristiqueAnnuel;
```
par :
```php
$taxeTouristiqueAnnuel = (float)($impotsAnnuels['taxe_touristique_total'] ?? 0);
$droitTimbreAnnuel = (float)($impotsAnnuels['droit_timbre_total'] ?? 0);
$totalImpotsAnnuel = $tvaAnnuel + $cfAnnuel + $itsImpotsAnnuel + $tlAnnuel + $irfAnnuel + $tvaLocationAnnuel + $tfAnnuel + $cssAnnuel + $taxeTouristiqueAnnuel + $droitTimbreAnnuel;
```
Dans `$lignesImpots`, après `['Taxe Touristique', $taxeTouristiqueAnnuel],` :
```php
                            ['Droit de Timbre', $droitTimbreAnnuel],
```
(Le bloc de recalcul de secours « si impots_mensuels est vide » n'inclut déjà ni RAS ni Taxe Touristique : ne pas le modifier.)

- [ ] **Step 4: Vérifier**

Run: `for f in pages/recapitulatif.php pages/recap-paiements.php pages/rapport-annuel.php; do "C:/xampp/php/php.exe" -l "$f"; done`
Expected: `No syntax errors detected` ×3.

- [ ] **Step 5: Commit**

```bash
git add pages/recapitulatif.php pages/recap-paiements.php pages/rapport-annuel.php
git commit -m "feat(timbre): droit de timbre dans les récapitulatifs et le rapport annuel"
```

---

### Task 6: Vérification de bout en bout

**Files:** aucun (sauf correctifs éventuels, commités séparément)

- [ ] **Step 1: Relancer le test du barème**

Run: commande de la Tâche 1, étape 2. Expected: `TOUT OK`.

- [ ] **Step 2: Démarrer un serveur local**

Run (en arrière-plan) : `"C:/xampp/php/php.exe" -S 127.0.0.1:8099 -t "C:/xampp/htdocs/IMPOT 3CE"`
Ouvrir `http://127.0.0.1:8099/index.php`, se connecter `admin@cabinet.local` / `admin123`.
(Le premier chargement exécute la migration : les colonnes timbre sont créées.)

- [ ] **Step 3: Parcours fonctionnel**

1. `pages/clients.php` → modifier un client → cocher « Droit de timbre (paiements en espèces) » → enregistrer → rouvrir : case toujours cochée.
2. `pages/impots.php?client=<id>&mois=<m>&annee=<a>` → sélecteur « Droit de Timbre » présent.
3. Saisir `100 000` en A → B et « à payer » = 320, mini-carte = 320, total augmenté de 320.
4. Saisir `1 250 000` → 4 000. Saisir `999` → 40. Saisir `50001` → 160.
5. **Review Focus 4 :** saisir `100000`, puis changer de type d'impôt (ex. TVA) puis revenir sur « Droit de Timbre » sans enregistrer → `100000` toujours là.
6. Saisir `2400` en C → « à payer » = 2 400 ; enregistrer ; recharger → A et C conservés, 2 400 affiché.
7. Vider C, enregistrer → retour au calcul auto (320). Mettre C = `0`, enregistrer → 0.
8. `pages/recapitulatif.php` et `pages/recap-paiements.php` (même client/mois) → total inclut le timbre ; ligne « Droit de Timbre » dans le récap paiements.
9. `pages/recap-paiements.php` sur un mois **sans** données → pas de plantage (Review Focus 3).
10. `pages/rapport-annuel.php` → ligne « Droit de Timbre » = somme des mois enregistrés.
11. Client **sans** le timbre activé → aucune option, aucune carte, aucune ligne, total inchangé.
12. Décocher le timbre sur le client de test → réenregistrer un mois → `droit_timbre` = 0, total sans timbre.

- [ ] **Step 4: Arrêter le serveur et faire le point**

Arrêter le serveur `php -S`. Supprimer le script de test du scratchpad n'est pas nécessaire (hors dépôt). Rapporter à l'utilisateur les résultats exacts ; ne pas pousser sans son accord.

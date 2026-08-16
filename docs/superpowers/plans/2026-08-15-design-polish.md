# Design Polish (Composants Partagés + 3 Écrans Pilotes) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduce a small shared Tailwind component layer (cards, stat cards, badges, buttons) in `assets/css/input.css`, harmonize the two page-nav shells, and apply the new classes to 3 high-traffic screens (Accueil, Liste des clients, Gestion des impôts) — without changing the color palette or any business logic.

**Architecture:** Pure CSS/markup change. New reusable classes live in `@layer components` of `assets/css/input.css`, compiled to `assets/css/style.css` via the existing Tailwind build (`npm run build`). Pages keep their existing PHP logic untouched; only the HTML they render is edited to use the new classes instead of ad hoc inline Tailwind utility strings.

**Tech Stack:** Tailwind CSS v4 (`@import "tailwindcss"`, `@theme`, `@layer components`), PHP 8 (no framework), no automated test suite in this repo.

**Spec:** `docs/superpowers/specs/2026-08-15-design-polish-design.md`

**Correction (mid-execution, after Tasks 1-2 landed):** the spec and this plan's original Task 3-5 snippets were drafted by reading `pages/dashboard.php`/`pages/clients.php`/`pages/impots.php` from a working tree that had local uncommitted edits never present in git history. Against the actual tracked codebase: only `pages/importation.php` uses `includes/header.php` (Task 2's harmonization, already done, is real but lower-impact than originally described); every other page — including all 3 pilots — builds its own bespoke inline header, and status colors (`etat_class`) are computed inline per page rather than via a shared helper. Tasks 3-5 below were corrected to match the real tracked file content before being dispatched; see the SDD ledger's Task 3 entry for the full finding.

## Global Constraints

- No new colors — reuse `primary-*` / `slate-*` / `gray-*` / `red-*` / `green-*` / `amber-*` tokens already defined in `assets/css/input.css`.
- No change to `classes/*.php` business logic, SQL, or data flow.
- Status colors (`$client['etat_class']`, e.g. `bg-green-500`/`bg-amber-500`/`bg-red-500`/`bg-slate-400`) are computed inline, independently, in each page that needs them (there is no shared helper for this in the tracked codebase) — the new `.badge`/`.badge-dot` classes stay color-agnostic (shape/spacing/typography only) and pages keep supplying `etat_class` alongside them exactly as before; do not touch the PHP that computes `etat_class`.
- No automated test suite exists in this repo (per `CLAUDE.md`) — every task's verification step is a concrete manual check: run `npm run build`, then load the page in a browser at `http://localhost/IMPOT%203CE/pages/<page>.php` (adjust to the actual local vhost/docroot) logged in as `admin@cabinet.local` / `admin123`, and confirm the described visual/functional outcome.
- Bump the CSS cache-busting query (`style.css?v=1.1` → `?v=1.2`) wherever it appears, so browsers don't serve a stale cached stylesheet after the rebuild.
- `header.php` and `navbar-impots.php` keep their separate responsibilities (global nav vs. client-contextual nav) — harmonize appearance only, not structure.

---

## File Structure

- Modify: `assets/css/input.css` — add `@layer components` block (new classes only, no new tokens).
- Modify: `assets/css/style.css` — regenerated build output (never hand-edited).
- Modify: `includes/header.php` — align body background and secondary-menu bar styling with `includes/navbar-impots.php`; bump cache-busting version.
- Modify: `pages/dashboard.php` — apply `.card`, `.card-stat`, `.badge`, `.btn-*` to the Accueil screen.
- Modify: `pages/clients.php` — apply `.card`, `.badge`, `.btn-*`, `.table-clean` to the Liste des clients screen.
- Modify: `pages/impots.php` — apply `.badge`-equivalent alert style and `.btn-*` to the top message banner, type-selector panel, and the final action buttons row (the large per-tax-type calculation tables in the middle of the file are out of scope for this pilot).

---

### Task 1: Shared component layer in `assets/css/input.css`

**Files:**
- Modify: `assets/css/input.css`
- Modify: `includes/header.php:52` (cache-busting version bump)

**Interfaces:**
- Produces (CSS classes consumed by Tasks 2–5): `.card`, `.card-header`, `.card-stat`, `.card-stat-value`, `.card-stat-label`, `.card-stat-accent`, `.card-stat-warn`, `.card-stat-neutral`, `.badge`, `.badge-dot`, `.btn`, `.btn-primary`, `.btn-secondary`, `.btn-success`, `.btn-danger`, `.btn-outline`, `.table-clean`.

- [ ] **Step 1: Append the component layer to `assets/css/input.css`**

Add this block at the end of the file (after the existing `@layer utilities { ... }` block):

```css
@layer components {
  /*
   * Échelle d'élévation/rayon commune à tout le rollout design polish :
   * - Contenu (cartes, panneaux) : rounded-xl + shadow-sm + border slate-100
   * - Nav globale (header.php)   : shadow-xl (déjà en place, ne pas changer)
   * - Éléments interactifs       : rounded-lg
   */

  .card {
    @apply bg-white rounded-xl shadow-sm border border-slate-100 p-6;
  }

  .card-header {
    @apply px-6 py-4 border-b border-slate-100;
  }

  .card-stat {
    @apply bg-white rounded-xl shadow-sm border border-slate-100 border-l-4 border-l-slate-300 px-6 py-4 min-w-35;
  }

  .card-stat-accent {
    @apply border-l-primary-600;
  }

  .card-stat-warn {
    @apply border-l-red-600;
  }

  .card-stat-neutral {
    @apply border-l-slate-300;
  }

  .card-stat-label {
    @apply text-sm text-slate-500;
  }

  .card-stat-value {
    @apply text-3xl font-bold text-slate-800;
  }

  .card-stat-warn .card-stat-value {
    @apply text-red-700;
  }

  .badge {
    @apply inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold;
  }

  .badge-dot {
    @apply inline-block w-2 h-2 rounded-full;
  }

  .btn {
    @apply inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-offset-1;
  }

  /* Tailwind v4 cannot @apply a custom @layer components class into another
     (only core utilities) — each variant below repeats .btn's base list
     inline instead of composing "@apply btn ...". */

  .btn-primary {
    @apply inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-offset-1 bg-primary-600 text-white hover:bg-primary-700 focus:ring-primary-500;
  }

  .btn-secondary {
    @apply inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-offset-1 bg-slate-600 text-white hover:bg-slate-700 focus:ring-slate-400;
  }

  .btn-success {
    @apply inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-offset-1 bg-green-700 text-white hover:bg-green-800 focus:ring-green-500;
  }

  .btn-danger {
    @apply inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-offset-1 bg-red-700 text-white hover:bg-red-800 focus:ring-red-500;
  }

  .btn-outline {
    @apply inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-offset-1 border border-slate-300 text-slate-700 bg-white hover:bg-slate-50 focus:ring-slate-300;
  }

  .table-clean {
    @apply w-full;
  }

  .table-clean thead {
    @apply bg-slate-50 border-b border-slate-100;
  }

  .table-clean th {
    @apply px-6 py-3 text-left text-sm font-medium text-slate-600;
  }

  .table-clean tbody {
    @apply divide-y divide-slate-100;
  }

  .table-clean tbody tr {
    @apply hover:bg-slate-50 transition-colors;
  }
}
```

- [ ] **Step 2: Rebuild the compiled stylesheet**

Run: `npm run build`
Expected: exits 0 and reports the minified `assets/css/style.css` was written (no Tailwind syntax errors).

- [ ] **Step 3: Verify the new classes made it into the compiled CSS**

Run: `grep -c "card-stat-warn" "assets/css/style.css"` and `grep -c "btn-primary" "assets/css/style.css"`
Expected: both return a number greater than 0 (the class names survive minification as literal selectors).

- [ ] **Step 4: Bump the cache-busting version in `includes/header.php`**

In `includes/header.php`, change:

```php
    <link rel="stylesheet" href="<?= $basePath ?>assets/css/style.css?v=1.1">
```

to:

```php
    <link rel="stylesheet" href="<?= $basePath ?>assets/css/style.css?v=1.2">
```

Also update the Font Awesome line right below it for consistency (same file, same pattern):

```php
    <link rel="stylesheet" href="<?= $basePath ?>assets/vendor/fontawesome/css/all.min.css?v=1.1">
```

to:

```php
    <link rel="stylesheet" href="<?= $basePath ?>assets/vendor/fontawesome/css/all.min.css?v=1.2">
```

- [ ] **Step 5: Commit**

```bash
git add assets/css/input.css assets/css/style.css includes/header.php
git commit -m "style: add shared card/badge/button component layer"
```

---

### Task 2: Harmonize the two nav shells

**Files:**
- Modify: `includes/header.php`

**Interfaces:**
- Consumes: none (pure markup/class change).
- Produces: `header.php`'s body background and secondary-menu bar now visually match `navbar-impots.php`'s breadcrumb bar, so navigating between global pages (Accueil, Clients) and a client's tax-declaration pages (Achats, Impôts…) no longer shows a visible background-color jump.

- [ ] **Step 1: Align the body background**

In `includes/header.php`, change:

```php
<body class="bg-gray-100 min-h-screen">
```

to:

```php
<body class="bg-slate-100 min-h-screen">
```

- [ ] **Step 2: Align the secondary-menu bar's shadow/border to match `navbar-impots.php`'s breadcrumb bar**

In `includes/header.php`, change:

```php
    <!-- Menu secondaire -->
    <div class="bg-white shadow no-print">
```

to:

```php
    <!-- Menu secondaire -->
    <div class="bg-white border-b shadow-sm no-print">
```

- [ ] **Step 3: Manual verification**

Run: `npm run build` is not needed here (no new CSS classes, only existing utility classes). Start the app (`npm start`, or XAMPP + browse to `pages/dashboard.php`), log in, and:
1. On `dashboard.php`, confirm the page background is a light slate gray (not the previous cooler gray) and the white sub-menu bar under the dark nav has a thin bottom border + soft shadow.
2. Navigate to a client's `impots.php?client=<id>` page and confirm the page background color now visually matches what you just saw on `dashboard.php` (same shade of slate-100).

- [ ] **Step 4: Commit**

```bash
git add includes/header.php
git commit -m "style: harmonize header.php background/shadow with navbar-impots.php"
```

---

### Task 3: Apply shared components to `pages/dashboard.php` (Accueil)

**Files:**
- Modify: `pages/dashboard.php` (profile + KPI card, row action buttons, footer link button — this pilot leaves the page's own `<header>`/breadcrumb block, lines ~113-145, untouched; see Note below)

**Interfaces:**
- Consumes: `.card`, `.card-stat`, `.card-stat-accent`, `.card-stat-warn`, `.card-stat-neutral`, `.card-stat-label`, `.card-stat-value`, `.badge`, `.badge-dot`, `.btn-primary`, `.btn-secondary`, `.btn-success`, `.btn-outline` (Task 1). `$client['etat_class']` / `$client['retard']` (existing PHP vars, unchanged — computed inline in this file, not via a shared helper; see Global Constraints).

**Note:** `pages/dashboard.php` builds its own `<!DOCTYPE>/<head>/<header>` inline (it does not include `includes/header.php` or `includes/navbar-impots.php`) — same pattern as most other pages in this codebase. This task only touches the card/KPI/badge/button markup below the header, per the file structure above; the header block itself is out of scope for this pilot (unifying the many bespoke inline headers is rollout-scope work, not part of these 3 pilots).

- [ ] **Step 1: Replace the flat KPI blocks with `.card-stat`**

In `pages/dashboard.php`, change:

```php
                <!-- KPIs -->
                <div class="flex space-x-4">
                    <!-- Nombre de clients -->
                    <div class="bg-primary-600 text-white px-6 py-4 rounded-lg text-center min-w-35">
                        <div class="text-sm opacity-80">Nombre de clients</div>
                        <div class="text-3xl font-bold"><?= $totalClients ?></div>
                    </div>
                    
                    <!-- Mois actif -->
                    <div class="bg-slate-200 text-slate-700 px-6 py-4 rounded-lg text-center min-w-35">
                        <div class="text-sm">Mois actif</div>
                        <div class="text-lg font-bold"><?= $moisNoms[$moisActuel] ?> <?= $anneeActuelle ?></div>
                    </div>
                    
                    <!-- Clients en retard -->
                    <div class="<?= $clientsEnRetard > 0 ? 'bg-red-600' : 'bg-green-600' ?> text-white px-6 py-4 rounded-lg text-center min-w-35">
                        <div class="text-sm opacity-80">Retards (<?= $moisNoms[$moisPrecedent] ?>)</div>
                        <div class="text-3xl font-bold"><?= $clientsEnRetard ?></div>
                        <div class="text-xs opacity-70 mt-1">Limite: <?= date('d/m/Y', strtotime($dateLimiteMoisPrecedent)) ?></div>
                    </div>
                </div>
```

to:

```php
                <!-- KPIs -->
                <div class="flex space-x-4">
                    <!-- Nombre de clients -->
                    <div class="card-stat card-stat-accent text-center">
                        <div class="card-stat-label">Nombre de clients</div>
                        <div class="card-stat-value"><?= $totalClients ?></div>
                    </div>
                    
                    <!-- Mois actif -->
                    <div class="card-stat card-stat-neutral text-center">
                        <div class="card-stat-label">Mois actif</div>
                        <div class="text-lg font-bold text-slate-800"><?= $moisNoms[$moisActuel] ?> <?= $anneeActuelle ?></div>
                    </div>
                    
                    <!-- Clients en retard -->
                    <div class="card-stat <?= $clientsEnRetard > 0 ? 'card-stat-warn' : 'card-stat-accent' ?> text-center">
                        <div class="card-stat-label">Retards (<?= $moisNoms[$moisPrecedent] ?>)</div>
                        <div class="card-stat-value"><?= $clientsEnRetard ?></div>
                        <div class="text-xs text-slate-400 mt-1">Limite: <?= date('d/m/Y', strtotime($dateLimiteMoisPrecedent)) ?></div>
                    </div>
                </div>
```

- [ ] **Step 2: Replace the two action buttons with `.btn-*`**

In `pages/dashboard.php`, change:

```php
            <!-- Boutons d'action -->
            <div class="mt-6 flex space-x-4">
                <a href="clients.php" class="flex-1 py-3 bg-slate-700 text-white text-center rounded-lg hover:bg-slate-800 transition">
                    <i class="fas fa-users mr-2"></i> Portefeuille clients
                </a>
                <a href="client-nouveau.php" class="flex-1 py-3 bg-green-600 text-white text-center rounded-lg hover:bg-green-700 transition">
                    <i class="fas fa-plus mr-2"></i> Nouveau client
                </a>
            </div>
```

to:

```php
            <!-- Boutons d'action -->
            <div class="mt-6 flex space-x-4">
                <a href="clients.php" class="btn-secondary flex-1 py-3">
                    <i class="fas fa-users"></i> Portefeuille clients
                </a>
                <a href="client-nouveau.php" class="btn-success flex-1 py-3">
                    <i class="fas fa-plus"></i> Nouveau client
                </a>
            </div>
```

- [ ] **Step 3: Standardize the status badge and row action buttons in the "Clients récents" table**

In `pages/dashboard.php`, change:

```php
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center">
                                <span class="w-2 h-2 rounded-full <?= $client['etat_class'] ?> mr-2"></span>
                                <?= $client['etat_label'] ?>
                            </span>
                        </td>
```

to:

```php
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center">
                                <span class="badge-dot <?= $client['etat_class'] ?> mr-2"></span>
                                <?= $client['etat_label'] ?>
                            </span>
                        </td>
```

Then change:

```php
                        <td class="px-6 py-4 text-center">
                            <div class="flex justify-center space-x-2">
                                <a href="achats.php?client=<?= $client['id'] ?>&mois=<?= $moisActuel ?>&annee=<?= $anneeActuelle ?>" 
                                   class="inline-flex items-center px-4 py-2 bg-primary-600 text-white text-sm font-medium rounded-lg hover:bg-primary-700 transition-colors">
                                    <i class="fas fa-folder-open mr-2"></i>
                                    Ouvrir
                                </a>
                                <a href="rapport-annuel.php?client=<?= $client['id'] ?>&annee=<?= $anneeActuelle ?>" 
                                   class="inline-flex items-center px-3 py-2 bg-slate-600 text-white text-sm font-medium rounded-lg hover:bg-slate-700 transition-colors" title="Rapport Annuel">
                                    <i class="fas fa-chart-bar mr-1"></i>
                                    Annuel
                                </a>
                            </div>
                        </td>
```

to:

```php
                        <td class="px-6 py-4 text-center">
                            <div class="flex justify-center space-x-2">
                                <a href="achats.php?client=<?= $client['id'] ?>&mois=<?= $moisActuel ?>&annee=<?= $anneeActuelle ?>" 
                                   class="btn-primary">
                                    <i class="fas fa-folder-open"></i>
                                    Ouvrir
                                </a>
                                <a href="rapport-annuel.php?client=<?= $client['id'] ?>&annee=<?= $anneeActuelle ?>" 
                                   class="btn-secondary" title="Rapport Annuel">
                                    <i class="fas fa-chart-bar"></i>
                                    Annuel
                                </a>
                            </div>
                        </td>
```

Finally, change the "Voir tous les clients" link:

```php
                <a href="clients.php" class="inline-flex items-center px-6 py-2 border border-slate-300 text-slate-700 rounded-lg hover:bg-slate-50 transition">
                    Voir tous les clients
                </a>
```

to:

```php
                <a href="clients.php" class="btn-outline">
                    Voir tous les clients
                </a>
```

- [ ] **Step 4: Manual verification**

Start the app, log in, open `pages/dashboard.php`:
1. The 3 KPI tiles are white cards with a colored left border (blue for "Nombre de clients", blue or red for "Retards" depending on whether the count is 0), not flat colored blocks — matches `.card-stat` from Task 1.
2. "Portefeuille clients" / "Nouveau client" buttons render with the same rounded-lg + hover-darken behavior as before.
3. In "Clients récents", the status dot + label still shows the correct color per client (green/amber/red — unchanged colors, only the dot's CSS class name changed).
4. "Ouvrir" / "Annuel" / "Voir tous les clients" buttons still navigate to the same URLs as before.

- [ ] **Step 5: Commit**

```bash
git add pages/dashboard.php
git commit -m "style: apply shared card/badge/button components to dashboard.php"
```

---

### Task 4: Apply shared components to `pages/clients.php` (Liste des clients)

**Files:**
- Modify: `pages/clients.php` (filter panel, table) — this pilot leaves the page's own `<header>` block untouched, same as Task 3 (see its Note).

**Interfaces:**
- Consumes: `.card`, `.badge`, `.btn-primary`, `.btn-secondary` (used for the amber "edit" and indigo "rapport" actions — see note below), `.btn-success`, `.btn-outline`, `.table-clean` (Task 1).
- `$client['etat_class']` here is a solid background (`bg-green-500`/`bg-amber-500`/`bg-red-500`, computed inline in this file — same as Task 3's note on this) — keep `text-white` alongside `.badge` in Step 3 below, since `.badge` itself sets no text color and the solid background needs it for contrast.

- [ ] **Step 1: Replace the filter panel wrapper and its buttons with `.card`/`.btn-*`**

In `pages/clients.php`, change:

```php
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-slate-800">Liste des Clients</h1>
            <a href="client-nouveau.php" class="inline-flex items-center px-4 py-2 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700 transition-colors">
                <i class="fas fa-plus mr-2"></i> Nouveau client
            </a>
        </div>

        <!-- Filtres -->
        <div class="bg-white rounded-xl shadow-sm p-4 mb-6">
```

to:

```php
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-slate-800">Liste des Clients</h1>
            <a href="client-nouveau.php" class="btn-success">
                <i class="fas fa-plus"></i> Nouveau client
            </a>
        </div>

        <!-- Filtres -->
        <div class="card mb-6 p-4">
```

Then change the filter form's submit/reset buttons:

```php
                <button type="submit" class="px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700">
                    <i class="fas fa-filter mr-2"></i> Filtrer
                </button>
                
                <?php if (!empty($recherche) || !empty($filtreActivite) || !empty($filtreEtat)): ?>
                <a href="clients.php" class="px-4 py-2 border border-slate-300 text-slate-700 rounded-lg hover:bg-slate-50">
                    <i class="fas fa-times mr-2"></i> Réinitialiser
                </a>
                <?php endif; ?>
```

to:

```php
                <button type="submit" class="btn-primary">
                    <i class="fas fa-filter"></i> Filtrer
                </button>
                
                <?php if (!empty($recherche) || !empty($filtreActivite) || !empty($filtreEtat)): ?>
                <a href="clients.php" class="btn-outline">
                    <i class="fas fa-times"></i> Réinitialiser
                </a>
                <?php endif; ?>
```

- [ ] **Step 2: Switch the table wrapper and header to `.card`/`.table-clean`**

In `pages/clients.php`, change:

```php
        <!-- Tableau des clients -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <table class="w-full">
                <thead class="bg-slate-50 border-b">
                    <tr>
```

to:

```php
        <!-- Tableau des clients -->
        <div class="card overflow-hidden p-0">
            <table class="table-clean">
                <thead>
                    <tr>
```

- [ ] **Step 3: Standardize the status badge**

In `pages/clients.php`, change:

```php
                        <td class="px-4 py-4 text-center">
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-white text-sm font-medium <?= $client['etat_class'] ?>">
                                <?= $client['etat_label'] ?>
                            </span>
                        </td>
```

to:

```php
                        <td class="px-4 py-4 text-center">
                            <span class="badge text-white text-sm <?= $client['etat_class'] ?>">
                                <?= $client['etat_label'] ?>
                            </span>
                        </td>
```

- [ ] **Step 4: Standardize the row "Ouvrir" action button (keep the distinct amber/indigo colors for Modifier/Rapport as-is — they're intentionally different actions, not covered by the 4 shared button variants)**

In `pages/clients.php`, change:

```php
                                <a href="achats.php?client=<?= $client['id'] ?>&mois=<?= $moisActuel ?>&annee=<?= $anneeActuelle ?>" 
                                   class="inline-flex items-center px-3 py-2 bg-primary-600 text-white text-sm font-medium rounded-lg hover:bg-primary-700 transition-colors"
                                   title="Ouvrir le dossier">
                                    <i class="fas fa-folder-open"></i>
                                </a>
```

to:

```php
                                <a href="achats.php?client=<?= $client['id'] ?>&mois=<?= $moisActuel ?>&annee=<?= $anneeActuelle ?>" 
                                   class="btn-primary px-3 py-2"
                                   title="Ouvrir le dossier">
                                    <i class="fas fa-folder-open"></i>
                                </a>
```

- [ ] **Step 5: Manual verification**

Start the app, log in, open `pages/clients.php`:
1. The filter bar and the table are both white rounded cards with the same soft shadow.
2. Status badges (Complet/En cours/Incomplet) still show the correct green/amber/red colors per client.
3. "Filtrer" (blue), "Réinitialiser" (outline, appears only when a filter is active), "Nouveau client" (green), and the row-level folder-open icon button all still work and navigate/submit as before.
4. Search box + Activité/État selects still filter the table on submit (functionality untouched — only classes changed).

- [ ] **Step 6: Commit**

```bash
git add pages/clients.php
git commit -m "style: apply shared card/badge/button components to clients.php"
```

---

### Task 5: Apply shared components to `pages/impots.php` (Gestion des impôts) — outer shell only

**Files:**
- Modify: `pages/impots.php` (message banner, selector panel wrapper, bottom action buttons — approximate locations: message banner and selector panel are near the top of `<main>`, right after the page's own inline header/breadcrumb/tabs blocks; bottom action buttons are the last `<div>` before the closing of the form, near the end of the file). Use the exact "before" text below to locate each block — do not rely on line numbers, this file is long and edited by other in-flight work.

**Interfaces:**
- Consumes: `.card`, `.btn-success`, `.btn-danger`, `.btn-secondary` (Task 1).
- Out of scope: the per-tax-type calculation tables between the selector panel and the bottom buttons (TVA/CF/TL/ITS/IRF/TF/CSS/RAS line-item forms) — those are business-logic-dense and not part of this pilot; leave every `<input>`, `<table>`, and computed value in that region untouched.

- [ ] **Step 1: Standardize the message banner**

In `pages/impots.php`, change:

```php
        <?php if ($message): ?>
        <div class="mb-4 p-4 rounded-lg <?= $messageType === 'error' ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
```

to:

```php
        <?php if ($message): ?>
        <div class="mb-4 p-4 rounded-lg border-l-4 <?= $messageType === 'error' ? 'bg-red-50 border-red-500 text-red-700' : 'bg-green-50 border-green-500 text-green-700' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
```

(This matches the alert style already used in `pages/clients.php:107` — visual consistency between the two pilot pages. Correction: none of the 3 pilot pages actually include `includes/footer.php` — each builds its own inline page shell — so its `[class*="border-l-4"]` auto-dismiss script does not apply here; this is a look-alike style match only, not shared auto-dismiss behavior.)

- [ ] **Step 2: Switch the selector panel wrapper to `.card`**

In `pages/impots.php`, change:

```php
        <!-- Sélecteur de type d'impôt et Marge -->
        <div class="mb-6 bg-white rounded-xl shadow-sm border p-4">
```

to:

```php
        <!-- Sélecteur de type d'impôt et Marge -->
        <div class="card mb-6 p-4">
```

- [ ] **Step 3: Standardize the bottom action buttons ("Récap Paiements" / "Enregistrer" / "Fermer")**

In `pages/impots.php`, change:

```php
                        <a href="recap-paiements.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" 
                           class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 font-medium">
                            <i class="fas fa-file-pdf mr-2"></i>Récap Paiements
                        </a>
                        <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium">
                            <i class="fas fa-save mr-2"></i>Enregistrer
                        </button>
                        <a href="dashboard.php" 
                           class="px-6 py-2 bg-slate-500 text-white rounded-lg hover:bg-slate-600 font-medium">
                            <i class="fas fa-times mr-2"></i>Fermer
                        </a>
```

to:

```php
                        <a href="recap-paiements.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" 
                           class="btn-danger">
                            <i class="fas fa-file-pdf"></i>Récap Paiements
                        </a>
                        <button type="submit" class="btn-success">
                            <i class="fas fa-save"></i>Enregistrer
                        </button>
                        <a href="dashboard.php" 
                           class="btn-secondary">
                            <i class="fas fa-times"></i>Fermer
                        </a>
```

- [ ] **Step 4: Manual verification**

Start the app, log in, open `pages/impots.php?client=<un id client existant>`:
1. Any success/error message banner (e.g. after saving) now shows with a colored left border, matching the style on `clients.php`.
2. The "Type d'impôt" selector panel is a white card with the same shadow/radius as the cards on `dashboard.php`/`clients.php`.
3. Switch the "Impôt" dropdown through TVA / Salaires / Location / CA (CSS) / RAS — confirm every tax-type calculation table still renders and calculates identically to before (this task did not touch that markup, but confirm nothing broke from the two edits above).
4. The 3 bottom buttons ("Récap Paiements" in red, "Enregistrer" in green, "Fermer" in slate) still submit/navigate correctly. Correction: their exact shade moved by one Tailwind step (e.g. `bg-red-600`→`bg-red-700`) and padding tightened (`px-6`→`px-4`) as an intentional consequence of standardizing on `.btn-*` — "keep their colors" above means same hue family, not byte-identical values; this is expected, not a regression.
5. Print preview (`Ctrl+P`) on this page still hides `.no-print` elements as before (Task 1/2 did not touch the `@media print` rule in `includes/header.php`, and `impots.php` builds its own `<head>` without that rule — confirm this was already the case before your change, i.e. this task introduces no regression here).

- [ ] **Step 5: Commit**

```bash
git add pages/impots.php
git commit -m "style: apply shared card/button components to impots.php outer shell"
```

---

## Self-Review Notes

- **Spec coverage:** Composants partagés (Task 1) ✓; harmonisation des deux navs (Task 2) ✓; 3 écrans pilotes — dashboard.php (Task 3), clients.php (Task 4), impots.php (Task 5) ✓; palette inchangée — enforced via Global Constraints ✓; vérification manuelle — every task ends with a concrete browser-check step ✓.
- **Placeholder scan:** none — every step shows exact before/after code or an exact command.
- **Type/name consistency:** class names introduced in Task 1 (`.card`, `.card-stat`, `.card-stat-accent/-warn/-neutral`, `.card-stat-label/-value`, `.badge`, `.badge-dot`, `.btn-primary/-secondary/-success/-danger/-outline`, `.table-clean`) are the exact names consumed in Tasks 2–5; no renames introduced later.
- **Scope check:** rollout to the remaining ~20 pages is explicitly out of scope for this plan (per spec) — a separate plan should be written once these 3 pilots are approved.

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

**3CE FISCUS** (internal name `impot-3ce`) is a French-language fiscal/tax management system ("Système de Gestion Fiscale") for an accounting firm ("Cabinet d'Expertise Comptable"). It manages multiple clients' monthly tax declarations (TVA, CF, ITS, TL, IRF, TF, CSS, TVA-location, RAS BIC/IS) and is built to run **100% offline**.

The app has two run modes sharing the same PHP/MySQL-or-SQLite codebase:
1. **XAMPP/Apache + MySQL** — traditional web server setup (`localhost`).
2. **Electron desktop app** — `main.js` spawns a bundled portable PHP binary (`bin/php/php.exe`) as a local HTTP server (`127.0.0.1:<port>`) and loads it in a `BrowserWindow`; data is stored in SQLite under `%APPDATA%/IMPOT-3CE/database.sqlite`.

`config/database.php`'s `Database` singleton auto-detects which mode it's in (Electron port 8010 / internal PHP binary / CLI ⇒ SQLite; otherwise ⇒ MySQL on port 3406, with SQLite file fallback if MySQL is unreachable) and transparently rewrites MySQL-flavored schema SQL into SQLite-compatible SQL when initializing a SQLite DB.

## Commands

All commands run from the project root (`npm` scripts drive both the Tailwind build and the Electron packaging):

```bash
npm start       # Launch the Electron desktop app (electron .)
npm run build   # Compile Tailwind CSS: assets/css/input.css -> assets/css/style.css (minified)
npm run watch   # Tailwind watch mode during CSS development
npm run dist    # Package the Electron app via electron-builder (output: dist_desktop/)
npm run icon    # Regenerate app icons (scripts/build-icon.js)
```

There is no automated test suite, linter, or CI config in this repo — verify changes manually (see "Manual verification" below).

**Running the web (XAMPP) variant directly:** with Apache/MySQL running (MySQL on port **3406**, database `gestion_fiscale`, user `root`, empty password — see `config/database.php`), just browse to `index.php` under the vhost/docroot. `install.php` and `database/install_rapide.sql` / `database/schema_mysql.sql` bootstrap a fresh MySQL database; `Database::initialiserBaseDeDonnees()` also self-heals by creating tables/columns/indexes it doesn't find (see `verifierEtCreerTablesManquantes()` and `ajouterColonneSiManquante()`), so most schema changes should be added there rather than as one-off migration scripts.

**Manual verification:** after any change to PHP business logic or pages, actually load the affected page(s) in a browser against a running server and click through the flow (this app has no test suite to lean on). Default login is `admin@cabinet.local` / `admin123` (auto-seeded by `Database::assurerAdminExiste()` on every boot if no admin exists).

## Architecture

### Layering (lightweight MVC)
- **Model**: `classes/*.php` — one PHP class per business entity, using PDO through the `Database` singleton (`config/database.php`). Almost all data access goes through `Database::getInstance()->fetchOne()/fetchAll()/query()/insert()/update()/transaction()` — always use prepared-statement parameter arrays, never string-interpolate user input into SQL.
- **View/Controller**: `pages/*.php` — each file is both the controller and the view for one screen (reads `$_GET`/`$_POST`, calls into `classes/`, then renders HTML inline with Tailwind classes). There is no router/front-controller for `pages/`; each page is hit directly (e.g. `pages/impot-tva.php?client=5&mois=3&annee=2026`).
- `index.php` is the sole public entry point (login screen); everything under `pages/` requires an authenticated session.

### Key classes (`classes/`)
- `Agent.php` — authentication, session management, CSRF token issuance/verification, per-agent client access control (`aAccesClient()`), action logging to the `logs` table. Roles: `agent`, `admin`, `superviseur` (admins/superviseurs see all clients; plain agents only their own).
- `Client.php`, `CompteGestionMensuel.php` (monthly bookkeeping snapshot per client), `Achat.php` (supplier purchases), `Depense.php` (expenses), `Fournisseur.php`, `Annexe.php` (exemption justifications).
- `Impot.php` — contains the tax calculation engine: abstract `Impot` base class plus concrete subclasses `ImpotTVA`, `ImpotSalaire` (CF/ITS/TL), `ImpotLocation` (IRF/TVA-location/TF), `ImpotCA` (CSS). `CalculateurImpots` (also in this file) orchestrates all four, computes the total, and persists to `impots_mensuels`. Adding a new tax type means adding a new `Impot` subclass and wiring it into `CalculateurImpots`.
- Tax logic reads its inputs from `parametres_fiscaux` (per-client fiscal configuration: TVA regime/rate, which tax modules are active, rates for CF/TL/CSS/IRF/TF) and `compte_gestion_mensuel` (per-client-per-month figures: CA, masse salariale, valeur locative, and many `*_ligneNNN` fields that map directly to official tax-declaration form line numbers).

### Includes (`includes/`)
- `header.php` / `footer.php` — shared chrome (nav, auth gate, Tailwind/FontAwesome links) meant to be `require_once`'d by pages. **Not all pages use them** — several pages (e.g. `pages/impot-tva.php`) build their own `<html>`/auth-check/header inline instead of including `includes/header.php`. When editing a page, check whether it uses the shared header/footer or its own inline markup before assuming shared-layout changes will apply.
- `navbar-impots.php` — sub-navigation for the tax-declaration pages.
- `transfer_helpers.php` — builds/reads the "3CE_TRANSFER" JSON payload format used to export/import a single client's full data (params, exemptions, monthly accounts, purchases, expenses, computed taxes) between installations — used by client transfer between agents/computers.

### Database
- `database/schema.sql` is the canonical MySQL-flavored schema; `Database::initialiserBaseDeDonnees()` regex-rewrites it (AUTO_INCREMENT → SQLite AUTOINCREMENT, strips ENGINE/CHARSET, ENUM → VARCHAR, etc.) when running on SQLite. `database/schema_mysql.sql` is used verbatim for fresh MySQL installs when present.
- Because both engines share one schema source, avoid MySQL-only syntax in new `CREATE TABLE`/`ALTER TABLE` statements unless you also update the SQLite rewrite rules in `initialiserBaseDeDonnees()`.
- `impots_mensuels` line-item columns (e.g. `tva_ligne82`, `cf_ligne243`, `ras_ligne401`) intentionally mirror the numbered lines of the official government tax declaration forms — keep that naming convention when adding new declared fields so pages and printed forms stay traceable to the source form.
- CSV import/export (`Database::exportToCsv`/`importFromCsv`) is restricted to an explicit allow-list (`ALLOWED_CSV_TABLES`) — extend that list rather than bypassing `assertAllowedCsvTable()`.
- Backups (`Database::backup()`/`restore()`) use SQLite `VACUUM INTO` (or file copy fallback) / `mysqldump`+`mysql`, and write/verify a `.sha256` checksum sidecar — preserve that integrity check when touching backup/restore code.

### Security patterns already in place — follow them for new code
- All SQL goes through PDO prepared statements via the `Database` wrapper.
- CSRF tokens: `Agent::getCsrfToken()` / `Agent::verifierCsrfToken()` — use for any new state-changing form/POST handler.
- Session fixation prevention (`session_regenerate_id(true)` on login) and a 30-minute idle timeout (`Agent::verifierTimeout()`), checked in `includes/header.php` and again inline at the top of pages that don't include it.
- `config/database.php` installs a global exception/fatal-error handler that logs to `%APPDATA%/IMPOT-3CE/app-errors.log` (or `database/app-errors.log` fallback) and always renders a generic French error card instead of leaking a stack trace or blank screen — keep new top-level error handling consistent with "never show a blank screen" in this Electron-offline context.
- `.htaccess` blocks direct web access to `database/`, `backups/`, `config/`, `classes/`, and sensitive file extensions — remember `config/` and `classes/` are not meant to be web-reachable even though they sit under the docroot.

### Electron desktop packaging (`main.js`, `package.json` → `build`)
- Portable PHP binary lives at `bin/php/php.exe`; if missing, the app shows an inline "installation incomplete" error page rather than falling back to any online resource (must stay 100% offline).
- `electron-builder` config excludes dev-only files from the packaged app (`test_*.php`, `install.php`, Tailwind source/config, `*.sqlite*`, `.git`, etc.) — see the `files` array in `package.json`; add new dev-only artifacts there rather than relying on `.gitignore`.
- Auto-update uses `electron-updater` against a GitHub releases provider (`MOHDEV520/impot-3ce`, public repo — GitHub Releases auto-update requires public release assets); only stable releases are ever installed (`allowPrerelease = false`).

### Shared UI components (`assets/css/input.css`)
- On top of Tailwind, `assets/css/input.css` defines a small set of reusable component classes with `@apply` (`.card`, `.card-header`, `.card-stat*`, `.badge`, `.badge-dot`, `.btn-primary`/`.btn-success`/`.btn-outline`, `.table-clean`, …) compiled into `assets/css/style.css` via `npm run build`. Pages are being migrated one at a time from ad-hoc inline Tailwind utility soup to these shared classes (see recent `style: apply shared card/... components to X.php` commits) — when touching a page's markup, prefer the existing shared classes over hand-rolled Tailwind combinations, and remember any change to `input.css` requires re-running `npm run build` (or `npm run watch`) to take effect since `style.css` is a committed, generated file.

## Language / naming convention

The entire codebase — variable names, method names, comments, UI copy — is in **French**, matching French tax terminology (TVA, CF, ITS, TL, IRF, TF, CSS, RAS). Keep new code consistent with this convention rather than mixing in English identifiers.

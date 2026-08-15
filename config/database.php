<?php
/**
 * ============================================
 * CONFIGURATION BASE DE DONNÉES - MySQL
 * Système de Gestion Fiscale
 * ============================================
 */

/**
 * Gestionnaire d'erreurs global (chargé par toutes les pages via require_once).
 * Objectif : ne JAMAIS afficher d'écran blanc en production (Electron offline).
 * - Les erreurs et exceptions non gérées sont journalisées dans un fichier log.
 * - Une page d'erreur lisible est affichée à l'utilisateur.
 */
if (!defined('APP_GLOBAL_HANDLER')) {
    define('APP_GLOBAL_HANDLER', true);

    error_reporting(E_ALL);
    // On masque l'affichage brut des erreurs (évite les fuites / pages cassées),
    // tout est capturé par nos handlers ci-dessous et écrit dans le log.
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');

    if (!function_exists('app_error_log_path')) {
        function app_error_log_path(): string
        {
            $appData = getenv('APPDATA') ?: (isset($_SERVER['APPDATA']) ? $_SERVER['APPDATA'] : null);
            if ($appData) {
                $folder = $appData . DIRECTORY_SEPARATOR . 'IMPOT-3CE';
            } else {
                $folder = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database';
            }
            if (!is_dir($folder)) {
                @mkdir($folder, 0777, true);
            }
            return $folder . DIRECTORY_SEPARATOR . 'app-errors.log';
        }
    }

    if (!function_exists('app_write_error_log')) {
        function app_write_error_log(string $type, string $message, string $file = '', int $line = 0): void
        {
            $entry = '[' . date('Y-m-d H:i:s') . "] $type: $message";
            if ($file !== '') {
                $entry .= " @ $file:$line";
            }
            @file_put_contents(app_error_log_path(), $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    if (!function_exists('app_render_error_page')) {
        function app_render_error_page(): void
        {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
            }
            echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
                . '<title>Erreur</title><style>'
                . 'body{font-family:Segoe UI,Arial,sans-serif;background:#f3f4f6;color:#1f2937;'
                . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px;}'
                . '.card{background:#fff;max-width:520px;width:100%;border-radius:16px;'
                . 'box-shadow:0 10px 30px rgba(0,0,0,.1);padding:32px;text-align:center;}'
                . '.icon{font-size:48px;}h1{font-size:20px;margin:16px 0 8px;}'
                . 'p{color:#6b7280;line-height:1.5;}a{display:inline-block;margin-top:20px;'
                . 'background:#2563eb;color:#fff;text-decoration:none;padding:10px 20px;border-radius:8px;font-weight:600;}'
                . '</style></head><body><div class="card"><div class="icon">&#9888;&#65039;</div>'
                . '<h1>Une erreur est survenue</h1>'
                . '<p>L\'opération n\'a pas pu être effectuée. Aucune donnée n\'a été perdue.<br>'
                . 'Vous pouvez revenir en arrière et réessayer.</p>'
                . '<a href="javascript:history.length>1?history.back():location.reload()">Retour</a>'
                . '</div></body></html>';
        }
    }

    set_exception_handler(function ($e) {
        app_write_error_log('EXCEPTION', $e->getMessage(), $e->getFile(), $e->getLine());
        app_render_error_page();
    });

    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            app_write_error_log('FATAL', $err['message'], $err['file'] ?? '', $err['line'] ?? 0);
            app_render_error_page();
        }
    });
}

class Database
{
    private static ?Database $instance = null;
    private ?PDO $pdo_conn = null;
    
    // Configuration MySQL (Fallback)
    private const DB_HOST = 'localhost';
    private const DB_PORT = 3406;
    private const DB_NAME = 'gestion_fiscale';
    private const DB_USER = 'root';
    private const DB_PASS = '';
    private const DB_CHARSET = 'utf8mb4';
    private const ALLOWED_CSV_TABLES = [
        'clients',
        'fournisseurs',
        'achats',
        'depenses',
        'agents',
        'compte_gestion_mensuel',
        'impots_mensuels',
        'parametres_fiscaux',
        'natures_depenses',
        'logs',
        'services_annexe_tva',
        'exonerations_client',
        'annexes',
        // Legacy aliases used in the UI.
        'impots',
        'declarations'
    ];

    /**
     * Constructeur privé (Singleton)
     */
    private function __construct()
    {
        // Détection du mode portable (SQLite)
        // Sur ordinateur local, on préfère stocker dans AppData pour ne pas perdre les données 
        // lors d'une mise à jour de l'application.
        
        $appData = getenv('APPDATA') ?: (isset($_SERVER['APPDATA']) ? $_SERVER['APPDATA'] : null);
        
        if ($appData) {
            $dbFolder = $appData . DIRECTORY_SEPARATOR . 'IMPOT-3CE';
            if (!file_exists($dbFolder)) {
                @mkdir($dbFolder, 0777, true);
            }
            $sqlitePath = $dbFolder . DIRECTORY_SEPARATOR . 'database.sqlite';
        } else {
            // Fallback si APPDATA n'est pas accessible
            $sqlitePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'database.sqlite';
        }
        
        // On force SQLite SI on est dans l'app Electron (Port 8010)
        // OU si on utilise le binaire PHP interne
        $isElectron = (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 8010);
        $isCli = (php_sapi_name() === 'cli');
        $isInternalPhp = (strpos(defined('PHP_BINARY') ? PHP_BINARY : '', 'bin' . DIRECTORY_SEPARATOR . 'php') !== false);
        
        if ($isElectron || $isInternalPhp || ($isCli && is_dir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php'))) {
            // Mode Desktop / Portable : On utilise SQLite
            try {
                // S'assurer que le dossier existe
                $dir = dirname($sqlitePath);
                if (!is_dir($dir)) {
                    if (!mkdir($dir, 0777, true)) {
                        throw new Exception("Impossible de créer le dossier de base de données : " . $dir);
                    }
                }
                
                $this->pdo_conn = new PDO("sqlite:" . $sqlitePath);
                $this->pdo_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->pdo_conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                
                // Si la base est nouvelle ou incomplète, on l'initialise
                if (!$this->tablesExist()) {
                    $this->initialiserBaseDeDonnees();
                } else {
                    // S'assurer que les tables récentes existent
                    $this->verifierEtCreerTablesManquantes();
                }
                
                // On s'assure TOUJOURS qu'au moins un admin existe
                $this->assurerAdminExiste();

                return;
            } catch (PDOException $e) {
                error_log('DB SQLite error: ' . $e->getMessage());
                die('Erreur base de donnees. Contactez l administrateur.');
            } catch (Exception $e) {
                error_log('DB system error: ' . $e->getMessage());
                die('Erreur systeme. Contactez l administrateur.');
            }
        }

        try {
            $dsn = "mysql:host=" . self::DB_HOST . ";port=" . self::DB_PORT . ";dbname=" . self::DB_NAME . ";charset=" . self::DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->pdo_conn = new PDO($dsn, self::DB_USER, self::DB_PASS, $options);
            
            // Si la base est nouvelle ou incomplète, on l'initialise
            if (!$this->tablesExist()) {
                $this->initialiserBaseDeDonnees();
            } else {
                $this->verifierEtCreerTablesManquantes();
            }
            
            $this->assurerAdminExiste();

        } catch (PDOException $e) {
            // Si MySQL échoue et qu'on a le fichier SQLite de secours, on l'utilise
            if (file_exists($sqlitePath)) {
                $this->pdo_conn = new PDO("sqlite:" . $sqlitePath);
                $this->pdo_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->pdo_conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                
                // Vérifier l'intégrité même en fallback
                if (!$this->tablesExist()) {
                    $this->initialiserBaseDeDonnees();
                } else {
                    $this->verifierEtCreerTablesManquantes();
                }
                return;
            }
            error_log('DB MySQL connection error: ' . $e->getMessage());
            die('Erreur de connexion a la base de donnees. Verifiez le service puis reessayez.');
        }
    }

    /**
     * Vérifier et créer les tables qui pourraient manquer (mises à jour)
     */
    private function verifierEtCreerTablesManquantes(): void
    {
        try {
            if ($this->pdo_conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $this->pdo_conn->exec("CREATE TABLE IF NOT EXISTS services_annexe_tva (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    client_id INTEGER NOT NULL,
                    mois INTEGER NOT NULL CHECK (mois BETWEEN 1 AND 12),
                    annee INTEGER NOT NULL CHECK (annee >= 2020),
                    prestataire_nom VARCHAR(200) NOT NULL,
                    prestataire_adresse TEXT,
                    prestataire_nif VARCHAR(50),
                    montant_ht DECIMAL(15,2) NOT NULL DEFAULT 0,
                    taux_tva DECIMAL(5,2) DEFAULT 18.00,
                    montant_tva DECIMAL(15,2) NOT NULL DEFAULT 0,
                    reference_document VARCHAR(100),
                    date_document DATE,
                    date_saisie DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
                )");

                $this->pdo_conn->exec("CREATE TABLE IF NOT EXISTS exonerations_client (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    client_id INTEGER NOT NULL,
                    numero_ordre INTEGER DEFAULT 0,
                    code_produit VARCHAR(50),
                    nature VARCHAR(255) NOT NULL,
                    montant_ht DECIMAL(15,2) DEFAULT 0,
                    taux VARCHAR(50),
                    actif BOOLEAN DEFAULT 1,
                    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
                )");

                $this->pdo_conn->exec("CREATE TABLE IF NOT EXISTS impots_mensuels (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    client_id INTEGER NOT NULL,
                    compte_gestion_id INTEGER NOT NULL,
                    mois INTEGER NOT NULL,
                    annee INTEGER NOT NULL,
                    tva_collectee DECIMAL(15,2) DEFAULT 0,
                    tva_deductible DECIMAL(15,2) DEFAULT 0,
                    tva_a_payer DECIMAL(15,2) DEFAULT 0,
                    credit_tva DECIMAL(15,2) DEFAULT 0,
                    cf DECIMAL(15,2) DEFAULT 0,
                    its DECIMAL(15,2) DEFAULT 0,
                    tl DECIMAL(15,2) DEFAULT 0,
                    irf DECIMAL(15,2) DEFAULT 0,
                    tva_location DECIMAL(15,2) DEFAULT 0,
                    tf DECIMAL(15,2) DEFAULT 0,
                    css DECIMAL(15,2) DEFAULT 0,
                    total_impots DECIMAL(15,2) DEFAULT 0,
                    date_calcul DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(client_id, mois, annee),
                    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
                    FOREIGN KEY (compte_gestion_id) REFERENCES compte_gestion_mensuel(id) ON DELETE CASCADE
                )");
            } else {
                $this->pdo_conn->exec("CREATE TABLE IF NOT EXISTS services_annexe_tva (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    client_id INT NOT NULL,
                    mois INT NOT NULL,
                    annee INT NOT NULL,
                    prestataire_nom VARCHAR(200) NOT NULL,
                    prestataire_adresse TEXT,
                    prestataire_nif VARCHAR(50),
                    montant_ht DECIMAL(15,2) NOT NULL DEFAULT 0,
                    taux_tva DECIMAL(5,2) DEFAULT 18.00,
                    montant_tva DECIMAL(15,2) NOT NULL DEFAULT 0,
                    reference_document VARCHAR(100),
                    date_document DATE,
                    date_saisie DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
                    CHECK (mois BETWEEN 1 AND 12),
                    CHECK (annee >= 2020)
                ) ENGINE=InnoDB");

                $this->pdo_conn->exec("CREATE TABLE IF NOT EXISTS exonerations_client (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    client_id INT NOT NULL,
                    numero_ordre INT DEFAULT 0,
                    code_produit VARCHAR(50),
                    nature VARCHAR(255) NOT NULL,
                    montant_ht DECIMAL(15,2) DEFAULT 0,
                    taux VARCHAR(50),
                    actif TINYINT(1) DEFAULT 1,
                    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
                ) ENGINE=InnoDB");

                $this->pdo_conn->exec("CREATE TABLE IF NOT EXISTS impots_mensuels (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    client_id INT NOT NULL,
                    compte_gestion_id INT NOT NULL,
                    mois INT NOT NULL,
                    annee INT NOT NULL,
                    tva_collectee DECIMAL(15,2) DEFAULT 0,
                    tva_deductible DECIMAL(15,2) DEFAULT 0,
                    tva_a_payer DECIMAL(15,2) DEFAULT 0,
                    credit_tva DECIMAL(15,2) DEFAULT 0,
                    cf DECIMAL(15,2) DEFAULT 0,
                    its DECIMAL(15,2) DEFAULT 0,
                    tl DECIMAL(15,2) DEFAULT 0,
                    irf DECIMAL(15,2) DEFAULT 0,
                    tva_location DECIMAL(15,2) DEFAULT 0,
                    tf DECIMAL(15,2) DEFAULT 0,
                    css DECIMAL(15,2) DEFAULT 0,
                    total_impots DECIMAL(15,2) DEFAULT 0,
                    date_calcul DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(client_id, mois, annee),
                    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
                    FOREIGN KEY (compte_gestion_id) REFERENCES compte_gestion_mensuel(id) ON DELETE CASCADE
                ) ENGINE=InnoDB");
            }
            
            // Vérifier les colonnes manquantes dans les tables existantes
            $this->verifierColonnesManquantes();
            
        } catch (Exception $e) {
            error_log("Erreur lors de la vérification des tables: " . $e->getMessage());
        }
    }

    /**
     * Vérifier les colonnes manquantes dans les tables existantes (mises à jour)
     */
    private function verifierColonnesManquantes(): void
    {
        // 1. Colonnes pour compte_gestion_mensuel
        $colonnesCompte = [
            'ca_global' => 'DECIMAL(15,2) DEFAULT 0',
            'ca_exonere' => 'DECIMAL(15,2) DEFAULT 0',
            'ca_taxable' => 'DECIMAL(15,2) DEFAULT 0',
            'masse_salariale' => 'DECIMAL(15,2) DEFAULT 0',
            'loyers_percus' => 'DECIMAL(15,2) DEFAULT 0',
            'loc_ligne132' => 'DECIMAL(15,2) DEFAULT 0', 'loc_ligne133' => 'DECIMAL(15,2) DEFAULT 0', 
            'loc_ligne137' => 'DECIMAL(15,2) DEFAULT 0', 'loc_ligne141' => 'DECIMAL(15,2) DEFAULT 0', 
            'loc_ligne145' => 'DECIMAL(15,2) DEFAULT 0',
            'cf_ligne243' => 'DECIMAL(15,2) DEFAULT 0', 'cf_ligne246' => 'DECIMAL(15,2) DEFAULT 0', 
            'cf_ligne247' => 'DECIMAL(15,2) DEFAULT 0', 'cf_ligne248' => 'DECIMAL(15,2) DEFAULT 0', 
            'cf_ligne249' => 'DECIMAL(15,2) DEFAULT 0', 'cf_ligne250' => 'DECIMAL(15,2) DEFAULT 0', 
            'cf_ligne251' => 'DECIMAL(15,2) DEFAULT 0', 'tl_ligne212' => 'DECIMAL(15,2) DEFAULT 0',
            'tva_ligne82' => 'DECIMAL(15,2) DEFAULT 0', 'tva_ligne83' => 'DECIMAL(15,2) DEFAULT 0', 
            'tva_ligne84' => 'DECIMAL(15,2) DEFAULT 0', 'tva_ligne85' => 'DECIMAL(15,2) DEFAULT 0', 
            'tva_ligne86' => 'DECIMAL(15,2) DEFAULT 0',
            'tva_ligne101' => 'DECIMAL(15,2) DEFAULT 0', 'tva_ligne102' => 'DECIMAL(15,2) DEFAULT 0', 
            'tva_ligne103' => 'DECIMAL(15,2) DEFAULT 0', 'tva_ligne107' => 'DECIMAL(15,2) DEFAULT 0', 
            'tva_ligne110' => 'DECIMAL(15,2) DEFAULT 0',
            'tva_ligne112' => 'DECIMAL(15,2) DEFAULT 0',
            'tva_ligne113' => 'DECIMAL(15,2) DEFAULT 0', 'tva_ligne114' => 'DECIMAL(15,2) DEFAULT 0', 
            'tva_ligne115' => 'DECIMAL(15,2) DEFAULT 0', 'tva_ligne116' => 'DECIMAL(15,2) DEFAULT 0', 
            'tva_ligne117' => 'DECIMAL(15,2) DEFAULT 0', 'tva_ligne118' => 'DECIMAL(15,2) DEFAULT 0', 
            'tva_ligne120' => 'DECIMAL(15,2) DEFAULT 0',
            'its' => 'DECIMAL(15,2) DEFAULT 0',
            'marge' => 'DECIMAL(5,3) DEFAULT 1.30',
            'marge_taxable' => 'DECIMAL(5,3) DEFAULT 1.30',
            'statut' => "VARCHAR(50) DEFAULT 'en_preparation'",
            'date_validation' => 'DATETIME NULL',
            'valide_par' => 'INT NULL'
        ];

        foreach ($colonnesCompte as $colonne => $definition) {
            $this->ajouterColonneSiManquante('compte_gestion_mensuel', $colonne, $definition);
        }

        // 2. Colonnes pour parametres_fiscaux
        $colonnesParams = [
            'marge' => 'DECIMAL(5,3) DEFAULT 1.30',
            'marge_taxable' => 'DECIMAL(5,3) DEFAULT 1.30',
            'valeur_locative_mensuelle' => 'DECIMAL(15,2) DEFAULT 0',
            'taux_cf' => 'DECIMAL(5,2) DEFAULT 3.5',
            'taux_tl' => 'DECIMAL(5,2) DEFAULT 1.0',
            'taux_css' => 'DECIMAL(5,2) DEFAULT 0.5',
            'taux_irf' => 'DECIMAL(5,2) DEFAULT 12.0',
            'taux_tf' => 'DECIMAL(5,2) DEFAULT 3.0',
            'taux_tva_double' => 'TINYINT(1) DEFAULT 0',
            'salaires_actif' => 'TINYINT(1) DEFAULT 0',
            'location_actif' => 'TINYINT(1) DEFAULT 0',
            'irf_tf_actif' => 'TINYINT(1) DEFAULT 0',
            'css_actif' => 'TINYINT(1) DEFAULT 1',
            'ras_actif' => 'TINYINT(1) DEFAULT 0',
            'sans_marges' => 'TINYINT(1) DEFAULT 0'
        ];

        foreach ($colonnesParams as $colonne => $definition) {
            $this->ajouterColonneSiManquante('parametres_fiscaux', $colonne, $definition);
        }

        // 3. Migration: secteur ENUM → VARCHAR pour permettre des secteurs personnalisés
        $this->migrerEnumVersVarchar('clients', 'secteur', "VARCHAR(100) DEFAULT 'officine'");

        // 4. Colonnes pour achats
        $this->ajouterColonneSiManquante('achats', 'nature_operation', "VARCHAR(255) DEFAULT 'achat'");
        $this->ajouterColonneSiManquante('achats', 'exclure_ca', 'TINYINT(1) DEFAULT 0');

        // 5. Colonnes Retenue à la Source BIC/IS (lignes 401-430)
        $colonnesRas = [
            'ras_ligne401' => 'DECIMAL(15,2) DEFAULT 0',
            'ras_ligne403' => 'DECIMAL(15,2) DEFAULT 0',
            'ras_ligne404' => 'DECIMAL(15,2) DEFAULT 0',
            'ras_ligne405' => 'DECIMAL(15,2) DEFAULT 0',
            'ras_ligne406' => 'DECIMAL(15,2) DEFAULT 0',
            'ras_ligne411' => 'DECIMAL(15,2) DEFAULT 0',
            'ras_ligne412' => 'DECIMAL(15,2) DEFAULT 0',
            'ras_ligne413' => 'DECIMAL(15,2) DEFAULT 0',
            'ras_ligne418' => 'DECIMAL(15,2) DEFAULT 0',
            'ras_ligne419' => 'DECIMAL(15,2) DEFAULT 0',
            'ras_ligne425' => 'DECIMAL(15,2) DEFAULT 0',
        ];
        foreach ($colonnesRas as $colonne => $definition) {
            $this->ajouterColonneSiManquante('compte_gestion_mensuel', $colonne, $definition);
        }
        $this->ajouterColonneSiManquante('impots_mensuels', 'ras', 'DECIMAL(15,2) DEFAULT 0');

        // Index de performance pour usage professionnel
        $this->creerIndexSiManquant('achats', 'idx_achats_client_date', '(client_id, annee, mois)');
        $this->creerIndexSiManquant('depenses', 'idx_depenses_client_date', '(client_id, annee, mois)');
        $this->creerIndexSiManquant('impots_mensuels', 'idx_impots_client_date', '(client_id, annee, mois)');
    }

    /**
     * Migrer une colonne ENUM vers VARCHAR (MySQL uniquement, no-op sur SQLite)
     */
    private function migrerEnumVersVarchar(string $table, string $colonne, string $newDefinition): void
    {
        try {
            $driver = $this->pdo_conn->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') return;

            $colonneEchappee = $this->pdo_conn->quote($colonne);
            $stmt = $this->pdo_conn->query("SHOW COLUMNS FROM `$table` LIKE $colonneEchappee");
            $row = $stmt->fetch();
            if ($row && stripos($row['Type'], 'enum') === 0) {
                $this->pdo_conn->exec("ALTER TABLE `$table` MODIFY COLUMN `$colonne` $newDefinition");
                error_log("[MIGRATION] Colonne $table.$colonne convertie de ENUM vers VARCHAR");
            }
        } catch (Exception $e) {
            error_log("[MIGRATION ERROR] ENUM->VARCHAR $table.$colonne : " . $e->getMessage());
        }
    }

    /**
     * Créer un index s'il n'existe pas
     */
    private function creerIndexSiManquant(string $table, string $nomIndex, string $colonnes): void
    {
        try {
            $driver = $this->pdo_conn->getAttribute(PDO::ATTR_DRIVER_NAME);
            $existe = false;

            if ($driver === 'sqlite') {
                $stmt = $this->pdo_conn->query("PRAGMA index_list(`$table`)");
                while ($row = $stmt->fetch()) {
                    if ($row['name'] === $nomIndex) {
                        $existe = true;
                        break;
                    }
                }
            } else {
                $nomIndexEchappe = $this->pdo_conn->quote($nomIndex);
                $stmt = $this->pdo_conn->query("SHOW INDEX FROM `$table` WHERE Key_name = $nomIndexEchappe");
                if ($stmt->fetch()) {
                    $existe = true;
                }
            }

            if (!$existe) {
                $this->pdo_conn->exec("CREATE INDEX `$nomIndex` ON `$table` $colonnes");
                error_log("[MIGRATION] Création de l'index $nomIndex sur $table");
            }
        } catch (Exception $e) {
            error_log("[MIGRATION ERROR] Index $nomIndex : " . $e->getMessage());
        }
    }

    /**
     * Ajouter une colonne si elle n'existe pas
     */
    private function ajouterColonneSiManquante(string $table, string $colonne, string $definition): void
    {
        try {
            $driver = $this->pdo_conn->getAttribute(PDO::ATTR_DRIVER_NAME);
            $existe = false;

            if ($driver === 'sqlite') {
                $stmt = $this->pdo_conn->query("PRAGMA table_info($table)");
                while ($row = $stmt->fetch()) {
                    if ($row['name'] === $colonne) {
                        $existe = true;
                        break;
                    }
                }
            } else {
                $colonneEchappee = $this->pdo_conn->quote($colonne);
                $stmt = $this->pdo_conn->query("SHOW COLUMNS FROM `$table` LIKE $colonneEchappee");
                if ($stmt->fetch()) {
                    $existe = true;
                }
            }

            if (!$existe) {
                // Utilisation de backticks pour MySQL/SQLite pour éviter les mots réservés
                $this->pdo_conn->exec("ALTER TABLE `$table` ADD COLUMN `$colonne` $definition");
                error_log("[MIGRATION] Ajout de la colonne $colonne à la table $table");
            }
        } catch (Exception $e) {
            // Loguer l'erreur mais ne pas arrêter l'exécution pour les migrations non critiques
            error_log("[MIGRATION ERROR] $table.$colonne : " . $e->getMessage());
        }
    }

    /**
     * Obtenir l'instance unique
     */
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Obtenir la connexion PDO
     */
    public function getConnection(): PDO
    {
        if ($this->pdo_conn === null) {
            throw new RuntimeException("La connexion à la base de données a été fermée.");
        }
        return $this->pdo_conn;
    }

    /**
     * Exécuter une requête préparée (Sécurisé)
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        if ($this->pdo_conn === null) {
            throw new RuntimeException("Base de données non initialisée.");
        }
        
        try {
            $stmt = $this->pdo_conn->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            // Logging professionnel en production
            error_log("[SQL ERROR] " . $e->getMessage() . " | Query: " . $sql);
            throw new RuntimeException("Une erreur est survenue lors de l'exécution de la requête.");
        }
    }

    /**
     * Exécuter une transaction (Pour la stabilité des données)
     */
    public function transaction(callable $callback)
    {
        try {
            $this->pdo_conn->beginTransaction();
            $result = $callback($this);
            $this->pdo_conn->commit();
            return $result;
        } catch (Exception $e) {
            $this->pdo_conn->rollBack();
            error_log("[TRANSACTION ERROR] " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Récupérer un enregistrement
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Récupérer plusieurs enregistrements
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Récupérer une seule colonne
     */
    public function fetchColumn(string $sql, array $params = [], int $columnNumber = 0)
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn($columnNumber);
    }

    /**
     * Insérer et retourner l'ID
     */
    public function insert(string $sql, array $params = []): int
    {
        $this->query($sql, $params);
        return (int) $this->pdo_conn->lastInsertId();
    }

    /**
     * Mettre à jour et retourner le nombre de lignes affectées
     */
    public function update(string $sql, array $params = []): int
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Supprimer et retourner le nombre de lignes affectées
     */
    public function delete(string $sql, array $params = []): int
    {
        return $this->update($sql, $params);
    }

    /**
     * Vérifier si les tables existent
     */
    public function tablesExist(): bool
    {
        try {
            if ($this->pdo_conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                // On vérifie deux tables clés pour être sûr que l'init est complète
                $sql = "SELECT count(*) FROM sqlite_master WHERE type='table' AND name IN ('agents', 'compte_gestion_mensuel')";
                $count = (int)$this->pdo_conn->query($sql)->fetchColumn();
                return $count >= 2;
            } else {
                $sql = "SHOW TABLES LIKE 'agents'";
                $result = $this->fetchOne($sql);
                return $result !== null;
            }
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * S'assurer qu'au moins un compte administrateur par défaut existe
     */
    private function assurerAdminExiste(): void
    {
        try {
            // On s'assure que la table agents existe
            $driver = $this->pdo_conn->getAttribute(PDO::ATTR_DRIVER_NAME);
            $tableExists = false;
            
            if ($driver === 'sqlite') {
                $checkTable = $this->pdo_conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='agents'");
                $tableExists = $checkTable->fetch() !== false;
            } else {
                $checkTable = $this->pdo_conn->query("SHOW TABLES LIKE 'agents'");
                $tableExists = $checkTable->fetch() !== false;
            }

            if (!$tableExists) {
                error_log("CRITICAL: La table 'agents' n'existe pas lors de la vérification de l'admin.");
                return;
            }

            // Vérifier si l'admin par défaut existe (insensible à la casse)
            $stmt = $this->pdo_conn->prepare("SELECT COUNT(*) FROM agents WHERE LOWER(email) = ?");
            $stmt->execute(['admin@cabinet.local']);
            $count = (int)$stmt->fetchColumn();

            if ($count === 0) {
                // Mot de passe par défaut connu et identique sur toutes les installations.
                // Permet la première connexion sur chaque PC. À changer immédiatement
                // depuis Paramètres > Profil après la première connexion.
                $defaultPassword = 'admin123';
                $pass = password_hash($defaultPassword, PASSWORD_DEFAULT);
                $sql = "INSERT INTO agents (nom, prenom, email, mot_de_passe, role, statut) VALUES (?, ?, ?, ?, ?, ?)";
                $success = $this->pdo_conn->prepare($sql)->execute([
                    'Administrateur', 
                    'Cabinet', 
                    'admin@cabinet.local', 
                    $pass, 
                    'admin', 
                    'actif'
                ]);
                
                if ($success) {
                    error_log("SUCCESS: Compte administrateur cree (admin@cabinet.local / admin123 - a changer immediatement).");
                } else {
                    error_log("ERROR: Échec de la création du compte administrateur par défaut.");
                }
            }
        } catch (Exception $e) {
            error_log("Exception lors de la vérification de l'admin: " . $e->getMessage());
        }
    }

    /**
     * Initialiser la base de données
     */
    public function initialiserBaseDeDonnees(): bool
    {
        $driver = $this->pdo_conn->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'sqlite') {
            $schemaFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sql';
        } else {
            $schemaFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema_mysql.sql';
            if (!file_exists($schemaFile)) {
                $schemaFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sql';
            }
        }

        if (!file_exists($schemaFile)) {
            error_log("Fichier de schéma introuvable : " . $schemaFile);
            return false;
        }

        $sql = file_get_contents($schemaFile);
        
        if ($driver === 'sqlite') {
            // Nettoyage pour compatibilité SQLite
            $sql = preg_replace('/CREATE DATABASE.*?;/is', '', $sql);
            $sql = preg_replace('/USE .*?;/i', '', $sql);
            
            // Conversion critique : INT AUTO_INCREMENT PRIMARY KEY -> INTEGER PRIMARY KEY AUTOINCREMENT
            $sql = preg_replace('/INT\s+AUTO_INCREMENT\s+PRIMARY\s+KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
            $sql = preg_replace('/INTEGER\s+AUTO_INCREMENT\s+PRIMARY\s+KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
            
            // Supprimer les options de table MySQL
            $sql = preg_replace('/ENGINE=InnoDB/i', '', $sql);
            $sql = preg_replace('/DEFAULT\s+CHARSET=[^\s;]*/i', '', $sql);
            $sql = preg_replace('/COLLATE=[^\s;]*/i', '', $sql);
            $sql = preg_replace('/CHARACTER\s+SET\s+[^\s;]*/i', '', $sql);
            
            // Gérer les types de données incompatibles
            $sql = preg_replace('/ENUM\(.*?\)/i', 'VARCHAR(255)', $sql);
            $sql = preg_replace('/DECIMAL\(\d+,\d+\)/i', 'NUMERIC', $sql);
            $sql = preg_replace('/\bDOUBLE\b/i', 'NUMERIC', $sql);
            
            // Correction des contraintes MySQL spécifiques
            $sql = preg_replace('/UNIQUE KEY\s+\w+\s+\(/i', 'UNIQUE (', $sql);
            $sql = preg_replace('/KEY\s+\w+\s+\(.*\)/i', '', $sql); 
            
            $sql = preg_replace('/DATETIME\s+DEFAULT\s+CURRENT_TIMESTAMP\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP/i', 'DATETIME DEFAULT CURRENT_TIMESTAMP', $sql);
            $sql = preg_replace('/TIMESTAMP\s+DEFAULT\s+CURRENT_TIMESTAMP\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP/i', 'DATETIME DEFAULT CURRENT_TIMESTAMP', $sql);
            
            $sql = str_replace(['utf8mb4_unicode_ci', 'utf8mb4_general_ci', 'utf8mb4'], ['', '', ''], $sql);
            
            $this->pdo_conn->exec("PRAGMA foreign_keys = OFF;");
        }

        // Découpage par requêtes
        $queries = explode(';', $sql);
        $success = true;
        
        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query)) {
                try {
                    $this->pdo_conn->exec($query);
                } catch (PDOException $e) {
                    error_log("Erreur SQL Init ($driver): " . $e->getMessage());
                    $success = false;
                }
            }
        }

        if ($driver === 'sqlite') {
            $this->pdo_conn->exec("PRAGMA foreign_keys = ON;");
        }

        return $success;
    }

    /**
     * Démarrer une transaction
     */
    public function beginTransaction(): bool
    {
        if ($this->pdo_conn === null) return false;
        return $this->pdo_conn->beginTransaction();
    }

    /**
     * Valider une transaction
     */
    public function commit(): bool
    {
        if ($this->pdo_conn === null) return false;
        return $this->pdo_conn->commit();
    }

    /**
     * Annuler une transaction
     */
    public function rollback(): bool
    {
        if ($this->pdo_conn === null) return false;
        return $this->pdo_conn->rollBack();
    }

    /**
     * Obtenir le chemin du dossier des sauvegardes
     */
    public function getBackupDirectory(): string
    {
        return dirname(__DIR__) . '/backups';
    }

    /**
     * Résoudre le binaire MySQL/mysqldump (utile sous XAMPP Windows offline)
     */
    private function resolveMysqlBinary(string $binary): string
    {
        if (stripos(PHP_OS, 'WIN') === 0) {
            $candidate = 'C:\\xampp\\mysql\\bin\\' . $binary . '.exe';
            if (file_exists($candidate)) {
                return '"' . $candidate . '"';
            }
        }
        return $binary;
    }

    private function normalizeCsvTableName(string $table): string
    {
        $aliases = [
            'impots' => 'impots_mensuels',
            'declarations' => 'compte_gestion_mensuel'
        ];

        return $aliases[$table] ?? $table;
    }

    private function assertAllowedCsvTable(string $table): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Nom de table invalide.');
        }

        if (!in_array($table, self::ALLOWED_CSV_TABLES, true)) {
            throw new InvalidArgumentException('Table non autorisee pour import/export.');
        }

        return $this->normalizeCsvTableName($table);
    }

    private function writeBackupChecksum(string $filepath): void
    {
        $hash = hash_file('sha256', $filepath);
        if ($hash !== false) {
            @file_put_contents($filepath . '.sha256', $hash . PHP_EOL);
        }
    }

    private function verifyBackupChecksum(string $filepath): void
    {
        $checksumPath = $filepath . '.sha256';
        if (!file_exists($checksumPath)) {
            return;
        }

        $expected = trim((string)file_get_contents($checksumPath));
        $actual = hash_file('sha256', $filepath);

        if ($expected === '' || $actual === false || !hash_equals($expected, $actual)) {
            throw new RuntimeException('Integrity check failed for backup file.');
        }
    }

    /**
     * Créer une sauvegarde et retourner le chemin complet du fichier
     */
    public function backup(?string $label = null): string
    {
        $backupDir = $this->getBackupDirectory();
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $extension = ($this->pdo_conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') ? '.sqlite' : '.sql';
        
        if ($label) {
            $label = preg_replace('/[^a-zA-Z0-9_-]/', '', $label);
            $filename = 'backup_' . $timestamp . '_' . $label . $extension;
        } else {
            $filename = 'backup_' . $timestamp . $extension;
        }
        
        $filepath = $backupDir . DIRECTORY_SEPARATOR . $filename;

        if ($this->pdo_conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $dbPath = $this->getDatabasePath();
            if (!file_exists($dbPath)) {
                throw new RuntimeException("Fichier source SQLite introuvable pour le backup.");
            }
            
            try {
                $this->pdo_conn->exec("VACUUM INTO " . $this->pdo_conn->quote($filepath));
            } catch (PDOException $e) {
                if (!copy($dbPath, $filepath)) {
                    throw new RuntimeException("Impossible de copier la base de données SQLite.");
                }
            }
            
            $this->writeBackupChecksum($filepath);
            return $filepath;
        }

        // Pour MySQL
        $commandParts = [
            $this->resolveMysqlBinary('mysqldump'),
            '--host=' . self::DB_HOST,
            '--port=' . self::DB_PORT,
            '--user=' . self::DB_USER,
            self::DB_PASS !== '' ? '--password=' . escapeshellarg(self::DB_PASS) : '',
            '--no-tablespaces',
            '--single-transaction',
            '--quick',
            '--routines',
            '--events',
            '--triggers',
            '--result-file=' . escapeshellarg($filepath),
            escapeshellarg(self::DB_NAME),
        ];

        $command = trim(implode(' ', array_filter($commandParts)));
        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($filepath)) {
            throw new RuntimeException('La création de la sauvegarde a échoué. Vérifiez mysqldump.');
        }

        $this->writeBackupChecksum($filepath);

        return $filepath;
    }

    /**
     * Restaure la base à partir d'un fichier de sauvegarde
     */
    public function restore(string $filepath): void
    {
        if (!file_exists($filepath)) {
            throw new InvalidArgumentException('Fichier de sauvegarde introuvable.');
        }

        $this->verifyBackupChecksum($filepath);

        $pdo = $this->getConnection();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $dbPath = $this->getDatabasePath();
            // Fermer la connexion PDO avant d'écraser le fichier
            $this->pdo_conn = null;
            self::$instance = null;
            
            if (!copy($filepath, $dbPath)) {
                throw new RuntimeException("Échec de la restauration SQLite (impossible de copier le fichier).");
            }
            return;
        }

        // Pour MySQL
        $commandParts = [
            $this->resolveMysqlBinary('mysql'),
            '--host=' . self::DB_HOST,
            '--port=' . self::DB_PORT,
            '--user=' . self::DB_USER,
            self::DB_PASS !== '' ? '--password=' . escapeshellarg(self::DB_PASS) : '',
            escapeshellarg(self::DB_NAME),
            '--execute=' . escapeshellarg('source ' . str_replace('\\', '/', $filepath))
        ];

        $command = trim(implode(' ', array_filter($commandParts)));
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new RuntimeException('La restauration a échoué. Vérifiez le fichier SQL et mysql.exe.');
        }
    }

    /**
     * Obtenir la liste des sauvegardes disponibles
     */
    public function getBackups(): array
    {
        $backupDir = $this->getBackupDirectory();
        if (!is_dir($backupDir)) {
            return [];
        }

        // Chercher à la fois .sql et .sqlite
        $files = array_merge(
            glob($backupDir . '/backup_*.sql'),
            glob($backupDir . '/backup_*.sqlite')
        );
        $backups = [];

        foreach ($files as $file) {
            $backups[] = [
                'name' => basename($file),
                'filename' => basename($file),
                'size' => filesize($file),
                'date' => filemtime($file)
            ];
        }

        usort($backups, fn($a, $b) => $b['date'] - $a['date']);
        return $backups;
    }

    /**
     * Exporter une table vers un fichier CSV
     */
    public function exportToCsv(string $table, string $target = 'php://output', string $where = '', array $params = []): void
    {
        $table = $this->assertAllowedCsvTable($table);

        $handle = fopen($target, 'w');
        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

        $metaStmt = $this->pdo_conn->query("SELECT * FROM `$table` LIMIT 0");
        $colCount = $metaStmt->columnCount();
        $columns = [];
        for ($i = 0; $i < $colCount; $i++) {
            $meta = $metaStmt->getColumnMeta($i);
            $columns[] = $meta['name'];
        }
        if (!empty($columns)) {
            fputcsv($handle, $columns, ',');
        }

        $sql = "SELECT * FROM `$table` $where";
        $stmt = $this->query($sql, $params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($handle, array_values($row), ',');
        }

        fclose($handle);
    }

    /**
     * Importer un fichier CSV dans une table avec mapping
     */
    public function importFromCsv(string $table, string $filepath, array $columnMapping): int
    {
        if (!file_exists($filepath)) {
            throw new Exception("Fichier introuvable : $filepath");
        }

        $table = $this->assertAllowedCsvTable($table);

        $handle = fopen($filepath, 'r');
        
        // Ignorer BOM si présent
        $bom = fread($handle, 3);
        if ($bom !== chr(0xEF).chr(0xBB).chr(0xBF)) {
            rewind($handle);
        }

        // Détection automatique du séparateur (virgule ou point-virgule)
        $firstLine = fgets($handle);
        $separator = (strpos($firstLine, ';') !== false) ? ';' : ',';
        rewind($handle);
        if ($bom === chr(0xEF).chr(0xBB).chr(0xBF)) fread($handle, 3);

        $headers = fgetcsv($handle, 0, $separator);
        if (!$headers) {
            fclose($handle);
            return 0;
        }

        $count = 0;
        $this->beginTransaction();

        try {
            while (($data = fgetcsv($handle, 0, $separator)) !== false) {
                $params = [];
                $cols = [];
                $placeholders = [];

                // Le mapping est reçu ainsi : [db_column => csv_column_index]
                foreach ($columnMapping as $dbCol => $csvIndex) {
                    // Nettoyer le nom de la colonne (sécurité)
                    $dbColClean = preg_replace('/[^a-zA-Z0-9_]/', '', $dbCol);
                    
                    if ($csvIndex !== "" && isset($data[$csvIndex])) {
                        $cols[] = "`$dbColClean`";
                        $placeholders[] = "?";
                        $params[] = $data[$csvIndex];
                    }
                }

                if (!empty($cols)) {
                    $sql = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
                    $this->query($sql, $params);
                    $count++;
                }
            }
            $this->commit();
        } catch (Exception $e) {
            $this->rollback();
            fclose($handle);
            throw $e;
        }

        fclose($handle);
        return $count;
    }

    /**
     * Aide aux sauvegardes mensuelles
     */
    public function getDatabaseSizeBytes(): int
    {
        try {
            if ($this->pdo_conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $pageSize = (int)$this->fetchColumn("PRAGMA page_size");
                $pageCount = (int)$this->fetchColumn("PRAGMA page_count");
                return $pageSize * $pageCount;
            }

            $sql = "SELECT SUM(data_length + index_length) AS size_bytes
                    FROM information_schema.TABLES
                    WHERE table_schema = ?";
            $row = $this->fetchOne($sql, [self::DB_NAME]);
            return isset($row['size_bytes']) ? (int)$row['size_bytes'] : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Compatibilité avec l’ancien nom de méthode
     */
    public function createBackup(): ?string
    {
        try {
            return basename($this->backup());
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Informations affichables sur la base
     */
    public function getDatabasePath(): string
    {
        try {
            if ($this->pdo_conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $dbList = $this->fetchAll("PRAGMA database_list");
                return $dbList[0]['file'] ?? 'SQLite Local Database';
            }
        } catch (Throwable $e) {
            return 'Base de données active';
        }
        return sprintf('mysql://%s@%s/%s', self::DB_USER, self::DB_HOST, self::DB_NAME);
    }
}

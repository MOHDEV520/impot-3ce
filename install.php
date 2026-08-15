<?php
/**
 * ============================================
 * INSTALLATION DU SYSTÈME - MySQL
 * Crée la base de données et les tables
 * ============================================
 */

define('APP_ROOT', __DIR__);

// Configuration MySQL
$dbHost = 'localhost';
$dbName = 'gestion_fiscale';
$dbUser = 'root';
$dbPass = '';

$message = '';
$success = false;
$step = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Étape 1: Créer la base de données
        $pdo = new PDO("mysql:host=$dbHost;charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        
        // Créer la base si elle n'existe pas
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$dbName`");
        
        // Étape 2: Créer les tables
        
        // Table agents
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS agents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nom VARCHAR(100) NOT NULL,
                prenom VARCHAR(100) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                mot_de_passe VARCHAR(255) NOT NULL,
                role ENUM('agent', 'admin', 'superviseur') DEFAULT 'agent',
                statut ENUM('actif', 'inactif', 'suspendu') DEFAULT 'actif',
                date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                derniere_connexion TIMESTAMP NULL
            ) ENGINE=InnoDB
        ");
        
        // Table clients
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS clients (
                id INT AUTO_INCREMENT PRIMARY KEY,
                agent_id INT NOT NULL,
                nom VARCHAR(255) NOT NULL,
                ifu VARCHAR(50) UNIQUE,
                rccm VARCHAR(100),
                adresse TEXT,
                telephone VARCHAR(20),
                email VARCHAR(255),
                secteur_activite VARCHAR(100),
                regime_fiscal VARCHAR(50) DEFAULT 'RSI',
                assujetti_tva TINYINT(1) DEFAULT 1,
                taux_tva DECIMAL(5,2) DEFAULT 18.00,
                section_salaires TINYINT(1) DEFAULT 0,
                section_location TINYINT(1) DEFAULT 0,
                section_css TINYINT(1) DEFAULT 0,
                valeur_locative DECIMAL(15,2) DEFAULT 0,
                statut ENUM('actif', 'inactif') DEFAULT 'actif',
                date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (agent_id) REFERENCES agents(id)
            ) ENGINE=InnoDB
        ");
        
        // Table parametres_fiscaux
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS parametres_fiscaux (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(50) NOT NULL UNIQUE,
                libelle VARCHAR(255) NOT NULL,
                valeur DECIMAL(15,4) NOT NULL,
                type_valeur ENUM('pourcentage', 'montant', 'seuil') DEFAULT 'pourcentage',
                description TEXT,
                date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB
        ");
        
        // Table fournisseurs
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS fournisseurs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                nom VARCHAR(255) NOT NULL,
                ifu VARCHAR(50),
                adresse TEXT,
                telephone VARCHAR(20),
                email VARCHAR(255),
                date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");
        
        // Table achats
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS achats (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                fournisseur_id INT,
                mois INT NOT NULL,
                annee INT NOT NULL,
                type_document ENUM('facture', 'avoir', 'ticket') DEFAULT 'facture',
                numero_document VARCHAR(100),
                date_document DATE,
                montant_ht DECIMAL(15,2) NOT NULL DEFAULT 0,
                montant_tva DECIMAL(15,2) NOT NULL DEFAULT 0,
                montant_ttc DECIMAL(15,2) NOT NULL DEFAULT 0,
                tva_deductible TINYINT(1) DEFAULT 1,
                nature_operation VARCHAR(255) DEFAULT 'achat',
                description TEXT,
                date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
                FOREIGN KEY (fournisseur_id) REFERENCES fournisseurs(id) ON DELETE SET NULL
            ) ENGINE=InnoDB
        ");
        
        // Table natures_depenses
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS natures_depenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(50) NOT NULL UNIQUE,
                libelle VARCHAR(255) NOT NULL,
                deductible TINYINT(1) DEFAULT 1,
                description TEXT
            ) ENGINE=InnoDB
        ");
        
        // Table depenses
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS depenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                nature_id INT NOT NULL,
                mois INT NOT NULL,
                annee INT NOT NULL,
                montant DECIMAL(15,2) NOT NULL DEFAULT 0,
                description TEXT,
                date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
                FOREIGN KEY (nature_id) REFERENCES natures_depenses(id)
            ) ENGINE=InnoDB
        ");
        
        // Table compte_gestion_mensuel
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS compte_gestion_mensuel (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                mois INT NOT NULL,
                annee INT NOT NULL,
                ca_global DECIMAL(15,2) DEFAULT 0,
                ca_exonere DECIMAL(15,2) DEFAULT 0,
                ca_taxable DECIMAL(15,2) DEFAULT 0,
                masse_salariale DECIMAL(15,2) DEFAULT 0,
                effectif INT DEFAULT 0,
                loyers_percus DECIMAL(15,2) DEFAULT 0,
                statut ENUM('en_cours', 'pret', 'valide', 'verrouille') DEFAULT 'en_cours',
                date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_client_mois (client_id, mois, annee),
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");
        
        // Table impots_mensuels
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS impots_mensuels (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                mois INT NOT NULL,
                annee INT NOT NULL,
                tva_collectee DECIMAL(15,2) DEFAULT 0,
                tva_deductible DECIMAL(15,2) DEFAULT 0,
                tva_due DECIMAL(15,2) DEFAULT 0,
                credit_tva DECIMAL(15,2) DEFAULT 0,
                cf_base DECIMAL(15,2) DEFAULT 0,
                cf_montant DECIMAL(15,2) DEFAULT 0,
                its_montant DECIMAL(15,2) DEFAULT 0,
                tl_montant DECIMAL(15,2) DEFAULT 0,
                irf_base DECIMAL(15,2) DEFAULT 0,
                irf_montant DECIMAL(15,2) DEFAULT 0,
                tva_location DECIMAL(15,2) DEFAULT 0,
                tf_montant DECIMAL(15,2) DEFAULT 0,
                css_patronale DECIMAL(15,2) DEFAULT 0,
                css_salariale DECIMAL(15,2) DEFAULT 0,
                total_impots DECIMAL(15,2) DEFAULT 0,
                date_calcul TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_impots_mois (client_id, mois, annee),
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");
        
        // Table annexes
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS annexes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                type_annexe ENUM('A', 'B', 'C', 'D') NOT NULL,
                type_impot VARCHAR(50) NOT NULL,
                reference_legale VARCHAR(255),
                base_juridique TEXT,
                date_debut DATE,
                date_fin DATE,
                fichier_path VARCHAR(500),
                statut ENUM('actif', 'expire', 'annule') DEFAULT 'actif',
                date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");
        
        // Table logs
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                agent_id INT,
                action VARCHAR(100) NOT NULL,
                table_concernee VARCHAR(100),
                enregistrement_id INT,
                details TEXT,
                ip_address VARCHAR(45),
                date_action TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE SET NULL
            ) ENGINE=InnoDB
        ");
        
        // Étape 3: Insérer les données par défaut
        
        // Vérifier si l'admin existe déjà
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE email = ?");
        $stmt->execute(['admin@cabinet.local']);
        
        if (!$stmt->fetch()) {
            // Créer l'admin avec le bon hash
            $hash = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO agents (nom, prenom, email, mot_de_passe, role, statut) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute(['Admin', 'Système', 'admin@cabinet.local', $hash, 'admin', 'actif']);
        }
        
        // Insérer les natures de dépenses
        $natures = [
            ['EAU', 'Eau', 1],
            ['ELEC', 'Électricité', 1],
            ['TEL', 'Téléphone / Internet', 1],
            ['LOYER', 'Loyer', 1],
            ['CARB', 'Carburant', 1],
            ['MAINT', 'Maintenance / Réparations', 1],
            ['FOURN', 'Fournitures de bureau', 1],
            ['ASSUR', 'Assurances', 1],
            ['BANQUE', 'Frais bancaires', 1],
            ['DIVERS', 'Divers', 1],
            ['AMENDES', 'Amendes et pénalités', 0],
            ['DONS', 'Dons et libéralités', 0]
        ];
        
        $stmt = $pdo->prepare("INSERT IGNORE INTO natures_depenses (code, libelle, deductible) VALUES (?, ?, ?)");
        foreach ($natures as $nature) {
            $stmt->execute($nature);
        }
        
        // Insérer les paramètres fiscaux
        $params = [
            ['TVA_TAUX', 'Taux TVA standard', 18.00, 'pourcentage'],
            ['CF_TAUX', 'Contribution Foncière', 1.00, 'pourcentage'],
            ['ITS_TAUX_BASE', 'ITS Taux de base', 0.00, 'pourcentage'],
            ['TL_TAUX', 'Taxe de Logement', 1.00, 'pourcentage'],
            ['IRF_TAUX', 'IRF Prestataires', 5.00, 'pourcentage'],
            ['TVA_LOC_TAUX', 'TVA sur Location', 18.00, 'pourcentage'],
            ['TF_TAUX', 'Taxe Foncière', 11.00, 'pourcentage'],
            ['CSS_TAUX', 'CSS Employeur', 16.00, 'pourcentage'],
            ['CSS_TAUX_SAL', 'CSS Salarié', 6.40, 'pourcentage'],
            ['SMIG', 'SMIG Mensuel', 52000.00, 'montant'],
            ['SEUIL_TVA', 'Seuil assujettissement TVA', 50000000.00, 'seuil']
        ];
        
        $stmt = $pdo->prepare("INSERT IGNORE INTO parametres_fiscaux (code, libelle, valeur, type_valeur) VALUES (?, ?, ?, ?)");
        foreach ($params as $param) {
            $stmt->execute($param);
        }
        
        $message = "Installation réussie ! La base de données MySQL a été créée.";
        $success = true;
        
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        
        if (strpos($e->getMessage(), 'Access denied') !== false) {
            $message = "Erreur d'accès MySQL. Vérifiez que MySQL est démarré dans XAMPP.";
        } elseif (strpos($e->getMessage(), 'Connection refused') !== false) {
            $message = "MySQL n'est pas démarré. Lancez MySQL dans le panneau de contrôle XAMPP.";
        }
    }
}

// Vérifier le statut MySQL
$mysqlRunning = false;
try {
    $testPdo = @new PDO("mysql:host=localhost", 'root', '');
    $mysqlRunning = true;
} catch (Exception $e) {
    $mysqlRunning = false;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation - Gestion Fiscale</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full mx-4">
        <div class="bg-white rounded-xl shadow-lg p-8">
            <div class="text-center mb-6">
                <div class="w-20 h-20 bg-white rounded-xl shadow-md border border-gray-100 flex items-center justify-center mx-auto mb-4 p-2">
                    <img src="assets/img/logo.svg" alt="3CE Logo" class="w-full h-full object-contain">
                </div>
                <h1 class="text-3xl font-black text-primary-900 tracking-tighter">3CE FISCUS</h1>
                <p class="text-gray-500 mt-1 uppercase text-[10px] tracking-widest font-bold">Initialisation du Système</p>
            </div>
            
            <!-- Statut MySQL -->
            <?php 
                $statusClass = $mysqlRunning ? 'bg-green-50 border-green-500' : 'bg-red-50 border-red-500';
            ?>
            <div class="mb-6 p-4 rounded-lg border-l-4 <?= $statusClass ?>">
                <div class="flex items-center">
                    <i class="fas <?= $mysqlRunning ? 'fa-check-circle text-green-500' : 'fa-times-circle text-red-500' ?> mr-2"></i>
                    <span class="<?= $mysqlRunning ? 'text-green-700' : 'text-red-700' ?>">
                        MySQL est <?= $mysqlRunning ? 'démarré' : 'arrêté - Démarrez MySQL dans XAMPP' ?>
                    </span>
                </div>
            </div>
            
            <?php if ($message): ?>
            <?php 
                $alertClass = $success ? 'bg-green-50 border-green-500 text-green-700' : 'bg-red-50 border-red-500 text-red-700';
            ?>
            <div class="mb-6 p-4 rounded-lg border-l-4 <?= $alertClass ?>">
                <div class="flex items-center">
                    <i class="fas <?= $success ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
                    <span><?= htmlspecialchars($message) ?></span>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="text-center">
                <p class="text-gray-600 mb-4">Vous pouvez maintenant vous connecter :</p>
                <div class="bg-gray-50 p-4 rounded-lg mb-4 text-left">
                    <p class="text-sm"><strong>Email :</strong> admin@cabinet.local</p>
                    <p class="text-sm"><strong>Mot de passe :</strong> admin123</p>
                </div>
                <a href="index.php" class="inline-flex items-center px-6 py-3 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700">
                    <i class="fas fa-sign-in-alt mr-2"></i> Aller à la connexion
                </a>
            </div>
            <?php else: ?>
            
            <form method="POST">
                <div class="space-y-4">
                    <div class="p-4 bg-gray-50 rounded-lg">
                        <h3 class="font-medium text-gray-800 mb-2">Cette installation va :</h3>
                        <ul class="text-sm text-gray-600 space-y-1">
                            <li><i class="fas fa-check text-green-500 mr-2"></i>Créer la base "gestion_fiscale"</li>
                            <li><i class="fas fa-check text-green-500 mr-2"></i>Créer 11 tables</li>
                            <li><i class="fas fa-check text-green-500 mr-2"></i>Créer le compte administrateur</li>
                            <li><i class="fas fa-check text-green-500 mr-2"></i>Insérer les données par défaut</li>
                        </ul>
                    </div>
                    
                    <button type="submit" class="w-full py-3 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 <?= !$mysqlRunning ? 'opacity-50 cursor-not-allowed' : '' ?>" <?= !$mysqlRunning ? 'disabled' : '' ?>>
                        <i class="fas fa-database mr-2"></i> Installer la base de données
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
        
        <p class="text-center text-sm text-gray-400 mt-4">
            Supprimez ce fichier après l'installation.
        </p>
    </div>
</body>
</html>

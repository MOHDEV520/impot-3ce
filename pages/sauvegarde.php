<?php
/**
 * ============================================
 * SAUVEGARDE ET RESTAURATION
 * Système de Gestion Fiscale
 * ============================================
 */

define('APP_ROOT', dirname(__DIR__));
session_start();

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/classes/Agent.php';
require_once APP_ROOT . '/classes/Client.php';
require_once APP_ROOT . '/includes/transfer_helpers.php';

// Vérifier l'authentification
if (!Agent::estConnecte() || !Agent::verifierTimeout()) {
    header('Location: ../index.php');
    exit;
}

$agent = Agent::getAgentConnecte();
$db = Database::getInstance();

$clients = in_array($agent->getRole(), ['admin', 'superviseur'], true)
    ? Client::getAll()
    : Client::getByAgent($agent->getId());

$prefillClientId = (int)($_GET['client'] ?? 0);
$prefillMois = (int)($_GET['mois'] ?? 0);
$prefillAnnee = (int)($_GET['annee'] ?? 0);
if ($prefillClientId > 0 && !$agent->aAccesClient($prefillClientId)) {
    $prefillClientId = 0;
}

$moisNoms = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

// Message
$message = '';
$messageType = 'success';
$csrfToken = Agent::getCsrfToken();
$transferPreview = $_SESSION['transfer_import_preview'] ?? null;

// Dossier des sauvegardes
$backupDir = APP_ROOT . '/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!Agent::verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = 'Session invalide, veuillez reessayer.';
        $messageType = 'error';
        $action = '';
    }
    
    try {
        switch ($action) {
            case 'backup':
                $label = null;
                $moisBk = $_POST['mois'] ?? '';
                $annee = $_POST['annee'] ?? '';
                $clientId = $_POST['client_id'] ?? '';
                
                if (!empty($moisBk) && !empty($annee)) {
                    $moisNum = array_search($moisBk, $moisNoms);
                    $label = ($moisNum ? sprintf('%02d', $moisNum) : $moisBk) . '-' . $annee;
                }
                
                if (!empty($clientId)) {
                    $clientData = Client::getById((int)$clientId);
                    if ($clientData) {
                        $clientName = str_replace(' ', '_', $clientData['nom'] ?? 'Client');
                        $label = ($label ? $label . '_' : '') . $clientName;
                    }
                }
                
                $backupPath = $db->backup($label);
                $message = 'Sauvegarde créée avec succès: ' . basename($backupPath);
                break;

            case 'export_csv':
                $table = $_POST['table'] ?? '';
                $moisNum = (int)($_POST['mois'] ?? 0);
                $annee = $_POST['annee'] ?? '';
                $clientId = $_POST['client_id'] ?? '';
                
                $conditions = [];
                $params = [];
                
                $tablesAvecClientId = [
                    'achats', 'depenses', 'impots', 'declarations',
                    'impots_mensuels', 'compte_gestion_mensuel',
                    'parametres_fiscaux', 'services_annexe_tva',
                    'exonerations_client', 'annexes'
                ];
                $tablesAvecMoisAnnee = [
                    'achats', 'depenses', 'impots', 'declarations',
                    'impots_mensuels', 'compte_gestion_mensuel',
                    'services_annexe_tva'
                ];
                
                if (!empty($clientId)) {
                    if ($table === 'clients') {
                        $conditions[] = "id = ?";
                        $params[] = $clientId;
                    } elseif (in_array($table, $tablesAvecClientId)) {
                        $conditions[] = "client_id = ?";
                        $params[] = $clientId;
                    }
                }
                
                if (in_array($table, $tablesAvecMoisAnnee)) {
                    if ($moisNum >= 1 && $moisNum <= 12) {
                        $conditions[] = "mois = ?";
                        $params[] = $moisNum;
                    }
                    if (!empty($annee)) {
                        $conditions[] = "annee = ?";
                        $params[] = $annee;
                    }
                }
                
                $where = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
                
                $filename = "export_{$table}_" . date('Y-m-d_H-i') . ".csv";
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                
                $db->exportToCsv($table, 'php://output', $where, $params);
                exit;
                
            case 'restore':
                if (!$agent->estAdmin()) {
                    throw new Exception('Seul un administrateur peut restaurer une sauvegarde.');
                }
                
                $backupFile = $_POST['backup_file'] ?? '';
                $fullPath = $backupDir . '/' . basename($backupFile);
                
                if (!file_exists($fullPath)) {
                    throw new Exception('Fichier de sauvegarde introuvable.');
                }
                
                if (!isset($_POST['confirm_restore'])) {
                    throw new Exception('Veuillez confirmer la restauration.');
                }
                
                try {
                    $autoBackupPath = $db->backup('avant-restauration');
                    $autoBackupName = basename($autoBackupPath);
                } catch (Exception $backupEx) {
                    $autoBackupName = null;
                }
                
                $db->restore($fullPath);
                $message = 'Base de données restaurée avec succès.';
                if ($autoBackupName) {
                    $message .= ' (Sauvegarde de sécurité créée: ' . $autoBackupName . ')'  ;
                }
                break;

            case 'export_transfer':
                $clientId = (int)($_POST['client_id_transfer'] ?? 0);
                $moisExport = (int)($_POST['mois_transfer'] ?? 0);
                $anneeExport = (int)($_POST['annee_transfer'] ?? 0);

                if (!$clientId) {
                    throw new Exception('Veuillez sélectionner un client.');
                }
                if (!$agent->aAccesClient($clientId)) {
                    throw new Exception('Accès non autorisé à ce client.');
                }

                $clientData = Client::getById($clientId);
                if (!$clientData) {
                    throw new Exception('Client introuvable.');
                }

                $transfer = transfer_buildClientPayload($db, $clientData, $moisExport, $anneeExport);
                if (!transfer_hasData($transfer)) {
                    throw new Exception('Aucune donnée trouvée pour ce client sur la période sélectionnée.');
                }

                $filename = transfer_exportFilename($clientData, $moisExport, $anneeExport);
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                echo json_encode($transfer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                exit;

            case 'export_transfer_all':
                $moisExport = (int)($_POST['mois_transfer_all'] ?? 0);
                $anneeExport = (int)($_POST['annee_transfer_all'] ?? 0);
                $exports = [];

                foreach ($clients as $c) {
                    $transfer = transfer_buildClientPayload($db, $c, $moisExport, $anneeExport);
                    if (transfer_hasData($transfer)) {
                        $exports[] = $transfer;
                    }
                }

                if (empty($exports)) {
                    throw new Exception('Aucune donnée à exporter pour la période sélectionnée.');
                }

                $batch = [
                    'format'      => '3CE_TRANSFER_BATCH',
                    'version'     => '1.0',
                    'export_date' => date('Y-m-d H:i:s'),
                    'exports'     => $exports,
                ];
                $filename = transfer_exportFilename(['nom' => 'tous_clients'], $moisExport, $anneeExport, 'tous_clients');
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                echo json_encode($batch, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                exit;

            case 'import_transfer':
                if (!isset($_FILES['transfer_file']) || $_FILES['transfer_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Veuillez sélectionner un fichier de transfert.');
                }

                $content = file_get_contents($_FILES['transfer_file']['tmp_name']);
                $payload = json_decode($content, true);
                $transfers = transfer_normalizePayload($payload);

                $tempFile = $backupDir . '/transfer_' . bin2hex(random_bytes(16)) . '.json';
                file_put_contents($tempFile, $content);
                $_SESSION['transfer_import_file'] = $tempFile;
                $_SESSION['transfer_import_preview'] = transfer_buildPreview($db, $transfers, $moisNoms);
                $transferPreview = $_SESSION['transfer_import_preview'];

                $message = 'Fichier analysé avec succès. Vérifiez les informations puis confirmez l\'importation.';
                break;

            case 'confirm_transfer':
                $tempFile = $_SESSION['transfer_import_file'] ?? '';
                if (!$tempFile || !file_exists($tempFile)) {
                    throw new Exception('Session expirée. Veuillez re-téléverser le fichier.');
                }

                $payload = json_decode(file_get_contents($tempFile), true);
                $transfers = transfer_normalizePayload($payload);

                $db->beginTransaction();
                try {
                    $summaries = [];
                    foreach ($transfers as $transfer) {
                        $summaries[] = transfer_importOne($db, $agent, $transfer);
                    }
                    $db->commit();
                } catch (Exception $e) {
                    $db->rollback();
                    throw $e;
                }

                @unlink($tempFile);
                unset($_SESSION['transfer_import_file'], $_SESSION['transfer_import_preview']);
                $transferPreview = null;

                if (count($summaries) === 1) {
                    $s = $summaries[0];
                    $message = "Transfert réussi : {$s['nom']} — {$s['nb_mois']} mois, {$s['nb_achats']} achats, {$s['nb_depenses']} dépenses importés.";
                } else {
                    $message = count($summaries) . ' clients importés avec succès.';
                }
                break;

            case 'cancel_transfer':
                $tf = $_SESSION['transfer_import_file'] ?? '';
                if ($tf && file_exists($tf)) @unlink($tf);
                unset($_SESSION['transfer_import_file'], $_SESSION['transfer_import_preview']);
                $transferPreview = null;
                $message = 'Importation annulée.';
                break;
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Liste des sauvegardes
$backups = [];
if (is_dir($backupDir)) {
    foreach ($db->getBackups() as $backup) {
        $backups[] = [
            'name' => $backup['filename'],
            'size' => $backup['size'],
            'date' => $backup['date']
        ];
    }
}

// Infos sur la base actuelle
$dbPath = $db->getDatabasePath();
$dbSize = $db->getDatabaseSizeBytes();

function formatSize($bytes) {
    $units = ['o', 'Ko', 'Mo', 'Go'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sauvegarde - Gestion Fiscale</title>
    <!-- Utilisation des ressources locales pour le 100% Offline -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Navigation -->
    <nav class="bg-primary-800 text-white shadow-lg no-print">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="dashboard.php" class="flex items-center">
                        <div class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center mr-3">
                            <i class="fas fa-calculator text-xl"></i>
                        </div>
                        <span class="text-xl font-bold">Gestion Fiscale</span>
                    </a>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-blue-200"><?= htmlspecialchars($agent->getNomComplet()) ?></span>
                    <a href="logout.php" class="text-blue-200 hover:text-white"><i class="fas fa-sign-out-alt"></i></a>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Contenu -->
    <main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Breadcrumb -->
        <nav class="flex mb-6">
            <ol class="flex items-center space-x-2 text-sm">
                <li><a href="dashboard.php" class="text-gray-500 hover:text-gray-700">Tableau de bord</a></li>
                <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
                <li class="text-primary-600 font-medium">Sauvegarde</li>
            </ol>
        </nav>
        
        <!-- Titre -->
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-gray-800">Sauvegarde et restauration</h1>
            <p class="text-gray-500 mt-1">Gérez les sauvegardes de la base de données</p>
        </div>
        
        <!-- Message -->
        <?php if ($message): 
            $alertClass = $messageType === 'success' ? 'bg-green-50 border-green-500 text-green-700' : 'bg-red-50 border-red-500 text-red-700';
        ?>
        <div class="mb-6 p-4 rounded-lg border-l-4 <?= $alertClass ?>">
            <div class="flex items-center">
                <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Info base actuelle -->
        <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">
                <i class="fas fa-database mr-2 text-gray-400"></i>
                Base de données actuelle
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="p-4 bg-gray-50 rounded-lg">
                    <div class="text-sm text-gray-500"><?= strpos($dbPath, 'mysql:') !== false ? 'Serveur MySQL' : 'Fichier' ?></div>
                    <div class="font-medium text-gray-800 truncate" title="<?= htmlspecialchars($dbPath) ?>">
                        <?= basename($dbPath) ?>
                    </div>
                </div>
                <div class="p-4 bg-gray-50 rounded-lg">
                    <div class="text-sm text-gray-500">Taille</div>
                    <div class="font-medium text-gray-800"><?= formatSize($dbSize) ?></div>
                </div>
                <div class="p-4 bg-gray-50 rounded-lg">
                    <div class="text-sm text-gray-500">Dernière modification</div>
                    <div class="font-medium text-gray-800">
                        <?php 
                        // On n'affiche la date de modification que pour SQLite (vrai fichier)
                        if (strpos($dbPath, 'mysql:') === false && file_exists($dbPath)) {
                            echo date('d/m/Y H:i', filemtime($dbPath));
                        } else {
                            echo 'En production';
                        }
                        ?>
                    </div>
                </div>
            </div>
            
            <!-- Bouton sauvegarde -->
            <form method="POST" class="mt-4 pt-4 border-t border-gray-100">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <h3 class="text-md font-medium text-gray-700 mb-3">Créer une nouvelle sauvegarde complète</h3>
                
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Mois (optionnel)</label>
                        <select name="mois" class="rounded-lg border-gray-300 text-sm focus:ring-primary-500 focus:border-primary-500 py-2 pl-3 pr-8">
                            <option value="">-- Aucun --</option>
                            <?php foreach ($moisNoms as $num => $nom): ?>
                                <option value="<?= $nom ?>" <?= date('n') == $num ? 'selected' : '' ?>><?= $nom ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Année</label>
                        <select name="annee" class="rounded-lg border-gray-300 text-sm focus:ring-primary-500 focus:border-primary-500 py-2 pl-3 pr-8">
                            <?php for ($y = date('Y') + 1; $y >= date('Y') - 5; $y--): ?>
                                <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Client (optionnel)</label>
                        <select name="client_id" class="rounded-lg border-gray-300 text-sm focus:ring-primary-500 focus:border-primary-500 py-2 pl-3 pr-8 max-w-50">
                            <option value="">-- Tous les clients --</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <input type="hidden" name="action" value="backup">
                    <button type="submit" class="px-6 py-2 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700 transition-colors shadow-sm">
                        <i class="fas fa-download mr-2"></i> Sauvegarder .sqlite
                    </button>
                </div>
            </form>
        </div>

        <!-- ========== TRANSFERT DE DONNÉES CLIENT ========== -->
        <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-1">
                <i class="fas fa-exchange-alt mr-2 text-indigo-500"></i>
                Transfert de données entre ordinateurs
            </h2>
            <p class="text-sm text-gray-500 mb-4">Exportez les données d'un ou plusieurs clients, puis importez le fichier .json sur un autre PC.</p>

            <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-4 mb-5 text-sm text-indigo-900">
                <div class="font-semibold mb-2"><i class="fas fa-route mr-1"></i> Comment faire</div>
                <ol class="list-decimal list-inside space-y-1 text-indigo-800">
                    <li><strong>PC source</strong> : exportez le client (ou tous les clients) en fichier .json</li>
                    <li>Copiez le fichier sur clé USB, e-mail ou cloud</li>
                    <li><strong>PC destination</strong> : ouvrez 3CE FISCUS → Sauvegarde → importez le .json</li>
                    <li>Vérifiez l'aperçu, puis confirmez l'importation</li>
                </ol>
                <p class="text-xs text-indigo-600 mt-3"><i class="fas fa-database mr-1"></i> Pour migrer <strong>tout le cabinet</strong> d'un coup, utilisez la sauvegarde complète .sqlite plus bas (admin).</p>
            </div>
            
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Export transfert -->
                <div class="border border-gray-200 rounded-xl p-5">
                    <h3 class="font-semibold text-gray-700 mb-3">
                        <i class="fas fa-upload mr-1 text-green-500"></i> Exporter un client
                    </h3>
                    <form method="POST" target="_blank" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="export_transfer">
                        
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Client *</label>
                            <select name="client_id_transfer" required class="w-full rounded-lg border-gray-300 text-sm">
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($clients as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= $prefillClientId === (int)$c['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['nom']) ?> <?= !empty($c['ifu']) ? '(' . htmlspecialchars($c['ifu']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Mois</label>
                                <select name="mois_transfer" class="w-full rounded-lg border-gray-300 text-sm">
                                    <option value="">-- Tous --</option>
                                    <?php foreach ($moisNoms as $num => $nom): ?>
                                        <option value="<?= $num ?>" <?= $prefillMois === $num ? 'selected' : '' ?>><?= $nom ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Année</label>
                                <select name="annee_transfer" class="w-full rounded-lg border-gray-300 text-sm">
                                    <option value="">-- Toutes --</option>
                                    <?php for ($y = date('Y') + 1; $y >= 2020; $y--): ?>
                                        <option value="<?= $y ?>" <?= ($prefillAnnee > 0 ? $prefillAnnee : (int)date('Y')) === $y ? 'selected' : '' ?>><?= $y ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        
                        <button type="submit" class="w-full py-2.5 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700 transition-colors text-sm">
                            <i class="fas fa-file-export mr-2"></i> Exporter en fichier .json
                        </button>
                    </form>
                    <p class="text-xs text-gray-400 mt-2"><i class="fas fa-info-circle mr-1"></i> Achats, dépenses, impôts, fournisseurs, paramètres fiscaux, exonérations.</p>

                    <form method="POST" target="_blank" class="mt-4 pt-4 border-t border-gray-100 space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="export_transfer_all">
                        <h4 class="text-sm font-medium text-gray-600">Exporter tous mes clients</h4>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Mois</label>
                                <select name="mois_transfer_all" class="w-full rounded-lg border-gray-300 text-sm">
                                    <option value="">-- Tous --</option>
                                    <?php foreach ($moisNoms as $num => $nom): ?>
                                        <option value="<?= $num ?>" <?= $prefillMois === $num ? 'selected' : '' ?>><?= $nom ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Année</label>
                                <select name="annee_transfer_all" class="w-full rounded-lg border-gray-300 text-sm">
                                    <option value="">-- Toutes --</option>
                                    <?php for ($y = date('Y') + 1; $y >= 2020; $y--): ?>
                                        <option value="<?= $y ?>" <?= ($prefillAnnee > 0 ? $prefillAnnee : (int)date('Y')) === $y ? 'selected' : '' ?>><?= $y ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="w-full py-2 bg-indigo-600 text-white font-medium rounded-lg hover:bg-indigo-700 transition-colors text-sm">
                            <i class="fas fa-users mr-2"></i> Exporter tous les clients (.json)
                        </button>
                    </form>
                </div>
                
                <!-- Import transfert -->
                <div class="border border-gray-200 rounded-xl p-5">
                    <h3 class="font-semibold text-gray-700 mb-3">
                        <i class="fas fa-download mr-1 text-blue-500"></i> Importer un transfert
                    </h3>
                    <form method="POST" enctype="multipart/form-data" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="import_transfer">
                        
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Fichier de transfert (.json)</label>
                            <div class="flex items-center gap-2">
                                <label class="flex-1 flex items-center justify-center px-4 py-3 border-2 border-dashed border-gray-300 rounded-lg cursor-pointer hover:border-blue-400 transition-colors bg-gray-50">
                                    <i class="fas fa-file-code text-gray-400 mr-2"></i>
                                    <span class="text-sm text-gray-600" id="transfer-file-label">Choisir un fichier...</span>
                                    <input type="file" name="transfer_file" accept=".json" required class="hidden" onchange="document.getElementById('transfer-file-label').textContent = this.files[0]?.name || 'Choisir un fichier...'">
                                </label>
                            </div>
                        </div>
                        
                        <button type="submit" class="w-full py-2.5 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors text-sm">
                            <i class="fas fa-search mr-2"></i> Analyser le fichier
                        </button>
                    </form>
                    <p class="text-xs text-gray-400 mt-2"><i class="fas fa-shield-alt mr-1"></i> Le fichier sera vérifié avant import. Les doublons sont gérés automatiquement.</p>
                </div>
            </div>
        </div>
        
        <!-- Aperçu d'importation en attente -->
        <?php if ($transferPreview): ?>
        <div class="bg-white rounded-xl shadow-sm p-6 mb-6 border-2 border-blue-400 relative">
            <div class="absolute -top-3 left-4 bg-blue-500 text-white text-xs font-bold px-3 py-1 rounded-full">
                <i class="fas fa-eye mr-1"></i> Aperçu avant importation
            </div>

            <?php if (!empty($transferPreview['is_batch'])): ?>
            <div class="mt-2 mb-4">
                <div class="text-sm text-gray-500">Import groupé</div>
                <div class="font-bold text-gray-800 text-lg"><?= (int)$transferPreview['clients_count'] ?> client(s)</div>
                <div class="text-xs text-gray-500 mt-1">Exporté le <?= htmlspecialchars($transferPreview['export_date']) ?></div>
            </div>
            <div class="max-h-48 overflow-y-auto border border-gray-100 rounded-lg mb-4 divide-y divide-gray-100">
                <?php foreach ($transferPreview['clients'] ?? [] as $cp): ?>
                <div class="px-3 py-2 text-sm">
                    <span class="font-medium text-gray-800"><?= htmlspecialchars($cp['client_nom']) ?></span>
                    <?php if ($cp['client_existe']): ?>
                        <span class="text-xs text-yellow-700 ml-2">(mise à jour)</span>
                    <?php else: ?>
                        <span class="text-xs text-green-700 ml-2">(nouveau)</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else:
                $cp = ($transferPreview['clients'][0] ?? $transferPreview);
            ?>
            <div class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                <div>
                    <div class="text-sm text-gray-500">Client</div>
                    <div class="font-bold text-gray-800 text-lg"><?= htmlspecialchars($cp['client_nom'] ?? $transferPreview['client_nom'] ?? '') ?></div>
                    <?php if (!empty($cp['client_ifu'] ?? $transferPreview['client_ifu'] ?? '')): ?>
                        <div class="text-xs text-gray-500">IFU : <?= htmlspecialchars($cp['client_ifu'] ?? $transferPreview['client_ifu']) ?></div>
                    <?php endif; ?>
                    <?php $clientExiste = $cp['client_existe'] ?? $transferPreview['client_existe'] ?? false; ?>
                    <?php if ($clientExiste): ?>
                        <span class="inline-block mt-1 text-xs bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full">
                            <i class="fas fa-exclamation-triangle mr-1"></i> Client existant — les données seront mises à jour
                        </span>
                    <?php else: ?>
                        <span class="inline-block mt-1 text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">
                            <i class="fas fa-plus-circle mr-1"></i> Nouveau client — sera créé
                        </span>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Exporté le</div>
                    <div class="font-medium text-gray-800"><?= htmlspecialchars($transferPreview['export_date']) ?></div>
                    <?php
                    $moisList = $cp['mois_list'] ?? $transferPreview['mois_list'] ?? [];
                    if (!empty($moisList)):
                    ?>
                        <div class="text-sm text-gray-500 mt-2">Mois concernés</div>
                        <div class="text-sm font-medium text-gray-700"><?= htmlspecialchars(implode(', ', $moisList)) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php $st = $transferPreview['stats'] ?? []; ?>
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-5">
                <div class="bg-indigo-50 rounded-lg p-3 text-center">
                    <div class="text-2xl font-bold text-indigo-600"><?= (int)($st['mois_count'] ?? 0) ?></div>
                    <div class="text-xs text-indigo-500">Mois</div>
                </div>
                <div class="bg-green-50 rounded-lg p-3 text-center">
                    <div class="text-2xl font-bold text-green-600"><?= (int)($st['achats_count'] ?? 0) ?></div>
                    <div class="text-xs text-green-500">Achats</div>
                </div>
                <div class="bg-orange-50 rounded-lg p-3 text-center">
                    <div class="text-2xl font-bold text-orange-600"><?= (int)($st['depenses_count'] ?? 0) ?></div>
                    <div class="text-xs text-orange-500">Dépenses</div>
                </div>
                <div class="bg-blue-50 rounded-lg p-3 text-center">
                    <div class="text-2xl font-bold text-blue-600"><?= (int)($st['fournisseurs_count'] ?? 0) ?></div>
                    <div class="text-xs text-blue-500">Fournisseurs</div>
                </div>
                <div class="bg-purple-50 rounded-lg p-3 text-center">
                    <div class="text-2xl font-bold text-purple-600"><?= (int)($st['impots_count'] ?? 0) ?></div>
                    <div class="text-xs text-purple-500">Impôts</div>
                </div>
            </div>
            
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 mb-4 text-sm text-yellow-800">
                <i class="fas fa-info-circle mr-1"></i>
                L'importation remplacera les achats et dépenses existants pour les mois concernés.
                Les comptes de gestion et impôts seront mis à jour. Les fournisseurs existants seront réutilisés.
            </div>
            
            <div class="flex gap-3">
                <form method="POST" class="flex-1">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="confirm_transfer">
                    <button type="submit" class="w-full py-2.5 bg-green-600 text-white font-bold rounded-lg hover:bg-green-700 transition-colors">
                        <i class="fas fa-check-circle mr-2"></i> Confirmer l'importation
                    </button>
                </form>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="cancel_transfer">
                    <button type="submit" class="px-6 py-2.5 bg-gray-200 text-gray-700 font-medium rounded-lg hover:bg-gray-300 transition-colors">
                        Annuler
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Export / Import CSV -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Export CSV -->
            <div class="bg-white rounded-xl shadow-sm p-6 line-clamp-1">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-file-export mr-2 text-primary-500"></i>
                    Exportation de données (CSV)
                </h2>
                <p class="text-sm text-gray-500 mb-4">Exportez des tables spécifiques filtrées par mois ou par client.</p>
                
                <form method="POST" target="_blank" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="export_csv">
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Table à exporter</label>
                            <select name="table" required class="w-full rounded-lg border-gray-300 text-sm">
                                <optgroup label="Données principales">
                                    <option value="clients">Clients</option>
                                    <option value="achats">Achats</option>
                                    <option value="depenses">Dépenses</option>
                                    <option value="fournisseurs">Fournisseurs</option>
                                </optgroup>
                                <optgroup label="Fiscalité">
                                    <option value="impots">Impôts mensuels</option>
                                    <option value="declarations">Comptes de gestion</option>
                                    <option value="parametres_fiscaux">Paramètres fiscaux</option>
                                </optgroup>
                                <optgroup label="Référentiels">
                                    <option value="natures_depenses">Natures de dépenses</option>
                                    <option value="agents">Agents</option>
                                </optgroup>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Client</label>
                            <select name="client_id" class="w-full rounded-lg border-gray-300 text-sm">
                                <option value="">-- Tous --</option>
                                <?php foreach ($clients as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Mois</label>
                            <select name="mois" class="w-full rounded-lg border-gray-300 text-sm">
                                <option value="">-- Tous --</option>
                                <?php foreach ($moisNoms as $num => $nom): ?>
                                    <option value="<?= $num ?>"><?= $nom ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Année</label>
                            <select name="annee" class="w-full rounded-lg border-gray-300 text-sm">
                                <option value="">-- Toutes --</option>
                                <?php for ($y = date('Y') + 1; $y >= 2020; $y--): ?>
                                    <option value="<?= $y ?>"><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    
                    <button type="submit" class="w-full py-2 bg-primary-600 text-white font-medium rounded-lg hover:bg-primary-700 transition-colors">
                        <i class="fas fa-file-csv mr-2"></i> Télécharger l'export CSV
                    </button>
                </form>
            </div>

            <!-- Import CSV Link -->
            <div class="bg-linear-to-br from-blue-500 to-indigo-600 rounded-xl shadow-md p-6 text-white">
                <h2 class="text-lg font-semibold mb-4">
                    <i class="fas fa-file-import mr-2"></i>
                    Importation de fichiers
                </h2>
                <p class="mb-6 opacity-90">
                    Vous avez des données à importer ? Utilisez notre assistant d'importation CSV pour ajouter massivement des clients ou des opérations.
                </p>
                <a href="importation.php" class="inline-flex items-center px-6 py-3 bg-white text-blue-600 font-bold rounded-lg hover:bg-blue-50 transition-colors">
                    Aller vers l'importateur <i class="fas fa-arrow-right ml-2"></i>
                </a>
                <div class="mt-6 flex items-center text-xs opacity-75">
                    <i class="fas fa-info-circle mr-2"></i>
                    Supporte les formats CSV standards
                </div>
            </div>
        </div>
        
        <!-- Liste des sauvegardes -->
        <div class="bg-white rounded-xl shadow-sm">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-800">
                    <i class="fas fa-history mr-2 text-gray-400"></i>
                    Sauvegardes disponibles (<?= count($backups) ?>)
                </h2>
            </div>
            
            <?php if (empty($backups)): ?>
            <div class="p-8 text-center">
                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-archive text-gray-400 text-2xl"></i>
                </div>
                <p class="text-gray-500">Aucune sauvegarde disponible.</p>
                <p class="text-sm text-gray-400 mt-1">Créez votre première sauvegarde ci-dessus.</p>
            </div>
            <?php else: ?>
            <div class="divide-y divide-gray-200">
                <?php foreach ($backups as $backup): ?>
                <div class="px-6 py-4 flex items-center justify-between hover:bg-gray-50">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mr-4">
                            <i class="fas fa-file-archive text-blue-600"></i>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800"><?= htmlspecialchars($backup['name']) ?></p>
                            <p class="text-sm text-gray-500">
                                <?= formatSize($backup['size']) ?> • 
                                <?= date('d/m/Y H:i', $backup['date']) ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="flex items-center space-x-2">
                        <!-- Restaurer -->
                        <button type="button"
                                data-backup="<?= htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8') ?>"
                                onclick="openRestoreModal(this.dataset.backup)" 
                                class="p-2 text-green-600 hover:bg-green-50 rounded-lg" title="Restaurer">
                            <i class="fas fa-undo"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Conseils -->
        <div class="mt-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
            <h3 class="font-medium text-yellow-800 mb-2">
                <i class="fas fa-lightbulb mr-1"></i> Conseils
            </h3>
            <ul class="text-sm text-yellow-700 space-y-1">
                <li>• Effectuez des sauvegardes régulières (au moins une fois par semaine)</li>
                <li>• Conservez les sauvegardes sur un support externe (clé USB, disque dur)</li>
                <li>• Testez occasionnellement vos sauvegardes en les restaurant sur un autre poste</li>
                <li>• La restauration remplace toutes les données actuelles</li>
            </ul>
        </div>
    </main>
    
    <!-- Modal Restauration -->
    <div id="modal-restore" class="fixed inset-0 bg-black/50 items-center justify-center hidden z-50">
        <div class="bg-white rounded-xl shadow-xl max-w-md w-full mx-4">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Confirmer la restauration</h3>
            </div>
            <form method="POST" class="p-6">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="backup_file" id="restore-file">
                
                <div class="mb-4">
                    <div class="flex items-center justify-center w-16 h-16 bg-yellow-100 rounded-full mx-auto mb-4">
                        <i class="fas fa-exclamation-triangle text-yellow-600 text-2xl"></i>
                    </div>
                    <p class="text-center text-gray-600 mb-2">
                        Vous êtes sur le point de restaurer la sauvegarde :
                    </p>
                    <p class="text-center font-medium text-gray-800" id="restore-filename"></p>
                </div>
                
                <div class="bg-red-50 p-3 rounded-lg mb-4">
                    <p class="text-sm text-red-700">
                        <strong>Attention :</strong> Cette action remplacera toutes les données actuelles 
                        par celles de la sauvegarde. Cette opération est irréversible.
                    </p>
                </div>
                
                <label class="flex items-center mb-4">
                    <input type="checkbox" name="confirm_restore" value="1" required
                           class="w-4 h-4 text-primary-600 border-gray-300 rounded">
                    <span class="ml-2 text-sm text-gray-600">Je comprends et je confirme la restauration</span>
                </label>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeRestoreModal()" 
                            class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                        Annuler
                    </button>
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                        <i class="fas fa-undo mr-1"></i> Restaurer
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openRestoreModal(filename) {
            document.getElementById('restore-file').value = filename;
            document.getElementById('restore-filename').textContent = filename;
            const modal = document.getElementById('modal-restore');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }
        
        function closeRestoreModal() {
            const modal = document.getElementById('modal-restore');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeRestoreModal();
        });
    </script>
</body>
</html>

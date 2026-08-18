<?php
/**
 * ============================================
 * IMPORTATION DE DONNÉES
 * Système de Gestion Fiscale
 * ============================================
 */

define('APP_ROOT', dirname(__DIR__));
session_start();

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/classes/Agent.php';

// Vérifier l'authentification
if (!Agent::estConnecte() || !Agent::verifierTimeout()) {
    header('Location: ../index.php');
    exit;
}

$agent = Agent::getAgentConnecte();
$db = Database::getInstance();

$message = '';
$messageType = 'success';
$step = 1;
$tempFile = '';
$headers = [];
$targetTable = '';
$csrfToken = Agent::getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!Agent::verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = 'Session invalide, veuillez reessayer.';
        $messageType = 'danger';
        $action = '';
    }
    
    try {
        if ($action === 'upload') {
            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Erreur lors du téléchargement du fichier.');
            }
            
            $targetTable = $_POST['table'] ?? '';
            
            // Dossier temporaire sécurisé (AppData ou Temp OS)
            $uploadDir = APP_ROOT . '/backups';
            if (!is_writable($uploadDir)) {
                $uploadDir = sys_get_temp_dir();
            }
            
            $tempToken = bin2hex(random_bytes(16));
            $tempFile = $uploadDir . DIRECTORY_SEPARATOR . 'import_' . $tempToken . '.csv';
            
            if (!move_uploaded_file($_FILES['csv_file']['tmp_name'], $tempFile)) {
                throw new Exception('Impossible de sauvegarder le fichier temporaire.');
            }
            
            // Lire les entêtes avec détection de séparateur
            if (($handle = fopen($tempFile, "r")) !== FALSE) {
                $firstLine = fgets($handle);
                $separator = (strpos($firstLine, ';') !== false) ? ';' : ',';
                rewind($handle);
                $headers = fgetcsv($handle, 0, $separator);
                fclose($handle);
            }
            
            if (!$headers) {
                unlink($tempFile);
                throw new Exception('Fichier CSV invalide ou vide.');
            }
            
            $_SESSION['import_temp_file'] = $tempFile;
            $step = 2;
        } 
        elseif ($action === 'process') {
            $targetTable = $_POST['table'] ?? '';
            $tempFile = $_SESSION['import_temp_file'] ?? '';
            $mapping = $_POST['mapping'] ?? [];
            
            if (empty($tempFile) || !file_exists($tempFile)) {
                throw new Exception('Fichier temporaire introuvable ou session expirée.');
            }
            
            $count = $db->importFromCsv($targetTable, $tempFile, $mapping);
            
            unlink($tempFile);
            unset($_SESSION['import_temp_file']);
            $message = "$count enregistrements importés avec succès dans la table $targetTable.";
            $messageType = 'success';
            $step = 1;
        }
    } catch (Exception $e) {
        $message = messageErreurUtilisateur($e, "l'importation");
        $messageType = 'danger';
        $step = 1;
        if (!empty($tempFile) && file_exists($tempFile)) unlink($tempFile);
    }
}

// Liste des colonnes par table pour le mapping (doit correspondre au schéma réel)
$tableColumns = [
    'clients' => ['nom', 'ifu', 'type_activite', 'secteur', 'regime_fiscal', 'adresse', 'telephone', 'email', 'agent_id'],
    'achats' => ['client_id', 'fournisseur_id', 'compte_gestion_id', 'mois', 'annee', 'montant_ht', 'montant_tva', 'montant_ttc', 'type_document', 'reference_document', 'date_document'],
    'depenses' => ['client_id', 'compte_gestion_id', 'nature_id', 'mois', 'annee', 'montant', 'description'],
    'impots' => ['client_id', 'compte_gestion_id', 'mois', 'annee', 'tva_collectee', 'tva_deductible', 'tva_a_payer', 'credit_tva', 'cf', 'its', 'tl', 'irf', 'tva_location', 'tf', 'css', 'total_impots']
];

$titrePage = 'Importation';
include APP_ROOT . '/includes/header.php';
?>

        <!-- Titre -->
        <div class="mb-8 flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Assistant d'importation</h1>
                <p class="text-gray-500 mt-1">Importez vos données depuis des fichiers CSV</p>
            </div>
            <a href="sauvegarde.php" class="text-primary-600 hover:text-primary-700 font-medium">
                <i class="fas fa-arrow-left mr-1"></i> Retour aux sauvegardes
            </a>
        </div>

        <!-- Alertes -->
        <?php if ($message):
            $alertVariant = $messageType === 'success' ? 'alert-success' : 'alert-error';
        ?>
            <div class="alert <?= $alertVariant ?> mb-6">
                <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <div class="card overflow-hidden p-0">
            <!-- Stepper -->
            <div class="flex border-b border-gray-100 bg-gray-50/50">
                <div class="flex-1 py-4 px-6 flex items-center <?= $step == 1 ? 'bg-white border-b-2 border-primary-500' : '' ?>">
                    <span class="w-8 h-8 rounded-full <?= $step >= 1 ? 'bg-primary-600' : 'bg-gray-300' ?> text-white flex items-center justify-center mr-3 font-bold text-sm">1</span>
                    <span class="font-medium <?= $step == 1 ? 'text-primary-900' : 'text-gray-500' ?>">Téléchargement</span>
                </div>
                <div class="flex-1 py-4 px-6 flex items-center <?= $step == 2 ? 'bg-white border-b-2 border-primary-500' : '' ?>">
                    <span class="w-8 h-8 rounded-full <?= $step >= 2 ? 'bg-primary-600' : 'bg-gray-300' ?> text-white flex items-center justify-center mr-3 font-bold text-sm">2</span>
                    <span class="font-medium <?= $step == 2 ? 'text-primary-900' : 'text-gray-500' ?>">Mapping des colonnes</span>
                </div>
            </div>

            <div class="p-8">
                <?php if ($step == 1): ?>
                    <!-- Étape 1: Formulaire de téléchargement -->
                    <form method="POST" enctype="multipart/form-data" class="max-w-xl mx-auto">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="upload">
                        
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Table de destination</label>
                            <select name="table" required class="w-full rounded-lg border-gray-300 focus:ring-primary-500 focus:border-primary-500 shadow-sm">
                                <option value="clients">Clients</option>
                                <option value="achats">Achats</option>
                                <option value="depenses">Dépenses</option>
                                <option value="impots">Impôts</option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">Choisissez la table où les données seront insérées.</p>
                        </div>

                        <div class="mb-8">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Fichier CSV</label>
                            <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-lg hover:border-primary-400 transition-colors cursor-pointer group bg-gray-50" 
                                 onclick="document.getElementById('csv_file').click()">
                                <div class="space-y-1 text-center">
                                    <i class="fas fa-file-csv text-4xl text-gray-400 group-hover:text-primary-500 mb-3 block"></i>
                                    <div class="flex text-sm text-gray-600">
                                        <span class="relative cursor-pointer font-medium text-primary-600 hover:text-primary-500">Choisir un fichier</span>
                                        <p class="pl-1">ou glisser-déposer</p>
                                    </div>
                                    <p class="text-xs text-gray-500">CSV uniquement (max. 5Mo)</p>
                                    <input id="csv_file" name="csv_file" type="file" class="hidden" accept=".csv" required>
                                    <p id="file-name" class="mt-2 font-medium text-primary-700 hidden"></p>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-start mb-8 p-4 bg-blue-50 rounded-lg text-blue-700 text-sm">
                            <i class="fas fa-info-circle mt-0.5 mr-3"></i>
                            <div>
                                <p class="font-bold mb-1">Instruction importante :</p>
                                <p>Assurez-vous que votre fichier CSV utilise la virgule (,) comme séparateur et que la première ligne contient les noms des colonnes.</p>
                            </div>
                        </div>

                        <button type="submit" class="btn-primary w-full py-3">
                            Continuer vers le mapping <i class="fas fa-arrow-right"></i>
                        </button>
                    </form>

                <?php elseif ($step == 2): ?>
                    <!-- Étape 2: Mapping des colonnes -->
                    <form method="POST" class="max-w-4xl mx-auto">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="process">
                        <input type="hidden" name="table" value="<?= htmlspecialchars($targetTable) ?>">
                        
                        <div class="mb-8 text-center">
                            <h3 class="text-lg font-bold text-gray-800">Faites correspondre vos colonnes</h3>
                            <p class="text-gray-500">Liez les colonnes de votre fichier CSV aux champs de la base de données.</p>
                        </div>

                        <div class="overflow-x-auto border border-gray-200 rounded-xl mb-8">
                            <table class="w-full text-left">
                                <thead class="bg-gray-50 text-gray-500 text-xs font-bold uppercase tracking-wider">
                                    <tr>
                                        <th class="px-6 py-4">Champ BDD</th>
                                        <th class="px-6 py-4">Colonne CSV correspondante</th>
                                        <th class="px-6 py-4">Exemple (1ère ligne)</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php 
                                    $cols = $tableColumns[$targetTable] ?? [];
                                    foreach ($cols as $idx => $dbCol): 
                                    ?>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="px-6 py-4 font-medium text-gray-800">
                                            <?= str_replace('_', ' ', ucfirst($dbCol)) ?>
                                            <span class="text-xs text-gray-400 block font-normal"><?= $dbCol ?></span>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-600">
                                            <select name="mapping[<?= $dbCol ?>]" class="w-full rounded-lg border-gray-300 py-1 text-sm bg-white">
                                                <option value="">-- Ignorer --</option>
                                                <?php foreach ($headers as $hIdx => $header): ?>
                                                    <option value="<?= $hIdx ?>" <?= strtolower(trim($header)) === strtolower($dbCol) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($header) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500 italic">
                                            <?php 
                                            static $secondRow = null;
                                            if ($secondRow === null && !empty($tempFile) && file_exists($tempFile)) {
                                                if (($handle = fopen($tempFile, "r")) !== FALSE) {
                                                    // Détection du séparateur
                                                    $line1 = fgets($handle);
                                                    $sep = (strpos($line1, ';') !== false) ? ';' : ',';
                                                    rewind($handle);
                                                    
                                                    fgetcsv($handle, 0, $sep); // skip header
                                                    $secondRow = fgetcsv($handle, 0, $sep);
                                                    fclose($handle);
                                                }
                                            }
                                            
                                            // Affichage de l'exemple si possible
                                            $valExemple = '-';
                                            if ($secondRow) {
                                                // Trouver l'index sélectionné ou auto-détecté
                                                $selectedIdx = -1;
                                                // On cherche d'abord si le header match
                                                foreach ($headers as $hIdx => $hText) {
                                                    if (strtolower(trim($hText)) === strtolower($dbCol)) {
                                                        $selectedIdx = $hIdx;
                                                        break;
                                                    }
                                                }
                                                
                                                if ($selectedIdx !== -1 && isset($secondRow[$selectedIdx])) {
                                                    $valExemple = $secondRow[$selectedIdx];
                                                }
                                            }
                                            echo htmlspecialchars(strlen($valExemple) > 30 ? substr($valExemple, 0, 27) . '...' : $valExemple);
                                            ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="flex justify-between items-center gap-4">
                            <button type="button" onclick="window.location.reload()" class="btn-outline px-8 py-3">
                                Annuler
                            </button>
                            <button type="submit" class="btn-success flex-1 py-3">
                                <i class="fas fa-check-circle"></i> Lancer l'importation
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Aide -->
        <div class="mt-8 bg-amber-50 rounded-xl p-6 border border-amber-100">
            <h4 class="font-bold text-amber-800 mb-3"><i class="fas fa-question-circle mr-1"></i> Besoin d'aide pour l'import ?</h4>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-sm text-amber-900/80">
                <div>
                    <h5 class="font-bold mb-1">1. Format du fichier</h5>
                    <p>Utilisez des fichiers CSV (.csv) enregistrés en UTF-8 pour éviter les problèmes d'accents.</p>
                </div>
                <div>
                    <h5 class="font-bold mb-1">2. Types de colonnes</h5>
                    <p>Les dates doivent préférablement être au format AAAA-MM-JJ. Les montants doivent utiliser le point (.) comme séparateur décimal.</p>
                </div>
                <div>
                    <h5 class="font-bold mb-1">3. Clés étrangères</h5>
                    <p>Pour l'import d'achats ou d'impôts, la colonne 'client_id' doit correspondre à l'ID numérique du client déjà existant dans le système.</p>
                </div>
            </div>
        </div>
<script>
    // Affichage du nom du fichier sélectionné
    const fileInput = document.getElementById('csv_file');
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            const fileName = document.getElementById('file-name');
            if (this.files.length > 0) {
                fileName.textContent = 'Fichier sélectionné : ' + this.files[0].name;
                fileName.classList.remove('hidden');
            }
        });
    }
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>

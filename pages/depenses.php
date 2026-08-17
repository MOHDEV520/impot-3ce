<?php
/**
 * ============================================
 * GESTION DES DÉPENSES
 * Système de Gestion Fiscale
 * ============================================
 */

session_start();
define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/classes/Agent.php';
require_once APP_ROOT . '/classes/Client.php';

// Vérifier l'authentification
if (!Agent::estConnecte() || !Agent::verifierTimeout()) {
    header('Location: ../index.php');
    exit;
}

$agent = Agent::getAgentConnecte();
$db = Database::getInstance();

// Paramètres
$clientId = isset($_GET['client']) ? (int)$_GET['client'] : 0;
$mois = isset($_GET['mois']) ? (int)$_GET['mois'] : (int)date('n');
$annee = isset($_GET['annee']) ? (int)$_GET['annee'] : (int)date('Y');

// Vérifier que le client existe
$client = $db->fetchOne("SELECT * FROM clients WHERE id = ?", [$clientId]);
if (!$client) {
    header('Location: clients.php');
    exit;
}
if (!$agent->aAccesClient($clientId)) {
    header('Location: clients.php?msg=' . urlencode('Accès non autorisé') . '&type=error');
    exit;
}

$moisNoms = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

// Récupérer les natures de dépenses
$naturesDepenses = $db->fetchAll("SELECT * FROM natures_depenses ORDER BY ordre_affichage");

// Traitement des formulaires
$message = '';
$messageType = '';
$csrfToken = Agent::getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!Agent::verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = "Session invalide, veuillez reessayer.";
        $messageType = "error";
        $action = '';
    }
    
    if ($action === 'ajouter') {
        $natureId = (int)($_POST['nature_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $montant = floatval($_POST['montant'] ?? 0);
        
        if ($natureId > 0 && $montant > 0) {
            try {
                // Essayer d'abord avec compte_gestion_id
                try {
                    // Récupérer ou créer le compte de gestion
                    $compteGestion = $db->fetchOne(
                        "SELECT id FROM compte_gestion_mensuel WHERE client_id = ? AND mois = ? AND annee = ?",
                        [$clientId, $mois, $annee]
                    );
                    
                    if (!$compteGestion) {
                        $db->query(
                            "INSERT INTO compte_gestion_mensuel (client_id, mois, annee) VALUES (?, ?, ?)",
                            [$clientId, $mois, $annee]
                        );
                        $compteGestionId = $db->getConnection()->lastInsertId();
                    } else {
                        $compteGestionId = $compteGestion['id'];
                    }
                    
                    $db->query(
                        "INSERT INTO depenses (client_id, compte_gestion_id, nature_id, mois, annee, montant, description, saisi_par) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        [$clientId, $compteGestionId, $natureId, $mois, $annee, $montant, $description, $agent->getId()]
                    );
                } catch (Exception $e1) {
                    // Fallback compatible SQLite + MySQL : s'assurer que le compte de gestion existe
                    $cg = $db->fetchOne(
                        "SELECT id FROM compte_gestion_mensuel WHERE client_id = ? AND mois = ? AND annee = ?",
                        [$clientId, $mois, $annee]
                    );
                    if (!$cg) {
                        $db->query(
                            "INSERT INTO compte_gestion_mensuel (client_id, mois, annee) VALUES (?, ?, ?)",
                            [$clientId, $mois, $annee]
                        );
                        $cgId = $db->getConnection()->lastInsertId();
                    } else {
                        $cgId = $cg['id'];
                    }
                    $db->query(
                        "INSERT INTO depenses (client_id, compte_gestion_id, nature_id, mois, annee, montant, description, saisi_par) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        [$clientId, $cgId, $natureId, $mois, $annee, $montant, $description, $agent->getId()]
                    );
                }
                $message = "Dépense ajoutée avec succès.";
                $messageType = "success";
            } catch (Exception $e) {
                $message = messageErreurUtilisateur($e, "l'ajout de cette dépense");
                $messageType = "error";
            }
        } else {
            $message = "Veuillez sélectionner une nature et saisir un montant.";
            $messageType = "error";
        }
    }
    
    if ($action === 'modifier') {
        $depenseId = (int)($_POST['depense_id'] ?? 0);
        $natureId = (int)($_POST['nature_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $montant = floatval($_POST['montant'] ?? 0);
        
        if ($depenseId > 0 && $natureId > 0) {
            try {
                $db->query(
                    "UPDATE depenses SET nature_id = ?, montant = ?, description = ? WHERE id = ?",
                    [$natureId, $montant, $description, $depenseId]
                );
                $message = "Dépense modifiée avec succès.";
                $messageType = "success";
            } catch (Exception $e) {
                $message = messageErreurUtilisateur($e, "la modification de cette dépense");
                $messageType = "error";
            }
        }
    }

    if ($action === 'supprimer') {
        $depenseId = (int)($_POST['depense_id'] ?? 0);
        if ($depenseId > 0) {
            try {
                $db->query("DELETE FROM depenses WHERE id = ?", [$depenseId]);
                $message = "Dépense supprimée avec succès.";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Erreur lors de la suppression.";
                $messageType = "error";
            }
        }
    }
    
    // Créer une nouvelle nature de dépense
    if ($action === 'creer_nature') {
        $natureLibelle = trim($_POST['nature_libelle'] ?? '');
        $natureCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $natureLibelle));
        
        if (!empty($natureLibelle)) {
            try {
                // Vérifier si la nature existe déjà
                $existante = $db->fetchOne("SELECT id FROM natures_depenses WHERE libelle = ?", [$natureLibelle]);
                
                if ($existante) {
                    $message = "Cette nature de dépense existe déjà.";
                    $messageType = "error";
                } else {
                    // Récupérer le dernier ordre d'affichage
                    $dernierOrdre = $db->fetchOne("SELECT MAX(ordre_affichage) as max_ordre FROM natures_depenses");
                    $nouvelOrdre = ($dernierOrdre['max_ordre'] ?? 0) + 1;
                    
                    $db->query(
                        "INSERT INTO natures_depenses (code, libelle, deductible, ordre_affichage) VALUES (?, ?, 1, ?)",
                        [$natureCode, $natureLibelle, $nouvelOrdre]
                    );
                    $message = "Nature de dépense \"" . htmlspecialchars($natureLibelle) . "\" créée avec succès.";
                    $messageType = "success";
                    
                    // Recharger les natures
                    $naturesDepenses = $db->fetchAll("SELECT * FROM natures_depenses ORDER BY ordre_affichage");
                }
            } catch (Exception $e) {
                $message = messageErreurUtilisateur($e, "la création de cette nature de dépense");
                $messageType = "error";
            }
        } else {
            $message = "Veuillez saisir un nom pour la nature de dépense.";
            $messageType = "error";
        }
    }
}

// Récupérer les dépenses avec leurs natures
$depenses = $db->fetchAll(
    "SELECT d.*, nd.libelle as nature_libelle, nd.code as nature_code 
     FROM depenses d 
     LEFT JOIN natures_depenses nd ON d.nature_id = nd.id 
     WHERE d.client_id = ? AND d.mois = ? AND d.annee = ? 
     ORDER BY d.id DESC",
    [$clientId, $mois, $annee]
);

// Calculer le total
$totalDepenses = 0;

foreach ($depenses as $depense) {
    $totalDepenses += $depense['montant'] ?? 0;
}

$pageTitle = "Gestion des Dépenses - " . htmlspecialchars($client['nom']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=1.2">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css?v=1.2">
</head>
<body class="bg-slate-100 min-h-screen">
    <?php include APP_ROOT . '/includes/navbar-impots.php'; ?>

    <main class="max-w-7xl mx-auto px-4 py-2">
        <!-- Messages -->
        <?php if ($message): ?>
        <div class="mb-4 p-4 rounded-lg border-l-4 <?= $messageType === 'success' ? 'bg-green-50 border-green-500 text-green-700' : 'bg-red-50 border-red-500 text-red-700' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <!-- Titre et boutons -->
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-xl font-bold text-slate-800">Gestion des Dépenses</h1>
            <div class="flex space-x-3">
                <button onclick="ouvrirModal()" class="btn-primary">
                    <i class="fas fa-plus"></i> Ajouter Dépense
                </button>
                <button onclick="ouvrirModalNature()" class="btn-success">
                    <i class="fas fa-folder-plus"></i> Nouvelle Nature
                </button>
            </div>
        </div>

        <!-- Tableau des dépenses -->
        <div class="card overflow-hidden p-0">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th class="px-6 py-3 text-left text-sm font-medium text-slate-600">Nature</th>
                        <th class="px-4 py-3 text-left text-sm font-medium text-slate-600">Description</th>
                        <th class="px-4 py-3 text-left text-sm font-medium text-slate-600">Date</th>
                        <th class="px-4 py-3 text-right text-sm font-medium text-slate-600">Montant</th>
                        <th class="px-4 py-3 text-center text-sm font-medium text-slate-600">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($depenses)): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center text-slate-500">
                            <i class="fas fa-receipt text-4xl text-slate-300 mb-3"></i>
                            <p>Aucune dépense enregistrée pour ce mois.</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($depenses as $depense): 
                        $dateAffichee = $depense['date_saisie'] ?? date('Y-m-d');
                    ?>
                    <tr>
                        <td class="px-6 py-4">
                            <span class="text-primary-600 font-medium"><?= htmlspecialchars($depense['nature_libelle'] ?? 'N/A') ?></span>
                        </td>
                        <td class="px-4 py-4 text-slate-600">
                            <?= htmlspecialchars($depense['description'] ?? '-') ?>
                        </td>
                        <td class="px-4 py-4 text-slate-600">
                            <?= date('d/m/Y', strtotime($dateAffichee)) ?>
                        </td>
                        <td class="px-4 py-4 text-right font-bold text-primary-700">
                            <?= number_format($depense['montant'] ?? 0, 0, ',', ' ') ?> F CFA
                        </td>
                        <td class="px-4 py-4">
                            <div class="flex justify-center space-x-2">
                                <button type="button"
                                        data-depense="<?= htmlspecialchars(json_encode($depense, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>"
                                        onclick="ouvrirModificationFromBtn(this)"
                                        class="btn-primary px-3 py-1.5 text-sm">
                                    <i class="fas fa-edit"></i> Modifier
                                </button>
                                <form method="POST" class="inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cette dépense ?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="supprimer">
                                    <input type="hidden" name="depense_id" value="<?= $depense['id'] ?>">
                                    <button type="submit" class="btn-danger px-3 py-1.5 text-sm">
                                        <i class="fas fa-trash"></i> Supprimer
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($depenses)): ?>
                <tfoot class="bg-slate-100 border-t-2 border-slate-300">
                    <tr>
                        <td colspan="3" class="px-6 py-4 font-bold text-slate-800">Total</td>
                        <td class="px-4 py-4 text-right font-bold text-primary-800 text-lg">
                            <?= number_format($totalDepenses, 0, ',', ' ') ?> F CFA
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <!-- Lien retour -->
        <div class="mt-6 text-center">
            <a href="clients.php" class="inline-flex items-center text-slate-600 hover:text-primary-600">
                <i class="fas fa-chevron-left mr-2"></i> Voir tous les clients
            </a>
        </div>
    </main>

    <!-- Modal Ajouter/Modifier Dépense -->
    <div id="modalDepense" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
        <!-- Overlay -->
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm"></div>
        
        <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col">
            <div class="px-6 py-4 border-b flex items-center justify-between bg-slate-50 rounded-t-xl shrink-0">
                <h3 id="modalTitre" class="text-lg font-bold text-slate-800">Ajouter une dépense</h3>
                <button onclick="fermerModal()" class="text-slate-400 hover:text-slate-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <form method="POST" class="flex flex-col overflow-hidden">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" id="formAction" value="ajouter">
                <input type="hidden" name="depense_id" id="depenseId" value="">
                
                <div class="p-6 overflow-y-auto space-y-4">
                    <!-- Nature de dépense -->
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Nature de la dépense *</label>
                        <select name="nature_id" id="inputNature" required
                                class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                            <option value="">-- Sélectionnez une nature --</option>
                            <?php foreach ($naturesDepenses as $nature): ?>
                            <option value="<?= $nature['id'] ?>"><?= htmlspecialchars($nature['libelle']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Description -->
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
                        <input type="text" name="description" id="inputDescription"
                               class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                               placeholder="Description optionnelle">
                    </div>
                    
                    <!-- Montant -->
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Montant *</label>
                        <input type="number" name="montant" id="inputMontant" required min="0" step="1"
                               class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                               placeholder="0">
                        <p class="text-xs text-slate-500 mt-1">Montant en F CFA</p>
                    </div>
                </div>
                
                <div class="px-6 py-4 bg-slate-50 border-t flex justify-end space-x-3 shrink-0 rounded-b-xl">
                    <button type="button" onclick="fermerModal()" class="btn-outline">
                        Annuler
                    </button>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Créer Nature de Dépense -->
    <div id="modalNature" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
        <!-- Overlay -->
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm"></div>
        
        <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-md">
            <div class="px-6 py-4 border-b flex items-center justify-between bg-green-700 rounded-t-xl">
                <h3 class="text-lg font-bold text-white">
                    <i class="fas fa-folder-plus mr-2"></i> Créer une nature de dépense
                </h3>
                <button onclick="fermerModalNature()" class="text-white hover:text-green-200">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <form method="POST" class="p-6">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="creer_nature">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Nom de la nature *</label>
                        <input type="text" name="nature_libelle" id="inputNatureLibelle" required
                               class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                               placeholder="Ex: Carburant, Frais bancaires...">
                        <p class="text-xs text-slate-500 mt-1">Ce nom apparaîtra dans la liste des natures de dépenses</p>
                    </div>
                    
                    <!-- Liste des natures existantes -->
                    <div class="bg-slate-50 p-3 rounded-lg">
                        <p class="text-sm font-medium text-slate-700 mb-2">Natures existantes :</p>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($naturesDepenses as $nature): ?>
                            <span class="inline-block px-2 py-1 bg-slate-200 text-slate-600 text-xs rounded">
                                <?= htmlspecialchars($nature['libelle']) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end space-x-3">
                    <button type="button" onclick="fermerModalNature()" class="btn-outline">
                        Annuler
                    </button>
                    <button type="submit" class="btn-success">
                        <i class="fas fa-plus"></i> Créer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function ouvrirModal() {
            const getEl = (id) => document.getElementById(id);
            
            if (getEl('modalTitre')) getEl('modalTitre').textContent = 'Ajouter une dépense';
            if (getEl('formAction')) getEl('formAction').value = 'ajouter';
            if (getEl('depenseId')) getEl('depenseId').value = '';
            if (getEl('inputNature')) getEl('inputNature').value = '';
            if (getEl('inputDescription')) getEl('inputDescription').value = '';
            if (getEl('inputMontant')) getEl('inputMontant').value = '';
            
            const modal = getEl('modalDepense');
            if (modal) {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }
        }
        
        function ouvrirModificationFromBtn(btn) {
            try {
                ouvrirModification(JSON.parse(btn.dataset.depense));
            } catch (e) {
                alert("Impossible d'ouvrir cette dépense (données illisibles). Détail : " + e.message);
            }
        }

        function ouvrirModification(depense) {
            const getEl = (id) => document.getElementById(id);

            if (getEl('modalTitre')) getEl('modalTitre').textContent = 'Modifier la dépense';
            if (getEl('formAction')) getEl('formAction').value = 'modifier';
            if (getEl('depenseId')) getEl('depenseId').value = depense.id;
            if (getEl('inputNature')) getEl('inputNature').value = depense.nature_id || '';
            if (getEl('inputDescription')) getEl('inputDescription').value = depense.description || '';
            if (getEl('inputMontant')) getEl('inputMontant').value = depense.montant || 0;
            
            const modal = getEl('modalDepense');
            if (modal) {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }
        }
        
        function fermerModal() {
            const modal = document.getElementById('modalDepense');
            if (modal) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }
        }
        
        function ouvrirModalNature() {
            const getEl = (id) => document.getElementById(id);
            if (getEl('inputNatureLibelle')) getEl('inputNatureLibelle').value = '';
            
            const modal = getEl('modalNature');
            if (modal) {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }
        }
        
        function fermerModalNature() {
            const modal = document.getElementById('modalNature');
            if (modal) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }
        }
        
        // Navigation par mois
        function changerMois(m) {
            const url = new URL(window.location.href);
            url.searchParams.set('mois', m);
            window.location.href = url.toString();
        }
        
        function changerAnnee(a) {
            const url = new URL(window.location.href);
            url.searchParams.set('annee', a);
            window.location.href = url.toString();
        }
    </script>
</body>
</html>

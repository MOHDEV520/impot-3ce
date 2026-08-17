<?php
/**
 * ============================================
 * GESTION DES ACHATS FOURNISSEURS
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

// Saisie de masse : si l'agent a cliqué « Enregistrer et ajouter un autre »,
// on rouvre le modal (pré-focus Fournisseur) plutôt que de fermer après chaque achat.
$reouvrirModalApres = false;
$typeDocumentReouverture = 'facture';

// Paramètres
$clientId = isset($_GET['client']) ? (int)$_GET['client'] : 0;
$mois = isset($_GET['mois']) ? (int)$_GET['mois'] : (int)date('n');
$annee = isset($_GET['annee']) ? (int)$_GET['annee'] : (int)date('Y');
$fournisseurFiltre = isset($_GET['fournisseur']) ? (int)$_GET['fournisseur'] : 0;

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
        $fournisseurNom = trim($_POST['fournisseur_nom'] ?? '');
        $fournisseurNif = trim($_POST['fournisseur_nif'] ?? '');
        $document = trim($_POST['document'] ?? '');
        $typeDocument = $_POST['type_document'] ?? 'facture';
        $dateAchat = $_POST['date_achat'] ?? date('Y-m-d');
        $montantHT = floatval($_POST['montant_ht'] ?? 0);
        $montantTVA = floatval($_POST['montant_tva'] ?? 0);
        $montantTTC = $montantHT + $montantTVA;
        $exclureCA = isset($_POST['exclure_ca']) ? 1 : 0;
        
        if (!empty($fournisseurNom) && $montantHT > 0) {
            try {
                // Vérifier ou créer le fournisseur avec NIF et Adresse
                $fournisseurAdresse = trim($_POST['fournisseur_adresse'] ?? '');
                $fournisseurIfu = trim($_POST['fournisseur_nif'] ?? '');
                
                $fournisseur = $db->fetchOne("SELECT id FROM fournisseurs WHERE nom = ?", [$fournisseurNom]);
                $nouveauFournisseur = false;
                
                if (!$fournisseur) {
                    // Créer le nouveau fournisseur avec nom, adresse et NIF
                    $db->query(
                        "INSERT INTO fournisseurs (nom, adresse, ifu) VALUES (?, ?, ?)",
                        [$fournisseurNom, $fournisseurAdresse, $fournisseurIfu]
                    );
                    $fournisseurId = $db->getConnection()->lastInsertId();
                    $nouveauFournisseur = true;
                } else {
                    $fournisseurId = $fournisseur['id'];
                    // Mettre à jour l'adresse et le NIF si fournis
                    if (!empty($fournisseurAdresse) || !empty($fournisseurIfu)) {
                        $db->query(
                            "UPDATE fournisseurs SET adresse = COALESCE(NULLIF(?, ''), adresse), ifu = COALESCE(NULLIF(?, ''), ifu) WHERE id = ?",
                            [$fournisseurAdresse, $fournisseurIfu, $fournisseurId]
                        );
                    }
                }
                
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
                
                // Ajouter l'achat avec les bonnes colonnes
                $natureOperation = ($_POST['nature_operation'] ?? 'achat') === 'service' ? 'service' : 'achat';
                $db->query(
                    "INSERT INTO achats (client_id, fournisseur_id, compte_gestion_id, mois, annee, type_document, reference_document, date_document, montant_ht, montant_tva, montant_ttc, tva_deductible, nature_operation, exclure_ca) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$clientId, $fournisseurId, $compteGestionId, $mois, $annee, $typeDocument, $document, $dateAchat, $montantHT, $montantTVA, $montantTTC, 1, $natureOperation, $exclureCA]
                );
                
                if ($nouveauFournisseur) {
                    $message = "Achat ajouté avec succès. Nouveau fournisseur \"" . htmlspecialchars($fournisseurNom) . "\" créé et sauvegardé.";
                } else {
                    $message = "Achat ajouté avec succès.";
                }
                $messageType = "success";

                if (($_POST['continuer'] ?? '0') === '1') {
                    $reouvrirModalApres = true;
                    $typeDocumentReouverture = $typeDocument;
                }
            } catch (Exception $e) {
                $message = messageErreurUtilisateur($e, "l'ajout de cet achat");
                $messageType = "error";
            }
        } else {
            $message = "Veuillez remplir tous les champs obligatoires.";
            $messageType = "error";
        }
    }
    
    if ($action === 'modifier') {
        $achatId = (int)($_POST['achat_id'] ?? 0);
        $document = trim($_POST['document'] ?? '');
        $dateAchat = $_POST['date_achat'] ?? date('Y-m-d');
        $montantHT = floatval($_POST['montant_ht'] ?? 0);
        $montantTVA = floatval($_POST['montant_tva'] ?? 0);
        $montantTTC = $montantHT + $montantTVA;
        $exclureCA = isset($_POST['exclure_ca']) ? 1 : 0;
        
        if ($achatId > 0) {
            try {
                $natureOperation = ($_POST['nature_operation'] ?? 'achat') === 'service' ? 'service' : 'achat';
                $db->query(
                    "UPDATE achats SET reference_document = ?, date_document = ?, montant_ht = ?, montant_tva = ?, montant_ttc = ?, nature_operation = ?, exclure_ca = ? WHERE id = ?",
                    [$document, $dateAchat, $montantHT, $montantTVA, $montantTTC, $natureOperation, $exclureCA, $achatId]
                );
                $message = "Achat modifié avec succès.";
                $messageType = "success";
            } catch (Exception $e) {
                $message = messageErreurUtilisateur($e, "la modification de cet achat");
                $messageType = "error";
            }
        }
    }
    
    if ($action === 'supprimer') {
        $achatId = (int)($_POST['achat_id'] ?? 0);
        if ($achatId > 0) {
            try {
                $db->query("DELETE FROM achats WHERE id = ?", [$achatId]);
                $message = "Achat supprimé avec succès.";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Erreur lors de la suppression.";
                $messageType = "error";
            }
        }
    }
}

// Récupérer les fournisseurs pour le filtre (liés à ce client)
$fournisseurs = $db->fetchAll(
    "SELECT DISTINCT f.id, f.nom FROM fournisseurs f 
     INNER JOIN achats a ON a.fournisseur_id = f.id 
     WHERE a.client_id = ? ORDER BY f.nom",
    [$clientId]
);

// Récupérer TOUS les fournisseurs existants avec leurs infos pour l'autocomplétion
$tousLesFournisseurs = $db->fetchAll(
    "SELECT id, nom, adresse, ifu FROM fournisseurs ORDER BY nom"
);

// Récupérer les achats
$sqlAchats = "SELECT a.id, a.client_id, a.fournisseur_id, a.mois, a.annee, 
                     a.montant_ht, a.montant_tva, a.montant_ttc, a.exclure_ca,
                     a.type_document, a.date_document,
                     COALESCE(a.reference_document, a.type_document) as document,
                     COALESCE(a.nature_operation, 'achat') as nature_operation,
                     f.nom as fournisseur_nom,
                     f.ifu as fournisseur_nif,
                     f.adresse as fournisseur_adresse
              FROM achats a 
              LEFT JOIN fournisseurs f ON a.fournisseur_id = f.id 
              WHERE a.client_id = ? AND a.mois = ? AND a.annee = ?";
$paramsAchats = [$clientId, $mois, $annee];

if ($fournisseurFiltre > 0) {
    $sqlAchats .= " AND a.fournisseur_id = ?";
    $paramsAchats[] = $fournisseurFiltre;
}

$sqlAchats .= " ORDER BY a.id DESC";
$achats = $db->fetchAll($sqlAchats, $paramsAchats);

// Calculer les totaux
$totalHT = 0;
$totalTVA = 0;
$totalTTC = 0;

foreach ($achats as $achat) {
    $totalHT += $achat['montant_ht'] ?? 0;
    $totalTVA += $achat['montant_tva'] ?? 0;
    $totalTTC += $achat['montant_ttc'] ?? ($achat['montant_ht'] + $achat['montant_tva']);
}

$pageTitle = "Achats Fournisseurs - " . htmlspecialchars($client['nom']);
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

        <!-- Filtres et boutons -->
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center space-x-4">
                <span class="text-sm text-slate-600">Afficher :</span>
                <select onchange="filtrerFournisseur(this.value)" 
                        class="px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500">
                    <option value="0">Fournisseur: Tous</option>
                    <?php foreach ($fournisseurs as $f): ?>
                    <option value="<?= $f['id'] ?>" <?= $fournisseurFiltre == $f['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($f['nom']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex space-x-3">
                <button onclick="ouvrirModal('releve')" class="btn-secondary">
                    <i class="fas fa-plus"></i> Ajouter Relevé
                </button>
                <button onclick="ouvrirModal('facture')" class="btn-primary">
                    <i class="fas fa-plus"></i> Ajouter Facture
                </button>
            </div>
        </div>

        <!-- Tableau des achats -->
        <div class="card overflow-hidden p-0">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th class="px-6 py-3 text-left text-sm font-medium text-slate-600">Fournisseur</th>
                        <th class="px-4 py-3 text-left text-sm font-medium text-slate-600">NIF</th>
                        <th class="px-4 py-3 text-left text-sm font-medium text-slate-600">Document</th>
                        <th class="px-4 py-3 text-right text-sm font-medium text-slate-600">HT</th>
                        <th class="px-4 py-3 text-right text-sm font-medium text-slate-600">TVA</th>
                        <th class="px-4 py-3 text-right text-sm font-medium text-slate-600">TTC</th>
                        <th class="px-4 py-3 text-center text-sm font-medium text-slate-600">Historique</th>
                        <th class="px-4 py-3 text-center text-sm font-medium text-slate-600">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($achats)): ?>
                    <tr>
                        <td colspan="8" class="px-6 py-12 text-center text-slate-500">
                            <i class="fas fa-shopping-cart text-4xl text-slate-300 mb-3"></i>
                            <p>Aucun achat enregistré pour ce mois.</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($achats as $achat): 
                        $ht = $achat['montant_ht'] ?? 0;
                        $tva = $achat['montant_tva'] ?? 0;
                        $ttc = $achat['montant_ttc'] ?? ($ht + $tva);
                    ?>
                    <tr>
                        <td class="px-6 py-4">
                            <div class="text-primary-600 font-medium"><?= htmlspecialchars($achat['fournisseur_nom'] ?? 'N/A') ?></div>
                            <?php if (!empty($achat['fournisseur_adresse'])): ?>
                            <div class="text-xs text-slate-400"><?= htmlspecialchars($achat['fournisseur_adresse']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-4 text-slate-600 font-mono text-sm">
                            <?= htmlspecialchars($achat['fournisseur_nif'] ?? '-') ?>
                        </td>
                        <td class="px-4 py-4 text-slate-600">
                            <?= htmlspecialchars($achat['document'] ?? '-') ?>
                            <?php if (isset($achat['exclure_ca']) && $achat['exclure_ca'] == 1): ?>
                            <div class="mt-1">
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-700 uppercase tracking-wider">
                                    <i class="fas fa-exclamation-triangle mr-1"></i> EXCLU CA
                                </span>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-4 text-right font-medium text-primary-700">
                            <?= number_format($ht, 0, ',', ' ') ?> F CFA
                        </td>
                        <td class="px-4 py-4 text-right text-slate-600">
                            <?= number_format($tva, 0, ',', ' ') ?> F CFA
                        </td>
                        <td class="px-4 py-4 text-right font-bold text-slate-800">
                            <?= number_format($ttc, 0, ',', ' ') ?> F CFA
                        </td>
                        <td class="px-4 py-4 text-center">
                            <a href="historique-achats.php?client=<?= $clientId ?>&fournisseur=<?= $achat['fournisseur_id'] ?? 0 ?>"
                               class="btn-outline px-3 py-1.5 text-sm">
                                <i class="fas fa-folder-open"></i> Afficher
                            </a>
                        </td>
                        <td class="px-4 py-4">
                            <div class="flex justify-center space-x-2">
                                <button type="button"
                                        data-achat="<?= htmlspecialchars(json_encode($achat, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>"
                                        onclick="ouvrirModificationFromBtn(this)"
                                        class="btn-primary px-3 py-1.5 text-sm">
                                    <i class="fas fa-edit"></i> Modifier
                                </button>
                                <form method="POST" class="inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cet achat ?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="supprimer">
                                    <input type="hidden" name="achat_id" value="<?= $achat['id'] ?>">
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
                <?php if (!empty($achats)): ?>
                <tfoot class="bg-slate-100 border-t-2 border-slate-300">
                    <tr>
                        <td colspan="3" class="px-6 py-4 font-bold text-slate-800">Total</td>
                        <td class="px-4 py-4 text-right font-bold text-primary-700">
                            <?= number_format($totalHT, 0, ',', ' ') ?> F CFA
                        </td>
                        <td class="px-4 py-4 text-right font-bold text-slate-600">
                            <?= number_format($totalTVA, 0, ',', ' ') ?> F CFA
                        </td>
                        <td class="px-4 py-4 text-right font-bold text-primary-800 text-lg">
                            <?= number_format($totalTTC, 0, ',', ' ') ?> F CFA
                        </td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <!-- Liens -->
        <div class="mt-6 flex justify-center space-x-8">
            <a href="clients.php" class="inline-flex items-center text-slate-600 hover:text-primary-600">
                <i class="fas fa-chevron-left mr-2"></i> Voir tous les clients
            </a>
            <a href="historique-achats.php?client=<?= $clientId ?>&mois_nav=<?= $mois ?>&annee_nav=<?= $annee ?>" 
               class="inline-flex items-center text-primary-600 hover:text-primary-800 font-medium">
                <i class="fas fa-history mr-2"></i> Voir tout l'historique
            </a>
        </div>
    </main>

    <!-- Modal Ajouter/Modifier Achat -->
    <div id="modalAchat" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
        <!-- Overlay -->
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm"></div>
        
        <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col">
            <div class="px-6 py-4 border-b flex items-center justify-between bg-slate-50 rounded-t-xl shrink-0">
                <h3 id="modalTitre" class="text-lg font-bold text-slate-800">Ajouter un achat</h3>
                <button onclick="fermerModal()" class="text-slate-400 hover:text-slate-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <form method="POST" class="flex flex-col overflow-hidden">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" id="formAction" value="ajouter">
                <input type="hidden" name="achat_id" id="achatId" value="">
                <input type="hidden" name="type_document" id="inputTypeDocument" value="facture">
                
                <div class="p-6 overflow-y-auto space-y-4">
                    <!-- Fournisseur -->
                    <div id="sectionFournisseur">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Fournisseur *</label>
                        <input type="text" name="fournisseur_nom" id="inputFournisseur" 
                               class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                               placeholder="Nom du fournisseur (tapez pour rechercher ou créer)" 
                               list="listeFournisseurs"
                               onchange="remplirInfoFournisseur(this.value)"
                               oninput="remplirInfoFournisseur(this.value)">
                        <datalist id="listeFournisseurs">
                            <?php foreach ($tousLesFournisseurs as $f): ?>
                            <option value="<?= htmlspecialchars($f['nom']) ?>" data-nif="<?= htmlspecialchars($f['ifu'] ?? '') ?>" data-adresse="<?= htmlspecialchars($f['adresse'] ?? '') ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- NIF Fournisseur -->
                        <div id="sectionNif">
                            <label class="block text-sm font-medium text-slate-700 mb-1">NIF Fournisseur</label>
                            <input type="text" name="fournisseur_nif" id="inputNif"
                                   class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                                   placeholder="Numéro NIF">
                        </div>
                        
                        <!-- Date -->
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Date</label>
                            <input type="date" name="date_achat" id="inputDate" 
                                   value="<?= date('Y-m-d') ?>"
                                   class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        </div>
                    </div>
                    
                    <!-- Adresse Fournisseur -->
                    <div id="sectionAdresse">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Adresse Fournisseur</label>
                        <input type="text" name="fournisseur_adresse" id="inputAdresse"
                               class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                               placeholder="Adresse du fournisseur">
                    </div>
                    
                    <!-- Document -->
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Document / Référence</label>
                        <input type="text" name="document" id="inputDocument"
                               class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                               placeholder="Ex: FACT-0012">
                    </div>
                    
                    <!-- Montants -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Montant HT *</label>
                            <input type="number" name="montant_ht" id="inputMontantHT" required min="0" step="1"
                                   onchange="calculerTTC()"
                                   class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                                   placeholder="0">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Montant TVA</label>
                            <input type="number" name="montant_tva" id="inputMontantTVA" min="0" step="1"
                                   onchange="calculerTTC()"
                                   class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                                   placeholder="0">
                        </div>
                    </div>

                    <!-- Nature Opération -->
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Nature de l'opération</label>
                        <div class="flex space-x-4">
                            <label class="inline-flex items-center cursor-pointer">
                                <input type="radio" name="nature_operation" id="natureAchat" value="achat" checked 
                                       class="w-4 h-4 text-primary-600 focus:ring-primary-500">
                                <span class="ml-2 text-sm text-slate-600">Achat</span>
                            </label>
                            <label class="inline-flex items-center cursor-pointer">
                                <input type="radio" name="nature_operation" id="natureService" value="service" 
                                       class="w-4 h-4 text-primary-600 focus:ring-primary-500">
                                <span class="ml-2 text-sm text-slate-600">Service</span>
                            </label>
                        </div>
                    </div>

                    <!-- Exclusion CA -->
                    <div class="flex items-center p-3 bg-amber-50 border border-amber-200 rounded-lg">
                        <input type="checkbox" name="exclure_ca" id="inputExclureCA" value="1"
                               class="w-5 h-5 text-amber-600 border-slate-300 rounded focus:ring-amber-500 cursor-pointer">
                        <label for="inputExclureCA" class="ml-2 block text-xs font-medium text-amber-800 cursor-pointer">
                            Exclure du calcul du Chiffre d'Affaires
                        </label>
                    </div>
                    
                    <!-- Total TTC -->
                    <div class="bg-slate-100 p-3 rounded-lg">
                        <div class="flex justify-between items-center">
                            <span class="text-sm font-medium text-slate-700">Total TTC</span>
                            <span id="totalTTC" class="text-lg font-bold text-primary-700">0 F CFA</span>
                        </div>
                    </div>
                </div>
                
                <div class="px-6 py-4 bg-slate-50 border-t flex justify-end space-x-3 shrink-0 rounded-b-xl">
                    <button type="button" onclick="fermerModal()" class="btn-outline">
                        Annuler
                    </button>
                    <button type="submit" name="continuer" value="1" id="btnEnregistrerContinuer" class="px-4 py-2 border border-primary-600 text-primary-700 font-bold rounded-lg hover:bg-primary-50 transition">
                        <i class="fas fa-save mr-2"></i> Enregistrer et ajouter un autre
                    </button>
                    <button type="submit" name="continuer" value="0" class="btn-primary">
                        <i class="fas fa-check"></i> Enregistrer et fermer
                    </button>
                </div>
            </form>
        </div>
        </div>
    </div>

    <script>
        function filtrerFournisseur(id) {
            const url = new URL(window.location.href);
            if (id > 0) {
                url.searchParams.set('fournisseur', id);
            } else {
                url.searchParams.delete('fournisseur');
            }
            window.location.href = url.toString();
        }
        
        // Données des fournisseurs existants pour l'autocomplétion
        <?php
        $fournisseursJS = [];
        foreach ($tousLesFournisseurs as $f) {
            $fournisseursJS[$f['nom']] = [
                'nif' => $f['ifu'] ?? '',
                'adresse' => $f['adresse'] ?? ''
            ];
        }
        ?>
        const fournisseursData = <?= json_encode($fournisseursJS, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        
        // Remplir automatiquement NIF et adresse quand on sélectionne un fournisseur existant
        function remplirInfoFournisseur(nomFournisseur) {
            const inputNif = document.getElementById('inputNif');
            const inputAdresse = document.getElementById('inputAdresse');
            const inputFournisseur = document.getElementById('inputFournisseur');

            if (fournisseursData[nomFournisseur]) {
                const info = fournisseursData[nomFournisseur];
                if (inputNif) inputNif.value = info.nif || '';
                if (inputAdresse) inputAdresse.value = info.adresse || '';
                
                // Indication visuelle que le fournisseur existe
                if (inputFournisseur) {
                    inputFournisseur.classList.add('border-green-500');
                    inputFournisseur.classList.remove('border-slate-300');
                }
            } else {
                // Nouveau fournisseur - réinitialiser le style
                if (inputFournisseur) {
                    inputFournisseur.classList.remove('border-green-500');
                    inputFournisseur.classList.add('border-slate-300');
                }
            }
        }
        
        function ouvrirModal(type = 'facture', focusFournisseur = false) {
            const getEl = (id) => document.getElementById(id);
            
            if (getEl('modalTitre')) getEl('modalTitre').textContent = type === 'releve' ? 'Ajouter un relevé' : 'Ajouter une facture';
            if (getEl('formAction')) getEl('formAction').value = 'ajouter';
            if (getEl('achatId')) getEl('achatId').value = '';
            if (getEl('inputTypeDocument')) getEl('inputTypeDocument').value = type;
            if (getEl('inputFournisseur')) {
                getEl('inputFournisseur').value = '';
                getEl('inputFournisseur').classList.remove('border-green-500');
                getEl('inputFournisseur').classList.add('border-slate-300');
            }
            if (getEl('inputNif')) getEl('inputNif').value = '';
            if (getEl('inputAdresse')) getEl('inputAdresse').value = '';
            if (getEl('inputDocument')) getEl('inputDocument').value = '';
            if (getEl('inputDate')) getEl('inputDate').value = '<?= date('Y-m-d') ?>';
            if (getEl('inputMontantHT')) getEl('inputMontantHT').value = '';
            if (getEl('inputMontantTVA')) getEl('inputMontantTVA').value = '';
            if (getEl('natureAchat')) getEl('natureAchat').checked = true;
            if (getEl('inputExclureCA')) getEl('inputExclureCA').checked = false;
            if (getEl('totalTTC')) getEl('totalTTC').textContent = '0 F CFA';
            
            if (getEl('sectionFournisseur')) getEl('sectionFournisseur').style.display = 'block';
            if (getEl('sectionNif')) getEl('sectionNif').style.display = 'block';
            if (getEl('sectionAdresse')) getEl('sectionAdresse').style.display = 'block';
            if (getEl('btnEnregistrerContinuer')) getEl('btnEnregistrerContinuer').style.display = 'inline-flex';

            const modal = getEl('modalAchat');
            if (modal) {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }

            if (focusFournisseur && getEl('inputFournisseur')) {
                getEl('inputFournisseur').focus();
            }
        }

        function ouvrirModificationFromBtn(btn) {
            try {
                const achat = JSON.parse(btn.dataset.achat);
                ouvrirModification(achat);
            } catch (e) {
                alert("Impossible d'ouvrir cet achat (données illisibles). Détail : " + e.message);
            }
        }

        function ouvrirModification(achat) {
            const getEl = (id) => document.getElementById(id);

            if (getEl('modalTitre')) getEl('modalTitre').textContent = 'Modifier l\'achat';
            if (getEl('formAction')) getEl('formAction').value = 'modifier';
            if (getEl('achatId')) getEl('achatId').value = achat.id;
            if (getEl('inputTypeDocument')) getEl('inputTypeDocument').value = achat.type_document || 'facture';
            if (getEl('inputDocument')) getEl('inputDocument').value = achat.document || '';
            if (getEl('inputDate')) getEl('inputDate').value = achat.date_document || '<?= date('Y-m-d') ?>';
            if (getEl('inputMontantHT')) getEl('inputMontantHT').value = achat.montant_ht || 0;
            if (getEl('inputMontantTVA')) getEl('inputMontantTVA').value = achat.montant_tva || 0;
            
            if (achat.nature_operation === 'service') {
                if (getEl('natureService')) getEl('natureService').checked = true;
            } else {
                if (getEl('natureAchat')) getEl('natureAchat').checked = true;
            }
            
            if (getEl('inputExclureCA')) getEl('inputExclureCA').checked = (parseInt(achat.exclure_ca) === 1);
            calculerTTC();

            // Masquer les sections fournisseur en modification (un seul achat à la fois)
            if (getEl('sectionFournisseur')) getEl('sectionFournisseur').style.display = 'none';
            if (getEl('sectionNif')) getEl('sectionNif').style.display = 'none';
            if (getEl('sectionAdresse')) getEl('sectionAdresse').style.display = 'none';
            if (getEl('btnEnregistrerContinuer')) getEl('btnEnregistrerContinuer').style.display = 'none';
            
            const modal = getEl('modalAchat');
            if (modal) {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }
        }
        
        function fermerModal() {
            const modal = document.getElementById('modalAchat');
            if (modal) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }
        }
        
        function calculerTTC() {
            const htEl = document.getElementById('inputMontantHT');
            const tvaEl = document.getElementById('inputMontantTVA');
            const ht = (htEl) ? parseFloat(htEl.value) || 0 : 0;
            const tva = (tvaEl) ? parseFloat(tvaEl.value) || 0 : 0;
            const ttc = ht + tva;
            const ttcEl = document.getElementById('totalTTC');
            if (ttcEl) ttcEl.textContent = ttc.toLocaleString('fr-FR') + ' F CFA';
        }

        <?php if ($reouvrirModalApres): ?>
        // Saisie de masse : l'achat précédent vient d'être enregistré, on rouvre
        // immédiatement le modal avec le focus sur Fournisseur pour le suivant.
        document.addEventListener('DOMContentLoaded', function () {
            ouvrirModal(<?= json_encode($typeDocumentReouverture) ?>, true);
        });
        <?php endif; ?>
    </script>
</body>
</html>

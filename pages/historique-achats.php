<?php
/**
 * ============================================
 * HISTORIQUE DES ACHATS
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
$moisFiltre = isset($_GET['mois']) && $_GET['mois'] !== '' ? (int)$_GET['mois'] : null;
$anneeFiltre = isset($_GET['annee']) && $_GET['annee'] !== '' ? (int)$_GET['annee'] : null;
$fournisseurFiltre = isset($_GET['fournisseur']) && $_GET['fournisseur'] !== '' ? (int)$_GET['fournisseur'] : null;

// Pour navigation - mois/année courant
$moisCourant = isset($_GET['mois_nav']) ? (int)$_GET['mois_nav'] : (int)date('n');
$anneeCourant = isset($_GET['annee_nav']) ? (int)$_GET['annee_nav'] : (int)date('Y');

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

// Fonction de formatage des montants
function formatMontant($montant) {
    return number_format($montant, 0, ',', ' ');
}

// Récupérer tous les fournisseurs pour le filtre
$fournisseurs = $db->fetchAll("SELECT id, nom FROM fournisseurs ORDER BY nom");

// Construire la requête SQL avec filtres
$sql = "SELECT a.id, a.client_id, a.fournisseur_id, a.mois, a.annee,
               a.montant_ht, a.montant_tva, a.montant_ttc,
               a.type_document, a.date_document,
               COALESCE(a.reference_document, a.type_document) as document,
               COALESCE(a.nature_operation, 'achat') as nature_operation,
               f.nom as fournisseur_nom,
               f.ifu as fournisseur_nif,
               f.adresse as fournisseur_adresse
        FROM achats a 
        LEFT JOIN fournisseurs f ON a.fournisseur_id = f.id 
        WHERE a.client_id = ?";
$params = [$clientId];

if ($moisFiltre !== null) {
    $sql .= " AND a.mois = ?";
    $params[] = $moisFiltre;
}

if ($anneeFiltre !== null) {
    $sql .= " AND a.annee = ?";
    $params[] = $anneeFiltre;
}

if ($fournisseurFiltre !== null) {
    $sql .= " AND a.fournisseur_id = ?";
    $params[] = $fournisseurFiltre;
}

$sql .= " ORDER BY a.annee DESC, a.mois DESC, a.date_document DESC, a.id DESC";

$achats = $db->fetchAll($sql, $params);

// Calculer les totaux
$totalHT = 0;
$totalTVA = 0;
$totalTTC = 0;

foreach ($achats as $achat) {
    $totalHT += $achat['montant_ht'] ?? 0;
    $totalTVA += $achat['montant_tva'] ?? 0;
    $totalTTC += $achat['montant_ttc'] ?? ($achat['montant_ht'] + $achat['montant_tva']);
}

// Récupérer les années disponibles pour le filtre
$anneesDisponibles = $db->fetchAll(
    "SELECT DISTINCT annee FROM achats WHERE client_id = ? ORDER BY annee DESC",
    [$clientId]
);

// Récupérer le nom du fournisseur filtré pour l'en-tête d'impression
$fournisseurFiltreNom = '';
$fournisseurFiltreNif = '';
$fournisseurFiltreAdresse = '';
if ($fournisseurFiltre !== null) {
    $fournisseurInfo = $db->fetchOne("SELECT nom, ifu, adresse FROM fournisseurs WHERE id = ?", [$fournisseurFiltre]);
    if ($fournisseurInfo) {
        $fournisseurFiltreNom = $fournisseurInfo['nom'] ?? '';
        $fournisseurFiltreNif = $fournisseurInfo['ifu'] ?? '';
        $fournisseurFiltreAdresse = $fournisseurInfo['adresse'] ?? '';
    }
}

// Construire le libellé de la période pour l'impression
$periodePrintLabel = 'Toutes périodes';
if ($moisFiltre !== null && $anneeFiltre !== null) {
    $periodePrintLabel = ($moisNoms[$moisFiltre] ?? '') . ' ' . $anneeFiltre;
} elseif ($anneeFiltre !== null) {
    $periodePrintLabel = 'Année ' . $anneeFiltre;
} elseif ($moisFiltre !== null) {
    $periodePrintLabel = ($moisNoms[$moisFiltre] ?? '') . ' (toutes années)';
}

$pageTitle = "Historique des Achats - " . htmlspecialchars($client['nom']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <style>
        .print-header { display: none; }

        @page { size: landscape; margin: 10mm; }

        @media print {
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            header, nav, .no-print, footer, .breadcrumb-bar { display: none !important; }
            body { background: white !important; font-size: 11px; margin: 0; padding: 0; }
            main { max-width: 100% !important; padding: 0 !important; margin: 0 !important; }
            .bg-white { box-shadow: none !important; }
            .print-header {
                display: block !important;
                border-bottom: 2px solid #1e40af;
                padding-bottom: 10px;
                margin-bottom: 15px;
            }
            .print-header h2 { font-size: 16px; font-weight: bold; color: #1e3a8a; margin: 0 0 4px 0; }
            .print-header .print-subtitle { font-size: 13px; color: #334155; margin: 2px 0; }
            .print-header .print-info { font-size: 11px; color: #64748b; }
            .print-header .print-info-grid { display: flex; justify-content: space-between; margin-top: 8px; }
            .print-header .print-info-block { flex: 1; }
            .print-header .print-info-block strong { color: #1e3a8a; }

            .summary-cards { display: none !important; }

            table { font-size: 10px; width: 100%; border-collapse: collapse; }
            table thead { background: #f1f5f9 !important; }
            table th { padding: 5px 6px !important; font-size: 9px; text-transform: uppercase; letter-spacing: 0.3px; border-bottom: 2px solid #cbd5e1; }
            table td { padding: 4px 6px !important; border-bottom: 1px solid #e2e8f0; }
            table tfoot td { border-top: 2px solid #334155 !important; font-weight: bold; padding: 6px !important; }

            .print-footer-info {
                display: block !important;
                margin-top: 20px;
                padding-top: 8px;
                border-top: 1px solid #cbd5e1;
                font-size: 9px;
                color: #94a3b8;
                text-align: center;
            }
        }
        .print-footer-info { display: none; }
    </style>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {"50":"#eff6ff","100":"#dbeafe","200":"#bfdbfe","300":"#93c5fd","400":"#60a5fa","500":"#3b82f6","600":"#2563eb","700":"#1d4ed8","800":"#1e40af","900":"#1e3a8a"}
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-slate-100 min-h-screen">
    <!-- Header -->
    <header class="bg-primary-800 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 py-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-2">
                    <i class="fas fa-building text-xl"></i>
                    <span class="font-bold text-lg">CABINET FISCAL</span>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-primary-200">
                        Bienvenue, <strong class="text-white"><?= htmlspecialchars($agent->getPrenom() . ' ' . $agent->getNom()) ?></strong> | <?= $agent->getRole() === 'admin' ? 'Administrateur' : 'Agent Comptable' ?>
                    </span>
                    <a href="logout.php" class="text-primary-200 hover:text-white">Déconnexion</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Breadcrumb -->
    <div class="bg-slate-200 border-b">
        <div class="max-w-7xl mx-auto px-4 py-2">
            <div class="flex items-center text-sm text-slate-600">
                <a href="dashboard.php" class="hover:text-primary-600">
                    <i class="fas fa-home mr-1"></i> CABINET FISCAL
                </a>
                <span class="mx-2">|</span>
                <span class="font-medium text-primary-600"><?= htmlspecialchars($client['nom']) ?></span>
                <span class="mx-2">|</span>
                <span>Historique des Achats</span>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <nav class="bg-white border-b shadow-sm">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex space-x-1">
                <a href="achats.php?client=<?= $clientId ?>&mois=<?= $moisCourant ?>&annee=<?= $anneeCourant ?>" 
                   class="px-6 py-3 text-sm font-medium text-slate-600 hover:text-primary-600 hover:bg-slate-50 border-b-2 border-transparent">
                    ACHATS
                </a>
                <a href="depenses.php?client=<?= $clientId ?>&mois=<?= $moisCourant ?>&annee=<?= $anneeCourant ?>" 
                   class="px-6 py-3 text-sm font-medium text-slate-600 hover:text-primary-600 hover:bg-slate-50 border-b-2 border-transparent">
                    DÉPENSES
                </a>
                <a href="impots.php?client=<?= $clientId ?>&mois=<?= $moisCourant ?>&annee=<?= $anneeCourant ?>" 
                   class="px-6 py-3 text-sm font-medium text-white bg-primary-600 border-b-2 border-primary-600">
                    IMPÔTS
                </a>
                <a href="recapitulatif.php?client=<?= $clientId ?>&mois=<?= $moisCourant ?>&annee=<?= $anneeCourant ?>" 
                   class="px-6 py-3 text-sm font-medium text-slate-600 hover:text-primary-600 hover:bg-slate-50 border-b-2 border-transparent">
                    RÉCAPITULATIF
                </a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-4 py-6">

        <!-- EN-TÊTE D'IMPRESSION (visible uniquement à l'impression) -->
        <div class="print-header">
            <h2>HISTORIQUE DES ACHATS FOURNISSEURS</h2>
            <p class="print-subtitle">Client : <?= htmlspecialchars($client['nom']) ?> <?php if (!empty($client['nif'])): ?> — NIF : <?= htmlspecialchars($client['nif']) ?><?php endif; ?></p>
            <div class="print-info-grid">
                <div class="print-info-block">
                    <strong>Période :</strong> <?= $periodePrintLabel ?>
                </div>
                <?php if ($fournisseurFiltreNom): ?>
                <div class="print-info-block" style="text-align:center;">
                    <strong>Fournisseur :</strong> <?= htmlspecialchars($fournisseurFiltreNom) ?>
                    <?php if ($fournisseurFiltreNif): ?> — NIF : <?= htmlspecialchars($fournisseurFiltreNif) ?><?php endif; ?>
                    <?php if ($fournisseurFiltreAdresse): ?><br><span class="print-info"><?= htmlspecialchars($fournisseurFiltreAdresse) ?></span><?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="print-info-block" style="text-align:right;">
                    <strong>Édité le :</strong> <?= date('d/m/Y à H:i') ?>
                </div>
            </div>
        </div>

        <!-- Titre -->
        <h1 class="text-2xl font-bold text-slate-800 mb-6 no-print">Historique des Achats</h1>

        <!-- Filtres -->
        <form method="GET" class="bg-white rounded-lg shadow-sm border p-4 mb-6 no-print">
            <input type="hidden" name="client" value="<?= $clientId ?>">
            <input type="hidden" name="mois_nav" value="<?= $moisCourant ?>">
            <input type="hidden" name="annee_nav" value="<?= $anneeCourant ?>">
            
            <div class="flex flex-wrap items-end gap-4">
                <!-- Filtre Mois -->
                <div class="flex-1 min-w-48">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Mois</label>
                    <select name="mois" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="">Toutes les périodes</option>
                        <?php foreach ($moisNoms as $num => $nom): ?>
                        <option value="<?= $num ?>" <?= $moisFiltre === $num ? 'selected' : '' ?>><?= $nom ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Filtre Année -->
                <div class="flex-1 min-w-32">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Année</label>
                    <select name="annee" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="">Toutes</option>
                        <?php foreach ($anneesDisponibles as $a): ?>
                        <option value="<?= $a['annee'] ?>" <?= $anneeFiltre === (int)$a['annee'] ? 'selected' : '' ?>><?= $a['annee'] ?></option>
                        <?php endforeach; ?>
                        <?php if (empty($anneesDisponibles)): ?>
                        <option value="<?= date('Y') ?>"><?= date('Y') ?></option>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- Filtre Fournisseur -->
                <div class="flex-1 min-w-48">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Fournisseur</label>
                    <select name="fournisseur" id="fournisseurSelect" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="">Tous</option>
                        <?php foreach ($fournisseurs as $f): ?>
                        <option value="<?= $f['id'] ?>" <?= $fournisseurFiltre === (int)$f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Boutons -->
                <div class="flex space-x-2">
                    <button type="submit" class="px-5 py-2 bg-slate-700 text-white font-medium rounded-lg hover:bg-slate-800 transition">
                        <i class="fas fa-filter mr-1"></i> Filtrer
                    </button>
                    <a href="historique-achats.php?client=<?= $clientId ?>&mois_nav=<?= $moisCourant ?>&annee_nav=<?= $anneeCourant ?>" 
                       class="px-4 py-2 bg-slate-200 text-slate-700 font-medium rounded-lg hover:bg-slate-300 transition text-sm flex items-center">
                        <i class="fas fa-times mr-1"></i> Réinitialiser
                    </a>
                </div>
            </div>
        </form>

        <!-- Résumé -->
        <?php if (!empty($achats)): ?>
        <div class="grid grid-cols-3 gap-4 mb-6 summary-cards">
            <div class="bg-white rounded-lg shadow-sm border p-4 text-center">
                <div class="text-xs text-slate-500 uppercase font-medium">Total HT</div>
                <div class="text-lg font-bold text-primary-700 mt-1"><?= formatMontant($totalHT) ?> F CFA</div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border p-4 text-center">
                <div class="text-xs text-slate-500 uppercase font-medium">Total TVA</div>
                <div class="text-lg font-bold text-slate-600 mt-1"><?= formatMontant($totalTVA) ?> F CFA</div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border p-4 text-center">
                <div class="text-xs text-slate-500 uppercase font-medium">Total TTC</div>
                <div class="text-lg font-bold text-primary-800 mt-1"><?= formatMontant($totalTTC) ?> F CFA</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Nombre de résultats + bouton imprimer -->
        <div class="flex items-center justify-between mb-3">
            <span class="text-sm text-slate-500">
                <i class="fas fa-list mr-1"></i> <?= count($achats) ?> achat(s) trouvé(s)
                <?php if ($fournisseurFiltreNom): ?>
                    — <strong><?= htmlspecialchars($fournisseurFiltreNom) ?></strong>
                <?php endif; ?>
                <?php if ($moisFiltre !== null || $anneeFiltre !== null): ?>
                    — <?= $periodePrintLabel ?>
                <?php endif; ?>
            </span>
            <?php if (!empty($achats)): ?>
            <button onclick="window.print()" class="no-print inline-flex items-center px-4 py-2 bg-primary-700 text-white text-sm font-medium rounded-lg hover:bg-primary-800 transition">
                <i class="fas fa-print mr-2"></i> Imprimer
            </button>
            <?php endif; ?>
        </div>

        <!-- Tableau des achats -->
        <div class="bg-white rounded-lg shadow-sm border overflow-hidden">
            <table class="w-full">
                <thead class="bg-slate-50 border-b">
                    <tr>
                        <th class="px-3 py-3 text-left text-xs font-semibold text-slate-700">Période</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold text-slate-700">Date Document</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold text-slate-700">Fournisseur</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold text-slate-700">NIF</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold text-slate-700">N° Document</th>
                        <th class="px-3 py-3 text-center text-xs font-semibold text-slate-700">Nature</th>
                        <th class="px-3 py-3 text-right text-xs font-semibold text-slate-700">Montant HT</th>
                        <th class="px-3 py-3 text-right text-xs font-semibold text-slate-700">TVA</th>
                        <th class="px-3 py-3 text-right text-xs font-semibold text-slate-700">Montant TTC</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($achats)): ?>
                    <tr>
                        <td colspan="9" class="px-4 py-8 text-center text-slate-500">
                            <i class="fas fa-inbox text-4xl mb-2 text-slate-300"></i>
                            <p>Aucun achat trouvé pour les critères sélectionnés</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php 
                    $currentPeriode = '';
                    foreach ($achats as $achat): 
                        $ht = $achat['montant_ht'] ?? 0;
                        $tva = $achat['montant_tva'] ?? 0;
                        $ttc = $achat['montant_ttc'] ?? ($ht + $tva);
                        $periode = ($moisNoms[$achat['mois']] ?? '') . ' ' . $achat['annee'];
                        $showPeriode = ($periode !== $currentPeriode);
                        if ($showPeriode) $currentPeriode = $periode;
                    ?>
                    <tr class="hover:bg-slate-50<?= $showPeriode && $currentPeriode !== $periode ? '' : '' ?>">
                        <td class="px-3 py-2.5 text-sm">
                            <?php if ($showPeriode): ?>
                            <span class="font-semibold text-primary-700"><?= $periode ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2.5 text-sm text-slate-600">
                            <?= $achat['date_document'] ? date('d/m/Y', strtotime($achat['date_document'])) : '-' ?>
                        </td>
                        <td class="px-3 py-2.5 text-sm">
                            <div class="text-primary-600 font-medium"><?= htmlspecialchars($achat['fournisseur_nom'] ?? 'N/A') ?></div>
                            <?php if (!empty($achat['fournisseur_adresse'])): ?>
                            <div class="text-xs text-slate-400"><?= htmlspecialchars($achat['fournisseur_adresse']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2.5 text-sm text-slate-600 font-mono">
                            <?= htmlspecialchars($achat['fournisseur_nif'] ?? '-') ?>
                        </td>
                        <td class="px-3 py-2.5 text-sm text-slate-600">
                            <?= htmlspecialchars($achat['document'] ?? '-') ?>
                        </td>
                        <td class="px-3 py-2.5 text-center">
                            <?php 
                            $nature = $achat['nature_operation'] ?? 'achat';
                            if ($nature === 'service'): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700">Service</span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">Achats</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2.5 text-sm text-right font-medium text-primary-700">
                            <?= formatMontant($ht) ?>
                        </td>
                        <td class="px-3 py-2.5 text-sm text-right text-slate-600">
                            <?= formatMontant($tva) ?>
                        </td>
                        <td class="px-3 py-2.5 text-sm text-right font-semibold text-slate-800">
                            <?= formatMontant($ttc) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($achats)): ?>
                <tfoot class="bg-slate-100 border-t-2 border-slate-300">
                    <tr>
                        <td colspan="6" class="px-3 py-3 text-right font-bold text-slate-700">Total</td>
                        <td class="px-3 py-3 text-right font-bold text-primary-700">
                            <?= formatMontant($totalHT) ?>
                        </td>
                        <td class="px-3 py-3 text-right font-bold text-slate-600">
                            <?= formatMontant($totalTVA) ?>
                        </td>
                        <td class="px-3 py-3 text-right font-bold text-primary-800">
                            <?= formatMontant($totalTTC) ?>
                        </td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <!-- Pied de page impression -->
        <div class="print-footer-info">
            Cabinet Fiscal — <?= htmlspecialchars($client['nom']) ?> — Historique Achats 
            <?php if ($fournisseurFiltreNom): ?>— <?= htmlspecialchars($fournisseurFiltreNom) ?><?php endif; ?>
            — <?= $periodePrintLabel ?> — Édité le <?= date('d/m/Y') ?>
        </div>

        <!-- Lien vers tous les achats -->
        <div class="mt-6 text-center no-print">
            <a href="achats.php?client=<?= $clientId ?>&mois=<?= $moisCourant ?>&annee=<?= $anneeCourant ?>" 
               class="inline-flex items-center text-primary-600 hover:text-primary-800 font-medium">
                <i class="fas fa-arrow-left mr-2"></i>
                Retour aux achats du mois
            </a>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t mt-8 py-4">
        <div class="max-w-7xl mx-auto px-4 text-center text-sm text-slate-500">
            &copy; <?= date('Y') ?> Cabinet Fiscal - Système de Gestion Fiscale
        </div>
    </footer>
</body>
</html>

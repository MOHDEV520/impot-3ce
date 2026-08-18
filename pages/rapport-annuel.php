<?php
/**
 * ============================================
 * RAPPORT ANNUEL
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

// Récupérer les paramètres fiscaux
$parametres = $db->fetchOne("SELECT * FROM parametres_fiscaux WHERE client_id = ?", [$clientId]);
$tauxTVA = $parametres ? (float)($parametres['taux_tva'] ?? 18.00) : 18.00;
$tauxTVADouble = $parametres ? (int)($parametres['taux_tva_double'] ?? 0) : 0;
$tauxCF = $parametres ? (float)($parametres['taux_cf'] ?? 3.5) : 3.5;
$tauxTL = $parametres ? (float)($parametres['taux_tl'] ?? 1) : 1;
$tauxIRF = $parametres['taux_irf'] ?? 12;
$tauxTF = 3;
$tauxCSS = $parametres['taux_css'] ?? 0.5;

$moisNoms = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

function formatMontant($montant) {
    return number_format($montant, 0, ',', ' ');
}

// ============================================
// AGRÉGATION DES DONNÉES ANNUELLES
// ============================================

// 1. CA Global annuel (somme des comptes de gestion mensuels)
$comptesMensuels = $db->fetchAll(
    "SELECT * FROM compte_gestion_mensuel WHERE client_id = ? AND annee = ? ORDER BY mois",
    [$clientId, $annee]
);

$caGlobalAnnuel = 0;
$caExonereAnnuel = 0;
$caTaxableAnnuel = 0;
$masseSalarialeAnnuelle = 0;
$loyersPercusAnnuel = 0;
$itsAnnuel = 0;

// Données mensuelles pour le graphique
$dataParMois = [];
for ($m = 1; $m <= 12; $m++) {
    $dataParMois[$m] = ['ca_exonere' => 0, 'ca_taxable' => 0, 'ca_global' => 0];
}

foreach ($comptesMensuels as $cg) {
    $mois = (int)$cg['mois'];
    $caGlobalAnnuel += (float)($cg['ca_global'] ?? 0);
    $caExonereAnnuel += (float)($cg['ca_exonere'] ?? 0);
    $caTaxableAnnuel += (float)($cg['ca_taxable'] ?? 0);
    $masseSalarialeAnnuelle += (float)($cg['masse_salariale'] ?? 0);
    $loyersPercusAnnuel += (float)($cg['loyers_percus'] ?? 0);
    $itsAnnuel += (float)($cg['its'] ?? 0);
    
    $dataParMois[$mois]['ca_exonere'] = (float)($cg['ca_exonere'] ?? 0);
    $dataParMois[$mois]['ca_taxable'] = (float)($cg['ca_taxable'] ?? 0);
    $dataParMois[$mois]['ca_global'] = (float)($cg['ca_global'] ?? 0);
}

// 2. Achats par fournisseur (annuel)
$achatsParFournisseur = $db->fetchAll(
    "SELECT f.nom as fournisseur_nom, 
            SUM(a.montant_ht) as total_ht, 
            SUM(a.montant_tva) as total_tva,
            SUM(a.montant_ttc) as total_ttc
     FROM achats a 
     LEFT JOIN fournisseurs f ON a.fournisseur_id = f.id 
     WHERE a.client_id = ? AND a.annee = ?
     GROUP BY a.fournisseur_id, f.nom 
     ORDER BY total_ht DESC",
    [$clientId, $annee]
);

$totalAchatsHT = 0;
$totalAchatsTVA = 0;
foreach ($achatsParFournisseur as $af) {
    $totalAchatsHT += (float)$af['total_ht'];
    $totalAchatsTVA += (float)$af['total_tva'];
}

// 3. Total dépenses annuelles
$totalDepenses = $db->fetchOne(
    "SELECT SUM(montant) as total FROM depenses WHERE client_id = ? AND annee = ?",
    [$clientId, $annee]
);
$totalDepensesAnnuel = (float)($totalDepenses['total'] ?? 0);

// Dépenses par nature
$depensesParNature = $db->fetchAll(
    "SELECT nd.libelle as nature_nom, SUM(d.montant) as total
     FROM depenses d 
     LEFT JOIN natures_depenses nd ON d.nature_id = nd.id
     WHERE d.client_id = ? AND d.annee = ?
     GROUP BY d.nature_id, nd.libelle
     ORDER BY total DESC",
    [$clientId, $annee]
);

// 4. Impôts annuels (agrégation depuis impots_mensuels)
$impotsAnnuels = $db->fetchOne(
    "SELECT SUM(tva_a_payer) as tva_total,
            SUM(cf) as cf_total,
            SUM(its) as its_total,
            SUM(tl) as tl_total,
            SUM(irf) as irf_total,
            SUM(tva_location) as tva_location_total,
            SUM(tf) as tf_total,
            SUM(css) as css_total,
            SUM(taxe_touristique) as taxe_touristique_total,
            SUM(total_impots) as total_general
     FROM impots_mensuels
     WHERE client_id = ? AND annee = ?",
    [$clientId, $annee]
);

// Si pas d'impots_mensuels, recalculer depuis les données
$tvaAnnuel = (float)($impotsAnnuels['tva_total'] ?? 0);
$cfAnnuel = (float)($impotsAnnuels['cf_total'] ?? 0);
$itsImpotsAnnuel = (float)($impotsAnnuels['its_total'] ?? 0);
$tlAnnuel = (float)($impotsAnnuels['tl_total'] ?? 0);
$irfAnnuel = (float)($impotsAnnuels['irf_total'] ?? 0);
$tvaLocationAnnuel = (float)($impotsAnnuels['tva_location_total'] ?? 0);
$tfAnnuel = (float)($impotsAnnuels['tf_total'] ?? 0);
$cssAnnuel = (float)($impotsAnnuels['css_total'] ?? 0);
$taxeTouristiqueAnnuel = (float)($impotsAnnuels['taxe_touristique_total'] ?? 0);
$totalImpotsAnnuel = $tvaAnnuel + $cfAnnuel + $itsImpotsAnnuel + $tlAnnuel + $irfAnnuel + $tvaLocationAnnuel + $tfAnnuel + $cssAnnuel + $taxeTouristiqueAnnuel;

// Si la table impots_mensuels est vide, recalculer
if ($totalImpotsAnnuel == 0 && $caGlobalAnnuel > 0) {
    // TVA
    $tvaDeductibleAnnuel = $totalAchatsTVA;
    if ($tauxTVADouble) {
        $portionReduitAnnuel = 0;
        foreach ($comptesMensuels as $cg) {
            $portionReduitAnnuel += (float)($cg['tva_ligne102'] ?? 0);
        }
        $portionNormalAnnuel = max(0, $caTaxableAnnuel - $portionReduitAnnuel);
        $tvaCollecteeAnnuel = round($portionReduitAnnuel * 5 / 100) + round($portionNormalAnnuel * 18 / 100);
    } else {
        $tvaCollecteeAnnuel = round($caTaxableAnnuel * $tauxTVA / 100);
    }
    $tvaAnnuel = max(0, $tvaCollecteeAnnuel - $tvaDeductibleAnnuel);
    
    $cfAnnuel = round($masseSalarialeAnnuelle * $tauxCF / 100);
    $tlAnnuel = round($masseSalarialeAnnuelle * $tauxTL / 100);
    $itsImpotsAnnuel = $itsAnnuel;
    $cssAnnuel = round($caGlobalAnnuel * $tauxCSS / 100);
    $irfTfActif = $parametres ? (int)($parametres['irf_tf_actif'] ?? 0) : 0;
    $locationActif = $parametres ? (int)($parametres['location_actif'] ?? 0) : 0;
    $irfAnnuel = $irfTfActif ? round($loyersPercusAnnuel * $tauxIRF / 100) : 0;
    $tfAnnuel = $irfTfActif ? round($loyersPercusAnnuel * $tauxTF / 100) : 0;
    $tvaLocationAnnuel = $locationActif ? round($loyersPercusAnnuel * 18 / 100) : 0;
    $totalImpotsAnnuel = $tvaAnnuel + $cfAnnuel + $itsImpotsAnnuel + $tlAnnuel + $irfAnnuel + $tvaLocationAnnuel + $tfAnnuel + $cssAnnuel;
}

// Années disponibles
$anneesDisponibles = $db->fetchAll(
    "SELECT DISTINCT annee FROM compte_gestion_mensuel WHERE client_id = ? ORDER BY annee DESC",
    [$clientId]
);

$dateGeneration = date('d F Y');
$pageTitle = "Rapport Annuel " . $annee . " - " . htmlspecialchars($client['nom']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=1.3">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css?v=1.2">
    <script src="../assets/vendor/chartjs/chart.min.js"></script>
    <style>
        .print-only { display: none; }

        @page { size: A4 portrait; margin: 12mm; }

        @media print {
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            header, nav, .no-print, footer, .breadcrumb-bar { display: none !important; }
            body { background: white !important; font-size: 11px; margin: 0; padding: 0; }
            main { max-width: 100% !important; padding: 0 10px !important; margin: 0 !important; }
            .print-only { display: block !important; }
            .rapport-card { box-shadow: none !important; border: 1px solid #e2e8f0 !important; break-inside: avoid; }
            .ca-banner { padding: 12px 20px !important; }
            .ca-banner .text-3xl { font-size: 22px !important; }
            table { font-size: 10px; }
            table th, table td { padding: 4px 8px !important; }
            canvas { max-height: 180px !important; }
            .print-footer {
                display: block !important;
                margin-top: 20px;
                padding-top: 8px;
                border-top: 2px solid #1e40af;
                font-size: 10px;
                color: #64748b;
                text-align: center;
                font-weight: 600;
            }
        }
        .print-footer { display: none; }
    </style>
</head>
<body class="bg-slate-100 min-h-screen">
    <!-- Header -->
    <header class="bg-primary-900 text-white shadow-xl no-print border-b border-primary-800">
        <div class="max-w-5xl mx-auto px-4 py-3">
            <div class="flex items-center justify-between">
                <a href="dashboard.php" class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-white rounded flex items-center justify-center p-1 shadow-inner">
                        <img src="../assets/img/logo.png" alt="3CE FISCUS" class="w-full h-full object-contain">
                    </div>
                    <span class="font-bold text-xl tracking-wider text-white">3CE FISCUS</span>
                </a>
                <div class="flex items-center space-x-4">
                    <div class="hidden md:flex flex-col items-end">
                        <span class="text-xs text-primary-300 uppercase tracking-tighter">Session active</span>
                        <strong class="text-sm font-bold"><?= htmlspecialchars($agent->getPrenom() . ' ' . $agent->getNom()) ?></strong>
                    </div>
                    <a href="logout.php" class="flex items-center px-4 py-2 bg-red-600/20 hover:bg-red-600 text-red-100 font-bold rounded-lg transition-all border border-red-500/30">
                        <i class="fas fa-sign-out-alt mr-2"></i> <span class="hidden md:inline">Déconnexion</span>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Breadcrumb -->
    <div class="bg-slate-200 border-b breadcrumb-bar">
        <div class="max-w-5xl mx-auto px-4 py-2">
            <div class="flex items-center text-sm text-slate-600">
                <a href="dashboard.php" class="hover:text-primary-600">
                    <i class="fas fa-home mr-1"></i> ACCUEIL
                </a>
                <span class="mx-2">|</span>
                <span class="font-medium text-primary-600"><?= htmlspecialchars($client['nom'] ?? '') ?></span>
                <span class="mx-2">|</span>
                <span>Rapport annuel <?= $annee ?></span>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs + Sélecteur Année + Télécharger -->
    <nav class="bg-white border-b shadow-sm no-print">
        <div class="max-w-5xl mx-auto px-4">
            <div class="flex items-center justify-between">
                <div class="flex space-x-1">
                    <span class="px-6 py-3 text-sm font-medium text-white bg-primary-600 border-b-2 border-primary-600">
                        Dashboard
                    </span>
                    <a href="historique-achats.php?client=<?= $clientId ?>&annee=<?= $annee ?>" 
                       class="px-6 py-3 text-sm font-medium text-slate-600 hover:text-primary-600 hover:bg-slate-50 border-b-2 border-transparent">
                        Achats
                    </a>
                    <a href="depenses.php?client=<?= $clientId ?>&annee=<?= $annee ?>" 
                       class="px-6 py-3 text-sm font-medium text-slate-600 hover:text-primary-600 hover:bg-slate-50 border-b-2 border-transparent">
                        Dépenses
                    </a>
                    <a href="impots.php?client=<?= $clientId ?>&mois=1&annee=<?= $annee ?>" 
                       class="px-6 py-3 text-sm font-medium text-slate-600 hover:text-primary-600 hover:bg-slate-50 border-b-2 border-transparent">
                        Impôts
                    </a>
                </div>
                <div class="flex items-center space-x-3">
                    <!-- Sélecteur année -->
                    <form method="GET" class="flex items-center space-x-2">
                        <input type="hidden" name="client" value="<?= $clientId ?>">
                        <select name="annee" onchange="this.form.submit()" class="px-3 py-1.5 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500">
                            <?php foreach ($anneesDisponibles as $a): ?>
                            <option value="<?= $a['annee'] ?>" <?= (int)$a['annee'] === $annee ? 'selected' : '' ?>><?= $a['annee'] ?></option>
                            <?php endforeach; ?>
                            <?php if (empty($anneesDisponibles)): ?>
                            <option value="<?= $annee ?>" selected><?= $annee ?></option>
                            <?php endif; ?>
                        </select>
                    </form>
                    <!-- Bouton imprimer/PDF -->
                    <button onclick="window.print()" class="btn-primary py-1.5">
                        <i class="fas fa-download"></i> Rapport_Annuel_<?= $annee ?>.pdf
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <main class="max-w-5xl mx-auto px-4 py-6">

        <!-- ============================================ -->
        <!-- TITRE DU RAPPORT -->
        <!-- ============================================ -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-primary-800 mb-2">Rapport Annuel - <?= $annee ?></h1>
            <div class="text-sm text-slate-500 space-y-0.5">
                <p>Clôture : <strong class="text-slate-700">31 Décembre <?= $annee ?></strong></p>
                <p>Préparé par : <strong class="text-slate-700"><?= htmlspecialchars($agent->getPrenom() . ' ' . $agent->getNom()) ?></strong> | <?= $agent->getRole() === 'admin' ? 'Administrateur' : 'Agent Comptable' ?></p>
                <p>Date de génération : <strong class="text-slate-700"><?= date('d F Y') ?></strong></p>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- CA GLOBAL DÉCLARÉ -->
        <!-- ============================================ -->
        <div class="ca-banner bg-blue-100 text-blue-900 rounded-xl p-6 mb-8 shadow-md rapport-card border border-blue-200">
            <div class="text-sm font-bold text-blue-600 mb-1 uppercase tracking-wider">CA Global Déclaré</div>
            <div class="text-4xl font-extrabold tracking-tight text-black"><?= formatMontant($caGlobalAnnuel) ?> F CFA</div>
        </div>

        <!-- ============================================ -->
        <!-- ACHATS PAR FOURNISSEUR + GRAPHIQUE -->
        <!-- ============================================ -->
        <div class="card mb-8 rapport-card">
            <h2 class="text-lg font-bold text-slate-800 mb-4">Achats par Fournisseur</h2>
            <div class="flex gap-6">
                <!-- Tableau -->
                <div class="flex-1">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b-2 border-slate-200">
                                <th class="text-left py-2 px-3 text-slate-600 font-semibold">Fournisseur</th>
                                <th class="text-right py-2 px-3 text-slate-600 font-semibold">Total HT</th>
                                <th class="text-right py-2 px-3 text-slate-600 font-semibold">Total TVA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($achatsParFournisseur)): ?>
                            <tr><td colspan="3" class="py-4 text-center text-slate-400">Aucun achat enregistré</td></tr>
                            <?php else: ?>
                            <?php foreach ($achatsParFournisseur as $af): ?>
                            <tr class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="py-2 px-3 font-medium text-primary-700"><?= htmlspecialchars($af['fournisseur_nom'] ?? 'N/A') ?></td>
                                <td class="py-2 px-3 text-right text-slate-700"><?= formatMontant($af['total_ht']) ?> F CFA</td>
                                <td class="py-2 px-3 text-right text-slate-700"><?= formatMontant($af['total_tva']) ?> F CFA</td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-slate-300">
                                <td class="py-2 px-3 font-bold text-slate-800">Total</td>
                                <td class="py-2 px-3 text-right font-bold text-primary-700"><?= formatMontant($totalAchatsHT) ?> F CFA</td>
                                <td class="py-2 px-3 text-right font-bold text-primary-700"><?= formatMontant($totalAchatsTVA) ?> F CFA</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <!-- Graphique -->
                <div class="w-80 shrink-0">
                    <canvas id="chartCA" height="200"></canvas>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- TOTAL DÉPENSES + IMPÔTS PAYÉS -->
        <!-- ============================================ -->
        <div class="grid grid-cols-2 gap-6 mb-8">
            <!-- Total Dépenses -->
            <div class="card rapport-card">
                <h2 class="text-lg font-bold text-slate-800 mb-3">Total Dépenses</h2>
                <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-4">
                    <div class="text-2xl font-bold text-amber-700"><?= formatMontant($totalDepensesAnnuel) ?> F CFA</div>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b-2 border-slate-200">
                            <th class="text-left py-2 px-3 text-slate-600 font-semibold">Nature</th>
                            <th class="text-right py-2 px-3 text-slate-600 font-semibold">Montant</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($depensesParNature)): ?>
                        <tr><td colspan="2" class="py-4 text-center text-slate-400">Aucune dépense enregistrée</td></tr>
                        <?php else: ?>
                        <?php foreach ($depensesParNature as $dn): ?>
                        <tr class="border-b border-slate-100">
                            <td class="py-2 px-3 font-medium text-primary-700"><?= htmlspecialchars($dn['nature_nom'] ?? 'Autre') ?></td>
                            <td class="py-2 px-3 text-right text-slate-700"><?= formatMontant($dn['total']) ?> F CFA</td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-300">
                            <td class="py-2 px-3 font-bold text-slate-800">Total</td>
                            <td class="py-2 px-3 text-right font-bold text-primary-700"><?= formatMontant($totalDepensesAnnuel) ?> F CFA</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Impôts Payés -->
            <div class="card rapport-card">
                <h2 class="text-lg font-bold text-slate-800 mb-3">Impôts Payés en <?= $annee ?></h2>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b-2 border-slate-200">
                            <th class="text-left py-2 px-3 text-slate-600 font-semibold">Libellé</th>
                            <th class="text-right py-2 px-3 text-slate-600 font-semibold">Montant</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $lignesImpots = [
                            ['TVA Nette', $tvaAnnuel],
                            ['Contribution Forfaitaire (CF)', $cfAnnuel],
                            ['Impôt sur Traitements & Salaires (ITS)', $itsImpotsAnnuel],
                            ['Taxe Logement (TL)', $tlAnnuel],
                            ['TVA sur Location', $tvaLocationAnnuel],
                            ['Impôt sur Revenus Fonciers (IRF)', $irfAnnuel],
                            ['Taxe Foncière (TF)', $tfAnnuel],
                            ['Contribution Spéciale Solidarité (CSS)', $cssAnnuel],
                            ['Taxe Touristique', $taxeTouristiqueAnnuel],
                        ];
                        foreach ($lignesImpots as $li):
                            if ($li[1] > 0):
                        ?>
                        <tr class="border-b border-slate-100">
                            <td class="py-2 px-3 text-slate-700"><?= $li[0] ?></td>
                            <td class="py-2 px-3 text-right font-medium text-slate-800"><?= formatMontant($li[1]) ?> F CFA</td>
                        </tr>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-300 bg-slate-50">
                            <td class="py-3 px-3 font-bold text-slate-800">Total</td>
                            <td class="py-3 px-3 text-right font-bold text-primary-700 text-lg"><?= formatMontant($totalImpotsAnnuel) ?> F CFA</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- REMARQUES -->
        <!-- ============================================ -->
        <div class="card mb-6 rapport-card">
            <h2 class="text-lg font-bold text-slate-800 mb-3">Remarques</h2>
            <ul class="text-sm text-slate-600 space-y-1 list-disc list-inside">
                <?php if ($tvaAnnuel > 0): ?>
                <li>TVA nette à payer sur l'année <?= $annee ?> : <strong><?= formatMontant($tvaAnnuel) ?> F CFA</strong>.</li>
                <?php endif; ?>
                <?php if ($totalImpotsAnnuel > 0): ?>
                <li>Total des impôts déclarés sur l'année : <strong><?= formatMontant($totalImpotsAnnuel) ?> F CFA</strong>.</li>
                <?php endif; ?>
                <?php if ($caGlobalAnnuel > 0 && $totalAchatsHT > 0): ?>
                <li>Ratio achats / CA Global : <strong><?= number_format(($totalAchatsHT / $caGlobalAnnuel) * 100, 1, ',', ' ') ?>%</strong>.</li>
                <?php endif; ?>
                <?php if ($caGlobalAnnuel == 0 && $totalAchatsHT == 0): ?>
                <li>Aucune donnée enregistrée pour l'année <?= $annee ?>.</li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Pied de page impression -->
        <div class="print-footer">
            <strong>3CE FISCUS</strong> | Rapport annuel | Année <?= $annee ?> — <?= htmlspecialchars($client['nom']) ?>
        </div>

        <!-- Lien retour -->
        <div class="mt-4 text-center no-print">
            <a href="dashboard.php" class="inline-flex items-center text-primary-600 hover:text-primary-800 font-medium">
                <i class="fas fa-arrow-left mr-2"></i> Retour au tableau de bord
            </a>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t mt-8 py-4">
        <div class="max-w-5xl mx-auto px-4 text-center text-sm text-slate-500">
            &copy; <?= date('Y') ?> Cabinet Fiscal - Système de Gestion Fiscale
        </div>
    </footer>

    <!-- Chart.js - Graphique évolution mensuelle -->
    <script>
    const ctx = document.getElementById('chartCA').getContext('2d');
    const moisLabels = [<?php 
        $labels = [];
        foreach ($moisNoms as $m => $nom) {
            // Abréviation 3 lettres
            $labels[] = "'" . mb_substr($nom, 0, 3) . "'";
        }
        echo implode(',', $labels);
    ?>];

    const dataExonere = [<?php 
        $vals = [];
        for ($m = 1; $m <= 12; $m++) $vals[] = $dataParMois[$m]['ca_exonere'];
        echo implode(',', $vals);
    ?>];

    const dataTaxable = [<?php 
        $vals = [];
        for ($m = 1; $m <= 12; $m++) $vals[] = $dataParMois[$m]['ca_taxable'];
        echo implode(',', $vals);
    ?>];

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: moisLabels,
            datasets: [
                {
                    label: 'Exonérés',
                    data: dataExonere,
                    borderColor: '#1e40af',
                    backgroundColor: 'rgba(30, 64, 175, 0.1)',
                    tension: 0.3,
                    pointRadius: 3,
                    borderWidth: 2,
                    fill: false
                },
                {
                    label: 'Taxables',
                    data: dataTaxable,
                    borderColor: '#16a34a',
                    backgroundColor: 'rgba(22, 163, 74, 0.1)',
                    tension: 0.3,
                    pointRadius: 3,
                    borderWidth: 2,
                    fill: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { font: { size: 11 }, boxWidth: 12 }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            if (value >= 1000000) return (value / 1000000).toFixed(1) + 'M';
                            if (value >= 1000) return (value / 1000).toFixed(0) + 'K';
                            return value;
                        },
                        font: { size: 10 }
                    },
                    grid: { color: '#f1f5f9' }
                },
                x: {
                    ticks: { font: { size: 10 } },
                    grid: { display: false }
                }
            }
        }
    });
    </script>
</body>
</html>

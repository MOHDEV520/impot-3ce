<?php
/**
 * ============================================
 * RÉCAPITULATIF DES DÉCLARATIONS
 * Vue annuelle des impôts déclarés par mois
 * (données issues de impots_mensuels)
 * ============================================
 */

session_start();
define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/classes/Agent.php';
require_once APP_ROOT . '/classes/Client.php';

// Vérifier l'authentification
if (!Agent::estConnecte()) {
    header('Location: ../index.php');
    exit;
}

// Paramètres (accepte client_id comme les pages declaration-* et client comme la navbar)
$clientId = (int) ($_GET['client_id'] ?? $_GET['client'] ?? 0);
$annee = (int) ($_GET['annee'] ?? date('Y'));

if ($clientId === 0) {
    header('Location: clients.php');
    exit;
}

// Charger le client
$client = new Client($clientId);
$agent = Agent::getAgentConnecte();

// Vérifier l'accès
if (!$agent->aAccesClient($clientId)) {
    header('Location: clients.php?erreur=acces');
    exit;
}

$db = Database::getInstance();

// Impôts déclarés du client pour l'année, indexés par mois
$lignes = $db->fetchAll(
    "SELECT * FROM impots_mensuels WHERE client_id = ? AND annee = ? ORDER BY mois",
    [$clientId, $annee]
);
$impotsParMois = [];
foreach ($lignes as $ligne) {
    $impotsParMois[(int) $ligne['mois']] = $ligne;
}

// Colonnes d'impôts affichées
$colonnes = [
    'tva_a_payer' => 'TVA',
    'cf' => 'CF',
    'its' => 'ITS',
    'tl' => 'TL',
    'irf' => 'IRF',
    'tva_location' => 'TVA Loc.',
    'tf' => 'TF',
    'css' => 'CSS',
];

// Totaux annuels
$totaux = array_fill_keys(array_keys($colonnes), 0.0);
$totaux['credit_tva'] = 0.0;
$totaux['total_impots'] = 0.0;
foreach ($impotsParMois as $im) {
    foreach ($totaux as $col => $val) {
        $totaux[$col] += (float) ($im[$col] ?? 0);
    }
}

// Mois en français
$moisNoms = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

// Formatage des montants
function fmtMontant(float $montant): string
{
    return $montant != 0.0 ? number_format($montant, 0, ',', ' ') : '-';
}

$pageTitle = "Récapitulatif des déclarations - " . $annee;
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
        @media print {
            body { font-size: 11px; }
            .no-print { display: none !important; }
        }
        td.montant, th.montant { text-align: right; font-family: monospace; }
    </style>
</head>
<body class="bg-slate-100 min-h-screen">

    <!-- Barre d'actions -->
    <div class="bg-primary-900 text-white shadow-xl no-print border-b border-primary-800">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <a href="impots.php?client=<?= $clientId ?>&annee=<?= $annee ?>" class="text-primary-200 hover:text-white">
                    <i class="fas fa-arrow-left mr-1"></i> Retour
                </a>
                <span class="mx-2 text-primary-700">|</span>
                <span class="font-bold text-lg">Récapitulatif des déclarations</span>
            </div>
            <div class="flex items-center space-x-3">
                <form action="" method="GET" class="flex items-center space-x-2">
                    <input type="hidden" name="client_id" value="<?= $clientId ?>">
                    <select name="annee" onchange="this.form.submit()" class="px-3 py-1 border rounded bg-white text-slate-800 text-sm">
                        <?php for ($a = date('Y') - 5; $a <= date('Y') + 1; $a++): ?>
                        <option value="<?= $a ?>" <?= $a == $annee ? 'selected' : '' ?>><?= $a ?></option>
                        <?php endfor; ?>
                    </select>
                </form>
                <button onclick="window.print()" class="px-4 py-2 bg-white text-primary-900 font-bold rounded-lg hover:bg-primary-100">
                    <i class="fas fa-print mr-2"></i>Imprimer
                </button>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 py-6">

        <!-- En-tête client -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <div class="flex items-center justify-between flex-wrap gap-4">
                <div>
                    <h1 class="text-xl font-bold text-slate-800"><?= htmlspecialchars($client->getNom()) ?></h1>
                    <p class="text-sm text-slate-500">
                        IFU : <?= htmlspecialchars($client->getIfu() ?? '-') ?>
                        &nbsp;•&nbsp; Exercice <?= $annee ?>
                    </p>
                </div>
                <div class="text-right">
                    <div class="text-xs text-slate-500 uppercase">Total impôts déclarés <?= $annee ?></div>
                    <div class="text-2xl font-bold text-primary-700"><?= number_format($totaux['total_impots'], 0, ',', ' ') ?> F</div>
                </div>
            </div>
        </div>

        <!-- Tableau récapitulatif -->
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-100 border-b">
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Mois</th>
                        <?php foreach ($colonnes as $libelle): ?>
                        <th class="px-3 py-3 montant font-semibold text-slate-700"><?= $libelle ?></th>
                        <?php endforeach; ?>
                        <th class="px-3 py-3 montant font-semibold text-slate-700">Crédit TVA</th>
                        <th class="px-4 py-3 montant font-semibold text-slate-800 bg-slate-200">Total</th>
                        <th class="px-3 py-3 no-print"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($m = 1; $m <= 12; $m++):
                        $im = $impotsParMois[$m] ?? null; ?>
                    <tr class="border-b hover:bg-slate-50 <?= $im ? '' : 'text-slate-400' ?>">
                        <td class="px-4 py-2 font-medium"><?= $moisNoms[$m] ?></td>
                        <?php foreach (array_keys($colonnes) as $col): ?>
                        <td class="px-3 py-2 montant"><?= $im ? fmtMontant((float) $im[$col]) : '-' ?></td>
                        <?php endforeach; ?>
                        <td class="px-3 py-2 montant text-amber-700"><?= $im ? fmtMontant((float) $im['credit_tva']) : '-' ?></td>
                        <td class="px-4 py-2 montant font-bold bg-slate-50"><?= $im ? fmtMontant((float) $im['total_impots']) : '-' ?></td>
                        <td class="px-3 py-2 no-print text-center">
                            <a href="impots.php?client=<?= $clientId ?>&mois=<?= $m ?>&annee=<?= $annee ?>"
                               class="text-primary-600 hover:text-primary-800" title="Voir les impôts de <?= $moisNoms[$m] ?>">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
                <tfoot>
                    <tr class="bg-primary-50 border-t-2 border-primary-200 font-bold text-slate-800">
                        <td class="px-4 py-3">TOTAL <?= $annee ?></td>
                        <?php foreach (array_keys($colonnes) as $col): ?>
                        <td class="px-3 py-3 montant"><?= fmtMontant($totaux[$col]) ?></td>
                        <?php endforeach; ?>
                        <td class="px-3 py-3 montant text-amber-700"><?= fmtMontant($totaux['credit_tva']) ?></td>
                        <td class="px-4 py-3 montant bg-primary-100"><?= fmtMontant($totaux['total_impots']) ?></td>
                        <td class="no-print"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <?php if (empty($impotsParMois)): ?>
        <div class="mt-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-4 text-sm no-print">
            <i class="fas fa-info-circle mr-2"></i>
            Aucun impôt calculé pour <?= $annee ?>. Les montants apparaîtront ici après le calcul des impôts mensuels
            (page <a href="impots.php?client=<?= $clientId ?>&annee=<?= $annee ?>" class="underline font-semibold">Impôts</a>).
        </div>
        <?php endif; ?>

    </div>
</body>
</html>

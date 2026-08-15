<?php
/**
 * IMPOT - TVA
 */
session_start();
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/classes/Agent.php';
require_once APP_ROOT . '/classes/Client.php';
require_once APP_ROOT . '/classes/CompteGestionMensuel.php';
require_once APP_ROOT . '/classes/Achat.php';

if (!Agent::estConnecte() || !Agent::verifierTimeout()) {
    header('Location: ../index.php');
    exit;
}

$agent = Agent::getAgentConnecte();
$db = Database::getInstance();
$clientId = (int) ($_GET['client'] ?? 0);
$mois = (int) ($_GET['mois'] ?? date('n'));
$annee = (int) ($_GET['annee'] ?? date('Y'));

$client = new Client($clientId);
if (!$client->getId() || !$agent->aAccesClient($clientId)) {
    header('Location: clients.php');
    exit;
}

$moisNoms = [1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre'];

$compteGestion = new CompteGestionMensuel($clientId, $mois, $annee);
$totauxAchats = Achat::getTotaux($clientId, $mois, $annee);

$caGlobal = $compteGestion->getCaGlobal();
$caExonere = $compteGestion->getCaExonere();
$caTaxable = $compteGestion->getCaTaxable();
// Récupérer le taux TVA du client depuis parametres_fiscaux (18% ou 5%)
$parametresFiscaux = $db->fetchOne("SELECT taux_tva FROM parametres_fiscaux WHERE client_id = ?", [$clientId]);
$tauxTVA = $parametresFiscaux ? (float)$parametresFiscaux['taux_tva'] : 18.00;

$tvaCollectee = round($caTaxable * $tauxTVA / 100);
$tvaCollecteeTotale = $tvaCollectee;
$tvaDeductible = $totauxAchats['total_tva'] ?? 0;
$tvaNette = max(0, $tvaCollecteeTotale - $tvaDeductible);

function formatMontant($m) { return number_format($m, 0, ',', ' '); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TVA - <?= $client->getNom() ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
</head>
<body class="bg-slate-100 min-h-screen">
    <header class="bg-blue-800 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center space-x-2">
                <i class="fas fa-building"></i>
                <span class="font-bold">CABINET FISCAL</span>
            </div>
            <div class="flex items-center space-x-4">
                <span>Bienvenue, <strong><?= htmlspecialchars($agent->getPrenom().' '.$agent->getNom()) ?></strong></span>
                <a href="logout.php" class="text-blue-200 hover:text-white">Déconnexion</a>
            </div>
        </div>
    </header>

    <div class="bg-white border-b shadow-sm">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center text-sm">
            <a href="dashboard.php" class="text-slate-500 hover:text-slate-700"><i class="fas fa-home mr-1"></i> CABINET FISCAL</a>
            <span class="mx-2 text-slate-400">|</span>
            <span class="text-blue-600 font-medium"><?= htmlspecialchars($client->getNom()) ?></span>
            <span class="mx-2 text-slate-400">|</span>
            <span><?= $moisNoms[$mois] ?> <?= $annee ?></span>
        </div>
    </div>

    <!-- Navigation Impôts -->
    <div class="bg-white border-b">
        <div class="max-w-7xl mx-auto px-4">
            <nav class="flex space-x-1 overflow-x-auto">
                <a href="impot-tva.php?client=<?=$clientId?>&mois=<?=$mois?>&annee=<?=$annee?>" class="px-4 py-3 text-sm font-medium text-white bg-blue-600 rounded-t-lg whitespace-nowrap">TVA</a>
                <a href="impot-cf.php?client=<?=$clientId?>&mois=<?=$mois?>&annee=<?=$annee?>" class="px-4 py-3 text-sm font-medium text-slate-600 hover:bg-slate-50 whitespace-nowrap">CF</a>
                <a href="impot-tl.php?client=<?=$clientId?>&mois=<?=$mois?>&annee=<?=$annee?>" class="px-4 py-3 text-sm font-medium text-slate-600 hover:bg-slate-50 whitespace-nowrap">TL</a>
                <a href="impot-its.php?client=<?=$clientId?>&mois=<?=$mois?>&annee=<?=$annee?>" class="px-4 py-3 text-sm font-medium text-slate-600 hover:bg-slate-50 whitespace-nowrap">ITS</a>
                <a href="impot-tf.php?client=<?=$clientId?>&mois=<?=$mois?>&annee=<?=$annee?>" class="px-4 py-3 text-sm font-medium text-slate-600 hover:bg-slate-50 whitespace-nowrap">TF</a>
                <a href="impot-irf.php?client=<?=$clientId?>&mois=<?=$mois?>&annee=<?=$annee?>" class="px-4 py-3 text-sm font-medium text-slate-600 hover:bg-slate-50 whitespace-nowrap">IRF</a>
                <a href="impot-css.php?client=<?=$clientId?>&mois=<?=$mois?>&annee=<?=$annee?>" class="px-4 py-3 text-sm font-medium text-slate-600 hover:bg-slate-50 whitespace-nowrap">CSS</a>
                <a href="impot-tva-location.php?client=<?=$clientId?>&mois=<?=$mois?>&annee=<?=$annee?>" class="px-4 py-3 text-sm font-medium text-slate-600 hover:bg-slate-50 whitespace-nowrap">TVA/LOC</a>
            </nav>
        </div>
    </div>

    <main class="max-w-7xl mx-auto px-4 py-6">
        <h1 class="text-2xl font-bold text-slate-800 mb-6">TVA - Taxe sur la Valeur Ajoutée</h1>

        <div class="bg-amber-50 border-l-4 border-amber-400 p-4 mb-6">
            <div class="flex items-center">
                <i class="fas fa-exclamation-triangle text-amber-500 mr-3"></i>
                <p class="text-amber-700">Exonération valable uniquement avec <strong>annexe</strong> obligatoire</p>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border mb-6">
            <div class="p-6">
                <table class="w-full max-w-2xl mx-auto">
                    <tbody>
                        <tr class="border-b border-slate-100">
                            <td class="py-4 text-slate-600">Chiffre d'affaires</td>
                            <td class="py-4 text-right">
                                <input type="text" class="w-48 px-3 py-2 border border-slate-300 rounded text-right font-semibold" value="<?= formatMontant($caGlobal) ?> F CFA" readonly>
                            </td>
                        </tr>
                        <tr class="border-b border-slate-100">
                            <td class="py-4 text-slate-600">Chiffre d'affaires exonéré</td>
                            <td class="py-4 text-right">
                                <input type="text" class="w-48 px-3 py-2 border border-slate-300 rounded text-right font-semibold" value="<?= formatMontant($caExonere) ?> F CFA" readonly>
                            </td>
                        </tr>
                        <tr class="border-b border-slate-100">
                            <td class="py-4 text-slate-600">Chiffre d'affaires taxable</td>
                            <td class="py-4 text-right">
                                <input type="text" class="w-48 px-3 py-2 border border-slate-300 rounded text-right font-semibold" value="<?= formatMontant($caTaxable) ?> F CFA" readonly>
                            </td>
                        </tr>
                        <tr class="border-b border-slate-100">
                            <td class="py-4 text-slate-600">TVA collectée (<?= $tauxTVA ?>%)</td>
                            <td class="py-4 text-right">
                                <input type="text" class="w-48 px-3 py-2 border border-slate-300 rounded text-right font-semibold" value="<?= formatMontant($tvaCollecteeTotale) ?> F CFA" readonly>
                            </td>
                        </tr>
                        <tr class="border-b border-slate-100">
                            <td class="py-4 text-slate-600">TVA déductible</td>
                            <td class="py-4 text-right">
                                <input type="text" class="w-48 px-3 py-2 border border-slate-300 rounded text-right font-semibold" value="<?= formatMontant($tvaDeductible) ?> F CFA" readonly>
                            </td>
                        </tr>
                        <tr class="bg-blue-50">
                            <td class="py-4 px-2 text-slate-700 font-semibold">TVA Nette à payer</td>
                            <td class="py-4 px-2 text-right">
                                <input type="text" class="w-48 px-3 py-2 border-2 border-blue-400 bg-blue-50 rounded text-right font-bold text-blue-700" value="<?= formatMontant($tvaNette) ?> F CFA" readonly>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex justify-between items-center">
            <a href="achats.php?client=<?=$clientId?>&mois=<?=$mois?>&annee=<?=$annee?>" class="text-slate-600 hover:text-blue-600">
                <i class="fas fa-arrow-left mr-2"></i> Retour aux achats
            </a>
            <button class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium">
                <i class="fas fa-check mr-2"></i> Valider
            </button>
        </div>
    </main>
</body>
</html>

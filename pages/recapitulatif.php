<?php
/**
 * ============================================
 * RÉCAPITULATIF MENSUEL
 * Vue consolidée des données du mois
 * ============================================
 */

session_start();
define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/classes/Agent.php';
require_once APP_ROOT . '/classes/Client.php';
require_once APP_ROOT . '/classes/CompteGestionMensuel.php';
require_once APP_ROOT . '/classes/Achat.php';
require_once APP_ROOT . '/classes/Impot.php';

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

$client = $db->fetchOne("SELECT * FROM clients WHERE id = ?", [$clientId]);
if (!$client) {
    header('Location: clients.php');
    exit;
}
if (!$agent->aAccesClient($clientId)) {
    header('Location: clients.php?msg=' . urlencode('Accès non autorisé') . '&type=error');
    exit;
}

// Charger l'objet CompteGestionMensuel
$compteGestionObj = new CompteGestionMensuel($clientId, $mois, $annee);

// Traitement de la validation
$message = '';
$messageType = 'success';
$csrfToken = Agent::getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'valider_mois') {
    if (!Agent::verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = 'Session invalide, veuillez réessayer.';
        $messageType = 'error';
    } else {
        try {
            if ($compteGestionObj->valider()) {
                $moisNomsTmp = [1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre'];
                $message = "Le mois de " . ($moisNomsTmp[$mois] ?? $mois) . " $annee a été validé avec succès.";
            }
        } catch (Exception $e) {
            $message = messageErreurUtilisateur($e, "la validation de ce mois");
            $messageType = 'error';
        }
    }
}

// Récupérer le tableau des données pour la compatibilité avec le reste du code
$compteGestion = $compteGestionObj->toArray();

$moisNoms = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

// Récupérer les totaux achats
$totauxAchats = Achat::getTotaux($clientId, $mois, $annee);
$achatsParFournisseur = Achat::getByClientMoisGroupeParFournisseur($clientId, $mois, $annee);

// Récupérer les achats détaillés (avec numéro relevé/facture)
$achatsDetailles = Achat::getByClientMois($clientId, $mois, $annee);

// Récupérer les dépenses
$depenses = $db->fetchAll(
    "SELECT d.*, nd.libelle as nature_libelle 
     FROM depenses d 
     LEFT JOIN natures_depenses nd ON d.nature_id = nd.id 
     WHERE d.client_id = ? AND d.mois = ? AND d.annee = ? 
     ORDER BY nd.ordre_affichage",
    [$clientId, $mois, $annee]
);

$totalDepensesNormales = 0;
$depensesParNature = [];
foreach ($depenses as $dep) {
    $nature = $dep['nature_libelle'] ?? 'Autre';
    if (!isset($depensesParNature[$nature])) {
        $depensesParNature[$nature] = 0;
    }
    $depensesParNature[$nature] += $dep['montant'];
    $totalDepensesNormales += $dep['montant'];
}

// Récupérer le taux TVA du client (18% ou 5%) depuis parametres_fiscaux
$parametresFiscaux = $db->fetchOne(
    "SELECT * FROM parametres_fiscaux WHERE client_id = ?",
    [$clientId]
);
$tauxTVA = $parametresFiscaux ? (float)$parametresFiscaux['taux_tva'] : 18.00;
$tauxTVADouble = $parametresFiscaux ? (int)($parametresFiscaux['taux_tva_double'] ?? 0) : 0;
$typeTva = $parametresFiscaux ? ($parametresFiscaux['type_tva'] ?? 'non_exonere') : 'non_exonere';
$margeDefaut = $parametresFiscaux ? (float)($parametresFiscaux['marge'] ?? 1.30) : 1.30;
$margeTaxableDefaut = $parametresFiscaux ? (float)($parametresFiscaux['marge_taxable'] ?? 1.30) : 1.30;

$masseSalariale = $compteGestion['masse_salariale'] ?? 0;
$loyersPercus = $compteGestion['loyers_percus'] ?? 0;
$its = $compteGestion['its'] ?? 0;
$margeSauvegardee = (float)($compteGestion['marge'] ?? $margeDefaut);
$margeTaxableSauvegardee = (float)($compteGestion['marge_taxable'] ?? $margeTaxableDefaut);

// Calcul du CA
$achatsHT_Reel = $totauxAchats['total_ht'] ?? 0;
$achatsHT = $totauxAchats['total_ht_ca'] ?? $achatsHT_Reel;
$tvaDeductibleAchats = $totauxAchats['total_tva'] ?? 0;
$ligne112Deductible = (isset($compteGestion['tva_ligne112']) && (float)$compteGestion['tva_ligne112'] > 0)
    ? (float)$compteGestion['tva_ligne112']
    : (float)($totauxAchats['tva_deductible'] ?? 0);

// Utiliser le taux de TVA du client pour le calcul de la base taxable depuis la TVA
$tauxTVADecimal = ($tauxTVA > 0) ? ($tauxTVA / 100) : 0.18;
$achatsTaxable = ($tvaDeductibleAchats > 0) ? round($tvaDeductibleAchats / $tauxTVADecimal) : 0;

// CA Global : Priorité à la valeur sauvegardée si elle existe
$caGlobal = (float)($compteGestion['ca_global'] ?? 0);
if ($caGlobal == 0 && $margeSauvegardee > 0 && $achatsHT > 0) {
    $caGlobal = round($achatsHT * $margeSauvegardee);
}

// CA Taxable : Priorité à la valeur sauvegardée if it exists
$caTaxable = (float)($compteGestion['ca_taxable'] ?? 0);
if ($caTaxable == 0 && $margeTaxableSauvegardee > 0 && $achatsTaxable > 0) {
    $caTaxable = round($achatsTaxable * $margeTaxableSauvegardee);
}

// Si le client est exonéré à 100%, le CA Taxable est nul
if ($typeTva === 'exonere_total') {
    $caTaxable = 0;
}

// CA Exonéré
$caExonere = (float)($compteGestion['ca_exonere'] ?? 0);
if ($caExonere == 0) {
    if ($typeTva === 'exonere_total') {
        $caExonere = $caGlobal;
    } else {
        $caExonere = max(0, $caGlobal - $caTaxable);
    }
}

// Récupérer les paramètres fiscaux (taux CF, TL, etc.)
$tauxCF = $parametresFiscaux['taux_cf'] ?? 3.5;
$tauxTL = $parametresFiscaux['taux_tl'] ?? 1.0;
$tauxCSS = $parametresFiscaux['taux_css'] ?? 0.5;
$tauxTF = $parametresFiscaux['taux_tf'] ?? 3.0;
$tauxIRF = $parametresFiscaux['taux_irf'] ?? 12.0;

// 2b. Calcul TVA Nette COMPLET (Format Officiel)
if ($tauxTVADouble) {
    // Double taux
    $base5 = (float)($compteGestion['tva_ligne102'] ?? 0);
    $val105 = round(($base5 + (float)($compteGestion['tva_ligne103'] ?? 0)) * 5 / 100);
    $base18 = max(0, $caTaxable - $base5);
    $val109 = round(($base18 + (float)($compteGestion['tva_ligne107'] ?? 0)) * 18 / 100);
} else {
    // Taux unique
    $val105 = ($tauxTVA == 5) ? round(($caTaxable + (float)($compteGestion['tva_ligne103'] ?? 0)) * 5 / 100) : 0;
    $val109 = ($tauxTVA == 18) ? round(($caTaxable + (float)($compteGestion['tva_ligne107'] ?? 0)) * 18 / 100) : 0;
}
$tvaLigne110 = (float)($compteGestion['tva_ligne110'] ?? 0);
$tvaBrute = $val105 + $val109 + $tvaLigne110;
$tvaDeductions = $ligne112Deductible + 
                 (float)($compteGestion['tva_ligne113'] ?? 0) + 
                 (float)($compteGestion['tva_ligne114'] ?? 0) + 
                 (float)($compteGestion['tva_ligne115'] ?? 0) + 
                 (float)($compteGestion['tva_ligne116'] ?? 0) + 
                 (float)($compteGestion['tva_ligne117'] ?? 0) + 
                 (float)($compteGestion['tva_ligne118'] ?? 0) + 
                 (float)($compteGestion['tva_ligne120'] ?? 0);

$tvaNette = max(0, $tvaBrute - $tvaDeductions);

// 2c. Calcul Contribution Forfaitaire (CF) - Basé sur Masse Salariale
$cf_ligne243 = (float)($compteGestion['cf_ligne243'] ?? 0);
$cf_brut = $masseSalariale + $cf_ligne243;
$cf_exonerations = (float)($compteGestion['cf_ligne246'] ?? 0) + (float)($compteGestion['cf_ligne247'] ?? 0) + 
                    (float)($compteGestion['cf_ligne248'] ?? 0) + (float)($compteGestion['cf_ligne249'] ?? 0) + 
                    (float)($compteGestion['cf_ligne250'] ?? 0) + (float)($compteGestion['cf_ligne251'] ?? 0);
$cf_base_arrondie = floor(max(0, $cf_brut - $cf_exonerations) / 1000) * 1000;
$cf = round($cf_base_arrondie * $tauxCF / 100);

// 2d. Calcul Taxe Logement (TL) - Basé sur Masse Salariale
$tl_ligne212 = (float)($compteGestion['tl_ligne212'] ?? 0);
$tl_brut = $masseSalariale + $tl_ligne212;
$tl_base_arrondie = floor($tl_brut / 1000) * 1000;
$tl = round($tl_base_arrondie * $tauxTL / 100);

// 2e. Autres Impôts
$its = (float)($compteGestion['its'] ?? 0);
$css = round($caGlobal * $tauxCSS / 100);
$irfTfActif = $parametresFiscaux ? (int)($parametresFiscaux['irf_tf_actif'] ?? 0) : 0;
$locationActif = $parametresFiscaux ? (int)($parametresFiscaux['location_actif'] ?? 0) : 0;
$rasActif = $parametresFiscaux ? (int)($parametresFiscaux['ras_actif'] ?? 0) : 0;
$tf = $irfTfActif ? round($loyersPercus * $tauxTF / 100) : 0;
$irf = $irfTfActif ? round($loyersPercus * $tauxIRF / 100) : 0;

// 2f. TVA/Location
$locExonere = (float)($compteGestion['loc_ligne132'] ?? 0) + 
               (float)($compteGestion['loc_ligne133'] ?? 0) + 
               (float)($compteGestion['loc_ligne137'] ?? 0) + 
               (float)($compteGestion['loc_ligne141'] ?? 0);
$locBase = max(0, $loyersPercus - $locExonere);
$locTvaCollectee = round($locBase * 18 / 100);
$tvaLocation = $locationActif ? max(0, $locTvaCollectee - (float)($compteGestion['loc_ligne145'] ?? 0)) : 0;

// 2g. Retenue à la Source BIC/IS (si activée pour ce client)
$ras = $rasActif ? Impot::calculerRetenueSourceBIC($compteGestion)['430'] : 0;

// Récupérer les impôts
$impotsSaved = $db->fetchOne(
    "SELECT * FROM impots_mensuels WHERE client_id = ? AND mois = ? AND annee = ?",
    [$clientId, $mois, $annee]
);

// Pour le recapitulatif, garder la logique declaration TVA (ligne 131 = 111 - 125).

$totalImpots = $tvaNette + $cf + $tl + $its + $irf + $tf + $css + $tvaLocation + $ras;

// Total des dépenses = Dépenses normales + Masse Salariale + Impôts déclarés
$totalDepenses = $totalDepensesNormales + $masseSalariale + $totalImpots;

// Formatage
function formatMontant($montant) {
    return number_format($montant, 0, ',', ' ');
}

$dateGeneration = date('d/m/Y à H:i');
$pageTitle = "Récapitulatif - " . htmlspecialchars($client['nom']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=1.2">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css?v=1.2">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; font-size: 10pt; }
            .section-box { border: 1px solid #ddd !important; margin-bottom: 20px !important; break-inside: avoid; }
            .section-header { background: #f8fafc !important; color: black !important; border-bottom: 2px solid #ddd !important; }
            .tva-collectee-highlight {
                background: #dbeafe !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .tva-collectee-highlight td {
                color: #1e3a8a !important;
            }
        }
        .tva-collectee-highlight {
            background: #dbeafe;
        }
        .tva-collectee-highlight td {
            color: #1e3a8a;
        }
        .print-header { display: none; }
        @media print {
            .print-header { display: block; }
        }
    </style>
</head>
<body class="bg-gray-100 pb-12">
    <?php include APP_ROOT . '/includes/navbar-impots.php'; ?>

    <main class="max-w-7xl mx-auto px-4 py-6">
        <!-- Message -->
        <?php if ($message): ?>
        <div class="mb-4 p-4 rounded-lg border-l-4 <?= $messageType === 'error' ? 'bg-red-50 border-red-500 text-red-700' : 'bg-green-50 border-green-500 text-green-700' ?>">
            <i class="fas <?= $messageType === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle' ?> mr-2"></i>
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <!-- EN-TÊTE IMPRESSION -->
        <div class="print-header" style="text-align:center; padding-bottom:15px; margin-bottom:15px; border-bottom:2px solid #1e40af;">
            <h1 style="font-size:16pt; font-weight:bold; color:#1e40af; margin-bottom:5px;">RÉCAPITULATIF FISCAL MENSUEL</h1>
            <div style="font-size:10pt; color:#666;">
                <strong><?= htmlspecialchars(strtoupper($client['nom'])) ?></strong>
                <?php if ($client['ifu']): ?> | NIF: <?= htmlspecialchars($client['ifu']) ?><?php endif; ?>
                <br><?= $moisNoms[$mois] ?> <?= $annee ?> | Généré le <?= $dateGeneration ?>
            </div>
        </div>

        <!-- ========== 1. EN-TÊTE CLIENT + RÉSUMÉ ========== -->
        <div class="card mb-4 section-box">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-800"><?= htmlspecialchars($client['nom']) ?></h1>
                    <div class="flex items-center space-x-3">
                        <p class="text-slate-500"><?= $moisNoms[$mois] ?> <?= $annee ?></p>
                        <span class="px-2 py-0.5 bg-amber-100 text-amber-800 text-xs font-semibold rounded-full border border-amber-200">
                            Échéance : <?= Impot::getEcheanceFormatee($mois, $annee) ?>
                        </span>
                        <?php if ($compteGestion['statut'] === 'valide' || $compteGestion['statut'] === 'verrouille'): ?>
                        <span class="px-2 py-0.5 bg-green-100 text-green-800 text-xs font-semibold rounded-full border border-green-200">
                            <i class="fas fa-check-circle mr-1"></i> VALIDÉ
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($client['ifu']): ?><p class="text-sm text-slate-400">NIF: <?= htmlspecialchars($client['ifu']) ?></p><?php endif; ?>
                </div>
                <div class="text-right no-print flex items-center space-x-2">
                    <?php if ($compteGestion['statut'] !== 'valide' && $compteGestion['statut'] !== 'verrouille'): ?>
                    <button type="button" onclick="ouvrirModalValidation()" class="btn-success py-2.5">
                        <i class="fas fa-check"></i> VALIDER CE MOIS
                    </button>
                    <?php endif; ?>
                    <div class="h-8 w-px bg-slate-200"></div>
                    <a href="annexe-tva.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" class="btn-outline px-3 py-2 text-sm" title="Annexe TVA">
                        <i class="fas fa-file-alt text-amber-600"></i> Annexe TVA
                    </a>
                    <a href="annexe-exoneration.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" class="btn-outline px-3 py-2 text-sm" title="Annexe Exonération">
                        <i class="fas fa-file-alt text-purple-600"></i> Exonération
                    </a>
                    <a href="recap-paiements.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" class="btn-outline px-3 py-2 text-sm" title="Récap Paiements">
                        <i class="fas fa-file-pdf text-red-600"></i> Paiements
                    </a>
                    <button onclick="window.print()" class="btn-outline px-3 py-2 text-sm" title="Imprimer">
                        <i class="fas fa-print"></i>
                    </button>
                </div>
            </div>

            <?php if ($compteGestion['statut'] !== 'valide' && $compteGestion['statut'] !== 'verrouille'): ?>
            <!-- Dialog de validation du mois : montants figés listés explicitement -->
            <div id="modalValidation" class="hidden fixed inset-0 bg-slate-900/50 z-50 flex items-center justify-center p-4 no-print"
                 role="dialog" aria-modal="true" aria-labelledby="titreModalValidation">
                <div class="bg-white rounded-xl shadow-xl max-w-md w-full p-6">
                    <h2 id="titreModalValidation" class="text-lg font-bold text-slate-800 mb-1">
                        <i class="fas fa-check-circle text-green-700 mr-2"></i>Valider <?= $moisNoms[$mois] ?> <?= $annee ?> ?
                    </h2>
                    <p class="text-sm text-slate-500 mb-4">
                        Les montants ci-dessous seront figés pour <strong><?= htmlspecialchars($client['nom']) ?></strong>.
                        Le dossier passera au statut « Validé ».
                    </p>
                    <dl class="bg-slate-50 rounded-lg p-4 mb-5 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Chiffre d'affaires global</dt>
                            <dd class="font-semibold text-slate-800"><?= formatMontant($caGlobal) ?> F</dd>
                        </div>
                        <div class="flex justify-between border-t border-slate-200 pt-2">
                            <dt class="text-slate-700 font-medium">Total des impôts du mois</dt>
                            <dd class="font-bold text-primary-700"><?= formatMontant($totalImpots) ?> F</dd>
                        </div>
                    </dl>
                    <p class="text-xs text-slate-400 mb-4">
                        <i class="fas fa-info-circle mr-1"></i>Un administrateur peut annuler cette validation depuis la fiche client si besoin.
                    </p>
                    <div class="flex justify-end space-x-2">
                        <button type="button" onclick="fermerModalValidation()" class="px-4 py-2 text-slate-600 rounded-lg hover:bg-slate-100">
                            Annuler
                        </button>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="valider_mois">
                            <button type="submit" class="px-4 py-2 bg-green-700 text-white font-semibold rounded-lg hover:bg-green-800">
                                Confirmer la validation
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <script>
                function ouvrirModalValidation() {
                    const m = document.getElementById('modalValidation');
                    m.classList.remove('hidden');
                    const premier = m.querySelector('button, a, [tabindex]');
                    if (premier) premier.focus();
                }
                function fermerModalValidation() {
                    document.getElementById('modalValidation').classList.add('hidden');
                }
            </script>
            <?php endif; ?>
            
            <!-- Cartes résumé -->
            <div class="grid grid-cols-4 gap-3">
                <div class="bg-blue-50 rounded-lg p-3 text-center">
                    <div class="text-blue-600 text-xs font-medium uppercase">Achats HT</div>
                    <div class="text-lg font-bold text-blue-800"><?= formatMontant($totauxAchats['total_ht']) ?> F</div>
                    <div class="text-xs text-blue-500 mt-1">TVA: <?= formatMontant($totauxAchats['total_tva']) ?> F</div>
                </div>
                <div class="bg-green-50 rounded-lg p-3 text-center">
                    <div class="text-green-600 text-xs font-medium uppercase">Chiffre d'Affaires</div>
                    <div class="text-lg font-bold text-green-800"><?= formatMontant($caGlobal) ?> F</div>
                </div>
                <div class="bg-orange-50 rounded-lg p-3 text-center">
                    <div class="text-orange-600 text-xs font-medium uppercase">Total Dépenses</div>
                    <div class="text-lg font-bold text-orange-800"><?= formatMontant($totalDepenses) ?> F</div>
                </div>
                <div class="bg-red-50 rounded-lg p-3 text-center">
                    <div class="text-red-600 text-xs font-medium uppercase">Impôts</div>
                    <div class="text-lg font-bold text-red-800"><?= formatMontant($totalImpots) ?> F</div>
                </div>
            </div>
        </div>

        <!-- ========== 2. CHIFFRE D'AFFAIRES ========== -->
        <div class="card overflow-hidden mb-4 section-box p-0">
            <div class="bg-green-700 text-white px-4 py-2 section-header">
                <i class="fas fa-chart-line mr-2"></i> CHIFFRE D'AFFAIRES
            </div>
            <div class="p-4">
                <table class="w-full text-sm">
                    <tbody>
                        <tr class="border-b">
                            <td class="py-2 text-slate-600">CA Global</td>
                            <td class="py-2 text-right font-bold text-green-700"><?= formatMontant($caGlobal) ?> F</td>
                        </tr>
                        <tr class="border-b">
                            <td class="py-2 text-slate-600">
                                CA Taxable
                                <?php if ($tauxTVADouble): ?>
                                <div class="text-xs ml-4 mt-1">
                                    • Part à 5% : <?= formatMontant($base5) ?> F<br>
                                    • Part à 18% : <?= formatMontant($base18) ?> F
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="py-2 text-right font-medium"><?= formatMontant($caTaxable) ?> F</td>
                        </tr>
                        <tr class="border-b">
                            <td class="py-2 text-slate-600">CA Exonéré</td>
                            <td class="py-2 text-right font-medium"><?= formatMontant($caExonere) ?> F</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- ========== 2b. TVA ========== -->
        <div class="card overflow-hidden mb-4 section-box p-0">
            <div class="bg-purple-600 text-white px-4 py-2 section-header">
                <i class="fas fa-percentage mr-2"></i> TVA
            </div>
            <div class="p-4">
                <table class="w-full text-sm">
                    <tbody>
                        <tr class="border-b tva-collectee-highlight">
                            <td class="py-3 font-bold">TVA COLLECTE</td>
                            <td class="py-3 text-right font-bold"><?= formatMontant($tvaBrute) ?> F</td>
                        </tr>
                        <tr class="border-b bg-red-50">
                            <td class="py-3 font-bold text-red-700">TVA DEDUCTIBLE</td>
                            <td class="py-3 text-right font-bold text-red-700">- <?= formatMontant($tvaDeductions) ?> F</td>
                        </tr>
                        <tr class="bg-purple-100">
                            <td class="py-3 font-bold text-purple-800">TVA NET</td>
                            <td class="py-3 text-right font-bold text-purple-900"><?= formatMontant($tvaNette) ?> F</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ========== 2c. RÉCAPITULATIF DES IMPÔTS ========== -->
        <div class="card overflow-hidden mb-4 mt-4 section-box p-0">
            <div class="bg-red-700 text-white px-4 py-2 section-header">
                <i class="fas fa-file-invoice-dollar mr-2"></i> RÉSUMÉ DES IMPÔTS À PAYER
            </div>
            <div class="p-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b-2 border-slate-100">
                            <th class="text-left py-2 text-slate-600">Nature de l'Impôt</th>
                            <th class="text-right py-2 text-slate-600">Montant Net à Payer</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b">
                            <td class="py-2 text-slate-700">TVA Nette à Payer (Ligne 131)</td>
                            <td class="py-2 text-right"><?= formatMontant($tvaNette) ?> F</td>
                        </tr>
                        <tr class="border-b">
                            <td class="py-2 text-slate-700">ITS (Impôts sur Salaires)</td>
                            <td class="py-2 text-right"><?= formatMontant($its) ?> F</td>
                        </tr>
                        <tr class="border-b">
                            <td class="py-2 text-slate-700">CF (Contribution Forfaitaire)</td>
                            <td class="py-2 text-right"><?= formatMontant($cf) ?> F</td>
                        </tr>
                        <tr class="border-b">
                            <td class="py-2 text-slate-700">TL (Taxe Logement)</td>
                            <td class="py-2 text-right"><?= formatMontant($tl) ?> F</td>
                        </tr>
                        <tr class="border-b">
                            <td class="py-2 text-slate-700">CSS (Contribution Sociale de Solidarité)</td>
                            <td class="py-2 text-right"><?= formatMontant($css) ?> F</td>
                        </tr>
                        <?php if ($rasActif): ?>
                        <tr class="border-b">
                            <td class="py-2 text-slate-700">Retenue BIC/IS</td>
                            <td class="py-2 text-right"><?= formatMontant($ras) ?> F</td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($irfTfActif): ?>
                        <tr class="border-b">
                            <td class="py-2 text-slate-700">TF (Taxe Foncière / Location)</td>
                            <td class="py-2 text-right"><?= formatMontant($tf) ?> F</td>
                        </tr>
                        <tr class="border-b">
                            <td class="py-2 text-slate-700">IRF (Impôt sur Revenus Fonciers)</td>
                            <td class="py-2 text-right"><?= formatMontant($irf) ?> F</td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($locationActif): ?>
                        <tr class="border-b">
                            <td class="py-2 text-slate-700">TVA s/Location</td>
                            <td class="py-2 text-right"><?= formatMontant($tvaLocation) ?> F</td>
                        </tr>
                        <?php endif; ?>
                        <tr class="bg-slate-100">
                            <td class="py-3 font-bold text-slate-800 uppercase">TOTAL IMPÔTS À PAYER</td>
                            <td class="py-3 text-right font-bold text-slate-800"><?= formatMontant($totalImpots) ?> F</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ========== 3. ACHATS FOURNISSEURS ========== -->
        <div class="card overflow-hidden mb-4 section-box p-0">
            <div class="bg-blue-600 text-white px-4 py-2 section-header">
                <i class="fas fa-shopping-cart mr-2"></i> ACHATS FOURNISSEURS
            </div>
            <div class="p-4">
                <?php if (empty($achatsDetailles)): ?>
                <p class="text-slate-500 text-center py-4">Aucun achat ce mois</p>
                <?php else: ?>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b-2 border-blue-200">
                            <th class="text-left py-2 text-slate-600">Fournisseur</th>
                            <th class="text-center py-2 text-slate-600">Type</th>
                            <th class="text-left py-2 text-slate-600">N° Relevé/Facture</th>
                            <th class="text-center py-2 text-slate-600">Date</th>
                            <th class="text-right py-2 text-slate-600">HT</th>
                            <th class="text-right py-2 text-slate-600">TVA</th>
                            <th class="text-right py-2 text-slate-600">TTC</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $currentFournisseur = '';
                        $compteur = 0;
                        foreach ($achatsDetailles as $achat): 
                            $compteur++;
                            $newFournisseur = ($achat['fournisseur_nom'] !== $currentFournisseur);
                            if ($newFournisseur) $currentFournisseur = $achat['fournisseur_nom'];
                        ?>
                        <tr class="border-b border-slate-100 <?= $compteur % 2 === 0 ? 'bg-slate-50' : '' ?>">
                            <td class="py-2 <?= $newFournisseur ? 'font-semibold text-blue-700' : 'pl-4 text-slate-500' ?>">
                                <?= $newFournisseur ? htmlspecialchars($achat['fournisseur_nom']) : '↳' ?>
                            </td>
                            <td class="py-2 text-center">
                                <?php if ($achat['type_document'] === 'facture'): ?>
                                    <span class="inline-block px-2 py-0.5 bg-green-100 text-green-700 text-xs rounded-full font-medium">Facture</span>
                                <?php else: ?>
                                    <span class="inline-block px-2 py-0.5 bg-blue-100 text-blue-700 text-xs rounded-full font-medium">Relevé</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-2 text-slate-700"><?= htmlspecialchars($achat['reference_document'] ?? '-') ?></td>
                            <td class="py-2 text-center text-slate-500 text-xs"><?= $achat['date_document'] ? date('d/m/Y', strtotime($achat['date_document'])) : '-' ?></td>
                            <td class="py-2 text-right"><?= formatMontant($achat['montant_ht']) ?></td>
                            <td class="py-2 text-right"><?= formatMontant($achat['montant_tva']) ?></td>
                            <td class="py-2 text-right font-medium"><?= formatMontant($achat['montant_ttc']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-blue-50 border-t-2 border-blue-200">
                        <tr>
                            <td colspan="4" class="py-2 font-bold">TOTAL ACHATS (<?= count($achatsDetailles) ?> lignes)</td>
                            <td class="py-2 text-right font-bold"><?= formatMontant($totauxAchats['total_ht']) ?></td>
                            <td class="py-2 text-right font-bold"><?= formatMontant($totauxAchats['total_tva']) ?></td>
                            <td class="py-2 text-right font-bold text-blue-700"><?= formatMontant($totauxAchats['total_ttc']) ?> F</td>
                        </tr>
                    </tfoot>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ========== 4. DÉPENSES (incluant impôts) ========== -->
        <div class="card overflow-hidden mb-4 section-box p-0">
            <div class="bg-orange-600 text-white px-4 py-2 section-header">
                <i class="fas fa-receipt mr-2"></i> DÉPENSES (incluant impôts déclarés)
            </div>
            <div class="p-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="text-left py-2 text-slate-600">Nature</th>
                            <th class="text-right py-2 text-slate-600">HT</th>
                            <th class="text-right py-2 text-slate-600">TVA</th>
                            <th class="text-right py-2 text-slate-600">TTC</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Masse Salariale -->
                        <?php if ($masseSalariale > 0): ?>
                        <tr class="border-b border-slate-100 bg-blue-50">
                            <td class="py-2 font-semibold text-blue-700">
                                <i class="fas fa-users mr-1 text-xs"></i> Masse Salariale
                            </td>
                            <td class="py-2 text-right font-medium"><?= formatMontant($masseSalariale) ?></td>
                            <td class="py-2 text-right">0</td>
                            <td class="py-2 text-right font-medium"><?= formatMontant($masseSalariale) ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <!-- Dépenses normales -->
                        <?php if (!empty($depensesParNature)): ?>
                            <?php foreach ($depensesParNature as $nature => $montant): ?>
                            <tr class="border-b border-slate-100">
                                <td class="py-2"><?= htmlspecialchars($nature) ?></td>
                                <td class="py-2 text-right"><?= formatMontant($montant) ?></td>
                                <td class="py-2 text-right">0</td>
                                <td class="py-2 text-right font-medium"><?= formatMontant($montant) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <?php 
                        $totalDepensesAvecSalaires = $totalDepensesNormales + $masseSalariale;
                        ?>
                        <?php if ($totalDepensesNormales > 0 && ($totalImpots > 0 || $masseSalariale > 0)): ?>
                        <!-- Sous-total dépenses + masse salariale -->
                        <tr class="bg-slate-50">
                            <td class="py-2 font-semibold text-slate-700">Sous-total (Dépenses + Salaires)</td>
                            <td class="py-2 text-right font-bold"><?= formatMontant($totalDepensesAvecSalaires) ?></td>
                            <td class="py-2 text-right font-bold">0</td>
                            <td class="py-2 text-right font-bold text-slate-700"><?= formatMontant($totalDepensesAvecSalaires) ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <!-- Impôts Déclarés -->
                        <?php if ($totalImpots > 0): ?>
                        <tr class="bg-red-50">
                            <td colspan="4" class="py-2 font-semibold text-red-700">
                                <i class="fas fa-file-invoice-dollar mr-1"></i> Impôts Déclarés
                            </td>
                        </tr>
                        
                        <?php if ($tvaNette > 0): ?>
                        <tr class="border-b border-slate-100">
                            <td class="py-1 pl-6 text-slate-600">TVA</td>
                            <td class="py-1 text-right"><?= formatMontant($tvaNette) ?></td>
                            <td class="py-1 text-right">-</td>
                            <td class="py-1 text-right"><?= formatMontant($tvaNette) ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php if ($cf > 0): ?>
                        <tr class="border-b border-slate-100">
                            <td class="py-1 pl-6 text-slate-600">CF (Contribution Forfaitaire)</td>
                            <td class="py-1 text-right"><?= formatMontant($cf) ?></td>
                            <td class="py-1 text-right">-</td>
                            <td class="py-1 text-right"><?= formatMontant($cf) ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php if ($tl > 0): ?>
                        <tr class="border-b border-slate-100">
                            <td class="py-1 pl-6 text-slate-600">TL (Taxe Logement)</td>
                            <td class="py-1 text-right"><?= formatMontant($tl) ?></td>
                            <td class="py-1 text-right">-</td>
                            <td class="py-1 text-right"><?= formatMontant($tl) ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php if ($its > 0): ?>
                        <tr class="border-b border-slate-100">
                            <td class="py-1 pl-6 text-slate-600">ITS</td>
                            <td class="py-1 text-right"><?= formatMontant($its) ?></td>
                            <td class="py-1 text-right">-</td>
                            <td class="py-1 text-right"><?= formatMontant($its) ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php if ($css > 0): ?>
                        <tr class="border-b border-slate-100">
                            <td class="py-1 pl-6 text-slate-600">CSS</td>
                            <td class="py-1 text-right"><?= formatMontant($css) ?></td>
                            <td class="py-1 text-right">-</td>
                            <td class="py-1 text-right"><?= formatMontant($css) ?></td>
                        </tr>
                        <?php endif; ?>

                        <?php if ($rasActif && $ras > 0): ?>
                        <tr class="border-b border-slate-100">
                            <td class="py-1 pl-6 text-slate-600">Retenue à la Source BIC/IS</td>
                            <td class="py-1 text-right"><?= formatMontant($ras) ?></td>
                            <td class="py-1 text-right">-</td>
                            <td class="py-1 text-right"><?= formatMontant($ras) ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php if ($irf > 0): ?>
                        <tr class="border-b border-slate-100">
                            <td class="py-1 pl-6 text-slate-600">IRF (Revenus Fonciers)</td>
                            <td class="py-1 text-right"><?= formatMontant($irf) ?></td>
                            <td class="py-1 text-right">-</td>
                            <td class="py-1 text-right"><?= formatMontant($irf) ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php if ($tf > 0): ?>
                        <tr class="border-b border-slate-100">
                            <td class="py-1 pl-6 text-slate-600">TF (Taxe Foncière)</td>
                            <td class="py-1 text-right"><?= formatMontant($tf) ?></td>
                            <td class="py-1 text-right">-</td>
                            <td class="py-1 text-right"><?= formatMontant($tf) ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php if ($tvaLocation > 0): ?>
                        <tr class="border-b border-slate-100">
                            <td class="py-1 pl-6 text-slate-600">TVA/Location</td>
                            <td class="py-1 text-right"><?= formatMontant($tvaLocation) ?></td>
                            <td class="py-1 text-right">-</td>
                            <td class="py-1 text-right"><?= formatMontant($tvaLocation) ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <!-- Sous-total impôts -->
                        <tr class="bg-red-50">
                            <td class="py-2 pl-6 font-semibold text-red-700">Sous-total Impôts</td>
                            <td class="py-2 text-right font-bold text-red-700"><?= formatMontant($totalImpots) ?></td>
                            <td class="py-2 text-right font-bold text-red-700">-</td>
                            <td class="py-2 text-right font-bold text-red-700"><?= formatMontant($totalImpots) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="bg-orange-100">
                        <tr>
                            <td class="py-3 font-bold text-lg">TOTAL DÉPENSES</td>
                            <td class="py-3 text-right font-bold"><?= formatMontant($totalDepenses) ?></td>
                            <td class="py-3 text-right font-bold">0</td>
                            <td class="py-3 text-right font-bold text-lg text-orange-700"><?= formatMontant($totalDepenses) ?> F</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        
        <!-- Pied de page impression -->
        <div class="print-footer" style="margin-top:20px; padding-top:10px; border-top:1px solid #ddd; font-size:8pt; color:#888; text-align:center;">
            3CE FISCUS - Système de Gestion Fiscale | Généré le <?= $dateGeneration ?> | Page 1/1
        </div>

        <!-- Liens (non imprimé) -->
        <div class="mt-6 text-center no-print">
            <a href="clients.php" class="inline-flex items-center text-slate-600 hover:text-primary-600">
                <i class="fas fa-chevron-left mr-2"></i> Voir tous les clients
            </a>
        </div>
    </main>

    <script>
    function changerMois(m) {
        const url = new URL(window.location);
        url.searchParams.set('mois', m);
        window.location.href = url.toString();
    }
    
    function changerAnnee(a) {
        const url = new URL(window.location);
        url.searchParams.set('annee', a);
        window.location.href = url.toString();
    }
    </script>
</body>
</html>


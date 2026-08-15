<?php
/**
 * ============================================
 * GESTION DES IMPÔTS - PAGE CLIENT
 * Calcul et saisie des impôts mensuels
 * ============================================
 */

session_start();
define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/classes/Agent.php';
require_once APP_ROOT . '/classes/Client.php';
require_once APP_ROOT . '/classes/CompteGestionMensuel.php';
require_once APP_ROOT . '/classes/Achat.php';
require_once APP_ROOT . '/classes/Depense.php';
require_once APP_ROOT . '/classes/Impot.php';

// Vérifier l'authentification
if (!Agent::estConnecte() || !Agent::verifierTimeout()) {
    header('Location: ../index.php');
    exit;
}

$agent = Agent::getAgentConnecte();
$db = Database::getInstance();

// Récupérer le client
$clientId = (int) ($_GET['client'] ?? $_POST['client_id'] ?? 0);
if ($clientId <= 0) {
    header('Location: clients.php');
    exit;
}

$client = new Client($clientId);
if (!$client->getId() || !$agent->aAccesClient($clientId)) {
    header('Location: clients.php?msg=Accès non autorisé&type=error');
    exit;
}

// Mois et année
$mois = (int) ($_GET['mois'] ?? date('n'));
$annee = (int) ($_GET['annee'] ?? date('Y'));

// Noms des mois
$moisNoms = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

// Récupérer les paramètres fiscaux du client
$parametresFiscaux = $db->fetchOne(
    "SELECT * FROM parametres_fiscaux WHERE client_id = ?",
    [$clientId]
);
$tauxTVA = $parametresFiscaux ? (float)$parametresFiscaux['taux_tva'] : 18.00;
$tauxTVADecimal = $tauxTVA / 100;
$tauxTVADouble = $parametresFiscaux ? (int)($parametresFiscaux['taux_tva_double'] ?? 0) : 0;
$sansMarges = $parametresFiscaux ? (int)($parametresFiscaux['sans_marges'] ?? 0) : 0;
$locationActif = $parametresFiscaux ? (int)($parametresFiscaux['location_actif'] ?? 0) : 0;
$irfTfActif = $parametresFiscaux ? (int)($parametresFiscaux['irf_tf_actif'] ?? 0) : 0;
$salairesActif = $parametresFiscaux ? (int)($parametresFiscaux['salaires_actif'] ?? 0) : 0;
$rasActif = $parametresFiscaux ? (int)($parametresFiscaux['ras_actif'] ?? 0) : 0;
$typeTva = $parametresFiscaux ? ($parametresFiscaux['type_tva'] ?? 'non_exonere') : 'non_exonere';

// Autres taux fiscaux
$tauxCF = $parametresFiscaux ? (float)($parametresFiscaux['taux_cf'] ?? 3.5) : 3.5;
$tauxTL = $parametresFiscaux ? (float)($parametresFiscaux['taux_tl'] ?? 1) : 1;
$tauxITS = 10; // Souvent variable selon barème, mais gardé ici par défaut
$tauxIRF = $parametresFiscaux ? (float)($parametresFiscaux['taux_irf'] ?? 12) : 12;
$tauxTF = $parametresFiscaux ? (float)($parametresFiscaux['taux_tf'] ?? 3) : 3;
$tauxCSS = $parametresFiscaux ? (float)($parametresFiscaux['taux_css'] ?? 0.5) : 0.5;

// Compte de gestion mensuel
$compteGestion = new CompteGestionMensuel($clientId, $mois, $annee);

// Récupérer les bases de calcul (Masse Salariale, Loyers) de manière robuste
$masseSalariale = (float)($_POST['masse_salariale'] ?? $_GET['masse_salariale'] ?? $compteGestion->getMasseSalariale() ?? 0);
$loyersPercus = (float)($_POST['loyers_percus'] ?? $_GET['loyers_percus'] ?? $compteGestion->getLoyersPercus() ?? 0);

// Récupérer les données pour les calculs
$totauxAchats = Achat::getTotaux($clientId, $mois, $annee);
$achatsHT_Reel = $totauxAchats['total_ht'] ?? 0;
$achatsHT = $totauxAchats['total_ht_ca'] ?? $achatsHT_Reel;
$tvaDeductible = $totauxAchats['tva_deductible'] ?? 0;

// Lignes TVA Location (chargées depuis POST/GET ou base)
$locLigne132 = (float)($_POST['loc_ligne132'] ?? $_GET['loc_ligne132'] ?? $compteGestion->getLocLigne132() ?? 0);
$locLigne133 = (float)($_POST['loc_ligne133'] ?? $_GET['loc_ligne133'] ?? $compteGestion->getLocLigne133() ?? 0);
$locLigne137 = (float)($_POST['loc_ligne137'] ?? $_GET['loc_ligne137'] ?? $compteGestion->getLocLigne137() ?? 0);
$locLigne141 = (float)($_POST['loc_ligne141'] ?? $_GET['loc_ligne141'] ?? $compteGestion->getLocLigne141() ?? 0);
$locLigne145 = (float)($_POST['loc_ligne145'] ?? $_GET['loc_ligne145'] ?? $compteGestion->getLocLigne145() ?? 0);

// Lignes CF (chargées depuis POST/GET ou base)
$cfLigne243 = (float)($_POST['cf_ligne243'] ?? $_GET['cf_ligne243'] ?? $compteGestion->getCfLigne243() ?? 0);
$cfLigne246 = (float)($_POST['cf_ligne246'] ?? $_GET['cf_ligne246'] ?? $compteGestion->getCfLigne246() ?? 0);
$cfLigne247 = (float)($_POST['cf_ligne247'] ?? $_GET['cf_ligne247'] ?? $compteGestion->getCfLigne247() ?? 0);
$cfLigne248 = (float)($_POST['cf_ligne248'] ?? $_GET['cf_ligne248'] ?? $compteGestion->getCfLigne248() ?? 0);
$cfLigne249 = (float)($_POST['cf_ligne249'] ?? $_GET['cf_ligne249'] ?? $compteGestion->getCfLigne249() ?? 0);
$cfLigne250 = (float)($_POST['cf_ligne250'] ?? $_GET['cf_ligne250'] ?? $compteGestion->getCfLigne250() ?? 0);
$cfLigne251 = (float)($_POST['cf_ligne251'] ?? $_GET['cf_ligne251'] ?? $compteGestion->getCfLigne251() ?? 0);

// Ligne TL (chargée depuis POST/GET ou base)
$tlLigne212 = (float)($_POST['tl_ligne212'] ?? $_GET['tl_ligne212'] ?? $compteGestion->getTlLigne212() ?? 0);

// Lignes TVA (chargées depuis POST/GET ou base)
$tvaLigne82 = (float)($_POST['tva_ligne82'] ?? $_GET['tva_ligne82'] ?? $compteGestion->getTvaLigne82() ?? 0);
$tvaLigne83 = (float)($_POST['tva_ligne83'] ?? $_GET['tva_ligne83'] ?? $compteGestion->getTvaLigne83() ?? 0);
$tvaLigne84 = (float)($_POST['tva_ligne84'] ?? $_GET['tva_ligne84'] ?? $compteGestion->getTvaLigne84() ?? 0);
$tvaLigne85 = (float)($_POST['tva_ligne85'] ?? $_GET['tva_ligne85'] ?? $compteGestion->getTvaLigne85() ?? 0);
$tvaLigne86 = (float)($_POST['tva_ligne86'] ?? $_GET['tva_ligne86'] ?? $compteGestion->getTvaLigne86() ?? 0);
$tvaLigne101 = (float)($_POST['tva_ligne101'] ?? $_GET['tva_ligne101'] ?? $compteGestion->getTvaLigne101() ?? 0);
$tvaLigne102 = (float)($_POST['tva_ligne102'] ?? $_GET['tva_ligne102'] ?? $compteGestion->getTvaLigne102() ?? 0);
$tvaLigne103 = (float)($_POST['tva_ligne103'] ?? $_GET['tva_ligne103'] ?? $compteGestion->getTvaLigne103() ?? 0);
$tvaLigne107 = (float)($_POST['tva_ligne107'] ?? $_GET['tva_ligne107'] ?? $compteGestion->getTvaLigne107() ?? 0);
$tvaLigne110 = (float)($_POST['tva_ligne110'] ?? $_GET['tva_ligne110'] ?? $compteGestion->getTvaLigne110() ?? 0);
$tvaLigne113 = (float)($_POST['tva_ligne113'] ?? $_GET['tva_ligne113'] ?? $compteGestion->getTvaLigne113() ?? 0);
$tvaLigne114 = (float)($_POST['tva_ligne114'] ?? $_GET['tva_ligne114'] ?? $compteGestion->getTvaLigne114() ?? 0);
$tvaLigne115 = (float)($_POST['tva_ligne115'] ?? $_GET['tva_ligne115'] ?? $compteGestion->getTvaLigne115() ?? 0);
$tvaLigne116 = (float)($_POST['tva_ligne116'] ?? $_GET['tva_ligne116'] ?? $compteGestion->getTvaLigne116() ?? 0);
$tvaLigne117 = (float)($_POST['tva_ligne117'] ?? $_GET['tva_ligne117'] ?? $compteGestion->getTvaLigne117() ?? 0);
$tvaLigne118 = (float)($_POST['tva_ligne118'] ?? $_GET['tva_ligne118'] ?? $compteGestion->getTvaLigne118() ?? 0);
$tvaLigne120 = (float)($_POST['tva_ligne120'] ?? $_GET['tva_ligne120'] ?? $compteGestion->getTvaLigne120() ?? 0);

// Lignes Retenue à la Source BIC/IS
$rasLigne401 = (float)($_POST['ras_ligne401'] ?? $_GET['ras_ligne401'] ?? $compteGestion->getRasLigne401() ?? 0);
$rasLigne403 = (float)($_POST['ras_ligne403'] ?? $_GET['ras_ligne403'] ?? $compteGestion->getRasLigne403() ?? 0);
$rasLigne404 = (float)($_POST['ras_ligne404'] ?? $_GET['ras_ligne404'] ?? $compteGestion->getRasLigne404() ?? 0);
$rasLigne405 = (float)($_POST['ras_ligne405'] ?? $_GET['ras_ligne405'] ?? $compteGestion->getRasLigne405() ?? 0);
$rasLigne406 = (float)($_POST['ras_ligne406'] ?? $_GET['ras_ligne406'] ?? $compteGestion->getRasLigne406() ?? 0);
$rasLigne411 = (float)($_POST['ras_ligne411'] ?? $_GET['ras_ligne411'] ?? $compteGestion->getRasLigne411() ?? 0);
$rasLigne412 = (float)($_POST['ras_ligne412'] ?? $_GET['ras_ligne412'] ?? $compteGestion->getRasLigne412() ?? 0);
$rasLigne413 = (float)($_POST['ras_ligne413'] ?? $_GET['ras_ligne413'] ?? $compteGestion->getRasLigne413() ?? 0);
$rasLigne418 = (float)($_POST['ras_ligne418'] ?? $_GET['ras_ligne418'] ?? $compteGestion->getRasLigne418() ?? 0);
$rasLigne419 = (float)($_POST['ras_ligne419'] ?? $_GET['ras_ligne419'] ?? $compteGestion->getRasLigne419() ?? 0);
$rasLigne425 = (float)($_POST['ras_ligne425'] ?? $_GET['ras_ligne425'] ?? $compteGestion->getRasLigne425() ?? 0);
$rasCalc = Impot::calculerRetenueSourceBIC([
    '401' => $rasLigne401, '403' => $rasLigne403, '404' => $rasLigne404,
    '405' => $rasLigne405, '406' => $rasLigne406, '411' => $rasLigne411,
    '412' => $rasLigne412, '413' => $rasLigne413, '418' => $rasLigne418,
    '419' => $rasLigne419, '425' => $rasLigne425,
]);
$ras = $rasActif ? (float)($rasCalc['430'] ?? 0) : 0;

// Marges sélectionnées (chargées depuis la base, ou depuis GET/POST si changées)
$margeDefaut = $parametresFiscaux ? (float)($parametresFiscaux['marge'] ?? 1.30) : 1.30;
$margeTaxableDefaut = $parametresFiscaux ? (float)($parametresFiscaux['marge_taxable'] ?? 1.30) : 1.30;

$margeSauvegardee = $compteGestion->getMarge() ?? $margeDefaut;
$margeTaxableSauvegardee = $compteGestion->getMargeTaxable() ?? $margeTaxableDefaut;
$margeSelectionnee = (float) ($_GET['marge'] ?? $_POST['marge'] ?? $margeSauvegardee);
$margeTaxableSelectionnee = (float) ($_GET['marge_taxable'] ?? $_POST['marge_taxable'] ?? $margeTaxableSauvegardee);

// Si client sans marges, forcer les deux marges à 0 (saisie manuelle)
if ($sansMarges) {
    $margeSelectionnee = 0;
    $margeTaxableSelectionnee = 0;
}

// Calculs basés sur les marges
// Achats Taxable = TVA sur Achats / Taux (basé sur le taux du client pour retrouver la base HT taxable)
$tauxTVADecimal = ($tauxTVA > 0) ? ($tauxTVA / 100) : 0.18;
$achatsTaxable = ($tvaDeductible > 0) ? round($tvaDeductible / $tauxTVADecimal) : 0;

if ($margeSelectionnee == 0) {
    // Marge Global = 0 : CA Global saisi manuellement
    $caGlobal = $compteGestion->getCaGlobal();
    $caExonere = $compteGestion->getCaExonere();
} else {
    // Marge Global != 0 : CA Global = Achats HT × Marge
    $caGlobal = round($achatsHT * $margeSelectionnee);
}

if ($margeTaxableSelectionnee == 0) {
    // Marge Taxable = 0 : CA Taxable saisi manuellement
    $caTaxable = $compteGestion->getCaTaxable();
} else {
    // Marge Taxable != 0 : CA Taxable = Achats Taxable × Marge Taxable
    $caTaxable = round($achatsTaxable * $margeTaxableSelectionnee);
}

// Si le client est exonéré à 100%, le CA Taxable est nul
if ($typeTva === 'exonere_total') {
    $caTaxable = 0;
}

// CA Exonéré = CA Global - CA Taxable
if ($margeSelectionnee != 0 || $margeTaxableSelectionnee != 0) {
    if ($typeTva === 'exonere_total') {
        $caExonere = $caGlobal;
    } else {
        $caExonere = max(0, $caGlobal - $caTaxable);
    }
} else {
    $caExonere = $compteGestion->getCaExonere();
}

// Calculs TVA COMPLET (Format Officiel)
if ($tauxTVADouble) {
    // Double taux : ligne 102 (base 5%) et reste (base 18%)
    $ligne102 = $tvaLigne102;
    $val105 = round(($ligne102 + $tvaLigne103) * 5 / 100);
    $ligne106 = max(0, $caTaxable - $ligne102);
    $val109 = round(($ligne106 + $tvaLigne107) * 18 / 100);
} else {
    // Taux unique
    $val105 = ($tauxTVA == 5) ? round(($caTaxable + $tvaLigne103) * 5 / 100) : 0;
    $val109 = ($tauxTVA == 18) ? round(($caTaxable + $tvaLigne107) * 18 / 100) : 0;
}
$tvaBrute = $val105 + $val109 + $tvaLigne110;

// Déductions TVA
$tvaDeductions = $tvaDeductible + $tvaLigne113 + $tvaLigne114 + $tvaLigne115 + $tvaLigne117 + $tvaLigne118 + $tvaLigne120;

$tvaNette = max(0, $tvaBrute - $tvaDeductions);
$tvaCredit = max(0, $tvaDeductions - $tvaBrute);

// Calculs impôts individuels avec prise en compte des exonérations
// CF : masse salariale + avantages - exonérations, arrondi au millier
$cfBrut = $masseSalariale + $cfLigne243;
$cfExonerations = $cfLigne246 + $cfLigne247 + $cfLigne248 + $cfLigne249 + $cfLigne250 + $cfLigne251;
$cfNetImposable = max(0, $cfBrut - $cfExonerations);
$cfBaseArrondie = floor($cfNetImposable / 1000) * 1000;
$cf = round($cfBaseArrondie * $tauxCF / 100);
// TL : masse salariale + avantages, arrondi au millier
$tlBrut = $masseSalariale + $tlLigne212;
$tlBaseArrondie = floor($tlBrut / 1000) * 1000;
$tl = round($tlBaseArrondie * $tauxTL / 100);
$its = (int)($_POST['its'] ?? $_GET['its'] ?? $compteGestion->getIts() ?? 0); // ITS saisi manuellement ou chargé
$tf = $irfTfActif ? round($loyersPercus * $tauxTF / 100) : 0;
$irf = $irfTfActif ? round($loyersPercus * $tauxIRF / 100) : 0;
$css = round($caGlobal * $tauxCSS / 100);
// TVA Location avec prise en compte des exonérations et retenue à la source
$locExonerations = $locLigne132 + $locLigne133 + $locLigne137 + $locLigne141;
$locRevenuPassible = max(0, $loyersPercus - $locExonerations);
$tvaLocationCollectee = round($locRevenuPassible * 18 / 100); // TVA/Location toujours à 18%
$tvaLocation = $locationActif ? max(0, $tvaLocationCollectee - $locLigne145) : 0;

// Total général
$totalImpots = $tvaNette + $cf + $tl + $its + $tf + $irf + $css + $tvaLocation + $ras;

// Type d'impôt sélectionné (avec protection contre les types désactivés)
$typeImpot = $_GET['type'] ?? 'tva';
if (in_array($typeImpot, ['tf', 'irf']) && !$irfTfActif) $typeImpot = 'tva';
if ($typeImpot === 'tva-location' && !$locationActif) $typeImpot = 'tva';
if (in_array($typeImpot, ['cf', 'tl', 'its']) && !$salairesActif) $typeImpot = 'tva';
if ($typeImpot === 'ras' && !$rasActif) $typeImpot = 'tva';

// Traitement formulaire
$message = '';
$messageType = 'success';
$csrfToken = Agent::getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!Agent::verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = 'Session invalide, veuillez reessayer.';
        $messageType = 'error';
        $action = '';
    }
    
    try {
        if ($action === 'valider') {
            $postMarge = (float) ($_POST['marge_value'] ?? $margeSelectionnee);
            $postMargeTaxable = (float) ($_POST['marge_taxable_value'] ?? $margeTaxableSelectionnee);
            $postMasseSalariale = (float) str_replace([' ', ','], ['', '.'], $_POST['masse_salariale'] ?? 0);
            $postLoyersPercus = (float) str_replace([' ', ','], ['', '.'], $_POST['loyers_percus'] ?? 0);
            $postIts = (float) str_replace([' ', ','], ['', '.'], $_POST['its'] ?? 0);
            
            // Lignes TVA Location
            $postLocLigne132 = (float) str_replace([' ', ','], ['', '.'], $_POST['loc_ligne132'] ?? 0);
            $postLocLigne133 = (float) str_replace([' ', ','], ['', '.'], $_POST['loc_ligne133'] ?? 0);
            $postLocLigne137 = (float) str_replace([' ', ','], ['', '.'], $_POST['loc_ligne137'] ?? 0);
            $postLocLigne141 = (float) str_replace([' ', ','], ['', '.'], $_POST['loc_ligne141'] ?? 0);
            $postLocLigne145 = (float) str_replace([' ', ','], ['', '.'], $_POST['loc_ligne145'] ?? 0);
            
            // Lignes CF
            $postCfLigne243 = (float) str_replace([' ', ','], ['', '.'], $_POST['cf_ligne243'] ?? 0);
            $postCfLigne246 = (float) str_replace([' ', ','], ['', '.'], $_POST['cf_ligne246'] ?? 0);
            $postCfLigne247 = (float) str_replace([' ', ','], ['', '.'], $_POST['cf_ligne247'] ?? 0);
            $postCfLigne248 = (float) str_replace([' ', ','], ['', '.'], $_POST['cf_ligne248'] ?? 0);
            $postCfLigne249 = (float) str_replace([' ', ','], ['', '.'], $_POST['cf_ligne249'] ?? 0);
            $postCfLigne250 = (float) str_replace([' ', ','], ['', '.'], $_POST['cf_ligne250'] ?? 0);
            $postCfLigne251 = (float) str_replace([' ', ','], ['', '.'], $_POST['cf_ligne251'] ?? 0);
            
            // Ligne TL
            $postTlLigne212 = (float) str_replace([' ', ','], ['', '.'], $_POST['tl_ligne212'] ?? 0);
            
            // Lignes TVA
            $postTvaLigne82 = (float) str_replace([' ', ','], ['', '.'], $_POST['tva_ligne82'] ?? 0);
            $postTvaLigne83 = (float) str_replace([' ', ','], ['', '.'], $_POST['tva_ligne83'] ?? 0);
            $postTvaLigne84 = (float) str_replace([' ', ','], ['', '.'], $_POST['tva_ligne84'] ?? 0);
            $postTvaLigne85 = (float) str_replace([' ', ','], ['', '.'], $_POST['tva_ligne85'] ?? 0);
            $postTvaLigne86 = (float) str_replace([' ', ','], ['', '.'], $_POST['tva_ligne86'] ?? 0);
            $postTvaLigne101 = (float) str_replace([' ', ','], ['', '.'], $_POST['tva_ligne101'] ?? 0);
            $postTvaLigne102 = (float) str_replace([' ', ','], ['', '.'], $_POST['tva_ligne102'] ?? 0);
            $postTvaLigne103 = (float) str_replace([' ', ','], ['', '.'], $_POST['tva_ligne103'] ?? 0);
            $postTvaLigne107 = (float) str_replace([' ', ','], ['', '.'], $_POST['tva_ligne107'] ?? 0);
            $postTvaLigne110 = (float) str_replace([' ', ','], ['', '.'], $_POST['tva_ligne110'] ?? 0);
            $postTvaLigne112 = (float) str_replace([' ', ','], ['', '.'], $_POST['tva_ligne112'] ?? 0);
            $postTvaLigne113 = (float) str_replace([' ', ','], ['', '.'], $_POST['tva_ligne113'] ?? 0);
            $postTvaLigne114 = (float) str_replace([' ', ','], ['', '.'], $_POST['tva_ligne114'] ?? 0);
            $postTvaLigne115 = (float) str_replace([' ', ','], ['', '.'], $_POST['tva_ligne115'] ?? 0);
            $postTvaLigne116 = (float) str_replace([' ', ','], ['', '.'], $_POST['tva_ligne116'] ?? 0);
            $postTvaLigne117 = (float) str_replace([' ', ','], ['', '.'], $_POST['tva_ligne117'] ?? 0);
            $postTvaLigne118 = (float) str_replace([' ', ','], ['', '.'], $_POST['tva_ligne118'] ?? 0);
            $postTvaLigne120 = (float) str_replace([' ', ','], ['', '.'], $_POST['tva_ligne120'] ?? 0);

            // Lignes Retenue à la Source
            $postRasLigne401 = (float) str_replace([' ', ','], ['', '.'], $_POST['ras_ligne401'] ?? 0);
            $postRasLigne403 = (float) str_replace([' ', ','], ['', '.'], $_POST['ras_ligne403'] ?? 0);
            $postRasLigne404 = (float) str_replace([' ', ','], ['', '.'], $_POST['ras_ligne404'] ?? 0);
            $postRasLigne405 = (float) str_replace([' ', ','], ['', '.'], $_POST['ras_ligne405'] ?? 0);
            $postRasLigne406 = (float) str_replace([' ', ','], ['', '.'], $_POST['ras_ligne406'] ?? 0);
            $postRasLigne411 = (float) str_replace([' ', ','], ['', '.'], $_POST['ras_ligne411'] ?? 0);
            $postRasLigne412 = (float) str_replace([' ', ','], ['', '.'], $_POST['ras_ligne412'] ?? 0);
            $postRasLigne413 = (float) str_replace([' ', ','], ['', '.'], $_POST['ras_ligne413'] ?? 0);
            $postRasLigne418 = (float) str_replace([' ', ','], ['', '.'], $_POST['ras_ligne418'] ?? 0);
            $postRasLigne419 = (float) str_replace([' ', ','], ['', '.'], $_POST['ras_ligne419'] ?? 0);
            $postRasLigne425 = (float) str_replace([' ', ','], ['', '.'], $_POST['ras_ligne425'] ?? 0);
            
            $compteGestion->setCaGlobal((float) str_replace([' ', ','], ['', '.'], $_POST['ca_global'] ?? 0))
                          ->setCaExonere((float) str_replace([' ', ','], ['', '.'], $_POST['ca_exonere'] ?? 0))
                          ->setMasseSalariale($postMasseSalariale)
                          ->setLoyersPercus($postLoyersPercus)
                          ->setLocLigne132($postLocLigne132)
                          ->setLocLigne133($postLocLigne133)
                          ->setLocLigne137($postLocLigne137)
                          ->setLocLigne141($postLocLigne141)
                          ->setLocLigne145($postLocLigne145)
                          ->setCfLigne243($postCfLigne243)
                          ->setCfLigne246($postCfLigne246)
                          ->setCfLigne247($postCfLigne247)
                          ->setCfLigne248($postCfLigne248)
                          ->setCfLigne249($postCfLigne249)
                          ->setCfLigne250($postCfLigne250)
                          ->setCfLigne251($postCfLigne251)
                          ->setTlLigne212($postTlLigne212)
                          ->setTvaLigne82($postTvaLigne82)
                          ->setTvaLigne83($postTvaLigne83)
                          ->setTvaLigne84($postTvaLigne84)
                          ->setTvaLigne85($postTvaLigne85)
                          ->setTvaLigne86($postTvaLigne86)
                          ->setTvaLigne101($postTvaLigne101)
                          ->setTvaLigne102($postTvaLigne102)
                          ->setTvaLigne103($postTvaLigne103)
                          ->setTvaLigne107($postTvaLigne107)
                          ->setTvaLigne110($postTvaLigne110)
                          ->setTvaLigne112($postTvaLigne112)
                          ->setTvaLigne113($postTvaLigne113)
                          ->setTvaLigne114($postTvaLigne114)
                          ->setTvaLigne115($postTvaLigne115)
                          ->setTvaLigne116($postTvaLigne116)
                          ->setTvaLigne117($postTvaLigne117)
                          ->setTvaLigne118($postTvaLigne118)
                          ->setTvaLigne120($postTvaLigne120)
                          ->setRasLigne401($postRasLigne401)
                          ->setRasLigne403($postRasLigne403)
                          ->setRasLigne404($postRasLigne404)
                          ->setRasLigne405($postRasLigne405)
                          ->setRasLigne406($postRasLigne406)
                          ->setRasLigne411($postRasLigne411)
                          ->setRasLigne412($postRasLigne412)
                          ->setRasLigne413($postRasLigne413)
                          ->setRasLigne418($postRasLigne418)
                          ->setRasLigne419($postRasLigne419)
                          ->setRasLigne425($postRasLigne425)
                          ->setIts($postIts)
                          ->setMarge($postMarge)
                          ->setMargeTaxable($postMargeTaxable);
            
            // Si marge taxable = 0, écraser le CA Taxable avec la valeur saisie manuellement
            // Correction : Supporte 'ca_taxable' (hidden) et 'ca_taxable_manual'
            $postCaTaxable = (float) str_replace([' ', ','], ['', '.'], $_POST['ca_taxable_manual'] ?? $_POST['ca_taxable'] ?? 0);
            if ($postCaTaxable > 0) {
                $compteGestion->setCaTaxable($postCaTaxable);
            }
            
            $compteGestion->sauvegarder();

            // RECALCULER les totaux avec les nouvelles valeurs pour la table impots_mensuels
            // (on utilise les variables postées pour être précis)
            $ca_global_new = (float) str_replace([' ', ','], ['', '.'], $_POST['ca_global_manual'] ?? $_POST['ca_global'] ?? 0);
            $ca_taxable_new = $postCaTaxable;
            
            // CF
            $cf_brut_new = $postMasseSalariale + $postCfLigne243;
            $cf_exo_new = $postCfLigne246 + $postCfLigne247 + $postCfLigne248 + $postCfLigne249 + $postCfLigne250 + $postCfLigne251;
            $cf_base_new = floor(max(0, $cf_brut_new - $cf_exo_new) / 1000) * 1000;
            $cf_new = round($cf_base_new * $tauxCF / 100);
            
            // TL
            $tl_brut_new = $postMasseSalariale + $postTlLigne212;
            $tl_base_new = floor($tl_brut_new / 1000) * 1000;
            $tl_new = round($tl_base_new * $tauxTL / 100);
            
            // TVA
            if ($tauxTVADouble) {
                $base5 = $postTvaLigne102;
                $v105 = round(($base5 + $postTvaLigne103) * 5 / 100);
                $base18 = max(0, $ca_taxable_new - $base5);
                $v109 = round(($base18 + $postTvaLigne107) * 18 / 100);
            } else {
                $v105 = ($tauxTVA == 5) ? round(($ca_taxable_new + $postTvaLigne103) * 5 / 100) : 0;
                $v109 = ($tauxTVA == 18) ? round(($ca_taxable_new + $postTvaLigne107) * 18 / 100) : 0;
            }
            // Alignement avec la declaration TVA: ligne111 = 105 + 109 + 110, ligne125 = 119 + 120
            $tva_brute_new = $v105 + $v109 + $postTvaLigne110;
            $tva_deduc_new = $postTvaLigne112 + $postTvaLigne113 + $postTvaLigne114 + $postTvaLigne115 + $postTvaLigne116 + $postTvaLigne117 + $postTvaLigne118 + $postTvaLigne120;
            $tva_nette_new = max(0, $tva_brute_new - $tva_deduc_new);
            
            // TVA LOC
            $loc_exo_new = $postLocLigne132 + $postLocLigne133 + $postLocLigne137 + $postLocLigne141;
            $loc_base_new = max(0, $postLoyersPercus - $loc_exo_new);
            $tva_loc_new = $locationActif ? max(0, round($loc_base_new * 18 / 100) - $postLocLigne145) : 0;
            
            $tf_new = $irfTfActif ? round($postLoyersPercus * $tauxTF / 100) : 0;
            $irf_new = $irfTfActif ? round($postLoyersPercus * $tauxIRF / 100) : 0;
            $css_new = round($ca_global_new * $tauxCSS / 100);

            $ras_new = $rasActif ? Impot::calculerRetenueSourceBIC([
                '401' => $postRasLigne401, '403' => $postRasLigne403, '404' => $postRasLigne404,
                '405' => $postRasLigne405, '406' => $postRasLigne406, '411' => $postRasLigne411,
                '412' => $postRasLigne412, '413' => $postRasLigne413, '418' => $postRasLigne418,
                '419' => $postRasLigne419, '425' => $postRasLigne425,
            ])['430'] : 0;
            
            $total_new = $tva_nette_new + $cf_new + $tl_new + $postIts + $tf_new + $irf_new + $css_new + $tva_loc_new + $ras_new;

            // Mise à jour de la table impots_mensuels pour les rapports
            $sqlCheck = "SELECT id FROM impots_mensuels WHERE client_id = ? AND mois = ? AND annee = ?";
            $existingImpots = $db->fetchOne($sqlCheck, [$clientId, $mois, $annee]);
            
            if ($existingImpots) {
                $sqlImpots = "UPDATE impots_mensuels SET 
                    tva_a_payer = ?, cf = ?, its = ?, tl = ?, irf = ?, tf = ?, css = ?, tva_location = ?, ras = ?, total_impots = ?,
                    date_calcul = CURRENT_TIMESTAMP
                    WHERE id = ?";
                $db->update($sqlImpots, [$tva_nette_new, $cf_new, $postIts, $tl_new, $irf_new, $tf_new, $css_new, $tva_loc_new, $ras_new, $total_new, $existingImpots['id']]);
            } else {
                $sqlImpots = "INSERT INTO impots_mensuels (client_id, compte_gestion_id, mois, annee, tva_a_payer, cf, its, tl, irf, tf, css, tva_location, ras, total_impots) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $db->insert($sqlImpots, [$clientId, $compteGestion->getId(), $mois, $annee, $tva_nette_new, $cf_new, $postIts, $tl_new, $irf_new, $tf_new, $css_new, $tva_loc_new, $ras_new, $total_new]);
            }
            
            $message = 'Données enregistrées avec succès.';
            header("Location: impots.php?client=$clientId&mois=$mois&annee=$annee&type=$typeImpot&marge=$postMarge&marge_taxable=$postMargeTaxable&msg=" . urlencode($message));
            exit;
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $messageType = $_GET['msgtype'] ?? 'success';
}

// Formatage des montants
function formatMontant($montant) {
    return number_format((float)$montant, 0, ',', ' ');
}

// Sécurité : S'assurer que les bases sont toujours définies pour la suite du script
if (!isset($masseSalariale)) { $masseSalariale = 0; }
if (!isset($loyersPercus)) { $loyersPercus = 0; }

$pageTitle = "Gestion des Impôts - " . $client->getNom();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
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

    <!-- Sous-header avec breadcrumb et sélecteur de mois -->
    <div class="bg-white border-b shadow-sm">
        <div class="max-w-7xl mx-auto px-4 py-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center text-sm">
                    <a href="dashboard.php" class="text-slate-500 hover:text-slate-700">
                        <i class="fas fa-home mr-1"></i> CABINET FISCAL
                    </a>
                    <span class="mx-2 text-slate-400">|</span>
                    <span class="text-primary-600 font-medium"><?= htmlspecialchars($client->getNom()) ?></span>
                </div>
                
                <!-- Sélecteur de mois -->
                <div class="flex items-center space-x-2">
                    <a href="?client=<?= $clientId ?>&mois=<?= $mois == 1 ? 12 : $mois - 1 ?>&annee=<?= $mois == 1 ? $annee - 1 : $annee ?>&type=<?= $typeImpot ?>" 
                       class="px-2 py-1 bg-slate-100 rounded hover:bg-slate-200" title="Mois précédent">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <select onchange="changerMoisImpot(this.value)" class="px-3 py-1 border rounded bg-white text-sm">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m == $mois ? 'selected' : '' ?>><?= $moisNoms[$m] ?></option>
                        <?php endfor; ?>
                    </select>
                    <select onchange="changerAnneeImpot(this.value)" class="px-3 py-1 border rounded bg-white text-sm">
                        <?php for ($a = date('Y') - 5; $a <= date('Y') + 5; $a++): ?>
                        <option value="<?= $a ?>" <?= $a == $annee ? 'selected' : '' ?>><?= $a ?></option>
                        <?php endfor; ?>
                    </select>
                    <a href="?client=<?= $clientId ?>&mois=<?= $mois == 12 ? 1 : $mois + 1 ?>&annee=<?= $mois == 12 ? $annee + 1 : $annee ?>&type=<?= $typeImpot ?>" 
                       class="px-2 py-1 bg-slate-100 rounded hover:bg-slate-200" title="Mois suivant">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation par onglets -->
    <div class="bg-white border-b">
        <div class="max-w-7xl mx-auto px-4">
            <nav class="flex space-x-1">
                <a href="achats.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" 
                   class="px-4 py-3 text-sm font-medium text-slate-600 hover:text-slate-800 hover:bg-slate-50">
                    ACHATS
                </a>
                <a href="depenses.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" 
                   class="px-4 py-3 text-sm font-medium text-slate-600 hover:text-slate-800 hover:bg-slate-50">
                    DÉPENSES
                </a>
                <a href="impots.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" 
                   class="px-4 py-3 text-sm font-medium text-white bg-primary-600 rounded-t-lg">
                    IMPÔTS
                </a>
                <a href="recapitulatif.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" 
                   class="px-4 py-3 text-sm font-medium text-slate-600 hover:text-slate-800 hover:bg-slate-50">
                    RÉCAPITULATIF
                </a>
            </nav>
        </div>
    </div>

    <main class="max-w-7xl mx-auto px-4 py-2">
        <!-- Message -->
        <?php if ($message): ?>
        <div class="mb-4 p-4 rounded-lg <?= $messageType === 'error' ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <!-- Sélecteur de type d'impôt et Marge -->
        <div class="mb-6 bg-white rounded-xl shadow-sm border p-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <!-- Sélecteur Impôt -->
                <div class="flex items-center gap-3">
                    <div class="flex items-center justify-center w-10 h-10 bg-primary-100 rounded-lg">
                        <i class="fas fa-file-invoice-dollar text-primary-600"></i>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500 uppercase tracking-wide">Type d'impôt</label>
                        <select id="typeImpot" onchange="changerType(this.value)" 
                                class="block w-full mt-1 px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 bg-white font-medium text-slate-700">
                            <option value="tva" <?= $typeImpot === 'tva' ? 'selected' : '' ?>>TVA</option>
                            <?php if ($salairesActif): ?>
                            <option value="cf" <?= $typeImpot === 'cf' ? 'selected' : '' ?>>CF (Contribution Forfaitaire)</option>
                            <option value="tl" <?= $typeImpot === 'tl' ? 'selected' : '' ?>>TL (Taxe Logement)</option>
                            <option value="its" <?= $typeImpot === 'its' ? 'selected' : '' ?>>ITS (Impôt sur Traitements et Salaires)</option>
                            <?php endif; ?>
                            <?php if ($irfTfActif): ?>
                            <option value="tf" <?= $typeImpot === 'tf' ? 'selected' : '' ?>>TF (Taxe Foncière)</option>
                            <option value="irf" <?= $typeImpot === 'irf' ? 'selected' : '' ?>>IRF (Impôt Revenus Fonciers)</option>
                            <?php endif; ?>
                            <option value="css" <?= $typeImpot === 'css' ? 'selected' : '' ?>>CSS (Contribution Spéciale de Solidarité)</option>
                            <?php if ($rasActif): ?>
                            <option value="ras" <?= $typeImpot === 'ras' ? 'selected' : '' ?>>Retenue à la Source BIC/IS</option>
                            <?php endif; ?>
                            <?php if ($locationActif): ?>
                            <option value="tva-location" <?= $typeImpot === 'tva-location' ? 'selected' : '' ?>>TVA/LOCATION</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <?php if ($typeImpot === 'tva'): ?>
                <?php if ($sansMarges): ?>
                <!-- Mode sans marges : On n'affiche rien ici pour libérer de l'espace -->
                <?php else: ?>
                <!-- Séparateur vertical -->
                <div class="hidden md:block w-px h-12 bg-slate-200"></div>

                <!-- Section Marge CA Global (uniquement pour TVA) -->
                <div class="flex items-center gap-3">
                    <div class="flex items-center justify-center w-10 h-10 bg-green-100 rounded-lg">
                        <i class="fas fa-percentage text-green-600"></i>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-green-600 uppercase tracking-wide">Marge CA Global</label>
                        <div class="flex mt-1 rounded-lg overflow-hidden border border-green-300">
                            <?php $margesDisponibles = [0, 1.20, 1.27, 1.30, 1.37]; ?>
                            <?php foreach ($margesDisponibles as $i => $m): ?>
                            <label class="marge-btn relative cursor-pointer <?= $i > 0 ? 'border-l border-green-300' : '' ?>">
                                <input type="radio" name="marge" value="<?= $m ?>" class="sr-only peer" onchange="changerMargeGlobal(<?= $m ?>)" <?= $margeSelectionnee == $m ? 'checked' : '' ?>>
                                <span class="block px-3 py-2 text-sm font-semibold text-slate-600 bg-white hover:bg-green-50 peer-checked:bg-green-600 peer-checked:text-white transition-all"><?= $m ?></span>
                            </label>
                            <?php endforeach; ?>
                            <div class="flex items-center bg-white px-2 border-l border-green-300">
                                <input type="number" step="0.001" placeholder="Autre" 
                                       class="w-20 py-1 text-sm border-none focus:ring-0 text-right font-semibold"
                                       value="<?= !in_array($margeSelectionnee, $margesDisponibles) ? $margeSelectionnee : '' ?>"
                                       onchange="changerMargeGlobal(this.value)">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section Marge CA Taxable -->
                <div class="flex items-center gap-3">
                    <div class="flex items-center justify-center w-10 h-10 bg-purple-100 rounded-lg">
                        <i class="fas fa-percentage text-purple-600"></i>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-purple-600 uppercase tracking-wide">Marge CA Taxable</label>
                        <div class="flex mt-1 rounded-lg overflow-hidden border border-purple-300">
                            <?php foreach ($margesDisponibles as $i => $m): ?>
                            <label class="marge-btn relative cursor-pointer <?= $i > 0 ? 'border-l border-purple-300' : '' ?>">
                                <input type="radio" name="marge_taxable" value="<?= $m ?>" class="sr-only peer" onchange="changerMargeTaxable(<?= $m ?>)" <?= $margeTaxableSelectionnee == $m ? 'checked' : '' ?>>
                                <span class="block px-3 py-2 text-sm font-semibold text-slate-600 bg-white hover:bg-purple-50 peer-checked:bg-purple-600 peer-checked:text-white transition-all"><?= $m ?></span>
                            </label>
                            <?php endforeach; ?>
                            <div class="flex items-center bg-white px-2 border-l border-purple-300">
                                <input type="number" step="0.001" placeholder="Autre" 
                                       class="w-20 py-1 text-sm border-none focus:ring-0 text-right font-semibold"
                                       value="<?= !in_array($margeTaxableSelectionnee, $margesDisponibles) ? $margeTaxableSelectionnee : '' ?>"
                                       onchange="changerMargeTaxable(this.value)">
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Affichage Achats HT -->
                <div class="hidden md:block w-px h-12 bg-slate-200"></div>
                <div class="flex items-center gap-3">
                    <div class="flex items-center justify-center w-10 h-10 bg-amber-100 rounded-lg">
                        <i class="fas fa-shopping-cart text-amber-600"></i>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500 uppercase tracking-wide">Achats HT</label>
                        <div class="mt-1 text-lg font-bold text-slate-700"><?= formatMontant($achatsHT) ?> F</div>
                    </div>
                </div>

                <!-- Affichage TVA sur Achats (uniquement pour TVA) -->
                <div class="hidden md:block w-px h-12 bg-slate-200"></div>
                <div class="flex items-center gap-3">
                    <div class="flex items-center justify-center w-10 h-10 bg-red-100 rounded-lg">
                        <i class="fas fa-receipt text-red-600"></i>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500 uppercase tracking-wide">TVA sur Achats</label>
                        <div class="mt-1 text-lg font-bold text-red-600"><?= formatMontant($tvaDeductible) ?> F</div>
                    </div>
                </div>

                <!-- Affichage Achats Taxable (uniquement pour TVA) -->
                <div class="hidden md:block w-px h-12 bg-slate-200"></div>
                <div class="flex items-center gap-3">
                    <div class="flex items-center justify-center w-10 h-10 bg-purple-100 rounded-lg">
                        <i class="fas fa-calculator text-purple-600"></i>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500 uppercase tracking-wide">Achats Taxable</label>
                        <div class="mt-1 text-sm font-bold text-purple-600"><?= formatMontant($achatsTaxable) ?> F</div>
                        <div class="text-xs text-slate-400">= TVA / <?= $tauxTVADecimal ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST" id="formImpots">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="valider">
            <input type="hidden" name="client_id" value="<?= $clientId ?>">
            <input type="hidden" name="marge_value" id="hiddenMarge" value="<?= $margeSelectionnee ?>">
            <input type="hidden" name="marge_taxable_value" id="hiddenMargeTaxable" value="<?= $margeTaxableSelectionnee ?>">
            <!-- Toujours envoyer les valeurs CA calculées -->
            <input type="hidden" name="ca_global" id="hiddenCaGlobal" value="<?= $caGlobal ?>">
            <input type="hidden" name="ca_exonere" id="hiddenCaExonere" value="<?= $caExonere ?>">
            <input type="hidden" name="ca_taxable" id="hiddenCaTaxable" value="<?= $caTaxable ?>">

            <!-- Section Bases de calcul -->
            <?php if ($salairesActif || $locationActif || $irfTfActif): ?>
            <div class="bg-white rounded-xl shadow-sm border mb-6 overflow-hidden">
                <div class="bg-slate-100 border-b border-slate-200 p-4 flex items-center justify-between cursor-pointer hover:bg-slate-200 transition" onclick="toggleSectionBases()">
                    <h3 class="text-lg font-semibold text-slate-700">
                        <i class="fas fa-sliders-h text-slate-500 mr-2"></i>
                        Bases de calcul mensuelles
                    </h3>
                    <div class="flex items-center gap-2">
                        <span id="labelStatusBases" class="text-xs font-medium text-slate-500 uppercase">Réduire</span>
                        <i id="iconToggleBases" class="fas fa-chevron-up text-slate-400 transform transition-transform duration-300"></i>
                    </div>
                </div>
                <div id="contentBasesCalcul" class="p-6 transition-all duration-300">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-3xl mx-auto">
                        <!-- Masse Salariale -->
                        <?php if ($salairesActif): ?>
                        <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                            <label class="block text-sm font-medium text-blue-700 mb-2">
                                <i class="fas fa-users mr-1"></i> Masse Salariale
                            </label>
                            <div class="flex items-center">
                                <input type="number" name="masse_salariale" id="inputMasseSalariale"
                                       class="flex-1 px-4 py-2 border-2 border-blue-300 rounded-lg text-right font-bold text-blue-800 bg-white focus:ring-2 focus:ring-blue-500" 
                                       value="<?= $masseSalariale ?>" min="0" step="1">
                                <span class="ml-2 text-blue-600 font-medium">F CFA</span>
                            </div>
                            <p class="text-xs text-blue-500 mt-1">Utilisée pour CF, TL</p>
                        </div>
                        <?php endif; ?>

                        <!-- Valeur Locative / Loyers Perçus -->
                        <?php if ($locationActif || $irfTfActif): ?>
                        <div class="bg-green-50 p-4 rounded-lg border border-green-200">
                            <label class="block text-sm font-medium text-green-700 mb-2">
                                <i class="fas fa-home mr-1"></i> Loyers Perçus (Valeur Locative)
                            </label>
                            <div class="flex items-center">
                                <input type="number" name="loyers_percus" id="inputLoyersPercus"
                                       class="flex-1 px-4 py-2 border-2 border-green-300 rounded-lg text-right font-bold text-green-800 bg-white focus:ring-2 focus:ring-green-500" 
                                       value="<?= $loyersPercus ?>" min="0" step="1">
                                <span class="ml-2 text-green-600 font-medium">F CFA</span>
                            </div>
                            <p class="text-xs text-green-500 mt-1">Utilisée pour <?= $irfTfActif ? 'TF, IRF' : '' ?><?= ($irfTfActif && $locationActif) ? ', ' : '' ?><?= $locationActif ? 'TVA/Location' : '' ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mt-4 text-center">
                        <button type="button" onclick="recalcAll()" class="inline-flex items-center px-6 py-2 bg-primary-600 text-white font-medium rounded-lg hover:bg-primary-700 transition">
                            <i class="fas fa-calculator mr-2"></i> Recalculer les impôts
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Section TVA - Format Officiel -->
            <?php
            // Pré-calculs pour le formulaire officiel TVA avec valeurs sauvegardées
            $ligne80 = $caGlobal;
            $ligne81 = $caExonere; // Article 195 CGI
            $ligne82 = $tvaLigne82; // Exportation
            $ligne83 = $tvaLigne83; // Code Investissements
            $ligne84 = $tvaLigne84; // Microfinance
            $ligne85 = $tvaLigne85; // Conventions Internationales
            $ligne86 = $tvaLigne86; // Autres exonérations
            $ligne95 = $ligne81 + $ligne82 + $ligne83 + $ligne84 + $ligne85 + $ligne86;
            $ligne100 = max(0, $ligne80 - $ligne95);
            $ligne101 = $tvaLigne101; // Livraison à soi-même imposable
            // Taux réduit 5%
            if ($tauxTVADouble) {
                // Double taux : ligne 102 saisie manuellement, ligne 106 = reste
                $ligne102 = $tvaLigne102;
                $ligne103 = $tvaLigne103;
                $ligne104 = $ligne102 + $ligne103;
                $ligne105 = round($ligne104 * 5 / 100);
                $ligne106 = max(0, $ligne100 - $ligne102);
                $ligne107 = $tvaLigne107;
                $ligne108 = $ligne106 + $ligne107;
                $ligne109 = round($ligne108 * 18 / 100);
            } else {
                // Taux unique
                $ligne102 = ($tauxTVA == 5) ? $ligne100 : 0;
                $ligne103 = $tvaLigne103;
                $ligne104 = $ligne102 + $ligne103;
                $ligne105 = round($ligne104 * 5 / 100);
                $ligne106 = ($tauxTVA == 18) ? $ligne100 : 0;
                $ligne107 = $tvaLigne107;
                $ligne108 = $ligne106 + $ligne107;
                $ligne109 = round($ligne108 * 18 / 100);
            }
            $ligne110 = $tvaLigne110; // Reversement régularisation
            $ligne111 = $ligne105 + $ligne109 + $ligne110;
            // TVA Déductible
            $ligne112 = $tvaDeductible; // Achats locaux
            $ligne113 = $tvaLigne113; // Importations
            $ligne114 = $tvaLigne114; // Prorata achats locaux
            $ligne115 = $tvaLigne115; // Prorata importations
            $ligne116 = $tvaLigne116; // Retenue Trésor
            $ligne117 = $tvaLigne117; // Complément régularisation
            $ligne118 = $tvaLigne118; // Retenue clients
            $ligne119 = $ligne112 + $ligne113 + $ligne114 + $ligne115 + $ligne116 + $ligne117 + $ligne118;
            $ligne120 = $tvaLigne120; // Report crédit mois précédents
            $ligne125 = $ligne119 + $ligne120;
            $ligne131_tva = max(0, $ligne111 - $ligne125);
            $ligne132_tva = max(0, $ligne125 - $ligne111);
            $ligne133_tva = 0;
            ?>
            <div id="section-tva" class="<?= $typeImpot !== 'tva' ? 'hidden' : '' ?>">
                <!-- En-tête info (Exonération / Marges) -->
                <?php if ($typeTva === 'exonere_total' || !$sansMarges): ?>
                <div class="bg-white rounded-xl shadow-sm border mb-4">
                    <div class="p-3 flex items-center justify-between flex-wrap gap-2">
                        <div class="flex items-center gap-2 text-sm">
                            <i class="fas fa-info-circle text-primary-500"></i>
                            <?php if ($typeTva === 'exonere_total'): ?>
                            <span class="px-2 py-0.5 bg-red-100 text-red-700 text-xs font-semibold rounded"><i class="fas fa-ban mr-1"></i> EXONÉRÉ À 100% (TVA)</span>
                            <?php endif; ?>
                            
                            <?php if (!$sansMarges): ?>
                            <span class="text-slate-600">Marges :</span>
                            <?php if ($margeSelectionnee == 0): ?>
                            <span class="px-2 py-0.5 bg-amber-100 text-amber-700 text-xs font-semibold rounded">Global: Saisie manuelle</span>
                            <?php else: ?>
                            <span class="px-2 py-0.5 bg-green-100 text-green-700 text-xs font-semibold rounded">Global: <?= $margeSelectionnee ?></span>
                            <?php endif; ?>
                            <?php if ($margeTaxableSelectionnee == 0): ?>
                            <span class="px-2 py-0.5 bg-purple-100 text-purple-700 text-xs font-semibold rounded">Taxable: Saisie manuelle</span>
                            <?php else: ?>
                            <span class="px-2 py-0.5 bg-purple-100 text-purple-700 text-xs font-semibold rounded">Taxable: <?= $margeTaxableSelectionnee ?></span>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <style>
                    .tva-form { width: 100%; border-collapse: collapse; font-size: 13px; }
                    .tva-form th { background: #1e40af; color: #fff; padding: 8px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
                    .tva-form th.col-ligne { width: 55px; text-align: center; }
                    .tva-form th.col-montant { width: 180px; text-align: right; }
                    .tva-form td { padding: 6px 12px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
                    .tva-form td.td-ligne { text-align: center; font-weight: 700; color: #1e40af; font-size: 12px; background: #eff6ff; }
                    .tva-form td.td-montant { text-align: right; font-family: 'Consolas', monospace; font-size: 13px; }
                    .tva-form tr.row-section td { background: #f1f5f9; font-weight: 700; color: #334155; padding: 8px 12px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.3px; }
                    .tva-form tr.row-total td { background: #dbeafe; font-weight: 700; border-top: 2px solid #3b82f6; }
                    .tva-form tr.row-result td { background: #dcfce7; font-weight: 700; border-top: 2px solid #16a34a; font-size: 14px; }
                    .tva-form tr.row-credit td { background: #fef3c7; font-weight: 600; }
                    .tva-form tr.row-sub td { color: #64748b; font-size: 12px; }
                    .tva-form .input-manual { width: 150px; padding: 4px 8px; border: 2px solid #f59e0b; border-radius: 4px; text-align: right; font-weight: 600; background: #fffbeb; font-size: 13px; font-family: 'Consolas', monospace; }
                    .tva-form .input-manual:focus { outline: none; border-color: #d97706; box-shadow: 0 0 0 2px rgba(217,119,6,0.2); }
                    .tva-form .input-manual-purple { border-color: #a855f7; background: #faf5ff; }
                    .tva-form .input-manual-purple:focus { border-color: #9333ea; box-shadow: 0 0 0 2px rgba(147,51,234,0.2); }
                </style>

                <div class="bg-white rounded-xl shadow-sm border overflow-hidden mb-6">
                    <table class="tva-form">
                        <thead>
                            <tr>
                                <th class="col-ligne">Ligne</th>
                                <th>Désignation</th>
                                <th class="col-montant">Montant</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- ====== SECTION CA ====== -->
                            <tr class="row-section"><td colspan="3"><i class="fas fa-chart-line mr-1"></i> Chiffre d'Affaires</td></tr>

                            <tr>
                                <td class="td-ligne">80</td>
                                <td>Chiffre d'Affaires Global Hors TVA Réalisé</td>
                                <td class="td-montant">
                                    <?php if ($margeSelectionnee == 0): ?>
                                    <input type="text" name="ca_global" id="ca_global_input" class="input-manual" value="<?= formatMontant($ligne80) ?>" oninput="updateSummary()">
                                    <?php else: ?>
                                    <?= formatMontant($ligne80) ?>
                                    <input type="hidden" id="ca_global_input" value="<?= $ligne80 ?>">
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="td-ligne">81</td>
                                <td>CA Exonéré Sauf Exportation - Article 195 CGI</td>
                                <td class="td-montant">
                                    <?php if ($margeSelectionnee == 0): ?>
                                    <input type="text" name="ca_exonere" id="ca_exonere" class="input-manual" value="<?= formatMontant($ligne81) ?>">
                                    <?php else: ?>
                                    <?= $ligne81 > 0 ? formatMontant($ligne81) : '' ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr><td class="td-ligne">82</td><td>CA Réalisé à l'Exportation - Article 195.1 CGI</td><td class="td-montant"><input type="number" name="tva_ligne82" id="tva_ligne82" class="input-manual" style="width:150px;" value="<?= $tvaLigne82 > 0 ? $tvaLigne82 : '' ?>" min="0" step="1" oninput="recalcTVA()"></td></tr>
                            <tr><td class="td-ligne">83</td><td>CA Exonéré de TVA - Code des Investissements</td><td class="td-montant"><input type="number" name="tva_ligne83" id="tva_ligne83" class="input-manual" style="width:150px;" value="<?= $tvaLigne83 > 0 ? $tvaLigne83 : '' ?>" min="0" step="1" oninput="recalcTVA()"></td></tr>
                            <tr><td class="td-ligne">84</td><td>CA Exonéré de TVA - Microfinance</td><td class="td-montant"><input type="number" name="tva_ligne84" id="tva_ligne84" class="input-manual" style="width:150px;" value="<?= $tvaLigne84 > 0 ? $tvaLigne84 : '' ?>" min="0" step="1" oninput="recalcTVA()"></td></tr>
                            <tr><td class="td-ligne">85</td><td>CA Exonéré TVA - Conventions Internationales ou Bilatérales, Financements Extérieurs</td><td class="td-montant"><input type="number" name="tva_ligne85" id="tva_ligne85" class="input-manual" style="width:150px;" value="<?= $tvaLigne85 > 0 ? $tvaLigne85 : '' ?>" min="0" step="1" oninput="recalcTVA()"></td></tr>
                            <tr><td class="td-ligne">86</td><td>Autres Exonérations de Chiffre d'Affaires à la TVA</td><td class="td-montant"><input type="number" name="tva_ligne86" id="tva_ligne86" class="input-manual" style="width:150px;" value="<?= $tvaLigne86 > 0 ? $tvaLigne86 : '' ?>" min="0" step="1" oninput="recalcTVA()"></td></tr>

                            <tr class="row-total">
                                <td class="td-ligne">95</td>
                                <td>CA Global Exonéré de TVA <span class="text-xs font-normal text-slate-500">(Somme Lig. 81 à 86)</span></td>
                                <td class="td-montant" id="tva_val95"><?= $ligne95 > 0 ? formatMontant($ligne95) : '' ?></td>
                            </tr>

                            <tr class="row-total">
                                <td class="td-ligne">100</td>
                                <td>CA Global Hors TVA Taxable <span class="text-xs font-normal text-slate-500">(Lig. 80 - 95)</span></td>
                                <td class="td-montant">
                                    <?php if ($margeTaxableSelectionnee == 0): ?>
                                    <input type="text" name="ca_taxable_manual" id="ca_taxable_manual" class="input-manual input-manual-purple" value="<?= formatMontant($caTaxable) ?>" onchange="updateCaTaxable()">
                                    <?php else: ?>
                                    <?= formatMontant($caTaxable) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <!-- ====== SECTION TVA COLLECTÉE ====== -->
                            <tr class="row-section"><td colspan="3"><i class="fas fa-arrow-up mr-1"></i> TVA Brute Collectée</td></tr>

                            <tr><td class="td-ligne">101</td><td>Livraison à soi-même, imposable, effectuée</td><td class="td-montant"><input type="number" name="tva_ligne101" id="tva_ligne101" class="input-manual" style="width:150px;" value="<?= $tvaLigne101 > 0 ? $tvaLigne101 : '' ?>" min="0" step="1" oninput="recalcTVA()"></td></tr>

                            <tr><td class="td-ligne">102</td><td>Portion du CA Taxable Soumise au Taux Réduit de TVA (5%)</td><td class="td-montant" id="tva_val102"><?php if ($tauxTVADouble): ?><input type="number" name="tva_ligne102" id="tva_ligne102" class="input-manual" style="width:150px;" value="<?= $tvaLigne102 > 0 ? $tvaLigne102 : '' ?>" min="0" step="1" oninput="recalcTVA()"><?php else: ?><?= $ligne102 > 0 ? formatMontant($ligne102) : '' ?><?php endif; ?></td></tr>
                            <tr><td class="td-ligne">103</td><td>Livraison à soi-même Soumise au Taux Réduit de TVA (5%)</td><td class="td-montant"><input type="number" name="tva_ligne103" id="tva_ligne103" class="input-manual" style="width:150px;" value="<?= $tvaLigne103 > 0 ? $tvaLigne103 : '' ?>" min="0" step="1" oninput="recalcTVA()"></td></tr>
                            <tr><td class="td-ligne">104</td><td>Base Soumise au Taux Réduit de TVA (5%) <span class="text-xs text-slate-400">(Lig. 102+103)</span></td><td class="td-montant" id="tva_val104"><?= $ligne104 > 0 ? formatMontant($ligne104) : '' ?></td></tr>
                            <tr class="row-total"><td class="td-ligne">105</td><td>TVA Brute Collectée au Taux Réduit (5%) <span class="text-xs font-normal text-slate-500">(Lig. 104 × 5%)</span></td><td class="td-montant" id="tva_val105"><?= $ligne105 > 0 ? formatMontant($ligne105) : '' ?></td></tr>

                            <tr><td class="td-ligne">106</td><td>Portion du CA Taxable Soumise au Taux Normal de TVA (18%) <?php if ($tauxTVADouble): ?><span class="text-xs text-slate-400">(Lig. 100 - 102)</span><?php endif; ?></td><td class="td-montant" id="tva_val106"><?= $ligne106 > 0 ? formatMontant($ligne106) : '' ?></td></tr>
                            <tr><td class="td-ligne">107</td><td>Livraison à soi-même Soumise au Taux Normal de TVA (18%)</td><td class="td-montant"><input type="number" name="tva_ligne107" id="tva_ligne107" class="input-manual" style="width:150px;" value="<?= $tvaLigne107 > 0 ? $tvaLigne107 : '' ?>" min="0" step="1" oninput="recalcTVA()"></td></tr>
                            <tr><td class="td-ligne">108</td><td>Base Soumise au Taux Normal de TVA (18%) <span class="text-xs text-slate-400">(Lig. 106+107)</span></td><td class="td-montant" id="tva_val108"><?= $ligne108 > 0 ? formatMontant($ligne108) : '' ?></td></tr>
                            <tr class="row-total"><td class="td-ligne">109</td><td>TVA Brute Collectée au Taux Normal (18%) <span class="text-xs font-normal text-slate-500">(Lig. 108 × 18%)</span></td><td class="td-montant" id="tva_val109"><?= $ligne109 > 0 ? formatMontant($ligne109) : '' ?></td></tr>

                            <tr><td class="td-ligne">110</td><td>Reversement de TVA Suite à Régularisation</td><td class="td-montant"><input type="number" name="tva_ligne110" id="tva_ligne110" class="input-manual" style="width:150px;" value="<?= $tvaLigne110 > 0 ? $tvaLigne110 : '' ?>" min="0" step="1" oninput="recalcTVA()"></td></tr>

                            <tr class="row-total">
                                <td class="td-ligne">111</td>
                                <td>TVA Brute Collectée <span class="text-xs font-normal text-slate-500">(Lig. 105 + 109 + 110)</span></td>
                                <td class="td-montant" id="tva_val111" style="font-size:14px; color:#1e40af;"><?= formatMontant($ligne111) ?></td>
                            </tr>

                            <!-- ====== SECTION TVA DÉDUCTIBLE ====== -->
                            <tr class="row-section"><td colspan="3"><i class="fas fa-arrow-down mr-1"></i> TVA Déductible</td></tr>

                            <tr>
                                <td class="td-ligne">112</td>
                                <td>TVA Déductible à 100% sur les Achats Locaux</td>
                                <td class="td-montant">
                                    <input type="number" name="tva_ligne112" id="tva_ligne112" class="input-manual" style="width:150px;" 
                                           value="<?= $ligne112 > 0 ? $ligne112 : '' ?>" min="0" step="1" oninput="recalcTVA()">
                                </td>
                            </tr>
                            <tr><td class="td-ligne">113</td><td>TVA Déductible à 100% sur les Importations</td><td class="td-montant"><input type="number" name="tva_ligne113" id="tva_ligne113" class="input-manual" style="width:150px;" value="<?= $tvaLigne113 > 0 ? $tvaLigne113 : '' ?>" min="0" step="1" oninput="recalcTVA()"></td></tr>
                            <tr><td class="td-ligne">114</td><td>TVA Déductible au Prorata sur les Achats Locaux</td><td class="td-montant"><input type="number" name="tva_ligne114" id="tva_ligne114" class="input-manual" style="width:150px;" value="<?= $tvaLigne114 > 0 ? $tvaLigne114 : '' ?>" min="0" step="1" oninput="recalcTVA()"></td></tr>
                            <tr><td class="td-ligne">115</td><td>TVA Déductible au Prorata sur les Importations</td><td class="td-montant"><input type="number" name="tva_ligne115" id="tva_ligne115" class="input-manual" style="width:150px;" value="<?= $tvaLigne115 > 0 ? $tvaLigne115 : '' ?>" min="0" step="1" oninput="recalcTVA()"></td></tr>
                            <tr><td class="td-ligne">116</td><td>TVA Retenue à la Source par le Trésor</td><td class="td-montant"><input type="number" name="tva_ligne116" id="tva_ligne116" class="input-manual" style="width:150px;" value="<?= $tvaLigne116 > 0 ? $tvaLigne116 : '' ?>" min="0" step="1" oninput="recalcTVA()"></td></tr>
                            <tr><td class="td-ligne">117</td><td>Complément de Déduction suite à Régularisation</td><td class="td-montant"><input type="number" name="tva_ligne117" id="tva_ligne117" class="input-manual" style="width:150px;" value="<?= $tvaLigne117 > 0 ? $tvaLigne117 : '' ?>" min="0" step="1" oninput="recalcTVA()"></td></tr>
                            <tr><td class="td-ligne">118</td><td>TVA Retenue à la Source par les Clients</td><td class="td-montant"><input type="number" name="tva_ligne118" id="tva_ligne118" class="input-manual" style="width:150px;" value="<?= $tvaLigne118 > 0 ? $tvaLigne118 : '' ?>" min="0" step="1" oninput="recalcTVA()"></td></tr>

                            <tr class="row-total">
                                <td class="td-ligne">119</td>
                                <td>TVA Déductible <span class="text-xs font-normal text-slate-500">(Lig. 112+113+114+115+116+117+118)</span></td>
                                <td class="td-montant" id="tva_val119"><?= formatMontant($ligne119) ?></td>
                            </tr>
                            <tr><td class="td-ligne">120</td><td>Report de Crédit des Mois Précédents</td><td class="td-montant"><input type="number" name="tva_ligne120" id="tva_ligne120" class="input-manual" style="width:150px;" value="<?= $tvaLigne120 > 0 ? $tvaLigne120 : '' ?>" min="0" step="1" oninput="recalcTVA()"></td></tr>

                            <tr class="row-total">
                                <td class="td-ligne">125</td>
                                <td>Total des Déductions Autorisées <span class="text-xs font-normal text-slate-500">(Lig. 119 + 120)</span></td>
                                <td class="td-montant" id="tva_val125" style="font-size:14px; color:#dc2626;"><?= formatMontant($ligne125) ?></td>
                            </tr>

                            <!-- ====== RÉSULTAT ====== -->
                            <tr class="row-section"><td colspan="3"><i class="fas fa-flag-checkered mr-1"></i> Résultat</td></tr>

                            <?php if ($ligne131_tva > 0): ?>
                            <tr class="row-result">
                                <td class="td-ligne" style="background:#dcfce7; color:#16a34a;">131</td>
                                <td style="color:#16a34a;">TVA Nette à Payer <span class="text-xs font-normal">(Lig. 111 - 125)</span></td>
                                <td class="td-montant" id="tva_val131" style="font-size:16px; color:#16a34a;"><?= formatMontant($ligne131_tva) ?></td>
                            </tr>
                            <?php else: ?>
                            <tr><td class="td-ligne">131</td><td>TVA Nette à Payer <span class="text-xs text-slate-400">(Lig. 111 - 125)</span></td><td class="td-montant" id="tva_val131">0</td></tr>
                            <?php endif; ?>

                            <?php if ($ligne132_tva > 0): ?>
                            <tr class="row-credit">
                                <td class="td-ligne" style="background:#fef3c7;">132</td>
                                <td>Crédit de TVA à Réporter <span class="text-xs font-normal text-amber-600">(Lig. 125 - 111)</span></td>
                                <td class="td-montant" id="tva_val132" style="color:#d97706;"><?= formatMontant($ligne132_tva) ?></td>
                            </tr>
                            <?php else: ?>
                            <tr class="row-sub"><td class="td-ligne">132</td><td>Crédit de TVA à Réporter <span class="text-xs text-slate-400">(Lig. 125 - 111)</span></td><td class="td-montant" id="tva_val132"></td></tr>
                            <?php endif; ?>

                            <tr class="row-sub"><td class="td-ligne">133</td><td>Crédit de TVA à Rembourser</td><td class="td-montant"></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Section CF - Format Officiel -->
            <?php
            $cf_ligne242 = $masseSalariale ?? 0;
            $cf_ligne245 = $cf_ligne242 + ($cfLigne243 ?? 0);
            $cf_ligne252 = ($cfLigne246 ?? 0) + ($cfLigne247 ?? 0) + ($cfLigne248 ?? 0) + ($cfLigne249 ?? 0) + ($cfLigne250 ?? 0) + ($cfLigne251 ?? 0);
            $cf_ligne253 = max(0, $cf_ligne245 - $cf_ligne252);
            $cf_ligne254 = floor($cf_ligne253 / 1000) * 1000;
            $cf_ligne255 = round($cf_ligne254 * $tauxCF / 100);
            $cf_ligne500 = $tauxCF;
            ?>
            <div id="section-cf" class="<?= $typeImpot !== 'cf' ? 'hidden' : '' ?>">
                <div class="bg-white rounded-xl shadow-sm border overflow-hidden mb-6">
                    <table class="tva-form">
                        <thead>
                            <tr>
                                <th class="col-ligne">Ligne</th>
                                <th>Désignation</th>
                                <th class="col-montant">Montant</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="row-section"><td colspan="3"><i class="fas fa-calculator mr-1"></i> Contribution Forfaitaire</td></tr>

                            <tr>
                                <td class="td-ligne">242</td>
                                <td>Salaire Brut Mensuel des Employés</td>
                                <td class="td-montant"><?= formatMontant($cf_ligne242) ?></td>
                            </tr>
                            <tr>
                                <td class="td-ligne">243</td>
                                <td>Avantages en Espèces et/ou en Nature accordés aux Employés</td>
                                <td class="td-montant">
                                    <input type="number" name="cf_ligne243" id="cf_ligne243" class="input-manual" style="width:150px;" 
                                           value="<?= $cfLigne243 > 0 ? $cfLigne243 : '' ?>" min="0" step="1" oninput="recalcCF()">
                                </td>
                            </tr>
                            <tr class="row-total">
                                <td class="td-ligne">245</td>
                                <td>Total Brut des Salaires et Avantages <span class="text-xs font-normal text-slate-500">(Lig. 242 + 243)</span></td>
                                <td class="td-montant" id="cf_val245"><?= formatMontant($cf_ligne245) ?></td>
                            </tr>

                            <tr class="row-section"><td colspan="3"><i class="fas fa-file-alt mr-1"></i> Salaires ou Avantages Exonérés</td></tr>

                            <tr>
                                <td class="td-ligne">246</td>
                                <td>Sommes ou Avantages exonérés - Article 161 CGI <span class="text-xs text-slate-400">(Jeunes diplômés)</span></td>
                                <td class="td-montant">
                                    <input type="number" name="cf_ligne246" id="cf_ligne246" class="input-manual" style="width:150px;" 
                                           value="<?= $cfLigne246 > 0 ? $cfLigne246 : '' ?>" min="0" step="1" oninput="recalcCF()">
                                </td>
                            </tr>
                            <tr>
                                <td class="td-ligne">247</td>
                                <td>Sommes ou Avantages exonérés - Article 162 CGI <span class="text-xs text-slate-400">(Compressés)</span></td>
                                <td class="td-montant">
                                    <input type="number" name="cf_ligne247" id="cf_ligne247" class="input-manual" style="width:150px;" 
                                           value="<?= $cfLigne247 > 0 ? $cfLigne247 : '' ?>" min="0" step="1" oninput="recalcCF()">
                                </td>
                            </tr>
                            <tr>
                                <td class="td-ligne">248</td>
                                <td>Allocation versée aux Stagiaires - Article 163 CGI</td>
                                <td class="td-montant">
                                    <input type="number" name="cf_ligne248" id="cf_ligne248" class="input-manual" style="width:150px;" 
                                           value="<?= $cfLigne248 > 0 ? $cfLigne248 : '' ?>" min="0" step="1" oninput="recalcCF()">
                                </td>
                            </tr>
                            <tr>
                                <td class="td-ligne">249</td>
                                <td>Indemnités Non Imposables à la CF</td>
                                <td class="td-montant">
                                    <input type="number" name="cf_ligne249" id="cf_ligne249" class="input-manual" style="width:150px;" 
                                           value="<?= $cfLigne249 > 0 ? $cfLigne249 : '' ?>" min="0" step="1" oninput="recalcCF()">
                                </td>
                            </tr>
                            <tr>
                                <td class="td-ligne">250</td>
                                <td>Sommes, Avantages exonérés de CF - Code Investissement</td>
                                <td class="td-montant">
                                    <input type="number" name="cf_ligne250" id="cf_ligne250" class="input-manual" style="width:150px;" 
                                           value="<?= $cfLigne250 > 0 ? $cfLigne250 : '' ?>" min="0" step="1" oninput="recalcCF()">
                                </td>
                            </tr>
                            <tr>
                                <td class="td-ligne">251</td>
                                <td>Sommes, Avantages exonérés de CF - Accord Cadre ONG</td>
                                <td class="td-montant">
                                    <input type="number" name="cf_ligne251" id="cf_ligne251" class="input-manual" style="width:150px;" 
                                           value="<?= $cfLigne251 > 0 ? $cfLigne251 : '' ?>" min="0" step="1" oninput="recalcCF()">
                                </td>
                            </tr>

                            <tr class="row-total">
                                <td class="td-ligne">252</td>
                                <td>Total Salaires ou Avantages exonérés de CF <span class="text-xs font-normal text-slate-500">(Lig. 246 à 251)</span></td>
                                <td class="td-montant" id="cf_val252"><?= $cf_ligne252 > 0 ? formatMontant($cf_ligne252) : '' ?></td>
                            </tr>
                            <tr class="row-total">
                                <td class="td-ligne">253</td>
                                <td>Total Net Imposable <span class="text-xs font-normal text-slate-500">(Lig. 245 - 252)</span></td>
                                <td class="td-montant" id="cf_val253"><?= formatMontant($cf_ligne253) ?></td>
                            </tr>
                            <tr>
                                <td class="td-ligne">254</td>
                                <td>Base Soumise à la CF Arrondie</td>
                                <td class="td-montant" id="cf_val254"><?= formatMontant($cf_ligne254) ?></td>
                            </tr>

                            <tr class="row-result">
                                <td class="td-ligne" style="background:#dcfce7; color:#16a34a;">255</td>
                                <td style="color:#16a34a;">Contribution Forfaitaire à Payer <span class="text-xs font-normal">(Lig. 254 × <?= $tauxCF ?>%)</span></td>
                                <td class="td-montant" id="cf_val255" style="font-size:16px; color:#16a34a;"><?= formatMontant($cf_ligne255) ?></td>
                            </tr>
                            <tr class="row-sub">
                                <td class="td-ligne">500</td>
                                <td>Taux de l'Impôt</td>
                                <td class="td-montant"><?= $cf_ligne500 ?>%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Section TL - Format Officiel -->
            <?php
            $tl_ligne211 = $masseSalariale ?? 0;
            $tl_ligne213 = $tl_ligne211 + ($tlLigne212 ?? 0);
            $tl_ligne219 = floor($tl_ligne213 / 1000) * 1000;
            $tl_ligne224 = round($tl_ligne219 * $tauxTL / 100);
            $tl_ligne500 = $tauxTL;
            ?>
            <div id="section-tl" class="<?= $typeImpot !== 'tl' ? 'hidden' : '' ?>">
                <div class="bg-white rounded-xl shadow-sm border overflow-hidden mb-6">
                    <table class="tva-form">
                        <thead>
                            <tr>
                                <th class="col-ligne">Ligne</th>
                                <th>Désignation</th>
                                <th class="col-montant">Montant</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="row-section"><td colspan="3"><i class="fas fa-home mr-1"></i> Taxe-Logement</td></tr>

                            <tr>
                                <td class="td-ligne">211</td>
                                <td>Salaire Brut Mensuel des Employés</td>
                                <td class="td-montant"><?= formatMontant($tl_ligne211) ?></td>
                            </tr>
                            <tr>
                                <td class="td-ligne">212</td>
                                <td>Avantages Mensuels en Espèces et/ou en Nature des Employés</td>
                                <td class="td-montant">
                                    <input type="number" name="tl_ligne212" id="tl_ligne212" class="input-manual" style="width:150px;" 
                                           value="<?= $tlLigne212 > 0 ? $tlLigne212 : '' ?>" min="0" step="1" oninput="recalcTL()">
                                </td>
                            </tr>
                            <tr class="row-total">
                                <td class="td-ligne">213</td>
                                <td>Total Brut <span class="text-xs font-normal text-slate-500">(Lig. 211 + 212)</span></td>
                                <td class="td-montant" id="tl_val213"><?= formatMontant($tl_ligne213) ?></td>
                            </tr>
                            <tr>
                                <td class="td-ligne">219</td>
                                <td>Base Soumise à la TL Arrondie</td>
                                <td class="td-montant" id="tl_val219"><?= formatMontant($tl_ligne219) ?></td>
                            </tr>

                            <tr class="row-result">
                                <td class="td-ligne" style="background:#dcfce7; color:#16a34a;">224</td>
                                <td style="color:#16a34a;">Taxe-Logement à Payer <span class="text-xs font-normal">(Lig. 219 × <?= $tauxTL ?>%)</span></td>
                                <td class="td-montant" id="tl_val224" style="font-size:16px; color:#16a34a;"><?= formatMontant($tl_ligne224) ?></td>
                            </tr>
                            <tr class="row-sub">
                                <td class="td-ligne">500</td>
                                <td>Taux de l'Impôt</td>
                                <td class="td-montant"><?= $tl_ligne500 ?>%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Section ITS - Format Officiel -->
            <div id="section-its" class="<?= $typeImpot !== 'its' ? 'hidden' : '' ?>">
                <div class="bg-white rounded-xl shadow-sm border overflow-hidden mb-6">
                    <table class="tva-form">
                        <thead>
                            <tr>
                                <th class="col-ligne">Ligne</th>
                                <th>Désignation</th>
                                <th class="col-montant">Montant</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="row-section"><td colspan="3"><i class="fas fa-users mr-1"></i> Impôt sur les Traitements et Salaires</td></tr>

                            <tr class="row-result">
                                <td class="td-ligne" style="background:#dcfce7; color:#16a34a;">610</td>
                                <td style="color:#16a34a;">I.T.S Total à Payer</td>
                                <td class="td-montant">
                                    <input type="number" name="its" id="its_input" class="input-manual" style="border-color:#16a34a; background:#f0fdf4; font-size:14px; width:160px;" 
                                           value="<?= $its ?>" min="0" step="1" oninput="updateSummary()">
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Section TF - Format Officiel -->
            <?php if ($irfTfActif):
            $tf_ligne130 = 470; // Nature ou état de l'immeuble (470 = Immeubles bâtis)
            $tf_ligne132 = $loyersPercus ?? 0; // Valeur locative mensuelle
            $tf_ligne133 = 1; // Durée de la période (mois)
            $tf_ligne510 = $tauxTF; // Taux applicable
            $tf_ligne134 = round($tf_ligne132 * $tf_ligne510 / 100); // Montant payé
            $tf_ligne810 = $tf_ligne134; // TF-Retenue à émettre
            ?>
            <div id="section-tf" class="<?= $typeImpot !== 'tf' ? 'hidden' : '' ?>">
                <div class="bg-white rounded-xl shadow-sm border overflow-hidden mb-6">
                    <table class="tva-form">
                        <thead>
                            <tr>
                                <th class="col-ligne">Ligne</th>
                                <th>Désignation</th>
                                <th class="col-montant">Montant</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="row-section"><td colspan="3"><i class="fas fa-building mr-1"></i> Taxe Foncière - Retenue par les Locataires (Immeubles Bâtis)</td></tr>

                            <tr>
                                <td class="td-ligne">130</td>
                                <td>Nature ou État de l'Immeuble</td>
                                <td class="td-montant">
                                    <select name="tf_ligne130" id="tf_ligne130" class="input-manual" style="width:200px; font-size:13px;">
                                        <option value="470" selected>470 - Immeubles bâtis</option>
                                        <option value="480">480 - Terrains non bâtis</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <td class="td-ligne">132</td>
                                <td>Valeur Locative Mensuelle de l'Établissement</td>
                                <td class="td-montant"><?= formatMontant($tf_ligne132) ?></td>
                            </tr>
                            <tr>
                                <td class="td-ligne">133</td>
                                <td>Durée de la Période (mois)</td>
                                <td class="td-montant"><?= $tf_ligne133 ?></td>
                            </tr>
                            <tr>
                                <td class="td-ligne">134</td>
                                <td>Montant Payé <span class="text-xs text-slate-400">(Lig. 132 × <?= $tf_ligne510 ?>%)</span></td>
                                <td class="td-montant"><?= formatMontant($tf_ligne134) ?></td>
                            </tr>
                            <tr>
                                <td class="td-ligne">510</td>
                                <td>Taux Applicable</td>
                                <td class="td-montant"><?= $tf_ligne510 ?> %</td>
                            </tr>

                            <tr class="row-result">
                                <td class="td-ligne" style="background:#dcfce7; color:#16a34a;">810</td>
                                <td style="color:#16a34a;">TF - Retenue à Émettre</td>
                                <td class="td-montant" style="font-size:16px; color:#16a34a;"><?= formatMontant($tf_ligne810) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Section IRF - Format Officiel -->
            <?php if ($irfTfActif):
            $irf_ligne130 = 280; // Nature de l'Immeuble (280 = Immeuble en dur et semi-dur)
            $irf_ligne132 = $loyersPercus ?? 0; // Valeur Locative Mensuelle
            $irf_ligne510 = $tauxIRF; // Taux de l'Impôt
            $irf_ligne685 = round($irf_ligne132 * $irf_ligne510 / 100); // Impôt Net Retenu à la Source
            ?>
            <div id="section-irf" class="<?= $typeImpot !== 'irf' ? 'hidden' : '' ?>">
                <div class="bg-white rounded-xl shadow-sm border overflow-hidden mb-6">
                    <table class="tva-form">
                        <thead>
                            <tr>
                                <th class="col-ligne">Ligne</th>
                                <th>Désignation</th>
                                <th class="col-montant">Montant</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="row-section"><td colspan="3"><i class="fas fa-hand-holding-usd mr-1"></i> IRF - Impôt sur les Revenus Fonciers (Retenu à la Source)</td></tr>

                            <tr>
                                <td class="td-ligne">130</td>
                                <td>Nature de l'Immeuble</td>
                                <td class="td-montant">
                                    <select name="irf_ligne130" id="irf_ligne130" class="input-manual" style="width:180px; font-size:13px;">
                                        <option value="280" selected>280 - Dur / Semi-dur</option>
                                        <option value="290">290 - Autres</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <td class="td-ligne">132</td>
                                <td>Valeur Locative Mensuelle</td>
                                <td class="td-montant"><?= formatMontant($irf_ligne132) ?></td>
                            </tr>
                            <tr>
                                <td class="td-ligne">510</td>
                                <td>Taux de l'Impôt</td>
                                <td class="td-montant"><?= $irf_ligne510 ?> %</td>
                            </tr>

                            <tr class="row-result">
                                <td class="td-ligne" style="background:#dcfce7; color:#16a34a;">685</td>
                                <td style="color:#16a34a;">Impôt Net Retenu à la Source <span class="text-xs font-normal">(Lig. 132 × <?= $irf_ligne510 ?>%)</span></td>
                                <td class="td-montant" style="font-size:16px; color:#16a34a;"><?= formatMontant($irf_ligne685) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Section CSS - Format Officiel -->
            <?php
            $css_ligne80 = $caGlobal;
            $css_ligne810 = round($css_ligne80 * $tauxCSS / 100);
            ?>
            <div id="section-css" class="<?= $typeImpot !== 'css' ? 'hidden' : '' ?>">
                <div class="bg-white rounded-xl shadow-sm border overflow-hidden mb-6">
                    <table class="tva-form">
                        <thead>
                            <tr>
                                <th class="col-ligne">Ligne</th>
                                <th>Désignation</th>
                                <th class="col-montant">Montant</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="row-section"><td colspan="3"><i class="fas fa-hands-helping mr-1"></i> Contribution Spéciale de Solidarité (CSS)</td></tr>

                            <tr>
                                <td class="td-ligne">80</td>
                                <td>Chiffre d'Affaires Global Hors Taxe Réalisé</td>
                                <td class="td-montant"><?= formatMontant($css_ligne80) ?></td>
                            </tr>

                            <tr class="row-result">
                                <td class="td-ligne" style="background:#dcfce7; color:#16a34a;">810</td>
                                <td style="color:#16a34a;">Contribution Spéciale de Solidarité Nette à Payer <span class="text-xs font-normal">(Lig. 80 × <?= $tauxCSS ?>%)</span></td>
                                <td class="td-montant" style="font-size:16px; color:#16a34a;"><?= formatMontant($css_ligne810) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Section RAS - Retenue à la Source BIC/IS (lignes 400-430) -->
            <?php if ($rasActif): ?>
            <div id="section-ras" class="<?= $typeImpot !== 'ras' ? 'hidden' : '' ?>">
                <div class="bg-white rounded-xl shadow-sm border overflow-hidden mb-6">
                    <table class="tva-form">
                        <thead>
                            <tr>
                                <th class="col-ligne">Ligne</th>
                                <th>Désignation</th>
                                <th class="col-montant">Montant</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="row-section"><td colspan="3"><i class="fas fa-hand-holding-usd mr-1"></i> Retenue à la Source BIC/IS</td></tr>

                            <tr>
                                <td class="td-ligne">401</td>
                                <td>Rémunérations Versées aux Membres des Professions Libérales</td>
                                <td class="td-montant"><input type="number" name="ras_ligne401" id="ras_ligne401" class="input-manual" value="<?= $rasLigne401 ?>" min="0" step="1" oninput="recalcRAS()"></td>
                            </tr>
                            <tr>
                                <td class="td-ligne">403</td>
                                <td>Sommes ou Revenus Versés - Usage d'un Droit d'Auteur</td>
                                <td class="td-montant"><input type="number" name="ras_ligne403" id="ras_ligne403" class="input-manual" value="<?= $rasLigne403 ?>" min="0" step="1" oninput="recalcRAS()"></td>
                            </tr>
                            <tr>
                                <td class="td-ligne">404</td>
                                <td>Usage ou Concession de l'Usage d'un Brevet</td>
                                <td class="td-montant"><input type="number" name="ras_ligne404" id="ras_ligne404" class="input-manual" value="<?= $rasLigne404 ?>" min="0" step="1" oninput="recalcRAS()"></td>
                            </tr>
                            <tr>
                                <td class="td-ligne">405</td>
                                <td>Informations Ayant Trait à une Expérience Acquise</td>
                                <td class="td-montant"><input type="number" name="ras_ligne405" id="ras_ligne405" class="input-manual" value="<?= $rasLigne405 ?>" min="0" step="1" oninput="recalcRAS()"></td>
                            </tr>
                            <tr>
                                <td class="td-ligne">406</td>
                                <td>Prestations Fournies ou Utilisées au Mali</td>
                                <td class="td-montant"><input type="number" name="ras_ligne406" id="ras_ligne406" class="input-manual" value="<?= $rasLigne406 ?>" min="0" step="1" oninput="recalcRAS()"></td>
                            </tr>
                            <tr class="row-sub">
                                <td class="td-ligne">410</td>
                                <td>Rémunérations, Sommes/Revenus Versés (Lig.401+403+404+405+406)</td>
                                <td class="td-montant" id="ras_val410"><?= formatMontant($rasCalc['410']) ?></td>
                            </tr>

                            <tr class="row-section"><td colspan="3">Exonérations de Retenue BIC</td></tr>
                            <tr>
                                <td class="td-ligne">411</td>
                                <td>Rémunérations Exonérées - Code Minier et Investissement</td>
                                <td class="td-montant"><input type="number" name="ras_ligne411" id="ras_ligne411" class="input-manual" value="<?= $rasLigne411 ?>" min="0" step="1" oninput="recalcRAS()"></td>
                            </tr>
                            <tr>
                                <td class="td-ligne">412</td>
                                <td>Ligne Non Applicable (Se référer à la ligne 411)</td>
                                <td class="td-montant"><input type="number" name="ras_ligne412" id="ras_ligne412" class="input-manual" value="<?= $rasLigne412 ?>" min="0" step="1" oninput="recalcRAS()"></td>
                            </tr>
                            <tr>
                                <td class="td-ligne">413</td>
                                <td>Autres Rémunérations Exonérées de Retenue BIC</td>
                                <td class="td-montant"><input type="number" name="ras_ligne413" id="ras_ligne413" class="input-manual" value="<?= $rasLigne413 ?>" min="0" step="1" oninput="recalcRAS()"></td>
                            </tr>
                            <tr class="row-sub">
                                <td class="td-ligne">414</td>
                                <td>Total Rémunérations Exonérées (Lig.411+412+413)</td>
                                <td class="td-montant" id="ras_val414"><?= formatMontant($rasCalc['414']) ?></td>
                            </tr>
                            <tr class="row-sub">
                                <td class="td-ligne">415</td>
                                <td>Total des Rémunérations Imposables (Lig.410 - 414)</td>
                                <td class="td-montant" id="ras_val415"><?= formatMontant($rasCalc['415']) ?></td>
                            </tr>
                            <tr class="row-sub">
                                <td class="td-ligne">416</td>
                                <td>Charges Forfaitaires Déductibles (Lig.415 × 50%)</td>
                                <td class="td-montant" id="ras_val416"><?= formatMontant($rasCalc['416']) ?></td>
                            </tr>
                            <tr class="row-sub">
                                <td class="td-ligne">417</td>
                                <td>Base Rémunérations Taxable (Lig.415 - 416)</td>
                                <td class="td-montant" id="ras_val417"><?= formatMontant($rasCalc['417']) ?></td>
                            </tr>

                            <tr class="row-section"><td colspan="3">Marchés ou Contrats Publics</td></tr>
                            <tr>
                                <td class="td-ligne">418</td>
                                <td>Montant Global des Marchés ou Contrats Publics</td>
                                <td class="td-montant"><input type="number" name="ras_ligne418" id="ras_ligne418" class="input-manual" value="<?= $rasLigne418 ?>" min="0" step="1" oninput="recalcRAS()"></td>
                            </tr>
                            <tr>
                                <td class="td-ligne">419</td>
                                <td>Autres Marchés, Contrats Publics Exonérés de Retenue BIC</td>
                                <td class="td-montant"><input type="number" name="ras_ligne419" id="ras_ligne419" class="input-manual" value="<?= $rasLigne419 ?>" min="0" step="1" oninput="recalcRAS()"></td>
                            </tr>
                            <tr class="row-sub">
                                <td class="td-ligne">420</td>
                                <td>Total des Marchés ou Contrats Publics Imposables (Lig.418 - 419)</td>
                                <td class="td-montant" id="ras_val420"><?= formatMontant($rasCalc['420']) ?></td>
                            </tr>
                            <tr class="row-sub">
                                <td class="td-ligne">421</td>
                                <td>Charges Forfaitaires Déductibles (Lig.420 × 90%)</td>
                                <td class="td-montant" id="ras_val421"><?= formatMontant($rasCalc['421']) ?></td>
                            </tr>
                            <tr class="row-sub">
                                <td class="td-ligne">422</td>
                                <td>Base des Marchés ou Contrats Imposables (Lig.420 - 421)</td>
                                <td class="td-montant" id="ras_val422"><?= formatMontant($rasCalc['422']) ?></td>
                            </tr>
                            <tr class="row-sub">
                                <td class="td-ligne">423</td>
                                <td>Bases Totales Taxables (Lig.417 + 422)</td>
                                <td class="td-montant" id="ras_val423"><?= formatMontant($rasCalc['423']) ?></td>
                            </tr>
                            <tr class="row-sub">
                                <td class="td-ligne">424</td>
                                <td>Retenue à la Source Nette à Payer Art.94 CGI (Lig.423 × 30%)</td>
                                <td class="td-montant" id="ras_val424"><?= formatMontant($rasCalc['424']) ?></td>
                            </tr>
                            <tr>
                                <td class="td-ligne">425</td>
                                <td>Rémunérations des Prestataires non Identifiés - Art.440 NV LPF</td>
                                <td class="td-montant"><input type="number" name="ras_ligne425" id="ras_ligne425" class="input-manual" value="<?= $rasLigne425 ?>" min="0" step="1" oninput="recalcRAS()"></td>
                            </tr>
                            <tr class="row-sub">
                                <td class="td-ligne">426</td>
                                <td>Retenue à la Source Art.440 NV LPF (Lig.425 × 15%)</td>
                                <td class="td-montant" id="ras_val426"><?= formatMontant($rasCalc['426']) ?></td>
                            </tr>

                            <tr class="row-result">
                                <td class="td-ligne" style="background:#dcfce7; color:#16a34a;">430</td>
                                <td style="color:#16a34a;">Retenue à la Source Totale à Payer (Lig.424 + 426)</td>
                                <td class="td-montant" id="ras_val430" style="font-size:16px; color:#16a34a;"><?= formatMontant($rasCalc['430']) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Section TVA/LOCATION - Format Officiel -->
            <?php if ($locationActif):
            $loc_ligne131 = $loyersPercus ?? 0;
            $loc_ligne142 = ($locLigne132 ?? 0) + ($locLigne133 ?? 0) + ($locLigne137 ?? 0) + ($locLigne141 ?? 0);
            $loc_ligne143 = max(0, $loc_ligne131 - $loc_ligne142);
            $loc_ligne144 = round($loc_ligne143 * 18 / 100); // TVA/Location toujours à 18%
            $loc_ligne150 = max(0, $loc_ligne144 - ($locLigne145 ?? 0));
            ?>
            <div id="section-tva-location" class="<?= $typeImpot !== 'tva-location' ? 'hidden' : '' ?>">
                <div class="bg-white rounded-xl shadow-sm border overflow-hidden mb-6">
                    <table class="tva-form">
                        <thead>
                            <tr>
                                <th class="col-ligne">Ligne</th>
                                <th>Désignation</th>
                                <th class="col-montant">Montant</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="row-section"><td colspan="3"><i class="fas fa-building mr-1"></i> TVA sur Location</td></tr>

                            <tr>
                                <td class="td-ligne">131</td>
                                <td>Revenu Global Mensuel de Location des Biens Immeubles</td>
                                <td class="td-montant"><?= formatMontant($loc_ligne131) ?></td>
                            </tr>

                            <tr class="row-section"><td colspan="3"><i class="fas fa-file-alt mr-1"></i> Annexe - Exonérations TVA sur Location</td></tr>

                            <tr>
                                <td class="td-ligne">132</td>
                                <td>Revenu Mensuel des Imm. Nus à Usage d'Habitation - Art. 195-18 CGI</td>
                                <td class="td-montant">
                                    <input type="number" name="loc_ligne132" id="loc_ligne132" class="input-manual" 
                                           style="width:150px;" value="<?= $locLigne132 > 0 ? $locLigne132 : '' ?>" 
                                           min="0" step="1" oninput="recalcTvaLocation()">
                                </td>
                            </tr>
                            <tr>
                                <td class="td-ligne">133</td>
                                <td>Revenu Locatif Mensuel Exonéré - Code des Investissements, Miniers, Pétrole et Promotion Immobilière</td>
                                <td class="td-montant">
                                    <input type="number" name="loc_ligne133" id="loc_ligne133" class="input-manual" 
                                           style="width:150px;" value="<?= $locLigne133 > 0 ? $locLigne133 : '' ?>" 
                                           min="0" step="1" oninput="recalcTvaLocation()">
                                </td>
                            </tr>
                            <tr>
                                <td class="td-ligne">137</td>
                                <td>Revenu Locatif Mensuel Exonéré - Microfinance, Convention Internationale, Financement Extérieur, Coopération Bilatérale</td>
                                <td class="td-montant">
                                    <input type="number" name="loc_ligne137" id="loc_ligne137" class="input-manual" 
                                           style="width:150px;" value="<?= $locLigne137 > 0 ? $locLigne137 : '' ?>" 
                                           min="0" step="1" oninput="recalcTvaLocation()">
                                </td>
                            </tr>
                            <tr>
                                <td class="td-ligne">141</td>
                                <td>Autres Revenus Locatifs Mensuels Exonérés de TVA</td>
                                <td class="td-montant">
                                    <input type="number" name="loc_ligne141" id="loc_ligne141" class="input-manual" 
                                           style="width:150px;" value="<?= $locLigne141 > 0 ? $locLigne141 : '' ?>" 
                                           min="0" step="1" oninput="recalcTvaLocation()">
                                </td>
                            </tr>

                            <tr class="row-total">
                                <td class="td-ligne">142</td>
                                <td>Revenus Locatifs Mensuels Non Imposables <span class="text-xs font-normal text-slate-500">(Lig. 132+133+137+141)</span></td>
                                <td class="td-montant" id="loc_val142"><?= formatMontant($loc_ligne142) ?></td>
                            </tr>
                            <tr class="row-total">
                                <td class="td-ligne">143</td>
                                <td>Revenus Locatifs Mensuels Passibles de TVA <span class="text-xs font-normal text-slate-500">(Lig. 131 - 142)</span></td>
                                <td class="td-montant" id="loc_val143"><?= formatMontant($loc_ligne143) ?></td>
                            </tr>
                            <tr class="row-total">
                                <td class="td-ligne">144</td>
                                <td>TVA sur Location Collectée <span class="text-xs font-normal text-slate-500">(Lig. 143 × 18%)</span></td>
                                <td class="td-montant" id="loc_val144"><?= formatMontant($loc_ligne144) ?></td>
                            </tr>

                            <tr class="row-section"><td colspan="3"><i class="fas fa-hand-holding-usd mr-1"></i> TVA Retenue à la Source</td></tr>

                            <tr>
                                <td class="td-ligne">145</td>
                                <td>TVA sur Location Retenue à la Source par les Locataires</td>
                                <td class="td-montant">
                                    <input type="number" name="loc_ligne145" id="loc_ligne145" class="input-manual" 
                                           style="width:150px;" value="<?= $locLigne145 > 0 ? $locLigne145 : '' ?>" 
                                           min="0" step="1" oninput="recalcTvaLocation()">
                                </td>
                            </tr>

                            <tr class="row-result">
                                <td class="td-ligne" style="background:#dcfce7; color:#16a34a;">150</td>
                                <td style="color:#16a34a;">TVA sur Location Nette à Payer <span class="text-xs font-normal">(Lig. 144 - 145)</span></td>
                                <td class="td-montant" id="loc_val150" style="font-size:16px; color:#16a34a;"><?= formatMontant($loc_ligne150) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Cartes récapitulatives -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <!-- TVA -->
                <div class="bg-white rounded-lg border-2 border-slate-200 p-4">
                    <div class="flex items-center mb-2">
                        <i class="far fa-check-square text-green-500 mr-2"></i>
                        <span class="font-semibold text-slate-700">TVA</span>
                    </div>
                    <div class="text-lg font-bold text-slate-800"><span id="summary-tva"><?= formatMontant($tvaNette) ?></span> F</div>
                </div>

                <!-- CF -->
                <div class="bg-white rounded-lg border-2 border-slate-200 p-4">
                    <div class="flex items-center mb-2">
                        <i class="far fa-check-square text-green-500 mr-2"></i>
                        <span class="font-semibold text-slate-700">CF</span>
                    </div>
                    <div class="text-lg font-bold text-slate-800"><span id="summary-cf"><?= formatMontant($cf) ?></span> F</div>
                </div>

                <!-- TL -->
                <div class="bg-white rounded-lg border-2 border-slate-200 p-4">
                    <div class="flex items-center mb-2">
                        <i class="far fa-check-square text-green-500 mr-2"></i>
                        <span class="font-semibold text-slate-700">TL</span>
                    </div>
                    <div class="text-lg font-bold text-slate-800"><span id="summary-tl"><?= formatMontant($tl) ?></span> F</div>
                </div>

                <!-- ITS -->
                <div class="bg-white rounded-lg border-2 border-slate-200 p-4">
                    <div class="flex items-center mb-2">
                        <i class="far fa-check-square text-green-500 mr-2"></i>
                        <span class="font-semibold text-slate-700">ITS</span>
                    </div>
                    <div class="text-lg font-bold text-slate-800"><span id="summary-its"><?= formatMontant($its) ?></span> F</div>
                </div>

                <!-- TF -->
                <?php if ($irfTfActif): ?>
                <div class="bg-white rounded-lg border-2 border-slate-200 p-4">
                    <div class="flex items-center mb-2">
                        <i class="far fa-check-square text-green-500 mr-2"></i>
                        <span class="font-semibold text-slate-700">TF</span>
                    </div>
                    <div class="text-lg font-bold text-slate-800"><span id="summary-tf"><?= formatMontant($tf) ?></span> F</div>
                </div>

                <!-- IRF -->
                <div class="bg-white rounded-lg border-2 border-slate-200 p-4">
                    <div class="flex items-center mb-2">
                        <i class="far fa-check-square text-green-500 mr-2"></i>
                        <span class="font-semibold text-slate-700">IRF</span>
                    </div>
                    <div class="text-lg font-bold text-slate-800"><span id="summary-irf"><?= formatMontant($irf) ?></span> F</div>
                </div>
                <?php endif; ?>

                <!-- CSS -->
                <div class="bg-white rounded-lg border-2 border-slate-200 p-4">
                    <div class="flex items-center mb-2">
                        <i class="far fa-check-square text-green-500 mr-2"></i>
                        <span class="font-semibold text-slate-700">CSS</span>
                    </div>
                    <div class="text-lg font-bold text-slate-800"><span id="summary-css"><?= formatMontant($css) ?></span> F</div>
                </div>

                <!-- RAS -->
                <?php if ($rasActif): ?>
                <div class="bg-white rounded-lg border-2 border-slate-200 p-4">
                    <div class="flex items-center mb-2">
                        <i class="far fa-check-square text-green-500 mr-2"></i>
                        <span class="font-semibold text-slate-700">Retenue à la Source BIC/IS</span>
                    </div>
                    <div class="text-lg font-bold text-slate-800"><span id="summary-ras"><?= formatMontant($ras) ?></span> F</div>
                </div>
                <?php endif; ?>

                <!-- TVA/LOC -->
                <?php if ($locationActif): ?>
                <div class="bg-white rounded-lg border-2 border-slate-200 p-4">
                    <div class="flex items-center mb-2">
                        <i class="far fa-check-square text-green-500 mr-2"></i>
                        <span class="font-semibold text-slate-700">TVA/LOC</span>
                    </div>
                    <div class="text-lg font-bold text-slate-800"><span id="summary-tva-location"><?= formatMontant($tvaLocation) ?></span> F</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Total et boutons -->
            <div class="bg-white rounded-xl shadow-sm border p-6">
                <div class="flex items-center justify-between">
                    <div class="text-xl font-bold text-slate-800">
                        TOTAL IMPÔTS : <span class="text-primary-600"><span id="total-global"><?= formatMontant($totalImpots) ?></span> F CFA</span>
                    </div>
                    <div class="flex space-x-3">
                        <a href="recap-paiements.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" 
                           class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 font-medium">
                            <i class="fas fa-file-pdf mr-2"></i>Récap Paiements
                        </a>
                        <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium">
                            <i class="fas fa-save mr-2"></i>Enregistrer
                        </button>
                        <a href="dashboard.php" 
                           class="px-6 py-2 bg-slate-500 text-white rounded-lg hover:bg-slate-600 font-medium">
                            <i class="fas fa-times mr-2"></i>Fermer
                        </a>
                    </div>
                </div>
            </div>
        </form>

        <!-- Lien retour -->
        <div class="mt-6 text-center">
            <a href="clients.php" class="text-primary-600 hover:text-primary-800">
                <i class="fas fa-chevron-left mr-1"></i> Voir tous les clients
            </a>
        </div>
    </main>

    <script>
    // Fonctions utilitaires sécurisées pour éviter les plantages si un élément n'existe pas
    const parseValue = (id) => {
        const el = document.getElementById(id);
        if (!el) return 0;
        return parseFloat(el.textContent.replace(/\s/g, '').replace(',', '.')) || 0;
    };

    const parseInput = (id) => {
        const el = document.getElementById(id);
        if (!el) return 0;
        return parseFloat(el.value.replace(/\s/g, '').replace(',', '.')) || 0;
    };

    const getEl = (id) => document.getElementById(id);

    function fmt(n) { 
        if (isNaN(n)) return '0';
        return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' '); 
    }

    // Conserver les valeurs des lignes TVA Location dans l'URL
    function conserverLocLignes(url) {
        let el;
        el = getEl('loc_ligne132'); if (el) url.searchParams.set('loc_ligne132', el.value || 0);
        el = getEl('loc_ligne133'); if (el) url.searchParams.set('loc_ligne133', el.value || 0);
        el = getEl('loc_ligne137'); if (el) url.searchParams.set('loc_ligne137', el.value || 0);
        el = getEl('loc_ligne141'); if (el) url.searchParams.set('loc_ligne141', el.value || 0);
        el = getEl('loc_ligne145'); if (el) url.searchParams.set('loc_ligne145', el.value || 0);
    }

    function changerType(type) {
        const url = new URL(window.location.href);
        url.searchParams.set('type', type);
        
        const inputMasse = getEl('inputMasseSalariale');
        if (inputMasse) url.searchParams.set('masse_salariale', inputMasse.value || 0);
        
        const inputLoyers = getEl('inputLoyersPercus');
        if (inputLoyers) url.searchParams.set('loyers_percus', inputLoyers.value || 0);
        
        const inputMarge = getEl('hiddenMarge');
        if (inputMarge) url.searchParams.set('marge', inputMarge.value || 1.30);
        
        const inputMargeTaxable = getEl('hiddenMargeTaxable');
        if (inputMargeTaxable) url.searchParams.set('marge_taxable', inputMargeTaxable.value || 1.30);
        
        const itsInput = document.querySelector('input[name="its"]');
        if (itsInput) url.searchParams.set('its', itsInput.value || 0);
        
        conserverLocLignes(url);
        window.location.href = url.toString();
    }

    function changerMargeGlobal(marge) {
        const url = new URL(window.location.href);
        url.searchParams.set('marge', marge);
        
        const inputMargeTaxable = getEl('hiddenMargeTaxable');
        if (inputMargeTaxable) url.searchParams.set('marge_taxable', inputMargeTaxable.value || 1.30);
        
        const inputMasse = getEl('inputMasseSalariale');
        if (inputMasse) url.searchParams.set('masse_salariale', inputMasse.value || 0);
        
        const inputLoyers = getEl('inputLoyersPercus');
        if (inputLoyers) url.searchParams.set('loyers_percus', inputLoyers.value || 0);
        
        const itsInput = document.querySelector('input[name="its"]');
        if (itsInput) url.searchParams.set('its', itsInput.value || 0);
        
        conserverLocLignes(url);
        
        const hiddenMarg = getEl('hiddenMarge');
        if (hiddenMarg) hiddenMarg.value = marge;
        
        window.location.href = url.toString();
    }

    function changerMargeTaxable(marge) {
        const url = new URL(window.location.href);
        
        const inputMarge = getEl('hiddenMarge');
        if (inputMarge) url.searchParams.set('marge', inputMarge.value || 1.30);
        
        url.searchParams.set('marge_taxable', marge);
        
        const inputMasse = getEl('inputMasseSalariale');
        if (inputMasse) url.searchParams.set('masse_salariale', inputMasse.value || 0);
        
        const inputLoyers = getEl('inputLoyersPercus');
        if (inputLoyers) url.searchParams.set('loyers_percus', inputLoyers.value || 0);
        
        const itsInput = document.querySelector('input[name="its"]');
        if (itsInput) url.searchParams.set('its', itsInput.value || 0);
        
        conserverLocLignes(url);
        
        const hiddenMargTax = getEl('hiddenMargeTaxable');
        if (hiddenMargTax) hiddenMargTax.value = marge;
        
        window.location.href = url.toString();
    }

    function updateCaTaxable() {
        const val = getEl('ca_taxable_manual');
        if (val) {
            const montant = val.value.replace(/\s/g, '').replace(',', '.');
            const hidden = getEl('hiddenCaTaxable');
            if (hidden) hidden.value = montant;
        }
    }

    function recalculerImpots() {
        const form = getEl('formImpots');
        if (form) form.submit();
    }
    
    function changerMoisImpot(m) {
        const url = new URL(window.location.href);
        url.searchParams.set('mois', m);
        url.searchParams.delete('masse_salariale');
        url.searchParams.delete('loyers_percus');
        url.searchParams.delete('its');
        url.searchParams.delete('marge');
        url.searchParams.delete('marge_taxable');
        window.location.href = url.toString();
    }
    
    function changerAnneeImpot(a) {
        const url = new URL(window.location.href);
        url.searchParams.set('annee', a);
        url.searchParams.delete('masse_salariale');
        url.searchParams.delete('loyers_percus');
        url.searchParams.delete('its');
        url.searchParams.delete('marge');
        url.searchParams.delete('marge_taxable');
        window.location.href = url.toString();
    }

    function recalcTVA() {
        const tauxTVA = <?= $tauxTVA ?>;
        const tauxTVADouble = <?= $tauxTVADouble ? 'true' : 'false' ?>;
        const ligne80 = <?= $ligne80 ?>;
        const ligne81 = <?= $ligne81 ?>;
        const v82 = parseInput('tva_ligne82');
        const v83 = parseInput('tva_ligne83');
        const v84 = parseInput('tva_ligne84');
        const v85 = parseInput('tva_ligne85');
        const v86 = parseInput('tva_ligne86');

        const ligne95 = ligne81 + v82 + v83 + v84 + v85 + v86;
        const ligne100 = Math.max(0, ligne80 - ligne95);

        const v101 = parseInput('tva_ligne101');
        let ligne102, ligne106;
        if (tauxTVADouble) {
            ligne102 = parseInput('tva_ligne102');
            ligne106 = Math.max(0, ligne100 - ligne102);
        } else {
            ligne102 = (tauxTVA == 5) ? ligne100 : 0;
            ligne106 = (tauxTVA == 18) ? ligne100 : 0;
        }
        const v103 = parseInput('tva_ligne103');
        const ligne104 = ligne102 + v103;
        const ligne105 = Math.round(ligne104 * 5 / 100);
        const v107 = parseInput('tva_ligne107');
        const ligne108 = ligne106 + v107;
        const ligne109 = Math.round(ligne108 * 18 / 100);
        const v110 = parseInput('tva_ligne110');
        const ligne111 = ligne105 + ligne109 + v110;

        const ligne112 = <?= $ligne112 ?>;
        const v113 = parseInput('tva_ligne113');
        const v114 = parseInput('tva_ligne114');
        const v115 = parseInput('tva_ligne115');
        const v116 = parseInput('tva_ligne116');
        const v117 = parseInput('tva_ligne117');
        const v118 = parseInput('tva_ligne118');
        const ligne119 = ligne112 + v113 + v114 + v115 + v116 + v117 + v118;

        const v120 = parseInput('tva_ligne120');
        const ligne125 = ligne119 + v120;

        const ligne131 = Math.max(0, ligne111 - ligne125);
        const ligne132 = Math.max(0, ligne125 - ligne111);

        const el95 = getEl('tva_val95'); if (el95) el95.textContent = ligne95 > 0 ? fmt(ligne95) : '';
        if (!tauxTVADouble) {
            const el102 = getEl('tva_val102'); if (el102) el102.textContent = ligne102 > 0 ? fmt(ligne102) : '';
        }
        const el104 = getEl('tva_val104'); if (el104) el104.textContent = ligne104 > 0 ? fmt(ligne104) : '';
        const el105 = getEl('tva_val105'); if (el105) el105.textContent = ligne105 > 0 ? fmt(ligne105) : '';
        const el106 = getEl('tva_val106'); if (el106) el106.textContent = ligne106 > 0 ? fmt(ligne106) : '';
        const el108 = getEl('tva_val108'); if (el108) el108.textContent = ligne108 > 0 ? fmt(ligne108) : '';
        const el109 = getEl('tva_val109'); if (el109) el109.textContent = ligne109 > 0 ? fmt(ligne109) : '';
        const el111 = getEl('tva_val111'); if (el111) el111.textContent = fmt(ligne111);
        const el119 = getEl('tva_val119'); if (el119) el119.textContent = fmt(ligne119);
        const el125 = getEl('tva_val125'); if (el125) el125.textContent = fmt(ligne125);
        const el131 = getEl('tva_val131'); if (el131) el131.textContent = fmt(ligne131);
        const el132 = getEl('tva_val132'); if (el132) el132.textContent = ligne132 > 0 ? fmt(ligne132) : '';

        updateSummary();
    }

    function recalcCF() {
        const tauxCF = <?= $tauxCF ?? 3.5 ?>;
        const ligne242 = <?= $cf_ligne242 ?? 0 ?>;
        const v243 = parseInput('cf_ligne243');
        const v246 = parseInput('cf_ligne246');
        const v247 = parseInput('cf_ligne247');
        const v248 = parseInput('cf_ligne248');
        const v249 = parseInput('cf_ligne249');
        const v250 = parseInput('cf_ligne250');
        const v251 = parseInput('cf_ligne251');

        const ligne245 = ligne242 + v243;
        const ligne252 = v246 + v247 + v248 + v249 + v250 + v251;
        const ligne253 = Math.max(0, ligne245 - ligne252);
        const ligne254 = Math.floor(ligne253 / 1000) * 1000;
        const ligne255 = Math.round(ligne254 * tauxCF / 100);

        const el245 = getEl('cf_val245'); if (el245) el245.textContent = fmt(ligne245);
        const el252 = getEl('cf_val252'); if (el252) el252.textContent = ligne252 > 0 ? fmt(ligne252) : '';
        const el253 = getEl('cf_val253'); if (el253) el253.textContent = fmt(ligne253);
        const el254 = getEl('cf_val254'); if (el254) el254.textContent = fmt(ligne254);
        const el255 = getEl('cf_val255'); if (el255) el255.textContent = fmt(ligne255);

        updateSummary();
    }

    function recalcTL() {
        const tauxTL = <?= $tauxTL ?? 1 ?>;
        const ligne211 = <?= $tl_ligne211 ?? 0 ?>;
        const v212 = parseInput('tl_ligne212');

        const ligne213 = ligne211 + v212;
        const ligne219 = Math.floor(ligne213 / 1000) * 1000;
        const ligne224 = Math.round(ligne219 * tauxTL / 100);

        const el213 = getEl('tl_val213'); if (el213) el213.textContent = fmt(ligne213);
        const el219 = getEl('tl_val219'); if (el219) el219.textContent = fmt(ligne219);
        const el224 = getEl('tl_val224'); if (el224) el224.textContent = fmt(ligne224);

        updateSummary();
    }

    function recalcTvaLocation() {
        const tauxTVA = 18;
        const ligne131 = <?= $loc_ligne131 ?? 0 ?>;
        const v132 = parseInput('loc_ligne132');
        const v133 = parseInput('loc_ligne133');
        const v137 = parseInput('loc_ligne137');
        const v141 = parseInput('loc_ligne141');
        const v145 = parseInput('loc_ligne145');

        const ligne142 = v132 + v133 + v137 + v141;
        const ligne143 = Math.max(0, ligne131 - ligne142);
        const ligne144 = Math.round(ligne143 * tauxTVA / 100);
        const ligne150 = Math.max(0, ligne144 - v145);

        const el142 = getEl('loc_val142'); if (el142) el142.textContent = fmt(ligne142);
        const el143 = getEl('loc_val143'); if (el143) el143.textContent = fmt(ligne143);
        const el144 = getEl('loc_val144'); if (el144) el144.textContent = fmt(ligne144);
        const el150 = getEl('loc_val150'); if (el150) el150.textContent = fmt(ligne150);
        
        updateSummary();
    }

    function updateSummary() {
        const tva = parseValue('tva_val131');
        const cf = parseValue('cf_val255');
        const tl = parseValue('tl_val224');
        
        let its = 0;
        const itsEl1 = getEl('its_input');
        const itsEl2 = document.querySelector('input[name="its"]');
        if (itsEl1) its = parseFloat(itsEl1.value.replace(/\s/g, '').replace(',', '.')) || 0;
        else if (itsEl2) its = parseFloat(itsEl2.value.replace(/\s/g, '').replace(',', '.')) || 0;

        const tvaLoc = parseValue('loc_val150');

        let caGlobal = 0;
        const caInput = getEl('ca_global_input');
        if (caInput) caGlobal = parseFloat(caInput.value.replace(/\s/g, '').replace(',', '.')) || 0;
        else caGlobal = <?= $caGlobal ?>;
        
        let loyersPercus = 0;
        const loyersInput = getEl('inputLoyersPercus');
        if (loyersInput) loyersPercus = parseFloat(loyersInput.value.replace(/\s/g, '').replace(',', '.')) || 0;
        else loyersPercus = <?= $loyersPercus ?>;
        
        const tf = <?= $irfTfActif ? '1' : '0' ?> ? Math.round(loyersPercus * <?= $tauxTF ?> / 100) : 0;
        const irf = <?= $irfTfActif ? '1' : '0' ?> ? Math.round(loyersPercus * <?= $tauxIRF ?> / 100) : 0;
        const css = Math.round(caGlobal * <?= $tauxCSS ?> / 100);
        const tvaLocFinal = <?= $locationActif ? '1' : '0' ?> ? tvaLoc : 0;

        const sTva = getEl('summary-tva'); if (sTva) sTva.textContent = fmt(tva);
        const sCf = getEl('summary-cf'); if (sCf) sCf.textContent = fmt(cf);
        const sTl = getEl('summary-tl'); if (sTl) sTl.textContent = fmt(tl);
        const sIts = getEl('summary-its'); if (sIts) sIts.textContent = fmt(its);
        const sTf = getEl('summary-tf'); if (sTf) sTf.textContent = fmt(tf);
        const sIrf = getEl('summary-irf'); if (sIrf) sIrf.textContent = fmt(irf);
        const sCss = getEl('summary-css'); if (sCss) sCss.textContent = fmt(css);
        const sRas = getEl('summary-ras'); if (sRas) sRas.textContent = fmt(<?= $rasActif ? '1' : '0' ?> ? parseValue('ras_val430') : 0);
        const sLoc = getEl('summary-tva-location'); if (sLoc) sLoc.textContent = fmt(tvaLocFinal);

        const ras = <?= $rasActif ? '1' : '0' ?> ? parseValue('ras_val430') : 0;
        const total = tva + cf + tl + its + tf + irf + css + tvaLocFinal + ras;
        const totalGlob = getEl('total-global'); if (totalGlob) totalGlob.textContent = fmt(total);
    }

    function recalcRAS() {
        const l401 = parseInput('ras_ligne401');
        const l403 = parseInput('ras_ligne403');
        const l404 = parseInput('ras_ligne404');
        const l405 = parseInput('ras_ligne405');
        const l406 = parseInput('ras_ligne406');
        const l411 = parseInput('ras_ligne411');
        const l412 = parseInput('ras_ligne412');
        const l413 = parseInput('ras_ligne413');
        const l418 = parseInput('ras_ligne418');
        const l419 = parseInput('ras_ligne419');
        const l425 = parseInput('ras_ligne425');

        const l410 = l401 + l403 + l404 + l405 + l406;
        const l414 = l411 + l412 + l413;
        const l415 = Math.max(0, l410 - l414);
        const l416 = Math.round(l415 * 0.50);
        const l417 = l415 - l416;
        const l420 = Math.max(0, l418 - l419);
        const l421 = Math.round(l420 * 0.90);
        const l422 = l420 - l421;
        const l423 = l417 + l422;
        const l424 = Math.round(l423 * 0.30);
        const l426 = Math.round(l425 * 0.15);
        const l430 = l424 + l426;

        const setTxt = (id, val) => { const el = getEl(id); if (el) el.textContent = fmt(val); };
        setTxt('ras_val410', l410);
        setTxt('ras_val414', l414);
        setTxt('ras_val415', l415);
        setTxt('ras_val416', l416);
        setTxt('ras_val417', l417);
        setTxt('ras_val420', l420);
        setTxt('ras_val421', l421);
        setTxt('ras_val422', l422);
        setTxt('ras_val423', l423);
        setTxt('ras_val424', l424);
        setTxt('ras_val426', l426);
        setTxt('ras_val430', l430);

        updateSummary();
    }

    function recalcAll() {
        if (typeof recalcTVA === 'function') recalcTVA();
        if (typeof recalcCF === 'function') recalcCF();
        if (typeof recalcTL === 'function') recalcTL();
        if (typeof recalcTvaLocation === 'function') recalcTvaLocation();
        if (typeof recalcRAS === 'function') recalcRAS();
        updateSummary();
    }

    // Gestion du repli de la section Bases de Calcul
    function toggleSectionBases(forceState = null) {
        const content = getEl('contentBasesCalcul');
        const icon = getEl('iconToggleBases');
        const label = getEl('labelStatusBases');
        
        if (!content) return;

        let isHidden = content.classList.contains('hidden');
        if (forceState !== null) isHidden = !forceState;

        if (isHidden) {
            // Développer
            content.classList.remove('hidden');
            if (icon) icon.classList.remove('rotate-180');
            if (label) label.textContent = 'Réduire';
            localStorage.setItem('section_bases_visible', 'true');
        } else {
            // Réduire
            content.classList.add('hidden');
            if (icon) icon.classList.add('rotate-180');
            if (label) label.textContent = 'Développer';
            localStorage.setItem('section_bases_visible', 'false');
        }
    }

    window.addEventListener('beforeunload', () => {
        localStorage.setItem('impots_scroll_pos', window.scrollY);
        localStorage.setItem('impots_active_tab', new URLSearchParams(window.location.search).get('type') || 'tva');
    });

    document.addEventListener('DOMContentLoaded', () => {
        const scrollPos = localStorage.getItem('impots_scroll_pos');
        if (scrollPos) {
            window.scrollTo(0, parseInt(scrollPos));
            localStorage.removeItem('impots_scroll_pos');
        }

        // Initialiser l'état de la section Bases de calcul
        const basesVisible = localStorage.getItem('section_bases_visible');
        if (basesVisible === 'false') {
            toggleSectionBases(false);
        }

        recalcAll();
    });
    </script>
</body>
</html>

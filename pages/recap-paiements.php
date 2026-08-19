<?php
/**
 * ============================================
 * RÉCAPITULATIF DES IMPÔTS À PAYER
 * Format imprimable PDF - Style simple
 * ============================================
 */

session_start();
define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/classes/Agent.php';
require_once APP_ROOT . '/classes/Client.php';
require_once APP_ROOT . '/classes/Achat.php';
require_once APP_ROOT . '/classes/Impot.php';

// Vérifier l'authentification
if (!Agent::estConnecte() || !Agent::verifierTimeout()) {
    header('Location: ../index.php');
    exit;
}

$db = Database::getInstance();
$agent = Agent::getAgentConnecte();

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

// Récupérer les totaux achats pour calculer la TVA
$totauxAchats = Achat::getTotaux($clientId, $mois, $annee);

// Récupérer le compte de gestion
$compteGestion = $db->fetchOne(
    "SELECT * FROM compte_gestion_mensuel WHERE client_id = ? AND mois = ? AND annee = ?",
    [$clientId, $mois, $annee]
);

$ligne112Deductible = (isset($compteGestion['tva_ligne112']) && (float)$compteGestion['tva_ligne112'] > 0)
    ? (float)$compteGestion['tva_ligne112']
    : (float)($totauxAchats['tva_deductible'] ?? 0);

// Récupérer les paramètres fiscaux
$parametres = $db->fetchOne("SELECT * FROM parametres_fiscaux WHERE client_id = ?", [$clientId]);

// Données de base
$masseSalariale = $compteGestion['masse_salariale'] ?? 0;
$loyersPercus = $compteGestion['loyers_percus'] ?? 0;
$margeSauvegardee = (float)($compteGestion['marge'] ?? 1.30);
$margeTaxableSauvegardee = (float)($compteGestion['marge_taxable'] ?? 1.30);

// Récupérer le taux TVA du client depuis parametres_fiscaux (18% ou 5%)
$tauxTVA = $parametres ? (float)($parametres['taux_tva'] ?? 18.00) : 18.00;
$tauxTVADouble = $parametres ? (int)($parametres['taux_tva_double'] ?? 0) : 0;

// Calcul du CA
$achatsHT_Reel = $totauxAchats['total_ht'] ?? 0;
$achatsHT = $totauxAchats['total_ht_ca'] ?? $achatsHT_Reel;
$tvaDeductibleAchats = $totauxAchats['total_tva'] ?? 0;

// Utiliser le taux de TVA du client pour le calcul de la base taxable depuis la TVA
$tauxTVADecimal = ($tauxTVA > 0) ? ($tauxTVA / 100) : 0.18;
$achatsTaxable = ($tvaDeductibleAchats > 0) ? round($tvaDeductibleAchats / $tauxTVADecimal) : 0;

// CA Global : Priorité à la valeur sauvegardée si elle existe
$caGlobal = (float)($compteGestion['ca_global'] ?? 0);
if ($caGlobal == 0 && $margeSauvegardee > 0 && $achatsHT > 0) {
    $caGlobal = round($achatsHT * $margeSauvegardee);
}

// CA Taxable : Priorité à la valeur sauvegardée
$caTaxable = (float)($compteGestion['ca_taxable'] ?? 0);
if ($caTaxable == 0 && $margeTaxableSauvegardee > 0 && $achatsTaxable > 0) {
    $caTaxable = round($achatsTaxable * $margeTaxableSauvegardee);
}

// Récupérer le type de TVA du client
$typeTva = $parametres ? ($parametres['type_tva'] ?? 'non_exonere') : 'non_exonere';

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
// Récupérer les paramètres fiscaux (taux CF, TL, etc.)
$tauxCF = $parametres['taux_cf'] ?? 3.5;
$tauxTL = $parametres['taux_tl'] ?? 1.0;
$tauxCSS = $parametres['taux_css'] ?? 0.5;
$tauxTF = $parametres['taux_tf'] ?? 3.0;
$tauxIRF = $parametres['taux_irf'] ?? 12.0;

// Calcul TVA Nette (logique déclaration : ligne 131 = 111 - 125)
if ($tauxTVADouble) {
    $base5 = (float)($compteGestion['tva_ligne102'] ?? 0);
    $val105 = round(($base5 + (float)($compteGestion['tva_ligne103'] ?? 0)) * 5 / 100);
    $base18 = max(0, $caTaxable - $base5);
    $val109 = round(($base18 + (float)($compteGestion['tva_ligne107'] ?? 0)) * 18 / 100);
} else {
    $val105 = ($tauxTVA == 5) ? round(($caTaxable + (float)($compteGestion['tva_ligne103'] ?? 0)) * 5 / 100) : 0;
    $val109 = ($tauxTVA == 18) ? round(($caTaxable + (float)($compteGestion['tva_ligne107'] ?? 0)) * 18 / 100) : 0;
}
$tvaBrute = $val105 + $val109 + (float)($compteGestion['tva_ligne110'] ?? 0);
$tvaDeductions = $ligne112Deductible + 
                 (float)($compteGestion['tva_ligne113'] ?? 0) + 
                 (float)($compteGestion['tva_ligne114'] ?? 0) + 
                 (float)($compteGestion['tva_ligne115'] ?? 0) + 
                 (float)($compteGestion['tva_ligne116'] ?? 0) + 
                 (float)($compteGestion['tva_ligne117'] ?? 0) + 
                 (float)($compteGestion['tva_ligne118'] ?? 0) + 
                 (float)($compteGestion['tva_ligne120'] ?? 0);
$tvaNet = max(0, $tvaBrute - $tvaDeductions);

// Calcul Contribution Forfaitaire (CF) - Formule déclaration complète
$cf_ligne243 = (float)($compteGestion['cf_ligne243'] ?? 0);
$cf_brut = $masseSalariale + $cf_ligne243;
$cf_exonerations = (float)($compteGestion['cf_ligne246'] ?? 0) + (float)($compteGestion['cf_ligne247'] ?? 0) + 
                    (float)($compteGestion['cf_ligne248'] ?? 0) + (float)($compteGestion['cf_ligne249'] ?? 0) + 
                    (float)($compteGestion['cf_ligne250'] ?? 0) + (float)($compteGestion['cf_ligne251'] ?? 0);
$cf_base_arrondie = floor(max(0, $cf_brut - $cf_exonerations) / 1000) * 1000;
$cf = round($cf_base_arrondie * $tauxCF / 100);

// Calcul Taxe Logement (TL) - Formule déclaration complète
$tl_ligne212 = (float)($compteGestion['tl_ligne212'] ?? 0);
$tl_brut = $masseSalariale + $tl_ligne212;
$tl_base_arrondie = floor($tl_brut / 1000) * 1000;
$tl = round($tl_base_arrondie * $tauxTL / 100);

// ITS (saisi manuellement dans le compte de gestion)
$its = (float)($compteGestion['its'] ?? 0);

// CSS
$css = round($caGlobal * $tauxCSS / 100);

// Impôts sur location (IRF + TF liés ensemble)
$irfTfActif = $parametres ? (int)($parametres['irf_tf_actif'] ?? 0) : 0;
$locationActif = $parametres ? (int)($parametres['location_actif'] ?? 0) : 0;
$rasActif = $parametres ? (int)($parametres['ras_actif'] ?? 0) : 0;
$tf = $irfTfActif ? round($loyersPercus * $tauxTF / 100) : 0;
$irf = $irfTfActif ? round($loyersPercus * $tauxIRF / 100) : 0;

// TVA/Location
$locExonere = (float)($compteGestion['loc_ligne132'] ?? 0) + 
               (float)($compteGestion['loc_ligne133'] ?? 0) + 
               (float)($compteGestion['loc_ligne137'] ?? 0) + 
               (float)($compteGestion['loc_ligne141'] ?? 0);
$locBase = max(0, $loyersPercus - $locExonere);
$locTvaCollectee = round($locBase * 18 / 100);
$tvaLocation = $locationActif ? max(0, $locTvaCollectee - (float)($compteGestion['loc_ligne145'] ?? 0)) : 0;

// Retenue à la Source BIC/IS (si activée pour ce client)
// $compteGestion peut être null (aucune ligne compte_gestion_mensuel enregistrée
// pour ce mois) : calculerRetenueSourceBIC() exige un array, d'où le TypeError
// fatal qui faisait planter toute la page (donc tout le récap, TVA comprise)
// avant même d'atteindre le tableau HTML.
$ras = $rasActif ? Impot::calculerRetenueSourceBIC($compteGestion ?? [])['430'] : 0;

// Taxe Touristique (Loi n°96-052) - Lig. 510 x 520, si activée pour ce client
$taxeTouristiqueActif = $parametres ? (int)($parametres['taxe_touristique_actif'] ?? 0) : 0;
$taxeTouristique = $taxeTouristiqueActif
    ? round((float)($compteGestion['taxe_touristique_ligne510'] ?? 0) * (float)($compteGestion['taxe_touristique_ligne520'] ?? 0), 2)
    : 0;

// Total
$totalImpots = $tvaNet + $cf + $tl + $its + $css + $irf + $tf + $tvaLocation + $ras + $taxeTouristique;

// Formatage
function formatMontant($montant) {
    return number_format($montant, 0, ',', ' ');
}

$dateGeneration = date('d/m/Y');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Récap Paiements - <?= htmlspecialchars($client['nom']) ?> - <?= $moisNoms[$mois] ?> <?= $annee ?></title>
    <style>
        @page {
            size: A4;
            margin: 20mm;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12pt;
            color: #000;
            background: #e5e5e5;
        }
        
        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            background: #fff;
            padding: 25mm 20mm;
            box-shadow: 0 0 15px rgba(0,0,0,0.2);
        }
        
        /* Titre */
        .title {
            text-align: center;
            font-size: 16pt;
            font-weight: bold;
            margin-bottom: 25px;
            padding-bottom: 10px;
            border-bottom: 2px solid #000;
        }
        
        /* Infos client */
        .client-info {
            margin-bottom: 20px;
            line-height: 1.6;
        }
        
        .client-info .nom {
            font-size: 14pt;
            font-weight: bold;
        }
        
        .client-info .detail {
            font-size: 11pt;
        }
        
        /* Tableau */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        thead th {
            background: #333;
            color: #fff;
            padding: 10px 12px;
            text-align: left;
            font-size: 11pt;
        }
        
        thead th:last-child {
            text-align: right;
        }
        
        tbody td {
            padding: 10px 12px;
            border-bottom: 1px solid #ddd;
            font-size: 11pt;
        }
        
        tbody td:last-child {
            text-align: right;
            font-family: 'Consolas', 'Courier New', monospace;
        }
        
        tbody tr:nth-child(even) {
            background: #f5f5f5;
        }
        
        .zero {
            color: #999;
        }
        
        .total-row {
            background: #333 !important;
            color: #fff;
        }
        
        .total-row td {
            padding: 12px;
            font-weight: bold;
            font-size: 12pt;
            border-bottom: none;
        }
        
        /* Boutons (non imprimés) */
        .actions {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: #222;
            padding: 10px 20px;
            display: flex;
            justify-content: center;
            gap: 10px;
            z-index: 100;
        }
        
        .actions a, .actions button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            border-radius: 5px;
            cursor: pointer;
            border: none;
            color: #fff;
        }
        
        .actions .btn-print { background: #dc2626; }
        .actions .btn-print:hover { background: #b91c1c; }
        .actions .btn-back { background: #555; }
        .actions .btn-back:hover { background: #444; }
        .actions .btn-edit { background: #0891b2; }
        .actions .btn-edit:hover { background: #0e7490; }
        
        .page-wrapper { padding-top: 60px; }
        
        @media print {
            .actions { display: none !important; }
            .page-wrapper { padding-top: 0; }
            body { background: #fff; }
            .page {
                width: 100%;
                margin: 0;
                padding: 0;
                box-shadow: none;
                min-height: auto;
            }
            /* L'impression des couleurs de fond dépend du pilote d'imprimante et
               des réglages système, qui varient d'un poste à l'autre (certains
               postes n'impriment pas les fonds même avec print-color-adjust:exact).
               Le texte blanc de l'entête et de la ligne de total ne doit donc jamais
               reposer uniquement sur un fond sombre pour rester lisible : on bascule
               sur du texte noir + un contour, qui s'imprime toujours. */
            thead th {
                background: #fff !important;
                color: #000 !important;
                border-bottom: 2px solid #000;
            }
            .total-row,
            .total-row td {
                background: #fff !important;
                color: #000 !important;
            }
            .total-row td {
                border-top: 2px solid #000;
                border-bottom: 2px solid #000 !important;
            }
            tbody tr:nth-child(even) {
                background: #f5f5f5 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <!-- Boutons d'action -->
    <div class="actions">
        <button class="btn-print" onclick="window.print()">🖨️ Imprimer / PDF</button>
        <a href="recapitulatif.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" class="btn-back">← Retour</a>
        <a href="impots.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" class="btn-edit">✏️ Modifier Impôts</a>
    </div>

    <div class="page-wrapper">
        <div class="page">
            
            <div class="title">RÉCAPITULATIF DES IMPÔTS À PAYER</div>
            
            <div class="client-info">
                <div class="nom"><?= htmlspecialchars(mb_strtoupper($client['nom'])) ?></div>
                <?php if (!empty($client['ifu'])): ?>
                <div class="detail">NIF: <?= htmlspecialchars($client['ifu']) ?></div>
                <?php endif; ?>
                <?php if (!empty($client['adresse'])): ?>
                <div class="detail"><?= htmlspecialchars($client['adresse']) ?></div>
                <?php endif; ?>
                <div class="detail">Mois : <?= $moisNoms[$mois] ?></div>
                <div class="detail">Généré le : <?= $dateGeneration ?></div>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Libellé</th>
                        <th>Montant</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>TVA Nette</td>
                        <td class="<?= $tvaNet == 0 ? 'zero' : '' ?>"><?= formatMontant($tvaNet) ?></td>
                    </tr>
                    <tr>
                        <td>Contributions Forfaitaires</td>
                        <td class="<?= $cf == 0 ? 'zero' : '' ?>"><?= formatMontant($cf) ?></td>
                    </tr>
                    <tr>
                        <td>Taxe Logements</td>
                        <td class="<?= $tl == 0 ? 'zero' : '' ?>"><?= formatMontant($tl) ?></td>
                    </tr>
                    <tr>
                        <td>ITS</td>
                        <td class="<?= $its == 0 ? 'zero' : '' ?>"><?= formatMontant($its) ?></td>
                    </tr>
                    <tr>
                        <td>CSS</td>
                        <td class="<?= $css == 0 ? 'zero' : '' ?>"><?= formatMontant($css) ?></td>
                    </tr>
                    <?php if ($rasActif): ?>
                    <tr>
                        <td>Retenue BIC/IS</td>
                        <td class="<?= $ras == 0 ? 'zero' : '' ?>"><?= formatMontant($ras) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($irfTfActif): ?>
                    <tr>
                        <td>Impôt sur les Revenus Fonciers</td>
                        <td class="<?= $irf == 0 ? 'zero' : '' ?>"><?= formatMontant($irf) ?></td>
                    </tr>
                    <tr>
                        <td>Taxe Foncière</td>
                        <td class="<?= $tf == 0 ? 'zero' : '' ?>"><?= formatMontant($tf) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($locationActif): ?>
                    <tr>
                        <td>TVA sur Location</td>
                        <td class="<?= $tvaLocation == 0 ? 'zero' : '' ?>"><?= formatMontant($tvaLocation) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($taxeTouristiqueActif): ?>
                    <tr>
                        <td>Taxe Touristique</td>
                        <td class="<?= $taxeTouristique == 0 ? 'zero' : '' ?>"><?= formatMontant($taxeTouristique) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="total-row">
                        <td>TOTAL IMPÔTS À PAYER</td>
                        <td><?= formatMontant($totalImpots) ?></td>
                    </tr>
                </tbody>
            </table>
            
        </div>
    </div>
</body>
</html>

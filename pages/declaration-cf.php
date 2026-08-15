<?php
/**
 * ============================================
 * DÉCLARATION CF - CONTRIBUTION FORFAITAIRE
 * Format SIGTAS - Saisie directe
 * ============================================
 */

session_start();
define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/classes/Agent.php';
require_once APP_ROOT . '/classes/Client.php';
require_once APP_ROOT . '/classes/Impot.php';

// Vérifier l'authentification
if (!Agent::estConnecte()) {
    header('Location: ../index.php');
    exit;
}

// Paramètres
$clientId = (int) ($_GET['client_id'] ?? 0);
$mois = (int) ($_GET['mois'] ?? date('n'));
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

// Charger le compte de gestion pour pré-remplir
require_once APP_ROOT . '/classes/CompteGestionMensuel.php';
$compteGestion = new CompteGestionMensuel($clientId, $mois, $annee);
$parametresFiscaux = Database::getInstance()->fetchOne("SELECT * FROM parametres_fiscaux WHERE client_id = ?", [$clientId]);
$tauxCF = $parametresFiscaux ? (float)($parametresFiscaux['taux_cf'] ?? 3.5) : 3.5;

$masseSalariale = $compteGestion->getMasseSalariale() ?? 0;
$cfLigne243 = $compteGestion->getCfLigne243() ?? 0;
$cfLigne246 = $compteGestion->getCfLigne246() ?? 0;
$cfLigne247 = $compteGestion->getCfLigne247() ?? 0;
$cfLigne248 = $compteGestion->getCfLigne248() ?? 0;
$cfLigne249 = $compteGestion->getCfLigne249() ?? 0;
$cfLigne250 = $compteGestion->getCfLigne250() ?? 0;
$cfLigne251 = $compteGestion->getCfLigne251() ?? 0;

// Mois en français
$moisNoms = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

$pageTitle = "Déclaration CF - " . $moisNoms[$mois] . " " . $annee;
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
            nav { display: none !important; }
            input { border: 1px solid #000 !important; }
        }
        input[type="number"] { text-align: right; font-family: monospace; }
        input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2); }
        .ligne-total { background-color: #e8f4e8 !important; font-weight: bold; }
        .ligne-resultat { background-color: #fff3cd !important; font-weight: bold; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Navbar -->
    <?php include APP_ROOT . '/includes/navbar-impots.php'; ?>

    <!-- Contenu -->
    <div class="max-w-5xl mx-auto p-6">
        <!-- En-tête du document -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <div class="text-center mb-6">
                <h1 class="text-2xl font-bold text-gray-800">CONTRIBUTION FORFAITAIRE (CF)</h1>
                <p class="text-lg text-gray-600">Déclaration des Impôts sur Salaires</p>
                <p class="text-gray-500">Période : <?= $moisNoms[$mois] ?> <?= $annee ?></p>
            </div>
            
            <div class="grid grid-cols-2 gap-6 text-sm">
                <div>
                    <p><strong>Entreprise :</strong> <?= htmlspecialchars($client->getNom()) ?></p>
                    <p><strong>NIF :</strong> <?= htmlspecialchars($client->getIfu() ?? 'Non renseigné') ?></p>
                    <p><strong>Date Limite :</strong> <span class="text-red-700 font-bold"><?= Impot::getEcheanceFormatee($mois, $annee) ?></span></p>
                </div>
                <div class="text-right">
                    <p><strong>Date d'impression :</strong> <?= date('d/m/Y') ?></p>
                </div>
            </div>
        </div>

        <!-- Tableau CF -->
        <div class="bg-white rounded-lg shadow mb-6 overflow-hidden">
            <div class="bg-purple-600 text-white px-6 py-3">
                <h2 class="text-lg font-semibold">CONTRIBUTION FORFAITAIRE - LIGNES 242 À 255</h2>
            </div>
            
            <table class="w-full text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-4 py-3 text-left w-1/2">Désignation</th>
                        <th class="px-4 py-3 text-left w-1/4">Annexe fiscale</th>
                        <th class="px-4 py-3 text-center w-20">Ligne</th>
                        <th class="px-4 py-3 text-right w-40">Montant (FCFA)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-t">
                        <td class="px-4 py-3">Salaire Brut Mensuel des Employés</td>
                        <td class="px-4 py-3 text-gray-400">................................................................</td>
                        <td class="px-4 py-3 text-center font-mono">242</td>
                        <td class="px-4 py-3 text-right">
                            <input type="number" id="ligne242" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $masseSalariale ?>" step="0.01" onchange="calculerCF()">
                        </td>
                    </tr>
                    <tr class="border-t bg-gray-50">
                        <td class="px-4 py-3">Avantages en Espèces et ou en Nature accordés aux employés</td>
                        <td class="px-4 py-3 text-gray-400">................................................................</td>
                        <td class="px-4 py-3 text-center font-mono">243</td>
                        <td class="px-4 py-3 text-right">
                            <input type="number" id="ligne243" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $cfLigne243 ?>" step="0.01" onchange="calculerCF()">
                        </td>
                    </tr>
                    <tr class="border-t ligne-total">
                        <td class="px-4 py-3">Total Brut des salaires et avantages (Lig. 242 + 243)</td>
                        <td class="px-4 py-3 text-gray-400">................................................................</td>
                        <td class="px-4 py-3 text-center font-mono">245</td>
                        <td class="px-4 py-3 text-right font-mono" id="ligne245">0,00</td>
                    </tr>
                    
                    <!-- Exonérations -->
                    <tr class="border-t-4">
                        <td colspan="4" class="px-4 py-2 bg-gray-200 font-semibold">EXONÉRATIONS</td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-3">Sommes ou Avantages (1) en nature exonéré - Article 161 CGI</td>
                        <td class="px-4 py-3 text-gray-400 text-xs">ANNEXE DÉCLARATION D'EXONÉRATION DU SALAIRE DES JEUNES DIPLÔMÉS SANS EMPLOI</td>
                        <td class="px-4 py-3 text-center font-mono">246</td>
                        <td class="px-4 py-3 text-right">
                            <input type="number" id="ligne246" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $cfLigne246 ?>" step="0.01" onchange="calculerCF()">
                        </td>
                    </tr>
                    <tr class="border-t bg-gray-50">
                        <td class="px-4 py-3">Sommes ou Avantages (2) en nature exonéré - Article 162 CGI</td>
                        <td class="px-4 py-3 text-gray-400 text-xs">ANNEXE DÉCLARATION D'EXONÉRATION DU SALAIRE DES COMPRESSÉS POUR RAISON ÉCONOMIQUE</td>
                        <td class="px-4 py-3 text-center font-mono">247</td>
                        <td class="px-4 py-3 text-right">
                            <input type="number" id="ligne247" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $cfLigne247 ?>" step="0.01" onchange="calculerCF()">
                        </td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-3">Allocation versée aux stagiaires - Article 163 CGI</td>
                        <td class="px-4 py-3 text-gray-400 text-xs">ANNEXE DÉCLARATION D'EXONÉRATION DES ALLOCATIONS VERSÉES AUX STAGIAIRES</td>
                        <td class="px-4 py-3 text-center font-mono">248</td>
                        <td class="px-4 py-3 text-right">
                            <input type="number" id="ligne248" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $cfLigne248 ?>" step="0.01" onchange="calculerCF()">
                        </td>
                    </tr>
                    <tr class="border-t bg-gray-50">
                        <td class="px-4 py-3">Indemnités Non Imposables à la CF</td>
                        <td class="px-4 py-3 text-gray-400">................................................................</td>
                        <td class="px-4 py-3 text-center font-mono">249</td>
                        <td class="px-4 py-3 text-right">
                            <input type="number" id="ligne249" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $cfLigne249 ?>" step="0.01" onchange="calculerCF()">
                        </td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-3">Sommes, avantages (3) exonérés de CF - Code Investissement</td>
                        <td class="px-4 py-3 text-gray-400 text-xs">ANNEXE DÉCLARATION EXONÉRATION SUIVANT CODE I M P PI</td>
                        <td class="px-4 py-3 text-center font-mono">250</td>
                        <td class="px-4 py-3 text-right">
                            <input type="number" id="ligne250" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $cfLigne250 ?>" step="0.01" onchange="calculerCF()">
                        </td>
                    </tr>
                    <tr class="border-t bg-gray-50">
                        <td class="px-4 py-3">Sommes, avantages exonérés de CF - Accord Cadre ONG</td>
                        <td class="px-4 py-3 text-gray-400 text-xs">ANNEXE DÉCLARATION D'EXONÉRATION À LA CF SUIVANT ACCORD CADRE ONG</td>
                        <td class="px-4 py-3 text-center font-mono">251</td>
                        <td class="px-4 py-3 text-right">
                            <input type="number" id="ligne251" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $cfLigne251 ?>" step="0.01" onchange="calculerCF()">
                        </td>
                    </tr>
                    <tr class="border-t ligne-total">
                        <td class="px-4 py-3">Total Salaires ou avantages exonéré de CF - L.246à251</td>
                        <td class="px-4 py-3 text-gray-400">................................................................</td>
                        <td class="px-4 py-3 text-center font-mono">252</td>
                        <td class="px-4 py-3 text-right font-mono" id="ligne252">0,00</td>
                    </tr>
                    
                    <!-- Calcul CF -->
                    <tr class="border-t-4">
                        <td colspan="4" class="px-4 py-2 bg-gray-200 font-semibold">CALCUL DE LA CONTRIBUTION FORFAITAIRE</td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-3">Total Net Imposable (Lig. 245 - 252)</td>
                        <td class="px-4 py-3 text-gray-400">................................................................</td>
                        <td class="px-4 py-3 text-center font-mono">253</td>
                        <td class="px-4 py-3 text-right font-mono" id="ligne253">0,00</td>
                    </tr>
                    <tr class="border-t bg-gray-50">
                        <td class="px-4 py-3">Base Soumise à la CF - (Lig. 253 Arrondie)</td>
                        <td class="px-4 py-3 text-gray-400">................................................................</td>
                        <td class="px-4 py-3 text-center font-mono">254</td>
                        <td class="px-4 py-3 text-right font-mono" id="ligne254">0,00</td>
                    </tr>
                    <tr class="border-t ligne-resultat">
                        <td class="px-4 py-3 text-lg">Contribution Forfaitaire à Payer L.254 x <?= number_format($tauxCF, 1, ',', '') ?>%</td>
                        <td class="px-4 py-3 text-gray-400">................................................................</td>
                        <td class="px-4 py-3 text-center font-mono text-lg">255</td>
                        <td class="px-4 py-3 text-right font-mono text-lg" id="ligne255">0,00</td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-3">Taux de l'Impôt</td>
                        <td class="px-4 py-3 text-gray-400">................................................................</td>
                        <td class="px-4 py-3 text-center font-mono">500</td>
                        <td class="px-4 py-3 text-right font-mono"><?= number_format($tauxCF, 2, ',', '') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pied de page -->
        <div class="text-center text-sm text-gray-500 mt-8">
            <p>Document généré le <?= date('d/m/Y à H:i') ?></p>
            <p>Système de Gestion Fiscale - <?= $client->getNom() ?></p>
        </div>
    </div>

    <script>
    function formatMontant(montant) {
        return new Intl.NumberFormat('fr-FR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(montant);
    }

    function getVal(id) {
        return parseFloat(document.getElementById(id).value) || 0;
    }

    function setVal(id, val) {
        document.getElementById(id).textContent = formatMontant(val);
    }

    function calculerCF() {
        // Total brut (245)
        const ligne242 = getVal('ligne242');
        const ligne243 = getVal('ligne243');
        const ligne245 = ligne242 + ligne243;
        setVal('ligne245', ligne245);
        
        // Total exonérations (252)
        const ligne246 = getVal('ligne246');
        const ligne247 = getVal('ligne247');
        const ligne248 = getVal('ligne248');
        const ligne249 = getVal('ligne249');
        const ligne250 = getVal('ligne250');
        const ligne251 = getVal('ligne251');
        const ligne252 = ligne246 + ligne247 + ligne248 + ligne249 + ligne250 + ligne251;
        setVal('ligne252', ligne252);
        
        // Net imposable (253)
        const ligne253 = Math.max(0, ligne245 - ligne252);
        setVal('ligne253', ligne253);
        
        // Base arrondie (254)
        const ligne254 = Math.floor(ligne253 / 1000) * 1000;
        setVal('ligne254', ligne254);
        
        // CF à payer (255)
        const tauxCF = <?= $tauxCF ?> / 100;
        const ligne255 = Math.round(ligne254 * tauxCF);
        setVal('ligne255', ligne255);
    }

    document.addEventListener('DOMContentLoaded', calculerCF);
    </script>
</body>
</html>

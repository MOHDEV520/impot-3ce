<?php
/**
 * ============================================
 * DÉCLARATION TL - TAXE LOGEMENT
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
$tauxTL = $parametresFiscaux ? (float)($parametresFiscaux['taux_tl'] ?? 1.0) : 1.0;

$masseSalariale = $compteGestion->getMasseSalariale() ?? 0;
$tlLigne212 = $compteGestion->getTlLigne212() ?? 0;

// Mois en français
$moisNoms = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

$pageTitle = "Déclaration TL - " . $moisNoms[$mois] . " " . $annee;
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
                <h1 class="text-2xl font-bold text-gray-800">TAXE - LOGEMENT (TL)</h1>
                <p class="text-gray-600">Période : <?= $moisNoms[$mois] ?> <?= $annee ?></p>
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

        <!-- Tableau TL -->
        <div class="bg-white rounded-lg shadow mb-6 overflow-hidden">
            <div class="bg-teal-600 text-white px-6 py-3">
                <h2 class="text-lg font-semibold">TAXE LOGEMENT - LIGNES 211 À 224</h2>
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
                        <td class="px-4 py-3 text-center font-mono">211</td>
                        <td class="px-4 py-3 text-right">
                            <input type="number" id="ligne211" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $masseSalariale ?>" step="0.01" onchange="calculerTL()">
                        </td>
                    </tr>
                    <tr class="border-t bg-gray-50">
                        <td class="px-4 py-3">Avantages Mensuels en Espèces et ou en Nature des Employés</td>
                        <td class="px-4 py-3 text-gray-400">................................................................</td>
                        <td class="px-4 py-3 text-center font-mono">212</td>
                        <td class="px-4 py-3 text-right">
                            <input type="number" id="ligne212" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $tlLigne212 ?>" step="0.01" onchange="calculerTL()">
                        </td>
                    </tr>
                    <tr class="border-t ligne-total">
                        <td class="px-4 py-3">Total Brut (Lig. 211 + 212)</td>
                        <td class="px-4 py-3 text-gray-400">................................................................</td>
                        <td class="px-4 py-3 text-center font-mono">213</td>
                        <td class="px-4 py-3 text-right font-mono" id="ligne213">0,00</td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-3">Base Soumise à la TL Arrondie</td>
                        <td class="px-4 py-3 text-gray-400">................................................................</td>
                        <td class="px-4 py-3 text-center font-mono">219</td>
                        <td class="px-4 py-3 text-right font-mono" id="ligne219">0,00</td>
                    </tr>
                    <tr class="border-t ligne-resultat">
                        <td class="px-4 py-3 font-medium">Taxe - Logement à Payer</td>
                        <td class="px-4 py-3 text-gray-400">................................................................</td>
                        <td class="px-4 py-3 text-center font-mono font-bold">224</td>
                        <td class="px-4 py-3 text-right font-mono font-bold" id="ligne224">0,00</td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-3">Taux de l'Impôt</td>
                        <td class="px-4 py-3 text-gray-400">................................................................</td>
                        <td class="px-4 py-3 text-center font-mono">500</td>
                        <td class="px-4 py-3 text-right font-mono"><?= number_format($tauxTL, 2, ',', '') ?></td>
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

    function calculerTL() {
        // Total brut (213)
        const ligne211 = getVal('ligne211');
        const ligne212 = getVal('ligne212');
        const ligne213 = ligne211 + ligne212;
        setVal('ligne213', ligne213);
        
        // Base arrondie (219)
        const ligne219 = Math.floor(ligne213 / 1000) * 1000;
        setVal('ligne219', ligne219);
        
        // TL à payer (224)
        const tauxTL = <?= $tauxTL ?> / 100;
        const ligne224 = Math.round(ligne219 * tauxTL);
        setVal('ligne224', ligne224);
    }

    document.addEventListener('DOMContentLoaded', calculerTL);
    </script>
</body>
</html>

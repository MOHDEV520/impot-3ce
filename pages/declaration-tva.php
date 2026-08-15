<?php
/**
 * ============================================
 * DÉCLARATION TVA - FORMAT SIGTAS
 * Avec saisie directe des données
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

// Charger les données existantes
require_once APP_ROOT . '/classes/CompteGestionMensuel.php';
$totauxAchats = Achat::getTotaux($clientId, $mois, $annee);
$tvaDeductible = $totauxAchats['tva_deductible'] ?? 0;

// Charger le compte de gestion pour pré-remplir
$compteGestion = new CompteGestionMensuel($clientId, $mois, $annee);
$parametresFiscaux = Database::getInstance()->fetchOne("SELECT * FROM parametres_fiscaux WHERE client_id = ?", [$clientId]);
$tauxTVA = $parametresFiscaux ? (float)$parametresFiscaux['taux_tva'] : 18.00;
$tauxTVADouble = $parametresFiscaux ? (int)($parametresFiscaux['taux_tva_double'] ?? 0) : 0;
$sansMarges = $parametresFiscaux ? (int)($parametresFiscaux['sans_marges'] ?? 0) : 0;
$locationActif = $parametresFiscaux ? (int)($parametresFiscaux['location_actif'] ?? 0) : 0;

// Données pré-remplies depuis le compte de gestion
$caGlobal = $compteGestion->getCaGlobal();
$caTaxable = $compteGestion->getCaTaxable();
$caExonere = $compteGestion->getCaExonere();
$loyersPercus = $compteGestion->getLoyersPercus();

// Lignes TVA sauvegardées
$tvaLigne82 = $compteGestion->getTvaLigne82() ?? 0;
$tvaLigne83 = $compteGestion->getTvaLigne83() ?? 0;
$tvaLigne84 = $compteGestion->getTvaLigne84() ?? 0;
$tvaLigne85 = $compteGestion->getTvaLigne85() ?? 0;
$tvaLigne86 = $compteGestion->getTvaLigne86() ?? 0;
$tvaLigne101 = $compteGestion->getTvaLigne101() ?? 0;
$tvaLigne102 = $compteGestion->getTvaLigne102() ?? 0;
$tvaLigne103 = $compteGestion->getTvaLigne103() ?? 0;
$tvaLigne107 = $compteGestion->getTvaLigne107() ?? 0;
$tvaLigne110 = $compteGestion->getTvaLigne110() ?? 0;
$tvaLigne112 = $compteGestion->getTvaLigne112() ?? $tvaDeductible;
$tvaLigne113 = $compteGestion->getTvaLigne113() ?? 0;
$tvaLigne114 = $compteGestion->getTvaLigne114() ?? 0;
$tvaLigne115 = $compteGestion->getTvaLigne115() ?? 0;
$tvaLigne116 = $compteGestion->getTvaLigne116() ?? 0;
$tvaLigne117 = $compteGestion->getTvaLigne117() ?? 0;
$tvaLigne118 = $compteGestion->getTvaLigne118() ?? 0;
$tvaLigne120 = $compteGestion->getTvaLigne120() ?? 0;

// Lignes TVA Location
$locLigne132 = $compteGestion->getLocLigne132() ?? 0;
$locLigne133 = $compteGestion->getLocLigne133() ?? 0;
$locLigne137 = $compteGestion->getLocLigne137() ?? 0;
$locLigne141 = $compteGestion->getLocLigne141() ?? 0;
$locLigne145 = $compteGestion->getLocLigne145() ?? 0;

// Mois en français
$moisNoms = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

$pageTitle = "Déclaration TVA - " . $moisNoms[$mois] . " " . $annee;
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
        .ligne-total { background-color: #e8f4e8 !important; font-weight: bold; }
        .ligne-resultat { background-color: #fff3cd !important; font-weight: bold; }
        .ligne-important { background-color: #d4edda !important; font-weight: bold; }
        input[type="number"] {
            text-align: right;
            font-family: monospace;
        }
        input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
        }
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
                <h1 class="text-2xl font-bold text-gray-800">DÉCLARATION DE TVA</h1>
                <p class="text-gray-600">Période : <?= $moisNoms[$mois] ?> <?= $annee ?></p>
            </div>
            
            <div class="grid grid-cols-2 gap-6 text-sm">
                <div>
                    <p><strong>Entreprise :</strong> <?= htmlspecialchars($client->getNom()) ?></p>
                    <p><strong>NIF :</strong> <?= htmlspecialchars($client->getIfu() ?? 'Non renseigné') ?></p>
                    <p><strong>Date Limite :</strong> <span class="text-red-600 font-bold"><?= Impot::getEcheanceFormatee($mois, $annee) ?></span></p>
                </div>
                <div class="text-right">
                    <p><strong>Date d'impression :</strong> <?= date('d/m/Y') ?></p>
                    <p><strong>Régime :</strong> <?= $client->getRegimeFiscalLibelle() ?></p>
                </div>
            </div>
        </div>

        <!-- Section 1 : Chiffre d'Affaires -->
        <div class="bg-white rounded-lg shadow mb-6 overflow-hidden">
            <div class="bg-blue-600 text-white px-6 py-3">
                <h2 class="text-lg font-semibold">SECTION I - CHIFFRE D'AFFAIRES</h2>
            </div>
            
            <table class="w-full text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-4 py-2 text-left w-1/2">Désignation</th>
                        <th class="px-4 py-2 text-center w-20">Ligne</th>
                        <th class="px-4 py-2 text-right w-40">Montant (FCFA)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-t">
                        <td class="px-4 py-2">Chiffre d'Affaires Global Hors T.V.A Réalisé</td>
                        <td class="px-4 py-2 text-center font-mono">80</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne80" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $caGlobal ?>" step="0.01" onchange="calculerTVA()">
                        </td>
                    </tr>
                    <tr class="border-t bg-gray-50">
                        <td class="px-4 py-2">Chiffre d'Affaires Exonéré Sauf Exportation - Article 195 CGI</td>
                        <td class="px-4 py-2 text-center font-mono">81</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne81" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $caExonere ?>" step="0.01" onchange="calculerTVA()">
                        </td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-2">Chiffre d'Affaires Réalisé à l'Exportation - Article 195.1 CGI</td>
                        <td class="px-4 py-2 text-center font-mono">82</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne82" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $tvaLigne82 ?>" step="0.01" onchange="calculerTVA()">
                        </td>
                    </tr>
                    <tr class="border-t bg-gray-50">
                        <td class="px-4 py-2">C.A Exonéré de TVA - Code des Investissements</td>
                        <td class="px-4 py-2 text-center font-mono">83</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne83" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $tvaLigne83 ?>" step="0.01" onchange="calculerTVA()">
                        </td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-2">C.A Exonéré de T.V.A - Microfinance</td>
                        <td class="px-4 py-2 text-center font-mono">84</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne84" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $tvaLigne84 ?>" step="0.01" onchange="calculerTVA()">
                        </td>
                    </tr>
                    <tr class="border-t bg-gray-50">
                        <td class="px-4 py-2">C.A Exonéré TVA - Conventions Internationales ou Bilatérales</td>
                        <td class="px-4 py-2 text-center font-mono">85</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne85" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $tvaLigne85 ?>" step="0.01" onchange="calculerTVA()">
                        </td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-2">Autres Exonérations de Chiffre d'Affaires à la T.V.A</td>
                        <td class="px-4 py-2 text-center font-mono">86</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne86" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $tvaLigne86 ?>" step="0.01" onchange="calculerTVA()">
                        </td>
                    </tr>
                    <tr class="border-t ligne-total">
                        <td class="px-4 py-2">Chiffre d'Affaires Global Exonéré de T.V.A (Somme Lignes 81 à 86)</td>
                        <td class="px-4 py-2 text-center font-mono">95</td>
                        <td class="px-4 py-2 text-right font-mono" id="ligne95">0,00</td>
                    </tr>
                    <tr class="border-t ligne-important">
                        <td class="px-4 py-2">Chiffre d'Affaires Global Hors T.V.A Taxable (Lig. 80 - 95)</td>
                        <td class="px-4 py-2 text-center font-mono">100</td>
                        <td class="px-4 py-2 text-right font-mono" id="ligne100">0,00</td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-2">Livraison à soi-même, imposable, effectuée</td>
                        <td class="px-4 py-2 text-center font-mono">101</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne101" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $tvaLigne101 ?>" step="0.01" onchange="calculerTVA()">
                        </td>
                    </tr>
                    <tr class="border-t bg-gray-50">
                        <td class="px-4 py-2">Portion du C.A Taxable Soumise au Taux Réduit de T.V.A (5%)</td>
                        <td class="px-4 py-2 text-center font-mono">102</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne102" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $tvaLigne102 ?>" step="0.01" onchange="calculerTVA()">
                        </td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-2">Livraison à soi-même soumise au Taux Réduit de TVA (5%)</td>
                        <td class="px-4 py-2 text-center font-mono">103</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne103" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $tvaLigne103 ?>" step="0.01" onchange="calculerTVA()">
                        </td>
                    </tr>
                    <tr class="border-t bg-gray-50">
                        <td class="px-4 py-2">Base Soumise au Taux Réduit de TVA (5%) (Lig. 102+103)</td>
                        <td class="px-4 py-2 text-center font-mono">104</td>
                        <td class="px-4 py-2 text-right font-mono" id="ligne104">0,00</td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-2">T.V.A Brute Collectée au Taux Réduit (5%) (Lig. 104 x 5%)</td>
                        <td class="px-4 py-2 text-center font-mono">105</td>
                        <td class="px-4 py-2 text-right font-mono" id="ligne105">0,00</td>
                    </tr>
                    <tr class="border-t bg-gray-50">
                        <td class="px-4 py-2">Portion du C.A Taxable Soumise au Taux Normal de TVA (18%)</td>
                        <td class="px-4 py-2 text-center font-mono">106</td>
                        <td class="px-4 py-2 text-right font-mono" id="ligne106">0,00</td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-2">Livraison à soi-même Soumise au Taux Normal de TVA (18%)</td>
                        <td class="px-4 py-2 text-center font-mono">107</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne107" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $tvaLigne107 ?>" step="0.01" onchange="calculerTVA()">
                        </td>
                    </tr>
                    <tr class="border-t ligne-total">
                        <td class="px-4 py-2">Base Soumise au Taux Normal de T.V.A (18%) (L. 106+107)</td>
                        <td class="px-4 py-2 text-center font-mono">108</td>
                        <td class="px-4 py-2 text-right font-mono" id="ligne108">0,00</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Section 2 : Calcul TVA -->
        <div class="bg-white rounded-lg shadow mb-6 overflow-hidden">
            <div class="bg-green-600 text-white px-6 py-3">
                <h2 class="text-lg font-semibold">SECTION II - CALCUL DE LA TVA</h2>
            </div>
            
            <table class="w-full text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-4 py-2 text-left w-1/2">Désignation</th>
                        <th class="px-4 py-2 text-center w-20">Ligne</th>
                        <th class="px-4 py-2 text-right w-40">Montant (FCFA)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-t ligne-important">
                        <td class="px-4 py-2">T.V.A Brute Collectée au Taux Normal (18%) (Lig. 108 x 18%)</td>
                        <td class="px-4 py-2 text-center font-mono">109</td>
                        <td class="px-4 py-2 text-right font-mono" id="ligne109">0,00</td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-2">Reversement de T.V.A Suite à Régularisation</td>
                        <td class="px-4 py-2 text-center font-mono">110</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne110" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $tvaLigne110 ?>" step="0.01" onchange="calculerTVA()">
                        </td>
                    </tr>
                    <tr class="border-t ligne-total">
                        <td class="px-4 py-2">T.V.A Brute Collectée (Lig. 105 + 109 + 110)</td>
                        <td class="px-4 py-2 text-center font-mono">111</td>
                        <td class="px-4 py-2 text-right font-mono" id="ligne111">0,00</td>
                    </tr>
                    
                    <!-- TVA Déductible -->
                    <tr class="border-t-4">
                        <td colspan="3" class="px-4 py-2 bg-gray-200 font-semibold">TVA DÉDUCTIBLE</td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-2">DÉCLARATION DE TVA DÉDUCTIBLE À 100% SUR LES ACHATS LOCAUX</td>
                        <td class="px-4 py-2 text-center font-mono">112</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne112" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $tvaLigne112 ?>" step="0.01" onchange="calculerTVA()">
                        </td>
                    </tr>
                    <tr class="border-t bg-gray-50">
                        <td class="px-4 py-2">DÉCLARATION DE TVA DÉDUCTIBLE À 100% SUR LES IMPORTATIONS</td>
                        <td class="px-4 py-2 text-center font-mono">113</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne113" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $tvaLigne113 ?>" step="0.01" onchange="calculerTVA()">
                        </td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-2">DÉCLARATION DE TVA DÉDUCTIBLE AU PRORATA SUR LES ACHATS LOCAUX</td>
                        <td class="px-4 py-2 text-center font-mono">114</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne114" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $tvaLigne114 ?>" step="0.01" onchange="calculerTVA()">
                        </td>
                    </tr>
                    <tr class="border-t bg-gray-50">
                        <td class="px-4 py-2">DÉCLARATION DE TVA DÉDUCTIBLE AU PRORATA SUR LES IMPORTATIONS</td>
                        <td class="px-4 py-2 text-center font-mono">115</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne115" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $tvaLigne115 ?>" step="0.01" onchange="calculerTVA()">
                        </td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-2">TVA Retenue à la Source par le Trésor</td>
                        <td class="px-4 py-2 text-center font-mono">116</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne116" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $tvaLigne116 ?>" step="0.01" onchange="calculerTVA()">
                        </td>
                    </tr>
                    <tr class="border-t bg-gray-50">
                        <td class="px-4 py-2">Complément de Déduction suite à Régularisation</td>
                        <td class="px-4 py-2 text-center font-mono">117</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne117" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $tvaLigne117 ?>" step="0.01" onchange="calculerTVA()">
                        </td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-2">TVA Retenue à la Source par les Clients</td>
                        <td class="px-4 py-2 text-center font-mono">118</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne118" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $tvaLigne118 ?>" step="0.01" onchange="calculerTVA()">
                        </td>
                    </tr>
                    <tr class="border-t ligne-total">
                        <td class="px-4 py-2">TVA Déductible (Lig. 112 + 113 + 114 + 115 + 117 + 118)</td>
                        <td class="px-4 py-2 text-center font-mono">119</td>
                        <td class="px-4 py-2 text-right font-mono" id="ligne119">0,00</td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-2">Report de Crédit des Mois Précédents</td>
                        <td class="px-4 py-2 text-center font-mono">120</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne120" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="<?= $tvaLigne120 ?>" step="0.01" onchange="calculerTVA()">
                        </td>
                    </tr>
                    <tr class="border-t ligne-total">
                        <td class="px-4 py-2">Total des Déductions Autorisées (Lig. 119 + 120)</td>
                        <td class="px-4 py-2 text-center font-mono">125</td>
                        <td class="px-4 py-2 text-right font-mono" id="ligne125">0,00</td>
                    </tr>
                    
                    <!-- Résultat -->
                    <tr class="border-t-4">
                        <td colspan="3" class="px-4 py-2 bg-gray-200 font-semibold">RÉSULTAT</td>
                    </tr>
                    <tr class="border-t ligne-resultat">
                        <td class="px-4 py-2 text-lg">T.V.A Nette à Payer (Lig. 111 - 125)</td>
                        <td class="px-4 py-2 text-center font-mono text-lg">131</td>
                        <td class="px-4 py-2 text-right font-mono text-lg" id="ligne131">0,00</td>
                    </tr>
                    <tr class="border-t">
                        <td class="px-4 py-2">Crédit de TVA à Reporter (Ligne 125 - 111)</td>
                        <td class="px-4 py-2 text-center font-mono">132</td>
                        <td class="px-4 py-2 text-right font-mono" id="ligne132">0,00</td>
                    </tr>
                    <tr class="border-t bg-gray-50">
                        <td class="px-4 py-2">Crédit de TVA à Rembourser</td>
                        <td class="px-4 py-2 text-center font-mono">133</td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" id="ligne133" class="w-full border border-gray-300 rounded px-2 py-1" 
                                   value="0" step="0.01">
                        </td>
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

    function calculerTVA() {
        // Section I - Chiffre d'Affaires
        const ligne80 = getVal('ligne80');
        const ligne81 = getVal('ligne81');
        const ligne82 = getVal('ligne82');
        const ligne83 = getVal('ligne83');
        const ligne84 = getVal('ligne84');
        const ligne85 = getVal('ligne85');
        const ligne86 = getVal('ligne86');
        
        // Ligne 95 : Total CA Exonéré
        const ligne95 = ligne81 + ligne82 + ligne83 + ligne84 + ligne85 + ligne86;
        setVal('ligne95', ligne95);
        
        // Ligne 100 : CA Taxable
        const ligne100 = Math.max(0, ligne80 - ligne95);
        setVal('ligne100', ligne100);
        
        // Taux réduit 5%
        const ligne102 = getVal('ligne102');
        const ligne103 = getVal('ligne103');
        const ligne104 = ligne102 + ligne103;
        setVal('ligne104', ligne104);
        
        // TVA 5%
        const ligne105 = ligne104 * 0.05;
        setVal('ligne105', ligne105);
        
        // Taux normal 18%
        const ligne101 = getVal('ligne101');
        const ligne106 = Math.max(0, ligne100 - ligne102 + ligne101 - ligne103);
        setVal('ligne106', ligne106);
        
        const ligne107 = getVal('ligne107');
        const ligne108 = ligne106 + ligne107;
        setVal('ligne108', ligne108);
        
        // Section II - Calcul TVA
        // TVA Collectée 18%
        const ligne109 = ligne108 * 0.18;
        setVal('ligne109', ligne109);
        
        const ligne110 = getVal('ligne110');
        
        // TVA Brute Collectée
        const ligne111 = ligne105 + ligne109 + ligne110;
        setVal('ligne111', ligne111);
        
        // TVA Déductible
        const ligne112 = getVal('ligne112');
        const ligne113 = getVal('ligne113');
        const ligne114 = getVal('ligne114');
        const ligne115 = getVal('ligne115');
        const ligne116 = getVal('ligne116');
        const ligne117 = getVal('ligne117');
        const ligne118 = getVal('ligne118');
        
        const ligne119 = ligne112 + ligne113 + ligne114 + ligne115 + ligne116 + ligne117 + ligne118;
        setVal('ligne119', ligne119);
        
        const ligne120 = getVal('ligne120');
        const ligne125 = ligne119 + ligne120;
        setVal('ligne125', ligne125);
        
        // Résultat
        const tvaNetteAPayer = Math.max(0, ligne111 - ligne125);
        const creditTVA = Math.max(0, ligne125 - ligne111);
        
        setVal('ligne131', tvaNetteAPayer);
        setVal('ligne132', creditTVA);
    }

    // Calculer au chargement
    document.addEventListener('DOMContentLoaded', calculerTVA);
    </script>
</body>
</html>

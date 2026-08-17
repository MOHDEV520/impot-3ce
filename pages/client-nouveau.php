<?php
/**
 * ============================================
 * NOUVEAU CLIENT
 * Système de Gestion Fiscale
 * ============================================
 */

define('APP_ROOT', dirname(__DIR__));
session_start();

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/classes/Agent.php';
require_once APP_ROOT . '/classes/Client.php';

// Vérifier l'authentification
if (!Agent::estConnecte() || !Agent::verifierTimeout()) {
    header('Location: ../index.php');
    exit;
}

$agent = Agent::getAgentConnecte();

// Variables du formulaire
$erreurs = [];
$succes = false;
$donnees = [
    'nom' => '',
    'ifu' => '',
    'type_activite' => '',
    'secteur' => 'officine',
    'regime_fiscal' => 'reel_normal',
    'adresse' => '',
    'telephone' => '',
    'email' => '',
    'type_tva' => 'non_exonere',
    'taux_tva' => '18.00',
    'taux_tva_double' => '0',
    'salaires_actif' => '1',
    'location_actif' => '0',
    'irf_tf_actif' => '0',
    'css_actif' => '1',
    'ras_actif' => '0',
    'sans_marges' => '0',
    'marge' => '1.30',
    'marge_taxable' => '1.30',
    'valeur_locative' => '0',
    'taux_cf' => '3.5',
    'taux_tl' => '1.0',
    'taux_css' => '0.5',
    'taux_irf' => '12.0',
    'taux_tf' => '3.0'
];

// Traitement du formulaire
$csrfToken = Agent::getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Agent::verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        $erreurs[] = 'Session invalide, veuillez réessayer.';
    }
    // Récupérer les données
    $donnees = [
        'nom' => trim($_POST['nom'] ?? ''),
        'ifu' => trim($_POST['ifu'] ?? ''),
        'type_activite' => trim($_POST['type_activite'] ?? ''),
        'secteur' => $_POST['secteur'] ?? 'officine',
        'regime_fiscal' => $_POST['regime_fiscal'] ?? 'reel_normal',
        'adresse' => trim($_POST['adresse'] ?? ''),
        'telephone' => trim($_POST['telephone'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'type_tva' => $_POST['type_tva'] ?? 'non_exonere',
        'taux_tva' => $_POST['taux_tva'] ?? '18.00',
        'taux_tva_double' => isset($_POST['taux_tva_double']) ? '1' : '0',
        'salaires_actif' => isset($_POST['salaires_actif']) ? '1' : '0',
        'location_actif' => isset($_POST['location_actif']) ? '1' : '0',
        'irf_tf_actif' => isset($_POST['irf_tf_actif']) ? '1' : '0',
        'css_actif' => isset($_POST['css_actif']) ? '1' : '0',
        'ras_actif' => isset($_POST['ras_actif']) ? '1' : '0',
        'sans_marges' => isset($_POST['sans_marges']) ? '1' : '0',
        'marge' => trim($_POST['marge'] ?? '1.30'),
        'marge_taxable' => trim($_POST['marge_taxable'] ?? '1.30'),
        'valeur_locative' => trim($_POST['valeur_locative'] ?? '0'),
        'taux_cf' => trim($_POST['taux_cf'] ?? '3.5'),
        'taux_tl' => trim($_POST['taux_tl'] ?? '1.0'),
        'taux_css' => trim($_POST['taux_css'] ?? '0.5'),
        'taux_irf' => trim($_POST['taux_irf'] ?? '12.0'),
        'taux_tf' => trim($_POST['taux_tf'] ?? '3.0')
    ];
    
    // Validation
    if (empty($donnees['nom'])) {
        $erreurs['nom'] = 'Le nom est obligatoire.';
    }
    
    if (!empty($donnees['email']) && !filter_var($donnees['email'], FILTER_VALIDATE_EMAIL)) {
        $erreurs['email'] = 'L\'email n\'est pas valide.';
    }
    
    // Si pas d'erreurs, créer le client
    if (empty($erreurs)) {
        try {
            $client = new Client();
            $client->setAgentId($agent->getId())
                   ->setNom($donnees['nom'])
                   ->setIfu($donnees['ifu'] ?: null)
                   ->setTypeActivite($donnees['type_activite'] ?: null)
                   ->setSecteur($donnees['secteur'])
                   ->setRegimeFiscal($donnees['regime_fiscal'])
                   ->setAdresse($donnees['adresse'] ?: null)
                   ->setTelephone($donnees['telephone'] ?: null)
                   ->setEmail($donnees['email'] ?: null);
            
            if ($client->sauvegarder()) {
                // Mettre à jour les paramètres fiscaux
                $client->setParametresFiscaux([
                    'type_tva' => $donnees['type_tva'],
                    'taux_tva' => (float) $donnees['taux_tva'],
                    'taux_tva_double' => (int) $donnees['taux_tva_double'],
                    'salaires_actif' => (int) $donnees['salaires_actif'],
                    'location_actif' => (int) $donnees['location_actif'],
                    'irf_tf_actif' => (int) $donnees['irf_tf_actif'],
                    'css_actif' => (int) $donnees['css_actif'],
                    'ras_actif' => (int) $donnees['ras_actif'],
                    'sans_marges' => (int) $donnees['sans_marges'],
                    'marge' => (float) $donnees['marge'],
                    'marge_taxable' => (float) $donnees['marge_taxable'],
                    'valeur_locative_mensuelle' => (float) $donnees['valeur_locative'],
                    'taux_cf' => (float) $donnees['taux_cf'],
                    'taux_tl' => (float) $donnees['taux_tl'],
                    'taux_css' => (float) $donnees['taux_css'],
                    'taux_irf' => (float) $donnees['taux_irf'],
                    'taux_tf' => (float) $donnees['taux_tf']
                ]);
                
                // Redirection
                header('Location: clients.php?msg=Client créé avec succès&type=success');
                exit;
            }
        } catch (Exception $e) {
            $erreurs['general'] = messageErreurUtilisateur($e, "la création de ce client");
        }
    }
}

$secteurs = Client::getSecteurs();
$regimes = Client::getRegimesFiscaux();
$typesActivite = Client::getTypesActivite();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouveau client - Gestion Fiscale</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=1.2">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#eff6ff', 100: '#dbeafe', 200: '#bfdbfe', 300: '#93c5fd',
                            400: '#60a5fa', 500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8',
                            800: '#1e40af', 900: '#1e3a8a',
                        }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css?v=1.2">
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Navigation -->
    <nav class="bg-primary-800 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <div class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center mr-3">
                        <i class="fas fa-calculator text-xl"></i>
                    </div>
                    <span class="text-xl font-bold">Gestion Fiscale</span>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-blue-200"><?= htmlspecialchars($agent->getNomComplet()) ?></span>
                    <a href="logout.php" class="text-blue-200 hover:text-white"><i class="fas fa-sign-out-alt"></i></a>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Contenu -->
    <main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Breadcrumb -->
        <nav class="flex mb-6" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm">
                <li><a href="dashboard.php" class="text-gray-500 hover:text-gray-700">Tableau de bord</a></li>
                <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
                <li><a href="clients.php" class="text-gray-500 hover:text-gray-700">Clients</a></li>
                <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
                <li class="text-primary-600 font-medium">Nouveau client</li>
            </ol>
        </nav>
        
        <!-- Titre -->
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-gray-800">Nouveau client</h1>
            <p class="text-gray-500 mt-1">Créez un nouveau dossier client</p>
        </div>
        
        <!-- Erreur générale -->
        <?php if (isset($erreurs['general'])): ?>
        <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 text-red-700 rounded-r-lg">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <span><?= htmlspecialchars($erreurs['general']) ?></span>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Formulaire -->
        <form method="POST" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <!-- Informations générales -->
            <div class="card">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-building mr-2 text-gray-400"></i>
                    Informations générales
                </h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Nom -->
                    <div class="md:col-span-2">
                        <label for="nom" class="block text-sm font-medium text-gray-700 mb-1">
                            Nom de l'entreprise <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="nom" name="nom" value="<?= htmlspecialchars($donnees['nom']) ?>"
                               class="w-full px-4 py-2 border <?= isset($erreurs['nom']) ? 'border-red-500' : 'border-gray-300' ?> rounded-lg focus:ring-2 focus:ring-primary-500"
                               required>
                        <?php if (isset($erreurs['nom'])): ?>
                        <p class="mt-1 text-sm text-red-500"><?= $erreurs['nom'] ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- NIF -->
                    <div>
                        <label for="ifu" class="block text-sm font-medium text-gray-700 mb-1">NIF</label>
                        <input type="text" id="ifu" name="ifu" value="<?= htmlspecialchars($donnees['ifu']) ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500"
                               placeholder="Numéro NIF">
                    </div>
                    
                    <!-- Type d'activité -->
                    <div>
                        <label for="type_activite" class="block text-sm font-medium text-gray-700 mb-1">Type d'activité</label>
                        <input type="text" id="type_activite" name="type_activite" value="<?= htmlspecialchars($donnees['type_activite']) ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500"
                               placeholder="Ex: Pharmacie, Commerce général..."
                               list="list_types_activite" autocomplete="off">
                        <datalist id="list_types_activite">
                            <?php foreach ($typesActivite as $ta): ?>
                            <option value="<?= htmlspecialchars($ta) ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <p class="mt-1 text-xs text-gray-400">Tapez pour voir les suggestions ou saisissez un nouveau type</p>
                    </div>
                    
                    <!-- Secteur -->
                    <div>
                        <label for="secteur_input" class="block text-sm font-medium text-gray-700 mb-1">Secteur</label>
                        <div class="flex gap-2">
                            <select id="secteur_select" onchange="onSecteurChange(this)" class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500">
                                <?php foreach ($secteurs as $code => $libelle): ?>
                                <option value="<?= htmlspecialchars($code) ?>" <?= $donnees['secteur'] === $code ? 'selected' : '' ?>><?= htmlspecialchars($libelle) ?></option>
                                <?php endforeach; ?>
                                <option value="__custom__" <?= !isset($secteurs[$donnees['secteur']]) && $donnees['secteur'] !== 'officine' ? 'selected' : '' ?>>+ Autre (personnalisé)...</option>
                            </select>
                        </div>
                        <input type="hidden" id="secteur_hidden" name="secteur" value="<?= htmlspecialchars($donnees['secteur']) ?>">
                        <input type="text" id="secteur_custom" placeholder="Saisir le nom du secteur..."
                               class="w-full mt-2 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 <?= (!isset($secteurs[$donnees['secteur']]) && $donnees['secteur'] !== 'officine') ? '' : 'hidden' ?>"
                               value="<?= (!isset($secteurs[$donnees['secteur']])) ? htmlspecialchars($donnees['secteur']) : '' ?>"
                               oninput="document.getElementById('secteur_hidden').value = this.value">
                    </div>
                    
                    <!-- Régime fiscal -->
                    <div>
                        <label for="regime_fiscal" class="block text-sm font-medium text-gray-700 mb-1">Régime fiscal</label>
                        <select id="regime_fiscal" name="regime_fiscal" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500">
                            <?php foreach ($regimes as $code => $libelle): ?>
                            <option value="<?= $code ?>" <?= $donnees['regime_fiscal'] === $code ? 'selected' : '' ?>><?= $libelle ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Coordonnées -->
            <div class="card">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-address-card mr-2 text-gray-400"></i>
                    Coordonnées
                </h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Adresse -->
                    <div class="md:col-span-2">
                        <label for="adresse" class="block text-sm font-medium text-gray-700 mb-1">Adresse</label>
                        <textarea id="adresse" name="adresse" rows="2"
                                  class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500"
                                  placeholder="Adresse complète"><?= htmlspecialchars($donnees['adresse']) ?></textarea>
                    </div>
                    
                    <!-- Téléphone -->
                    <div>
                        <label for="telephone" class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                        <input type="tel" id="telephone" name="telephone" value="<?= htmlspecialchars($donnees['telephone']) ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500"
                               placeholder="+229 XX XX XX XX">
                    </div>
                    
                    <!-- Email -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input type="email" id="email" name="email" value="<?= htmlspecialchars($donnees['email']) ?>"
                               class="w-full px-4 py-2 border <?= isset($erreurs['email']) ? 'border-red-500' : 'border-gray-300' ?> rounded-lg focus:ring-2 focus:ring-primary-500"
                               placeholder="email@exemple.com">
                        <?php if (isset($erreurs['email'])): ?>
                        <p class="mt-1 text-sm text-red-500"><?= $erreurs['email'] ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Paramètres fiscaux -->
            <div class="card">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-file-invoice-dollar mr-2 text-gray-400"></i>
                    Paramètres fiscaux
                </h2>
                
                <!-- TVA -->
                <div class="mb-6 pb-6 border-b border-gray-200">
                    <h3 class="text-sm font-medium text-gray-700 mb-3">TVA</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="type_tva" class="block text-sm text-gray-600 mb-1">Statut TVA</label>
                            <select id="type_tva" name="type_tva" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500">
                                <option value="non_exonere" <?= $donnees['type_tva'] === 'non_exonere' ? 'selected' : '' ?>>Non exonéré</option>
                                <option value="exonere_partiel" <?= $donnees['type_tva'] === 'exonere_partiel' ? 'selected' : '' ?>>Exonéré partiellement</option>
                                <option value="exonere_total" <?= $donnees['type_tva'] === 'exonere_total' ? 'selected' : '' ?>>Exonéré à 100%</option>
                            </select>
                        </div>
                        <div>
                            <label for="taux_tva" class="block text-sm text-gray-600 mb-1">Taux TVA principal</label>
                            <select id="taux_tva" name="taux_tva" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500">
                                <option value="18.00" <?= $donnees['taux_tva'] === '18.00' ? 'selected' : '' ?>>18%</option>
                                <option value="5.00" <?= $donnees['taux_tva'] === '5.00' ? 'selected' : '' ?>>5%</option>
                            </select>
                            <label class="flex items-center mt-2 p-2 bg-blue-50 border border-blue-200 rounded-lg">
                                <input type="checkbox" name="taux_tva_double" value="1" <?= $donnees['taux_tva_double'] === '1' ? 'checked' : '' ?>
                                       class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                <span class="ml-2 text-sm text-blue-700 font-medium">Double taux (5% + 18%)</span>
                            </label>
                        </div>
                    </div>
                </div>
                
                <!-- Sections actives -->
                <div class="mb-6 pb-6 border-b border-gray-200">
                    <h3 class="text-sm font-medium text-gray-700 mb-3">Sections actives</h3>
                    <div class="space-y-3">
                        <label class="flex items-center">
                            <input type="checkbox" name="salaires_actif" value="1" <?= $donnees['salaires_actif'] === '1' ? 'checked' : '' ?>
                                   class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500">
                            <span class="ml-2 text-sm text-gray-600">Impôts sur salaires (CF, ITS, TL)</span>
                        </label>
                        <label class="flex items-center">
                            <input type="checkbox" name="location_actif" value="1" <?= $donnees['location_actif'] === '1' ? 'checked' : '' ?>
                                   class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500"
                                   onchange="toggleLocation()">
                            <span class="ml-2 text-sm text-gray-600">TVA sur location</span>
                        </label>
                        <label class="flex items-center">
                            <input type="checkbox" name="irf_tf_actif" value="1" <?= $donnees['irf_tf_actif'] === '1' ? 'checked' : '' ?>
                                   class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500"
                                   onchange="toggleLocation()">
                            <span class="ml-2 text-sm text-gray-600">IRF + TF (Impôt Revenus Fonciers + Taxe Foncière)</span>
                        </label>
                        <label class="flex items-center">
                            <input type="checkbox" name="css_actif" value="1" <?= $donnees['css_actif'] === '1' ? 'checked' : '' ?>
                                   class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500">
                            <span class="ml-2 text-sm text-gray-600">CSS (0.5% du CA)</span>
                        </label>
                        <label class="flex items-center">
                            <input type="checkbox" name="ras_actif" value="1" <?= $donnees['ras_actif'] === '1' ? 'checked' : '' ?>
                                   class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500">
                            <span class="ml-2 text-sm text-gray-600">Retenue à la Source BIC/IS</span>
                        </label>
                        <label class="flex items-center mt-3 p-2 bg-amber-50 border border-amber-200 rounded-lg">
                            <input type="checkbox" name="sans_marges" value="1" <?= $donnees['sans_marges'] === '1' ? 'checked' : '' ?>
                                   class="w-4 h-4 text-amber-600 border-gray-300 rounded focus:ring-amber-500"
                                   id="checkbox_sans_marges" onchange="toggleMarges(this)">
                            <span class="ml-2 text-sm text-amber-700 font-medium"><i class="fas fa-edit mr-1"></i> Sans marges (saisie manuelle de tous les montants)</span>
                        </label>
                    </div>

                    <!-- Marges par défaut (si pas "sans marges") -->
                    <div id="section_marges" class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4 <?= $donnees['sans_marges'] === '1' ? 'hidden' : '' ?>">
                        <div>
                            <label for="marge" class="block text-sm text-gray-600 mb-1">Marge Global par défaut</label>
                            <div class="relative">
                                <input type="number" step="0.001" id="marge" name="marge" value="<?= htmlspecialchars($donnees['marge']) ?>"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500"
                                       list="marges_suggest">
                                <datalist id="marges_suggest">
                                    <option value="1.30">
                                    <option value="1.27">
                                    <option value="1.20">
                                    <option value="1.37">
                                    <option value="0">
                                </datalist>
                            </div>
                        </div>
                        <div>
                            <label for="marge_taxable" class="block text-sm text-gray-600 mb-1">Marge Taxable par défaut</label>
                            <div class="relative">
                                <input type="number" step="0.001" id="marge_taxable" name="marge_taxable" value="<?= htmlspecialchars($donnees['marge_taxable']) ?>"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500"
                                       list="marges_suggest">
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Valeur locative -->
                <div id="location_section" class="<?= ($donnees['location_actif'] === '1' || $donnees['irf_tf_actif'] === '1') ? '' : 'hidden' ?>">
                    <h3 class="text-sm font-medium text-gray-700 mb-3 border-t border-gray-100 pt-4">Location</h3>
                    <div class="max-w-xs">
                        <label for="valeur_locative" class="block text-sm text-gray-600 mb-1">Valeur locative mensuelle (FCFA)</label>
                        <input type="number" id="valeur_locative" name="valeur_locative" value="<?= htmlspecialchars($donnees['valeur_locative']) ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500"
                               min="0" step="1000">
                    </div>
                </div>

                <!-- Taux Spécifiques -->
                <div class="mt-8 border-t border-gray-100 pt-6">
                    <h3 class="text-sm font-medium text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-percent mr-2 text-primary-500"></i>
                        Taux fiscaux applicables
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div>
                            <label for="taux_cf" class="block text-xs text-gray-500 mb-1">Taux CF (%)</label>
                            <input type="number" step="0.01" id="taux_cf" name="taux_cf" value="<?= htmlspecialchars($donnees['taux_cf']) ?>"
                                   class="w-full px-3 py-1.5 border border-gray-300 rounded-lg focus:ring-1 focus:ring-primary-500">
                        </div>
                        <div>
                            <label for="taux_tl" class="block text-xs text-gray-500 mb-1">Taux TL (%)</label>
                            <input type="number" step="0.01" id="taux_tl" name="taux_tl" value="<?= htmlspecialchars($donnees['taux_tl']) ?>"
                                   class="w-full px-3 py-1.5 border border-gray-300 rounded-lg focus:ring-1 focus:ring-primary-500">
                        </div>
                        <div>
                            <label for="taux_css" class="block text-xs text-gray-500 mb-1">Taux CSS (%)</label>
                            <input type="number" step="0.01" id="taux_css" name="taux_css" value="<?= htmlspecialchars($donnees['taux_css']) ?>"
                                   class="w-full px-3 py-1.5 border border-gray-300 rounded-lg focus:ring-1 focus:ring-primary-500">
                        </div>
                        <div>
                            <label for="taux_irf" class="block text-xs text-gray-500 mb-1">Taux IRF (%)</label>
                            <input type="number" step="0.01" id="taux_irf" name="taux_irf" value="<?= htmlspecialchars($donnees['taux_irf']) ?>"
                                   class="w-full px-3 py-1.5 border border-gray-300 rounded-lg focus:ring-1 focus:ring-primary-500">
                        </div>
                        <div>
                            <label for="taux_tf" class="block text-xs text-gray-500 mb-1">Taux TF (%)</label>
                            <input type="number" step="0.01" id="taux_tf" name="taux_tf" value="<?= htmlspecialchars($donnees['taux_tf']) ?>"
                                   class="w-full px-3 py-1.5 border border-gray-300 rounded-lg focus:ring-1 focus:ring-primary-500">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Boutons -->
            <div class="flex justify-end space-x-4">
                <a href="clients.php" class="btn-outline">
                    Annuler
                </a>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-save"></i> Créer le client
                </button>
            </div>
        </form>
    </main>
    
    <script>
        function toggleLocation() {
            const section = document.getElementById('location_section');
            const locActif = document.querySelector('input[name="location_actif"]').checked;
            const irfTfActif = document.querySelector('input[name="irf_tf_actif"]').checked;
            if (locActif || irfTfActif) {
                section.classList.remove('hidden');
            } else {
                section.classList.add('hidden');
            }
        }

        function toggleMarges(checkbox) {
            const section = document.getElementById('section_marges');
            if (checkbox.checked) {
                section.classList.add('hidden');
            } else {
                section.classList.remove('hidden');
            }
        }

        function onSecteurChange(sel) {
            const hidden = document.getElementById('secteur_hidden');
            const custom = document.getElementById('secteur_custom');
            if (sel.value === '__custom__') {
                custom.classList.remove('hidden');
                custom.focus();
                hidden.value = custom.value;
            } else {
                custom.classList.add('hidden');
                hidden.value = sel.value;
            }
        }
    </script>
</body>
</html>

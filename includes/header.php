<?php
/**
 * ============================================
 * HEADER COMMUN
 * Système de Gestion Fiscale
 * ============================================
 */

// Vérifier que APP_ROOT est défini
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// Charger les dépendances si nécessaire
if (!class_exists('Agent')) {
    require_once APP_ROOT . '/config/database.php';
    require_once APP_ROOT . '/classes/Agent.php';
}

// Démarrer la session si pas déjà fait
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérifier l'authentification (sauf pour index.php)
$pageActuelle = basename($_SERVER['PHP_SELF']);
$pagesPubliques = ['index.php'];

if (!in_array($pageActuelle, $pagesPubliques)) {
    if (!Agent::estConnecte() || !Agent::verifierTimeout()) {
        header('Location: ' . (strpos($_SERVER['PHP_SELF'], '/pages/') !== false ? '../' : '') . 'index.php');
        exit;
    }
}

// Agent connecté
$agentConnecte = Agent::estConnecte() ? Agent::getAgentConnecte() : null;

// Titre de la page (peut être défini avant d'inclure header.php)
$titrePage = $titrePage ?? 'Gestion Fiscale';

// Déterminer le chemin de base
$basePath = strpos($_SERVER['PHP_SELF'], '/pages/') !== false ? '../' : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titrePage) ?> - Gestion Fiscale</title>
    <!-- Tailwind CSS (Offline) -->
    <link rel="stylesheet" href="<?= $basePath ?>assets/css/style.css?v=1.1">
    
    <!-- Font Awesome (Offline) -->
    <link rel="stylesheet" href="<?= $basePath ?>assets/vendor/fontawesome/css/all.min.css?v=1.1">
    
    <!-- Styles personnalisés -->
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
            .shadow-sm, .shadow-lg { box-shadow: none !important; }
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <?php if ($agentConnecte): ?>
    <!-- Navigation principale -->
    <nav class="bg-primary-900 text-white shadow-xl no-print border-b border-primary-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <!-- Logo et titre -->
                <div class="flex items-center">
                    <a href="<?= $basePath ?>pages/dashboard.php" class="flex items-center space-x-3 group">
                        <div class="w-10 h-10 bg-white rounded flex items-center justify-center p-1 shadow-inner group-hover:scale-105 transition-transform duration-200">
                            <img src="<?= $basePath ?>assets/img/logo.png" alt="3CE FISCUS" class="w-full h-full object-contain">
                        </div>
                        <div class="flex flex-col">
                            <span class="text-xl font-black tracking-wider leading-none">3CE FISCUS</span>
                            <span class="text-[10px] text-primary-300 uppercase tracking-widest mt-0.5 font-bold">Système de Gestion Fiscale</span>
                        </div>
                    </a>
                </div>
                
                <!-- Menu utilisateur -->
                <div class="flex items-center space-x-6">
                    <div class="hidden lg:flex flex-col items-end">
                        <span class="text-xs text-primary-300 uppercase tracking-tighter">Utilisateur</span>
                        <strong class="text-sm font-bold"><?= htmlspecialchars($agentConnecte->getNomComplet()) ?></strong>
                    </div>
                    <div class="h-8 w-px bg-primary-700 hidden lg:block"></div>
                    <a href="<?= $basePath ?>pages/logout.php" class="flex items-center px-4 py-1.5 bg-red-600/20 hover:bg-red-600 text-red-100 font-bold rounded-lg transition-all border border-red-500/30 text-sm">
                        <i class="fas fa-sign-out-alt mr-2"></i> Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Menu secondaire -->
    <div class="bg-white shadow no-print">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex space-x-8 h-12">
                <?php
                $menuItems = [
                    ['url' => 'dashboard.php', 'icon' => 'fa-tachometer-alt', 'label' => 'Tableau de bord'],
                    ['url' => 'clients.php', 'icon' => 'fa-users', 'label' => 'Clients'],
                ];
                
                if ($agentConnecte->estAdmin()) {
                    $menuItems[] = ['url' => 'agents.php', 'icon' => 'fa-user-cog', 'label' => 'Agents'];
                }
                
                $menuItems[] = ['url' => 'sauvegarde.php', 'icon' => 'fa-database', 'label' => 'Sauvegarde'];
                
                foreach ($menuItems as $item):
                    $isActive = $pageActuelle === $item['url'];
                ?>
                <a href="<?= $basePath ?>pages/<?= $item['url'] ?>" 
                   class="border-b-2 <?= $isActive ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> px-1 pt-3 text-sm font-medium">
                    <i class="fas <?= $item['icon'] ?> mr-1"></i> <?= $item['label'] ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Contenu principal -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

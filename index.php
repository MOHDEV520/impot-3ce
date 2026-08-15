<?php
/**
 * ============================================
 * PAGE D'ACCUEIL - CONNEXION
 * Système de Gestion Fiscale
 * ============================================
 */

// Définir la racine de l'application
define('APP_ROOT', __DIR__);

// Démarrer la session
session_start();

// Charger les dépendances
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/classes/Agent.php';

// Initialiser la base de données si nécessaire
$db = Database::getInstance();
if (!$db->tablesExist()) {
    $db->initialiserBaseDeDonnees();
}

// Sécurité supplémentaire : S'assurer que l'admin est présent
// Utile si la base a été créée mais l'insert a échoué précédemment
if (isset($_GET['repair']) && $_GET['repair'] === '1') {
    // La méthode assurerAdminExiste est privée dans Database, mais appelée au getInstance.
    // On peut forcer un re-check en demandant une nouvelle instance ou par un script dédié
}

// Vérifier si au moins un agent existe
$stmt = $db->getConnection()->query("SELECT COUNT(*) FROM agents");
$nbAgents = (int)$stmt->fetchColumn();

if ($nbAgents === 0) {
    $erreur = "La base de données est vide. Veuillez redémarrer l'application ou supprimer le fichier database.sqlite dans %APPDATA%/IMPOT-3CE pour la réinitialiser.";
}

// Vérifier si déjà connecté
if (Agent::estConnecte()) {
    header('Location: pages/dashboard.php');
    exit;
}

// Traitement du formulaire de connexion
$erreur = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $motDePasse = $_POST['mot_de_passe'] ?? '';
    
    if (empty($email) || empty($motDePasse)) {
        $erreur = 'Veuillez remplir tous les champs.';
    } else {
        $agent = Agent::seConnecter($email, $motDePasse);
        
        if ($agent !== null) {
            header('Location: pages/dashboard.php');
            exit;
        } else {
            $erreur = 'Email ou mot de passe incorrect.';
        }
    }
}

// Nom des mois pour l'affichage
$moisActuel = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
][date('n')] . ' ' . date('Y');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - Système de Gestion Fiscale</title>
    
    <!-- Tailwind CSS (Offline) -->
    <link rel="stylesheet" href="assets/css/style.css?v=1.1">
    
    <!-- Font Awesome (Offline) -->
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css?v=1.1">
    
    <style>
        .bg-pattern {
            background-color: #1e3a8a;
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%232563eb' fill-opacity='0.15'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }
    </style>
</head>
<body class="min-h-screen bg-gray-100">
    <div class="min-h-screen flex">
        <!-- Panneau gauche - Branding -->
        <div class="hidden lg:flex lg:w-1/2 bg-pattern flex-col justify-center items-center text-white p-12">
            <div class="max-w-md text-center">
                <!-- Logo / Icône -->
                <div class="mb-8">
                    <div class="w-32 h-32 bg-white rounded-2xl flex items-center justify-center mx-auto shadow-xl overflow-hidden p-2">
                        <img src="assets/img/logo.png" alt="3CE FISCUS" class="max-w-full max-h-full">
                    </div>
                </div>
                
                <!-- Titre -->
                <h1 class="text-4xl font-bold mb-2">Gestion Fiscale</h1>
                <div class="inline-block px-3 py-1 bg-green-500 text-white text-xs font-bold rounded-full mb-6">VERSION 1.0</div>
                <p class="text-xl text-blue-200 mb-8">Cabinet d'Expertise Comptable</p>
                
                <!-- Features -->
                <div class="space-y-4 text-left">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center">
                            <i class="fas fa-users text-blue-200"></i>
                        </div>
                        <span class="text-blue-100">Gestion multi-clients</span>
                    </div>
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center">
                            <i class="fas fa-file-invoice-dollar text-blue-200"></i>
                        </div>
                        <span class="text-blue-100">Calcul automatique des impôts</span>
                    </div>
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center">
                            <i class="fas fa-chart-line text-blue-200"></i>
                        </div>
                        <span class="text-blue-100">Tableaux de bord mensuels</span>
                    </div>
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center">
                            <i class="fas fa-shield-alt text-blue-200"></i>
                        </div>
                        <span class="text-blue-100">100% Offline & Sécurisé</span>
                    </div>
                </div>
                
                <!-- Date actuelle -->
                <div class="mt-12 pt-8 border-t border-white/20">
                    <p class="text-blue-200">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        <?= $moisActuel ?>
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Panneau droit - Formulaire de connexion -->
        <div class="w-full lg:w-1/2 flex items-center justify-center p-8">
            <div class="max-w-md w-full">
                <!-- Logo mobile -->
                <div class="lg:hidden text-center mb-8">
                    <div class="w-16 h-16 bg-primary-600 rounded-xl flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-calculator text-3xl text-white"></i>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-800">Gestion Fiscale</h1>
                </div>
                
                <!-- Carte de connexion -->
                <div class="bg-white rounded-2xl shadow-xl p-8">
                    <div class="text-center mb-8">
                        <h2 class="text-2xl font-bold text-gray-800">Connexion</h2>
                        <p class="text-gray-500 mt-2">Accédez à votre espace de travail</p>
                    </div>
                    
                    <!-- Message d'erreur -->
                    <?php if ($erreur): ?>
                    <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 text-red-700 rounded-r-lg">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle mr-2"></i>
                            <span><?= htmlspecialchars($erreur) ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Formulaire -->
                    <form method="POST" action="" class="space-y-6">
                        <!-- Email -->
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-envelope mr-1 text-gray-400"></i>
                                Adresse email
                            </label>
                            <input 
                                type="email" 
                                id="email" 
                                name="email" 
                                value="<?= htmlspecialchars($email) ?>"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors"
                                placeholder="votre@email.com"
                                required
                                autofocus
                            >
                        </div>
                        
                        <!-- Mot de passe -->
                        <div>
                            <label for="mot_de_passe" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-lock mr-1 text-gray-400"></i>
                                Mot de passe
                            </label>
                            <div class="relative">
                                <input 
                                    type="password" 
                                    id="mot_de_passe" 
                                    name="mot_de_passe"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors pr-12"
                                    placeholder="••••••••"
                                    required
                                >
                                <button 
                                    type="button" 
                                    onclick="togglePassword()"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                >
                                    <i class="fas fa-eye" id="toggleIcon"></i>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Bouton de connexion -->
                        <button 
                            type="submit"
                            class="w-full py-3 px-4 bg-primary-600 hover:bg-primary-700 text-white font-semibold rounded-lg shadow-md hover:shadow-lg transition-all duration-200 flex items-center justify-center space-x-2"
                        >
                            <i class="fas fa-sign-in-alt"></i>
                            <span>Se connecter</span>
                        </button>
                    </form>
                    
                    <!-- Aide -->
                    <div class="mt-6 text-center">
                        <p class="text-sm text-gray-500">
                            <i class="fas fa-info-circle mr-1"></i>
                            Contactez l'administrateur en cas de problème
                        </p>
                    </div>
                </div>
                
                <!-- Footer -->
                <div class="mt-8 text-center text-sm text-gray-400">
                    <p>&copy; <?= date('Y') ?> Cabinet d'Expertise Comptable</p>
                    <p class="mt-1">Système de Gestion Fiscale v1.0</p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Toggle password visibility
        function togglePassword() {
            const input = document.getElementById('mot_de_passe');
            const icon = document.getElementById('toggleIcon');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // Auto-hide error message after 5 seconds
        const errorMessage = document.querySelector('.bg-red-50');
        if (errorMessage) {
            setTimeout(() => {
                errorMessage.style.transition = 'opacity 0.5s ease-out';
                errorMessage.style.opacity = '0';
                setTimeout(() => errorMessage.remove(), 500);
            }, 5000);
        }
    </script>
</body>
</html>

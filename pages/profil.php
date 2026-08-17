<?php
/**
 * ============================================
 * PARAMÈTRES DU COMPTE - PROFIL AGENT
 * Système de Gestion Fiscale
 * ============================================
 */

session_start();
define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/classes/Agent.php';

// Vérifier l'authentification
if (!Agent::estConnecte() || !Agent::verifierTimeout()) {
    header('Location: ../index.php');
    exit;
}

$agent = Agent::getAgentConnecte();
$message = '';
$erreur = '';
$csrfToken = Agent::getCsrfToken();

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Agent::verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        $erreur = 'Session invalide, veuillez réessayer.';
    }
    $action = empty($erreur) ? ($_POST['action'] ?? '') : '';

    if ($action === 'update_profile') {
        $nom = trim($_POST['nom'] ?? '');
        $prenom = trim($_POST['prenom'] ?? '');
        $email = trim($_POST['email'] ?? '');

        try {
            if (empty($nom) || empty($prenom) || empty($email)) {
                throw new Exception("Tous les champs sont obligatoires.");
            }

            // Vérifier si l'email a changé et s'il est déjà pris
            if ($email !== $agent->getEmail()) {
                $db = Database::getInstance();
                $existing = $db->fetchOne("SELECT id FROM agents WHERE email = ? AND id != ?", [$email, $agent->getId()]);
                if ($existing) {
                    throw new Exception("Cette adresse email est déjà utilisée par un autre utilisateur.");
                }
            }

            $agent->setNom($nom);
            $agent->setPrenom($prenom);
            $agent->setEmail($email);

            if ($agent->sauvegarder()) {
                $message = "Profil mis à jour avec succès.";
                // Mettre à jour la session
                $_SESSION['agent_nom'] = $agent->getNomComplet();
                $_SESSION['agent_email'] = $agent->getEmail();
            } else {
                $message = "Profil mis à jour."; 
            }
        } catch (Exception $e) {
            $erreur = messageErreurUtilisateur($e, "la mise à jour du profil");
        }
    } elseif ($action === 'update_password') {
        $ancienMdp = $_POST['ancien_mdp'] ?? '';
        $nouveauMdp = $_POST['nouveau_mdp'] ?? '';
        $confirmMdp = $_POST['confirm_mdp'] ?? '';

        try {
            if (empty($ancienMdp) || empty($nouveauMdp) || empty($confirmMdp)) {
                throw new Exception("Tous les champs de mot de passe sont obligatoires.");
            }

            if ($nouveauMdp !== $confirmMdp) {
                throw new Exception("Les nouveaux mots de passe ne correspondent pas.");
            }

            if (strlen($nouveauMdp) < 6) {
                throw new Exception("Le nouveau mot de passe doit faire au moins 6 caractères.");
            }

            // Vérifier l'ancien mot de passe
            $db = Database::getInstance();
            $sql = "SELECT mot_de_passe FROM agents WHERE id = ?";
            $res = $db->fetchOne($sql, [$agent->getId()]);

            if (!password_verify($ancienMdp, $res['mot_de_passe'])) {
                throw new Exception("L'ancien mot de passe est incorrect.");
            }

            if ($agent->changerMotDePasse($nouveauMdp)) {
                $message = "Mot de passe modifié avec succès.";
            } else {
                $erreur = "Erreur lors du changement de mot de passe.";
            }
        } catch (Exception $e) {
            $erreur = messageErreurUtilisateur($e, "le changement de mot de passe");
        }
    }
}

// Récupérer quelques statistiques pour le profil
$db = Database::getInstance();
$nbClients = $db->fetchColumn("SELECT COUNT(*) FROM clients WHERE agent_id = ? AND statut = 'actif'", [$agent->getId()]);
if ($agent->getRole() === 'admin') {
    $nbClients = $db->fetchColumn("SELECT COUNT(*) FROM clients WHERE statut = 'actif'");
}

$moisNoms = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

$moisActuel = (int)date('n');
$anneeActuelle = (int)date('Y');
$nbComplets = $db->fetchColumn("
    SELECT COUNT(*) FROM compte_gestion_mensuel cg
    JOIN clients c ON cg.client_id = c.id
    WHERE cg.mois = ? AND cg.annee = ? AND cg.statut IN ('valide', 'verrouille')
    " . ($agent->getRole() !== 'admin' ? " AND c.agent_id = ?" : ""), 
    $agent->getRole() !== 'admin' ? [$moisActuel, $anneeActuelle, $agent->getId()] : [$moisActuel, $anneeActuelle]
);

$titrePage = "Mon profil";
require_once APP_ROOT . '/includes/header.php';
?>
    <div class="max-w-4xl mx-auto">
        <!-- Fil d'ariane -->
        <nav class="flex mb-6 text-sm">
            <a href="dashboard.php" class="text-slate-500 hover:text-primary-600 flex items-center">
                <i class="fas fa-home mr-1"></i> Accueil
            </a>
            <span class="mx-2 text-slate-400">/</span>
            <span class="text-slate-800 font-medium">Mon Profil</span>
        </nav>

        <div class="flex items-center justify-between mb-8">
            <h1 class="text-2xl font-bold text-slate-800">Paramètres du compte</h1>
        </div>

        <?php if ($message): ?>
        <div class="mb-6 p-4 bg-green-100 border-l-4 border-green-500 text-green-700 rounded shadow-sm flex items-center">
            <i class="fas fa-check-circle mr-3"></i>
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <?php if ($erreur): ?>
        <div class="mb-6 p-4 bg-red-100 border-l-4 border-red-500 text-red-700 rounded shadow-sm flex items-center">
            <i class="fas fa-exclamation-triangle mr-3"></i>
            <?= htmlspecialchars($erreur) ?>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <!-- Colonne de gauche : Résumé / Avatar -->
            <div class="md:col-span-1 space-y-6">
                <div class="card text-center">
                    <div class="w-24 h-24 bg-primary-100 text-primary-600 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl font-bold border-4 border-white shadow-md">
                        <?= strtoupper(substr($agent->getPrenom(), 0, 1) . substr($agent->getNom(), 0, 1)) ?>
                    </div>
                    <h2 class="text-xl font-bold text-slate-800"><?= htmlspecialchars($agent->getNomComplet()) ?></h2>
                    <p class="text-slate-500 text-sm mb-4"><?= htmlspecialchars($agent->getEmail()) ?></p>
                    <span class="px-3 py-1 bg-primary-100 text-primary-700 text-xs font-bold rounded-full uppercase">
                        <?= $agent->getRole() === 'admin' ? 'Administrateur' : 'Agent Comptable' ?>
                    </span>
                </div>

                <div class="card">
                    <h3 class="font-bold text-slate-800 mb-4 flex items-center text-sm uppercase tracking-wider">
                        <i class="fas fa-history mr-2 text-primary-500"></i> Historique du compte
                    </h3>
                    <ul class="space-y-4">
                        <li class="flex items-start">
                            <i class="fas fa-calendar-alt mt-1 mr-3 text-slate-400"></i>
                            <div>
                                <p class="text-xs text-slate-500">Membre depuis le</p>
                                <p class="text-sm font-medium text-slate-700">
                                    <?= $agent->getDateCreation() ? date('d/m/Y', strtotime($agent->getDateCreation())) : 'N/A' ?>
                                </p>
                            </div>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-clock mt-1 mr-3 text-slate-400"></i>
                            <div>
                                <p class="text-xs text-slate-500">Dernière connexion</p>
                                <p class="text-sm font-medium text-slate-700">
                                    <?= $agent->getDerniereConnexion() ? date('d/m/Y à H:i', strtotime($agent->getDerniereConnexion())) : 'Première fois' ?>
                                </p>
                            </div>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-shield-check mt-1 mr-3 text-green-500"></i>
                            <div>
                                <p class="text-xs text-slate-500">Statut du compte</p>
                                <p class="text-sm font-bold text-green-600">Actif</p>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Statistiques Portefeuille -->
                <div class="card">
                    <h3 class="font-bold text-slate-800 mb-4 flex items-center text-sm uppercase tracking-wider">
                        <i class="fas fa-chart-pie mr-2 text-indigo-500"></i> Portefeuille
                    </h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="p-3 bg-indigo-50 rounded-lg border border-indigo-100">
                            <p class="text-[10px] text-indigo-600 font-bold uppercase mb-1">Clients</p>
                            <p class="text-2xl font-black text-indigo-800"><?= $nbClients ?></p>
                        </div>
                        <div class="p-3 bg-emerald-50 rounded-lg border border-emerald-100">
                            <p class="text-[10px] text-emerald-600 font-bold uppercase mb-1">Dossiers OK</p>
                            <p class="text-2xl font-black text-emerald-800"><?= $nbComplets ?></p>
                        </div>
                    </div>
                    <p class="mt-3 text-[10px] text-slate-400 italic">Dossiers terminés pour <?= $moisNoms[(int)date('n')] ?>.</p>
                </div>
            </div>

            <!-- Colonne de droite : Formulaires -->
            <div class="md:col-span-2 space-y-8">
                <!-- Informations Personnelles -->
                <div class="card overflow-hidden p-0">
                    <div class="bg-primary-50 px-6 py-4 border-b border-slate-200">
                        <h2 class="font-bold text-primary-800 flex items-center">
                            <i class="fas fa-user-circle mr-2"></i> Informations du profil
                        </h2>
                    </div>
                    <div class="p-6">
                        <form action="" method="POST" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="update_profile">
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Prénom</label>
                                    <input type="text" name="prenom" value="<?= htmlspecialchars($agent->getPrenom()) ?>" 
                                           class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition-all">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Nom</label>
                                    <input type="text" name="nom" value="<?= htmlspecialchars($agent->getNom()) ?>" 
                                           class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition-all">
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1 tracking-tight">Email Professionnel (Identifiant)</label>
                                <input type="email" name="email" value="<?= htmlspecialchars($agent->getEmail()) ?>" 
                                       class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition-all">
                            </div>

                            <div class="pt-2">
                                <button type="submit" class="btn-primary py-2.5 px-6">
                                    <i class="fas fa-save"></i> Mettre à jour mon profil
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Changement de mot de passe -->
                <div class="card overflow-hidden p-0">
                    <div class="bg-amber-50 px-6 py-4 border-b border-slate-200">
                        <h2 class="font-bold text-amber-800 flex items-center">
                            <i class="fas fa-key mr-2"></i> Sécurité du compte
                        </h2>
                    </div>
                    <div class="p-6">
                        <form action="" method="POST" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="update_password">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="md:col-span-2">
                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Mot de passe actuel</label>
                                    <input type="password" name="ancien_mdp" required placeholder="••••••••"
                                           class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none transition-all">
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Nouveau mot de passe</label>
                                    <input type="password" name="nouveau_mdp" required placeholder="Min. 6 caractères"
                                           class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none transition-all">
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Confirmer</label>
                                    <input type="password" name="confirm_mdp" required placeholder="Confirmer"
                                           class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none transition-all">
                                </div>
                            </div>

                            <div class="pt-2">
                                <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white font-bold py-2.5 px-6 rounded-lg transition duration-200 shadow hover:shadow-lg flex items-center">
                                    <i class="fas fa-shield-alt mr-2"></i> Changer le mot de passe
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Mise à jour de l'application -->
                <div class="card overflow-hidden mt-6 p-0">
                    <div class="border-b border-slate-100 bg-slate-50/50 px-6 py-4 flex items-center">
                        <i class="fas fa-sync-alt text-blue-600 mr-3"></i>
                        <h2 class="text-xs font-bold text-slate-700 uppercase tracking-wider">Mise à jour de l'application</h2>
                    </div>
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-slate-700">Version actuelle</p>
                                <p class="text-xs text-slate-500" id="current-version">Chargement...</p>
                            </div>
                            <button type="button" onclick="checkForUpdates()" id="btn-update"
                                    class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg text-sm transition duration-200 flex items-center">
                                <i class="fas fa-search-plus mr-2"></i> Vérifier les mises à jour
                            </button>
                        </div>
                        <div id="update-message" class="hidden text-xs p-3 rounded-lg border"></div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // Fonctions pour la mise à jour (Electron Only)
            document.addEventListener('DOMContentLoaded', async () => {
                if (window.electronAPI) {
                    const version = await window.electronAPI.getVersion();
                    document.getElementById('current-version').textContent = 'V. ' + version;
                } else {
                    document.getElementById('current-version').textContent = 'Mode Web / Navigateur';
                    document.getElementById('btn-update').disabled = true;
                    document.getElementById('btn-update').classList.add('opacity-50', 'cursor-not-allowed');
                }
            });

            async function checkForUpdates() {
                const btn = document.getElementById('btn-update');
                const msg = document.getElementById('update-message');
                
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Recherche...';
                
                msg.classList.remove('hidden', 'bg-green-50', 'border-green-200', 'text-green-700', 'bg-blue-50', 'border-blue-200', 'text-blue-800', 'bg-red-50', 'border-red-200', 'text-red-700');
                
                try {
                    if (window.electronAPI) {
                        const result = await window.electronAPI.checkForUpdates();
                        
                        if (result && result.updateInfo) {
                            msg.textContent = "Une mise à jour est disponible (" + result.updateInfo.version + "). Le téléchargement va commencer.";
                            msg.classList.add('bg-blue-50', 'border-blue-200', 'text-blue-800');
                        } else {
                            msg.textContent = "Votre application est à jour.";
                            msg.classList.add('bg-green-50', 'border-green-200', 'text-green-700');
                        }
                    }
                } catch (e) {
                    msg.textContent = "Erreur lors de la vérification : " + e.message;
                    msg.classList.add('bg-red-50', 'border-red-200', 'text-red-700');
                } finally {
                    msg.classList.remove('hidden');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-search-plus mr-2"></i> Vérifier les mises à jour';
                }
            }
        </script>

        <div class="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-4 flex items-start">
            <i class="fas fa-info-circle text-blue-500 mt-1 mr-3"></i>
            <div class="text-sm text-blue-800">
                <p class="font-bold mb-1">Note sur la sécurité :</p>
                <p>Pour des raisons de sécurité, nous vous recommandons de ne pas partager vos identifiants et de choisir un mot de passe complexe mêlant lettres, chiffres et caractères spéciaux.</p>
            </div>
        </div>
    </div>
<?php require_once APP_ROOT . '/includes/footer.php'; ?>

<?php
/**
 * ============================================
 * GESTION DES AGENTS (ADMIN)
 * Système de Gestion Fiscale
 * ============================================
 */

define('APP_ROOT', dirname(__DIR__));
session_start();

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/classes/Agent.php';

// Vérifier l'authentification
if (!Agent::estConnecte() || !Agent::verifierTimeout()) {
    header('Location: ../index.php');
    exit;
}

$agent = Agent::getAgentConnecte();

// Vérifier les droits admin
if (!$agent->estAdmin()) {
    header('Location: dashboard.php');
    exit;
}

// Message
$message = '';
$messageType = 'success';
$csrfToken = Agent::getCsrfToken();

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!Agent::verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = 'Session invalide, veuillez réessayer.';
        $messageType = 'error';
        $action = '';
    }
    
    try {
        switch ($action) {
            case 'ajouter':
                $nouvelAgent = new Agent();
                $nouvelAgent->setNom(trim($_POST['nom'] ?? ''))
                            ->setPrenom(trim($_POST['prenom'] ?? ''))
                            ->setEmail(trim($_POST['email'] ?? ''))
                            ->setMotDePasse($_POST['mot_de_passe'] ?? '')
                            ->setRole($_POST['role'] ?? 'agent')
                            ->setStatut('actif');
                
                if ($nouvelAgent->sauvegarder()) {
                    $message = 'Agent créé avec succès.';
                }
                break;
                
            case 'modifier':
                $agentId = (int) $_POST['agent_id'];
                $agentModif = new Agent($agentId);
                
                if ($agentModif->getId()) {
                    $agentModif->setNom(trim($_POST['nom'] ?? ''))
                               ->setPrenom(trim($_POST['prenom'] ?? ''))
                               ->setEmail(trim($_POST['email'] ?? ''))
                               ->setRole($_POST['role'] ?? 'agent')
                               ->setStatut($_POST['statut'] ?? 'actif');
                    
                    // Changer le mot de passe si fourni
                    $nouveauMdp = $_POST['nouveau_mot_de_passe'] ?? '';
                    if (!empty($nouveauMdp)) {
                        $agentModif->changerMotDePasse($nouveauMdp);
                    }
                    
                    $agentModif->sauvegarder();
                    $message = 'Agent modifié avec succès.';
                }
                break;
                
            case 'supprimer':
                $agentId = (int) $_POST['agent_id'];
                if ($agentId !== $agent->getId()) { // Ne pas se supprimer soi-même
                    $agentSuppr = new Agent($agentId);
                    if ($agentSuppr->getId()) {
                        $agentSuppr->supprimer();
                        $message = 'Agent désactivé avec succès.';
                    }
                } else {
                    throw new Exception('Vous ne pouvez pas vous désactiver vous-même.');
                }
                break;
                
            case 'reactiver':
                $agentId = (int) $_POST['agent_id'];
                $agentReact = new Agent($agentId);
                if ($agentReact->getId()) {
                    $agentReact->setStatut('actif')->sauvegarder();
                    $message = 'Agent réactivé avec succès.';
                }
                break;
        }
    } catch (Exception $e) {
        $message = messageErreurUtilisateur($e, "cette action sur l'agent");
        $messageType = 'error';
    }
}

// Récupérer tous les agents
$agents = Agent::getAll(false); // Inclure les inactifs

// Statistiques
$nbActifs = 0;
$nbInactifs = 0;
foreach ($agents as $a) {
    if ($a['statut'] === 'actif') $nbActifs++;
    else $nbInactifs++;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des agents - Gestion Fiscale</title>
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
                    <a href="dashboard.php" class="flex items-center">
                        <div class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center mr-3">
                            <i class="fas fa-calculator text-xl"></i>
                        </div>
                        <span class="text-xl font-bold">Gestion Fiscale</span>
                    </a>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-blue-200"><?= htmlspecialchars($agent->getNomComplet()) ?></span>
                    <a href="logout.php" class="text-blue-200 hover:text-white"><i class="fas fa-sign-out-alt"></i></a>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Menu secondaire -->
    <div class="bg-white shadow">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex space-x-8 h-12">
                <a href="dashboard.php" class="border-b-2 border-transparent text-gray-500 hover:text-gray-700 px-1 pt-3 text-sm font-medium">
                    <i class="fas fa-tachometer-alt mr-1"></i> Tableau de bord
                </a>
                <a href="clients.php" class="border-b-2 border-transparent text-gray-500 hover:text-gray-700 px-1 pt-3 text-sm font-medium">
                    <i class="fas fa-users mr-1"></i> Clients
                </a>
                <a href="impots.php" class="border-b-2 border-transparent text-gray-500 hover:text-gray-700 px-1 pt-3 text-sm font-medium">
                    <i class="fas fa-file-invoice-dollar mr-1"></i> Impôts
                </a>
                <a href="agents.php" class="border-b-2 border-primary-500 text-primary-600 px-1 pt-3 text-sm font-medium">
                    <i class="fas fa-user-cog mr-1"></i> Agents
                </a>
            </div>
        </div>
    </div>
    
    <!-- Contenu -->
    <main class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- En-tête -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Gestion des agents</h1>
                <p class="text-gray-500 mt-1"><?= $nbActifs ?> actif(s), <?= $nbInactifs ?> inactif(s)</p>
            </div>
            <button onclick="openModal('modal-ajouter')"
                    class="btn-primary mt-4 md:mt-0">
                <i class="fas fa-plus"></i> Nouvel agent
            </button>
        </div>
        
        <!-- Message -->
        <?php if ($message): 
            $alertClass = $messageType === 'success' ? 'bg-green-50 border-green-500 text-green-700' : 'bg-red-50 border-red-500 text-red-700';
        ?>
        <div class="mb-6 p-4 rounded-lg border-l-4 <?= $alertClass ?>">
            <div class="flex items-center">
                <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Liste des agents -->
        <div class="card overflow-hidden p-0">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Agent</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Rôle</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Statut</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Dernière connexion</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agents as $a): ?>
                    <tr class="<?= $a['statut'] !== 'actif' ? 'opacity-60' : '' ?>">
                        <td class="px-6 py-4">
                            <div class="flex items-center">
                                <div class="w-10 h-10 bg-primary-100 rounded-full flex items-center justify-center mr-3">
                                    <span class="text-primary-600 font-semibold">
                                        <?= strtoupper(substr($a['prenom'], 0, 1) . substr($a['nom'], 0, 1)) ?>
                                    </span>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-800"><?= htmlspecialchars($a['prenom'] . ' ' . $a['nom']) ?></p>
                                    <?php if ($a['id'] === $agent->getId()): ?>
                                    <span class="text-xs text-primary-600">(vous)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-gray-600"><?= htmlspecialchars($a['email']) ?></td>
                        <td class="px-6 py-4 text-center">
                            <?php
                            $roleColors = [
                                'admin' => 'bg-purple-100 text-purple-700',
                                'superviseur' => 'bg-blue-100 text-blue-700',
                                'agent' => 'bg-gray-100 text-gray-700'
                            ];
                            ?>
                            <span class="px-2 py-1 rounded-full text-xs font-medium <?= $roleColors[$a['role']] ?? 'bg-gray-100 text-gray-700' ?>">
                                <?= ucfirst($a['role']) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <span class="px-2 py-1 rounded-full text-xs font-medium <?= $a['statut'] === 'actif' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                                <?= ucfirst($a['statut']) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-center text-sm text-gray-500">
                            <?= $a['derniere_connexion'] ? date('d/m/Y H:i', strtotime($a['derniere_connexion'])) : '-' ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <div class="flex items-center justify-center space-x-2">
                                <button type="button"
                                        data-agent="<?= htmlspecialchars(json_encode([
                                            'id' => $a['id'],
                                            'prenom' => $a['prenom'],
                                            'nom' => $a['nom'],
                                            'email' => $a['email'],
                                            'role' => $a['role'],
                                            'statut' => $a['statut'],
                                        ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>"
                                        onclick="openEditModalFromBtn(this)" 
                                        class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg" title="Modifier">
                                    <i class="fas fa-edit"></i>
                                </button>
                                
                                <?php if ($a['id'] !== $agent->getId()): ?>
                                    <?php if ($a['statut'] === 'actif'): ?>
                                    <form method="POST" class="inline" onsubmit="return confirm('Désactiver cet agent ?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="action" value="supprimer">
                                        <input type="hidden" name="agent_id" value="<?= $a['id'] ?>">
                                        <button type="submit" class="p-2 text-red-600 hover:bg-red-50 rounded-lg" title="Désactiver">
                                            <i class="fas fa-user-slash"></i>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="action" value="reactiver">
                                        <input type="hidden" name="agent_id" value="<?= $a['id'] ?>">
                                        <button type="submit" class="p-2 text-green-600 hover:bg-green-50 rounded-lg" title="Réactiver">
                                            <i class="fas fa-user-check"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
    
    <!-- Modal Ajouter -->
    <div id="modal-ajouter" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
        <!-- Overlay -->
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm"></div>
        
        <div class="relative bg-white rounded-xl shadow-2xl max-w-md w-full">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-800">Nouvel agent</h3>
                <button onclick="closeModal('modal-ajouter')" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="ajouter">
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Prénom</label>
                        <input type="text" name="prenom" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
                        <input type="text" name="nom" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Mot de passe</label>
                    <input type="password" name="mot_de_passe" required minlength="6"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500">
                    <p class="text-xs text-gray-500 mt-1">Minimum 6 caractères</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Rôle</label>
                    <select name="role" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500">
                        <option value="agent">Agent</option>
                        <option value="superviseur">Superviseur</option>
                        <option value="admin">Administrateur</option>
                    </select>
                </div>
                
                <div class="flex justify-end space-x-3 pt-4">
                    <button type="button" onclick="closeModal('modal-ajouter')"
                            class="btn-outline">
                        Annuler
                    </button>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-plus"></i> Créer
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal Modifier -->
    <div id="modal-modifier" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
        <!-- Overlay -->
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm"></div>
        
        <div class="relative bg-white rounded-xl shadow-2xl max-w-md w-full">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-800">Modifier l'agent</h3>
                <button onclick="closeModal('modal-modifier')" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="modifier">
                <input type="hidden" name="agent_id" id="edit-agent-id">
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Prénom</label>
                        <input type="text" name="prenom" id="edit-prenom" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
                        <input type="text" name="nom" id="edit-nom" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" id="edit-email" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nouveau mot de passe</label>
                    <input type="password" name="nouveau_mot_de_passe" minlength="6"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500"
                           placeholder="Laisser vide pour ne pas changer">
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Rôle</label>
                        <select name="role" id="edit-role" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500">
                            <option value="agent">Agent</option>
                            <option value="superviseur">Superviseur</option>
                            <option value="admin">Administrateur</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Statut</label>
                        <select name="statut" id="edit-statut" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500">
                            <option value="actif">Actif</option>
                            <option value="inactif">Inactif</option>
                            <option value="suspendu">Suspendu</option>
                        </select>
                    </div>
                </div>
                
                <div class="flex justify-end space-x-3 pt-4">
                    <button type="button" onclick="closeModal('modal-modifier')"
                            class="btn-outline">
                        Annuler
                    </button>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openModal(id) {
            document.getElementById(id).classList.remove('hidden');
        }
        
        function closeModal(id) {
            document.getElementById(id).classList.add('hidden');
        }
        
        function openEditModalFromBtn(btn) {
            try {
                openEditModal(JSON.parse(btn.dataset.agent));
            } catch (e) {
                alert("Impossible d'ouvrir cet agent (données illisibles). Détail : " + e.message);
            }
        }

        function openEditModal(agent) {
            document.getElementById('edit-agent-id').value = agent.id;
            document.getElementById('edit-prenom').value = agent.prenom;
            document.getElementById('edit-nom').value = agent.nom;
            document.getElementById('edit-email').value = agent.email;
            document.getElementById('edit-role').value = agent.role;
            document.getElementById('edit-statut').value = agent.statut;
            openModal('modal-modifier');
        }
        
        // Fermer modal avec Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('[id^="modal-"]').forEach(m => m.classList.add('hidden'));
            }
        });
    </script>
</body>
</html>

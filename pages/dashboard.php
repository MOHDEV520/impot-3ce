<?php
/**
 * ============================================
 * TABLEAU DE BORD - ACCUEIL AGENT
 * Système de Gestion Fiscale
 * ============================================
 */

session_start();
define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/classes/Agent.php';
require_once APP_ROOT . '/classes/Client.php';
require_once APP_ROOT . '/classes/Impot.php';

// Vérifier l'authentification
if (!Agent::estConnecte() || !Agent::verifierTimeout()) {
    header('Location: ../index.php');
    exit;
}

$agent = Agent::getAgentConnecte();
$db = Database::getInstance();

// Mois et année actuels
$moisActuel = (int) date('n');
$anneeActuelle = (int) date('Y');

$moisNoms = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

// Récupérer les clients de l'agent
if ($agent->getRole() === 'admin') {
    $clients = $db->fetchAll("
        SELECT c.*, 
               (SELECT statut FROM compte_gestion_mensuel WHERE client_id = c.id AND mois = ? AND annee = ? LIMIT 1) as etat_mois,
               (SELECT MAX(date_modification) FROM compte_gestion_mensuel WHERE client_id = c.id) as derniere_action
        FROM clients c 
        WHERE c.statut = 'actif' 
        ORDER BY c.nom
    ", [$moisActuel, $anneeActuelle]);
} else {
    $clients = $db->fetchAll("
        SELECT c.*, 
               (SELECT statut FROM compte_gestion_mensuel WHERE client_id = c.id AND mois = ? AND annee = ? LIMIT 1) as etat_mois,
               (SELECT MAX(date_modification) FROM compte_gestion_mensuel WHERE client_id = c.id) as derniere_action
        FROM clients c 
        WHERE c.agent_id = ? AND c.statut = 'actif' 
        ORDER BY c.nom
    ", [$moisActuel, $anneeActuelle, $agent->getId()]);
}

// Statistiques
$totalClients = count($clients);
$clientsEnRetard = 0;
$clientsComplets = 0;

// Calculer le mois précédent pour vérifier les retards réels
$moisPrecedent = $moisActuel - 1;
$anneePrecedente = $anneeActuelle;
if ($moisPrecedent < 1) {
    $moisPrecedent = 12;
    $anneePrecedente--;
}

$dateAujourdhui = date('Y-m-d');
$dateLimiteMoisPrecedent = Impot::calculerDateLimite($moisPrecedent, $anneePrecedente);
$estApresLimite = ($dateAujourdhui > $dateLimiteMoisPrecedent);

foreach ($clients as &$client) {
    // État du mois en cours
    $etat = $client['etat_mois'] ?? 'non_saisi';
    
    if ($etat === 'valide' || $etat === 'verrouille') {
        $client['etat_label'] = 'Complet';
        $client['etat_class'] = 'bg-green-500';
        $clientsComplets++;
    } elseif ($etat === 'en_preparation' || $etat === 'pret_declaration') {
        $client['etat_label'] = 'En cours';
        $client['etat_class'] = 'bg-amber-500';
    } else {
        $client['etat_label'] = 'Incomplet';
        $client['etat_class'] = 'bg-slate-400';
    }

    // Vérifier si en retard sur le mois PRÉCÉDENT
    $statutPrecedent = $db->fetchColumn("SELECT statut FROM compte_gestion_mensuel WHERE client_id = ? AND mois = ? AND annee = ?", [$client['id'], $moisPrecedent, $anneePrecedente]);
    $client['retard'] = false;
    
    if (($statutPrecedent === null || !in_array($statutPrecedent, ['valide', 'verrouille'])) && $estApresLimite) {
        $client['retard'] = true;
        $clientsEnRetard++;
    }
    
    // Formater la date
    if ($client['derniere_action']) {
        $client['derniere_action'] = date('d/m/Y', strtotime($client['derniere_action']));
    } else {
        $client['derniere_action'] = 'Non saisi';
    }
}
unset($client);

// Limiter aux 5 derniers clients pour l'affichage
$clientsRecents = array_slice($clients, 0, 5);

$pageTitle = "Accueil - Tableau de bord";
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
    <header class="bg-primary-900 text-white shadow-xl no-print border-b border-primary-800">
        <div class="max-w-7xl mx-auto px-4 py-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-white rounded flex items-center justify-center p-1 shadow-inner">
                        <img src="../assets/img/logo.png" alt="3CE FISCUS" class="w-full h-full object-contain">
                    </div>
                    <span class="font-bold text-xl tracking-wider text-white">3CE FISCUS</span>
                </div>
                <div class="flex items-center space-x-6">
                    <div class="hidden md:flex flex-col items-end">
                        <span class="text-xs text-primary-300 uppercase tracking-tighter">Session active</span>
                        <strong class="text-sm font-bold"><?= htmlspecialchars($agent->getPrenom() . ' ' . $agent->getNom()) ?></strong>
                    </div>
                    <div class="h-8 w-px bg-primary-700 hidden md:block"></div>
                    <a href="logout.php" class="flex items-center px-4 py-2 bg-red-600/20 hover:bg-red-600 text-red-100 font-bold rounded-lg transition-all border border-red-500/30">
                        <i class="fas fa-sign-out-alt mr-2"></i> Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-8">
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-2xl font-bold text-slate-800">Accueil de l'agent</h1>
            <div class="bg-amber-100 border border-amber-200 px-4 py-2 rounded-lg flex items-center shadow-sm">
                <i class="fas fa-calendar-check text-amber-600 mr-3"></i>
                <div class="text-sm">
                    <span class="text-amber-800 font-medium">Prochaine échéance :</span>
                    <span class="text-amber-900 font-bold ml-1"><?= Impot::getEcheanceFormatee($moisActuel, $anneeActuelle) ?></span>
                </div>
            </div>
        </div>

        <!-- Profil et statistiques -->
        <div class="bg-white rounded-xl shadow-sm p-6 mb-8">
            <div class="flex items-center justify-between">
                <!-- Profil agent -->
                <div class="flex items-center space-x-4">
                    <div class="w-16 h-16 bg-slate-200 rounded-full flex items-center justify-center border-2 border-slate-100 shadow-inner">
                        <i class="fas fa-user-tie text-2xl text-slate-500"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-slate-800"><?= htmlspecialchars($agent->getPrenom() . ' ' . $agent->getNom()) ?></h2>
                        <div class="flex items-center space-x-2">
                            <span class="text-slate-500 text-sm"><?= $agent->getRole() === 'admin' ? 'Administrateur' : 'Agent Comptable' ?></span>
                            <span class="text-slate-300">|</span>
                            <a href="profil.php" class="text-primary-600 hover:text-primary-800 text-sm font-medium flex items-center">
                                <i class="fas fa-user-cog mr-1"></i> Paramètres du compte
                            </a>
                        </div>
                    </div>
                </div>

                <!-- KPIs -->
                <div class="flex space-x-4">
                    <!-- Nombre de clients -->
                    <div class="bg-primary-600 text-white px-6 py-4 rounded-lg text-center min-w-35">
                        <div class="text-sm opacity-80">Nombre de clients</div>
                        <div class="text-3xl font-bold"><?= $totalClients ?></div>
                    </div>
                    
                    <!-- Mois actif -->
                    <div class="bg-slate-200 text-slate-700 px-6 py-4 rounded-lg text-center min-w-35">
                        <div class="text-sm">Mois actif</div>
                        <div class="text-lg font-bold"><?= $moisNoms[$moisActuel] ?> <?= $anneeActuelle ?></div>
                    </div>
                    
                    <!-- Clients en retard -->
                    <div class="<?= $clientsEnRetard > 0 ? 'bg-red-600' : 'bg-green-600' ?> text-white px-6 py-4 rounded-lg text-center min-w-35">
                        <div class="text-sm opacity-80">Retards (<?= $moisNoms[$moisPrecedent] ?>)</div>
                        <div class="text-3xl font-bold"><?= $clientsEnRetard ?></div>
                        <div class="text-xs opacity-70 mt-1">Limite: <?= date('d/m/Y', strtotime($dateLimiteMoisPrecedent)) ?></div>
                    </div>
                </div>
            </div>

            <!-- Boutons d'action -->
            <div class="mt-6 flex space-x-4">
                <a href="clients.php" class="flex-1 py-3 bg-slate-700 text-white text-center rounded-lg hover:bg-slate-800 transition">
                    <i class="fas fa-users mr-2"></i> Portefeuille clients
                </a>
                <a href="client-nouveau.php" class="flex-1 py-3 bg-green-600 text-white text-center rounded-lg hover:bg-green-700 transition">
                    <i class="fas fa-plus mr-2"></i> Nouveau client
                </a>
            </div>
        </div>

        <!-- Clients récents -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b">
                <h2 class="text-lg font-semibold text-slate-800">Clients récents</h2>
            </div>
            
            <table class="w-full">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-sm font-medium text-slate-600">Client</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-slate-600">État du mois</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-slate-600">Dernière action</th>
                        <th class="px-6 py-3 text-center text-sm font-medium text-slate-600">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($clientsRecents)): ?>
                    <tr>
                        <td colspan="4" class="px-6 py-8 text-center text-slate-500">
                            Aucun client trouvé.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($clientsRecents as $client): ?>
                    <tr class="hover:bg-slate-50 <?= $client['retard'] ? 'bg-red-50' : '' ?>">
                        <td class="px-6 py-4">
                            <div class="flex items-center">
                                <a href="achats.php?client=<?= $client['id'] ?>&mois=<?= $moisActuel ?>&annee=<?= $anneeActuelle ?>" 
                                   class="text-primary-600 hover:text-primary-800 font-medium hover:underline">
                                    <?= htmlspecialchars($client['nom']) ?>
                                </a>
                                <?php if ($client['retard']): ?>
                                <span class="ml-2 px-2 py-0.5 bg-red-100 text-red-700 text-[10px] font-bold uppercase rounded-full animate-pulse">
                                    <i class="fas fa-exclamation-circle mr-1"></i> Retard
                                </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center">
                                <span class="w-2 h-2 rounded-full <?= $client['etat_class'] ?> mr-2"></span>
                                <?= $client['etat_label'] ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-slate-600">
                            <?= $client['derniere_action'] ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <div class="flex justify-center space-x-2">
                                <a href="achats.php?client=<?= $client['id'] ?>&mois=<?= $moisActuel ?>&annee=<?= $anneeActuelle ?>" 
                                   class="inline-flex items-center px-4 py-2 bg-primary-600 text-white text-sm font-medium rounded-lg hover:bg-primary-700 transition-colors">
                                    <i class="fas fa-folder-open mr-2"></i>
                                    Ouvrir
                                </a>
                                <a href="rapport-annuel.php?client=<?= $client['id'] ?>&annee=<?= $anneeActuelle ?>" 
                                   class="inline-flex items-center px-3 py-2 bg-slate-600 text-white text-sm font-medium rounded-lg hover:bg-slate-700 transition-colors" title="Rapport Annuel">
                                    <i class="fas fa-chart-bar mr-1"></i>
                                    Annuel
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Lien vers tous les clients -->
            <div class="px-6 py-4 border-t text-center">
                <a href="clients.php" class="inline-flex items-center px-6 py-2 border border-slate-300 text-slate-700 rounded-lg hover:bg-slate-50 transition">
                    Voir tous les clients
                </a>
            </div>
        </div>
    </main>
</body>
</html>

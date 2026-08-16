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
require_once APP_ROOT . '/classes/CompteGestionMensuel.php';

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
    // État du mois en cours — source unique de vérité, partagée avec clients.php
    $etatAffichage = CompteGestionMensuel::getEtatAffichage($client['etat_mois'] ?? null);
    $client['etat_label'] = $etatAffichage['label'];
    $client['etat_class'] = $etatAffichage['classePoint'];
    if ($etatAffichage['label'] === 'Complet') {
        $clientsComplets++;
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

$titrePage = "Tableau de bord";
require_once APP_ROOT . '/includes/header.php';
?>
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
                <div class="flex flex-wrap gap-4">
                    <!-- Nombre de clients -->
                    <div class="card-stat card-stat-accent text-center">
                        <div class="card-stat-label">Nombre de clients</div>
                        <div class="card-stat-value"><?= $totalClients ?></div>
                    </div>

                    <!-- Mois actif -->
                    <div class="card-stat card-stat-neutral text-center">
                        <div class="card-stat-label">Mois actif</div>
                        <div class="text-lg font-bold text-slate-800"><?= $moisNoms[$moisActuel] ?> <?= $anneeActuelle ?></div>
                    </div>

                    <!-- Clients en retard -->
                    <div class="card-stat <?= $clientsEnRetard > 0 ? 'card-stat-warn' : 'card-stat-accent' ?> text-center">
                        <div class="card-stat-label">Retards (<?= $moisNoms[$moisPrecedent] ?>)</div>
                        <div class="card-stat-value"><?= $clientsEnRetard ?></div>
                        <div class="text-xs text-slate-500 mt-1">Limite: <?= date('d/m/Y', strtotime($dateLimiteMoisPrecedent)) ?></div>
                    </div>
                </div>
            </div>

            <!-- Boutons d'action -->
            <div class="mt-6 flex space-x-4">
                <a href="clients.php" class="btn-secondary flex-1 py-3">
                    <i class="fas fa-users"></i> Portefeuille clients
                </a>
                <a href="client-nouveau.php" class="btn-success flex-1 py-3">
                    <i class="fas fa-plus"></i> Nouveau client
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
                                <span class="badge-dot <?= $client['etat_class'] ?> mr-2"></span>
                                <?= $client['etat_label'] ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-slate-600">
                            <?= $client['derniere_action'] ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <div class="flex justify-center space-x-2">
                                <a href="achats.php?client=<?= $client['id'] ?>&mois=<?= $moisActuel ?>&annee=<?= $anneeActuelle ?>"
                                   class="btn-primary">
                                    <i class="fas fa-folder-open"></i>
                                    Ouvrir
                                </a>
                                <a href="rapport-annuel.php?client=<?= $client['id'] ?>&annee=<?= $anneeActuelle ?>"
                                   class="btn-secondary" title="Rapport Annuel">
                                    <i class="fas fa-chart-bar"></i>
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
                <a href="clients.php" class="btn-outline">
                    Voir tous les clients
                </a>
            </div>
        </div>
<?php require_once APP_ROOT . '/includes/footer.php'; ?>

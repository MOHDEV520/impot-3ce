<?php
/**
 * ============================================
 * LISTE DES CLIENTS
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

// Filtres
$recherche = trim($_GET['recherche'] ?? '');
$filtreActivite = $_GET['activite'] ?? '';
$filtreEtat = $_GET['etat'] ?? '';

// Récupérer les clients
if ($agent->getRole() === 'admin') {
    $sql = "SELECT c.*, 
                   (SELECT statut FROM compte_gestion_mensuel WHERE client_id = c.id AND mois = ? AND annee = ? LIMIT 1) as etat_mois,
                   (SELECT MAX(date_modification) FROM compte_gestion_mensuel WHERE client_id = c.id) as derniere_action
            FROM clients c 
            WHERE c.statut = 'actif'";
    $params = [$moisActuel, $anneeActuelle];
} else {
    $sql = "SELECT c.*, 
                   (SELECT statut FROM compte_gestion_mensuel WHERE client_id = c.id AND mois = ? AND annee = ? LIMIT 1) as etat_mois,
                   (SELECT MAX(date_modification) FROM compte_gestion_mensuel WHERE client_id = c.id) as derniere_action
            FROM clients c 
            WHERE c.agent_id = ? AND c.statut = 'actif'";
    $params = [$moisActuel, $anneeActuelle, $agent->getId()];
}

// Filtre recherche
if (!empty($recherche)) {
    $sql .= " AND c.nom LIKE ?";
    $params[] = "%$recherche%";
}

$sql .= " ORDER BY c.nom";
$clients = $db->fetchAll($sql, $params);

// Activités (simplifié - pas de colonne activite dans la table)
$activites = [];

// Traiter les états
foreach ($clients as &$client) {
    $etat = $client['etat_mois'] ?? 'non_saisi';
    
    if ($etat === 'valide' || $etat === 'verrouille') {
        $client['etat_label'] = 'Complet';
        $client['etat_class'] = 'bg-green-500';
    } elseif ($etat === 'en_cours' || $etat === 'pret') {
        $client['etat_label'] = 'En cours';
        $client['etat_class'] = 'bg-amber-500';
    } else {
        $client['etat_label'] = 'Incomplet';
        $client['etat_class'] = 'bg-red-500';
    }
    
    // Formater la date
    if ($client['derniere_action']) {
        $client['derniere_action_formatted'] = date('d/m/Y', strtotime($client['derniere_action']));
    } else {
        $client['derniere_action_formatted'] = 'Non saisi';
    }
}
unset($client);

// Appliquer le filtre d'état après traitement
if (!empty($filtreEtat)) {
    $clients = array_filter($clients, function($c) use ($filtreEtat) {
        if ($filtreEtat === 'complet') return $c['etat_label'] === 'Complet';
        if ($filtreEtat === 'en_cours') return $c['etat_label'] === 'En cours';
        if ($filtreEtat === 'incomplet') return $c['etat_label'] === 'Incomplet';
        return true;
    });
}

$pageTitle = "Liste des Clients";
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
    <header class="bg-primary-800 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 py-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-2">
                    <i class="fas fa-building text-xl"></i>
                    <span class="font-bold text-lg">CABINET FISCAL</span>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-primary-200">
                        Bienvenue, <strong class="text-white"><?= htmlspecialchars($agent->getPrenom() . ' ' . $agent->getNom()) ?></strong> | <?= $agent->getRole() === 'admin' ? 'Administrateur' : 'Agent Comptable' ?>
                    </span>
                    <a href="logout.php" class="text-primary-200 hover:text-white">Déconnexion</a>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-8">
        <?php 
        // Afficher les messages
        $msg = $_GET['msg'] ?? '';
        $msgType = $_GET['type'] ?? 'info';
        if ($msg): 
            $bgColor = $msgType === 'success' ? 'bg-green-50 border-green-500 text-green-700' : 'bg-red-50 border-red-500 text-red-700';
            $icon = $msgType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        ?>
        <div class="mb-6 p-4 <?= $bgColor ?> border-l-4 rounded-r-lg">
            <div class="flex items-center">
                <i class="fas <?= $icon ?> mr-2"></i>
                <span><?= htmlspecialchars($msg) ?></span>
            </div>
        </div>
        <?php endif; ?>

        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-slate-800">Liste des Clients</h1>
            <a href="client-nouveau.php" class="inline-flex items-center px-4 py-2 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700 transition-colors">
                <i class="fas fa-plus mr-2"></i> Nouveau client
            </a>
        </div>

        <!-- Filtres -->
        <div class="bg-white rounded-xl shadow-sm p-4 mb-6">
            <form method="GET" class="flex flex-wrap items-center gap-4">
                <!-- Recherche -->
                <div class="flex-1 min-w-50">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="text" name="recherche" value="<?= htmlspecialchars($recherche) ?>"
                               placeholder="Rechercher un client..."
                               class="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    </div>
                </div>
                
                <!-- Filtre Activité -->
                <select name="activite" class="px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500">
                    <option value="">Activité: Toutes</option>
                    <?php foreach ($activites as $act): ?>
                    <option value="<?= htmlspecialchars($act['activite']) ?>" <?= $filtreActivite === $act['activite'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($act['activite']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                
                <!-- Filtre État -->
                <select name="etat" class="px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500">
                    <option value="">État du mois: Tous</option>
                    <option value="complet" <?= $filtreEtat === 'complet' ? 'selected' : '' ?>>Complet</option>
                    <option value="en_cours" <?= $filtreEtat === 'en_cours' ? 'selected' : '' ?>>En cours</option>
                    <option value="incomplet" <?= $filtreEtat === 'incomplet' ? 'selected' : '' ?>>Incomplet</option>
                </select>
                
                <button type="submit" class="px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700">
                    <i class="fas fa-filter mr-2"></i> Filtrer
                </button>
                
                <?php if (!empty($recherche) || !empty($filtreActivite) || !empty($filtreEtat)): ?>
                <a href="clients.php" class="px-4 py-2 border border-slate-300 text-slate-700 rounded-lg hover:bg-slate-50">
                    <i class="fas fa-times mr-2"></i> Réinitialiser
                </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Tableau des clients -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <table class="w-full">
                <thead class="bg-slate-50 border-b">
                    <tr>
                        <th class="px-6 py-3 text-left text-sm font-medium text-slate-600">Client</th>
                        <th class="px-4 py-3 text-left text-sm font-medium text-slate-600">Activité</th>
                        <th class="px-4 py-3 text-left text-sm font-medium text-slate-600">Mois en cours</th>
                        <th class="px-4 py-3 text-center text-sm font-medium text-slate-600">État du mois</th>
                        <th class="px-4 py-3 text-left text-sm font-medium text-slate-600">Dernière action</th>
                        <th class="px-4 py-3 text-center text-sm font-medium text-slate-600">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($clients)): ?>
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-slate-500">
                            <i class="fas fa-users text-4xl text-slate-300 mb-3"></i>
                            <p>Aucun client trouvé.</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($clients as $client): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-6 py-4">
                            <a href="achats.php?client=<?= $client['id'] ?>&mois=<?= $moisActuel ?>&annee=<?= $anneeActuelle ?>" 
                               class="text-primary-600 font-medium hover:text-primary-800 hover:underline">
                                <?= htmlspecialchars($client['nom']) ?>
                            </a>
                        </td>
                        <td class="px-4 py-4 text-slate-600">
                            <?= htmlspecialchars($client['activite'] ?? '-') ?>
                        </td>
                        <td class="px-4 py-4 text-slate-600">
                            <?= $moisNoms[$moisActuel] ?> <?= $anneeActuelle ?>
                        </td>
                        <td class="px-4 py-4 text-center">
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-white text-sm font-medium <?= $client['etat_class'] ?>">
                                <?= $client['etat_label'] ?>
                            </span>
                        </td>
                        <td class="px-4 py-4 text-slate-600">
                            <?= $client['derniere_action_formatted'] ?>
                        </td>
                        <td class="px-4 py-4 text-center">
                            <div class="flex justify-center space-x-2">
                                <a href="achats.php?client=<?= $client['id'] ?>&mois=<?= $moisActuel ?>&annee=<?= $anneeActuelle ?>" 
                                   class="inline-flex items-center px-3 py-2 bg-primary-600 text-white text-sm font-medium rounded-lg hover:bg-primary-700 transition-colors"
                                   title="Ouvrir le dossier">
                                    <i class="fas fa-folder-open"></i>
                                </a>
                                <a href="client-edit.php?id=<?= $client['id'] ?>" 
                                   class="inline-flex items-center px-3 py-2 bg-amber-500 text-white text-sm font-medium rounded-lg hover:bg-amber-600 transition-colors"
                                   title="Modifier">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="rapport-annuel.php?client=<?= $client['id'] ?>&annee=<?= $anneeActuelle ?>" 
                                   class="inline-flex items-center px-3 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors"
                                   title="Rapport annuel">
                                    <i class="fas fa-chart-line mr-1"></i> Rapport
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Lien retour au dashboard -->
        <div class="mt-6 text-center">
            <a href="dashboard.php" class="inline-flex items-center text-slate-600 hover:text-primary-600">
                <i class="fas fa-chevron-left mr-2"></i> Retour au tableau de bord
            </a>
        </div>
    </main>
</body>
</html>

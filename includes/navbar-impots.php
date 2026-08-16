<?php
/**
 * ============================================
 * NAVIGATION POUR LES PAGES CLIENTS (IMPOTS)
 * ============================================
 */
if (!isset($agentConnecte)) {
    $agentConnecte = Agent::getAgentConnecte();
}

$pageActuelle = basename($_SERVER['PHP_SELF']);

// Initialisation sécurisée des variables communes pour éviter les warnings
$clientId = $clientId ?? ($_GET['client'] ?? 0);
$mois = $mois ?? ($_GET['mois'] ?? date('n'));
$annee = $annee ?? ($_GET['annee'] ?? date('Y'));
$moisNoms = $moisNoms ?? [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août', 
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

// Nom du client à afficher : $client peut être un objet Client, un tableau, ou absent.
// Ne pas réassigner $client — les pages l'utilisent encore après l'inclusion de la navbar.
if (isset($client) && $client instanceof Client) {
    $clientNomNav = $client->getNom();
} elseif (isset($client) && is_array($client)) {
    $clientNomNav = $client['nom'] ?? 'Client';
} else {
    if ($clientId > 0) {
        require_once APP_ROOT . '/classes/Client.php';
        $clObj = new Client($clientId);
        $clientNomNav = $clObj->getNom();
    } else {
        $clientNomNav = 'Session';
    }
    $client = ['nom' => $clientNomNav];
}
?>

<!-- Navbar Principale -->
<header class="bg-primary-900 text-white shadow-xl no-print border-b border-primary-800">
    <div class="max-w-7xl mx-auto px-4 py-3">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-white rounded flex items-center justify-center p-1 shadow-inner">
                    <img src="../assets/img/logo.png" alt="3CE FISCUS" class="w-full h-full object-contain">
                </div>
                <span class="font-bold text-xl tracking-wider text-white">3CE FISCUS</span>
            </div>
            <div class="flex items-center space-x-4">
                <div class="hidden md:flex flex-col items-end">
                    <span class="text-xs text-primary-300 uppercase tracking-tighter">Session active</span>
                    <strong class="text-sm font-bold"><?= htmlspecialchars($agentConnecte->getPrenom() . ' ' . $agentConnecte->getNom()) ?></strong>
                </div>
                <div class="h-8 w-px bg-primary-700 hidden md:block mx-2"></div>
                <a href="logout.php" class="flex items-center px-4 py-2 bg-red-600/20 hover:bg-red-600 text-red-100 font-bold rounded-lg transition-all border border-red-500/30">
                    <i class="fas fa-sign-out-alt mr-2"></i> <span class="hidden md:inline">Déconnexion</span>
                </a>
            </div>
        </div>
    </div>
</header>

<!-- Sous-header avec breadcrumb et sélecteur de mois -->
<div class="bg-white border-b shadow-sm no-print">
    <div class="max-w-7xl mx-auto px-4 py-3">
        <div class="flex items-center justify-between">
            <div class="flex items-center text-sm">
                <a href="dashboard.php" class="text-slate-500 hover:text-slate-700 uppercase">
                    <i class="fas fa-home mr-1"></i> ACCUEIL
                </a>
                <span class="mx-2 text-slate-400">|</span>
                <span class="text-primary-600 font-bold uppercase"><?= htmlspecialchars($clientNomNav) ?></span>
            </div>
            
            <!-- Sélecteur de mois/année - visible uniquement si un client est sélectionné -->
            <?php if ($clientId > 0):
                // Liens mois précédent / suivant en préservant les autres paramètres (type, etc.)
                $moisPrec = $mois == 1 ? 12 : $mois - 1;
                $anneePrec = $mois == 1 ? $annee - 1 : $annee;
                $moisSuiv = $mois == 12 ? 1 : $mois + 1;
                $anneeSuiv = $mois == 12 ? $annee + 1 : $annee;
                // Ne pas transporter l'état volatil de la page (saisies, filtres) vers un autre mois
                $paramsNav = array_diff_key($_GET, array_flip(['masse_salariale', 'loyers_percus', 'its', 'marge', 'marge_taxable', 'fournisseur']));
                $urlPrec = '?' . http_build_query(array_merge($paramsNav, ['client' => $clientId, 'mois' => $moisPrec, 'annee' => $anneePrec]));
                $urlSuiv = '?' . http_build_query(array_merge($paramsNav, ['client' => $clientId, 'mois' => $moisSuiv, 'annee' => $anneeSuiv]));
            ?>
            <div class="flex items-center space-x-2">
                <a href="<?= htmlspecialchars($urlPrec) ?>" class="px-2 py-1 bg-slate-50 border rounded hover:bg-slate-100" aria-label="Mois précédent" title="Mois précédent">
                    <i class="fas fa-chevron-left" aria-hidden="true"></i>
                </a>
                <form action="" method="GET" class="flex items-center space-x-2">
                    <input type="hidden" name="client" value="<?= $clientId ?>">
                    <?php if (isset($typeImpot)): ?><input type="hidden" name="type" value="<?= $typeImpot ?>"><?php endif; ?>

                    <select name="mois" onchange="this.form.submit()" aria-label="Mois" class="px-3 py-1 border rounded bg-slate-50 text-sm focus:ring-primary-500">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m == $mois ? 'selected' : '' ?>><?= $moisNoms[$m] ?></option>
                        <?php endfor; ?>
                    </select>
                    <select name="annee" onchange="this.form.submit()" aria-label="Année" class="px-3 py-1 border rounded bg-slate-50 text-sm focus:ring-primary-500">
                        <?php for ($a = date('Y') - 5; $a <= date('Y') + 1; $a++): ?>
                        <option value="<?= $a ?>" <?= $a == $annee ? 'selected' : '' ?>><?= $a ?></option>
                        <?php endfor; ?>
                    </select>
                </form>
                <a href="<?= htmlspecialchars($urlSuiv) ?>" class="px-2 py-1 bg-slate-50 border rounded hover:bg-slate-100" aria-label="Mois suivant" title="Mois suivant">
                    <i class="fas fa-chevron-right" aria-hidden="true"></i>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Navigation par onglets -->
<?php if ($clientId > 0): ?>
<div class="bg-white border-b no-print mb-6">
    <div class="max-w-7xl mx-auto px-4">
        <nav class="flex overflow-x-auto">
            <a href="achats.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" 
               class="px-5 py-3 text-sm font-medium whitespace-nowrap <?= $pageActuelle == 'achats.php' ? 'text-primary-600 border-b-2 border-primary-600' : 'text-slate-600 hover:text-primary-600 hover:bg-slate-50' ?>">
                <i class="fas fa-shopping-cart mr-2"></i>ACHATS
            </a>
            <a href="depenses.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" 
               class="px-5 py-3 text-sm font-medium whitespace-nowrap <?= $pageActuelle == 'depenses.php' ? 'text-primary-600 border-b-2 border-primary-600' : 'text-slate-600 hover:text-primary-600 hover:bg-slate-50' ?>">
                <i class="fas fa-wallet mr-2"></i>DÉPENSES
            </a>
            <a href="impots.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" 
               class="px-5 py-3 text-sm font-medium whitespace-nowrap <?= $pageActuelle == 'impots.php' ? 'text-primary-600 border-b-2 border-primary-600' : 'text-slate-600 hover:text-primary-600 hover:bg-slate-50' ?>">
                <i class="fas fa-file-invoice-dollar mr-2"></i>IMPÔTS
            </a>
            <a href="recapitulatif.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" 
               class="px-5 py-3 text-sm font-medium whitespace-nowrap <?= $pageActuelle == 'recapitulatif.php' ? 'text-primary-600 border-b-2 border-primary-600 bg-primary-50' : 'text-slate-600 hover:text-primary-600 hover:bg-slate-50' ?>">
                <i class="fas fa-chart-bar mr-2"></i>RÉCAPITULATIF
            </a>
            <a href="sauvegarde.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" 
               class="px-5 py-3 text-sm font-medium whitespace-nowrap <?= $pageActuelle == 'sauvegarde.php' ? 'text-primary-600 border-b-2 border-primary-600' : 'text-slate-600 hover:text-primary-600 hover:bg-slate-50' ?>">
                <i class="fas fa-database mr-2"></i>MAINTENANCE
            </a>
        </nav>
    </div>
</div>
<?php endif; ?>

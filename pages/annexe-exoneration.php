<?php
/**
 * ============================================
 * FICHE ANNEXE CHIFFRE D'AFFAIRE EXONERES TVA
 * SUIVANT (ART 195 CGI)
 * Lignes configurables par client / activité
 * ============================================
 */

session_start();
define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/classes/Agent.php';
require_once APP_ROOT . '/classes/Client.php';
require_once APP_ROOT . '/classes/Achat.php';

if (!Agent::estConnecte() || !Agent::verifierTimeout()) {
    header('Location: ../index.php');
    exit;
}

$db = Database::getInstance();
$agent = Agent::getAgentConnecte();

$clientId = isset($_GET['client']) ? (int)$_GET['client'] : 0;
$mois = isset($_GET['mois']) ? (int)$_GET['mois'] : (int)date('n');
$annee = isset($_GET['annee']) ? (int)$_GET['annee'] : (int)date('Y');

$client = $db->fetchOne("SELECT * FROM clients WHERE id = ?", [$clientId]);
if (!$client) { header('Location: clients.php'); exit; }
if (!$agent->aAccesClient($clientId)) {
    header('Location: clients.php?msg=' . urlencode('Accès non autorisé') . '&type=error');
    exit;
}

$moisNoms = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

// ========== Traitement POST (ajout / suppression / modification) ==========
$message = '';
$csrfToken = Agent::getCsrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Agent::verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = 'Session invalide, veuillez réessayer.';
        header("Location: annexe-exoneration.php?client=$clientId&mois=$mois&annee=$annee&msg=" . urlencode($message));
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'ajouter') {
        $code = trim($_POST['code_produit'] ?? '');
        $nature = trim($_POST['nature'] ?? '');
        if ($nature) {
            $maxOrdre = $db->fetchOne("SELECT MAX(numero_ordre) as mx FROM exonerations_client WHERE client_id = ?", [$clientId]);
            $ordre = ($maxOrdre['mx'] ?? 0) + 1;
            $db->insert(
                "INSERT INTO exonerations_client (client_id, numero_ordre, code_produit, nature) VALUES (?, ?, ?, ?)",
                [$clientId, $ordre, $code, $nature]
            );
            $message = 'Ligne ajoutée.';
        }
    }

    if ($action === 'supprimer') {
        $id = (int)($_POST['ligne_id'] ?? 0);
        if ($id > 0) {
            $db->delete("DELETE FROM exonerations_client WHERE id = ? AND client_id = ?", [$id, $clientId]);
            $message = 'Ligne supprimée.';
        }
    }

    if ($action === 'sauvegarder_montants') {
        $ids = $_POST['ligne_ids'] ?? [];
        $montants = $_POST['montants'] ?? [];
        $taux = $_POST['taux_list'] ?? [];
        foreach ($ids as $i => $id) {
            $m = (float) str_replace([' ', ','], ['', '.'], $montants[$i] ?? 0);
            $t = trim($taux[$i] ?? '');
            $db->update("UPDATE exonerations_client SET montant_ht = ?, taux = ? WHERE id = ? AND client_id = ?", [$m, $t, (int)$id, $clientId]);
        }
        $message = 'Montants sauvegardés.';
    }

    // Rediriger pour éviter la double soumission
    $msgParam = $message ? '&msg=' . urlencode($message) : '';
    header("Location: annexe-exoneration.php?client=$clientId&mois=$mois&annee=$annee$msgParam");
    exit;
}

if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}

// ========== Données ==========
// CA Exonéré
$compteGestion = $db->fetchOne(
    "SELECT * FROM compte_gestion_mensuel WHERE client_id = ? AND mois = ? AND annee = ?",
    [$clientId, $mois, $annee]
);

$totauxAchats = Achat::getTotaux($clientId, $mois, $annee);
$achatsHT_Reel = (float)($totauxAchats['total_ht'] ?? 0);
$achatsHT = (float)($totauxAchats['total_ht_ca'] ?? $achatsHT_Reel);
$tvaDeductibleAchats = (float)($totauxAchats['total_tva'] ?? 0);

$margeSauvegardee = (float)($compteGestion['marge'] ?? 1.30);
$parametres = $db->fetchOne("SELECT taux_tva FROM parametres_fiscaux WHERE client_id = ?", [$clientId]);
$tauxTVA = $parametres ? (float)($parametres['taux_tva'] ?? 18.00) : 18.00;
$tauxTVADecimal = ($tauxTVA > 0) ? ($tauxTVA / 100) : 0.18;

$achatsTaxable = ($tvaDeductibleAchats > 0) ? round($tvaDeductibleAchats / $tauxTVADecimal) : 0;

$caGlobalSauvegarde = (float)($compteGestion['ca_global'] ?? 0);
$caExonereSauvegarde = (float)($compteGestion['ca_exonere'] ?? 0);

if ($caExonereSauvegarde > 0) {
    $caExonere = $caExonereSauvegarde;
} elseif ($margeSauvegardee > 0) {
    $caGlobal = $caGlobalSauvegarde > 0 ? $caGlobalSauvegarde : round($achatsHT * $margeSauvegardee);
    $caTaxable = round($achatsTaxable * $margeSauvegardee);
    $caExonere = max(0, $caGlobal - $caTaxable);
} else {
    $caExonere = 0;
}

// Lignes d'exonération du client
$lignes = $db->fetchAll(
    "SELECT * FROM exonerations_client WHERE client_id = ? AND actif = 1 ORDER BY numero_ordre",
    [$clientId]
);

// Si aucune ligne, insérer les lignes par défaut automatiquement
if (empty($lignes)) {
    $lignesDefaut = [
        [1, 'Art 195 (LF 2022)', 'Prestations sanitaires'],
        [2, 'Art 195 (LF 2022)', 'Ventes de cereales en grains'],
        [3, 'Art 195 (LF 2022)', 'Enseignements'],
        [4, '27.10.00.32.00', 'Essence Super'],
        [5, '27.10.00.32.01', 'Gas Oil'],
        [6, '30.04', 'Medicaments therapeutiques, prophylactique et autres'],
    ];
    foreach ($lignesDefaut as $ld) {
        $db->insert(
            "INSERT INTO exonerations_client (client_id, numero_ordre, code_produit, nature) VALUES (?, ?, ?, ?)",
            [$clientId, $ld[0], $ld[1], $ld[2]]
        );
    }
    // Recharger
    $lignes = $db->fetchAll(
        "SELECT * FROM exonerations_client WHERE client_id = ? AND actif = 1 ORDER BY numero_ordre",
        [$clientId]
    );
}

$dateGeneration = date('d/m/Y');

function formatMontant($m) { return number_format($m, 0, ',', ' '); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Annexe Exonération - <?= htmlspecialchars($client['nom'] ?? '') ?></title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; color: #000; background: #d0d0d0; }

        .page {
            width: 297mm; min-height: 210mm; margin: 15px auto;
            background: #fff; padding: 10mm 12mm;
            box-shadow: 0 0 15px rgba(0,0,0,0.2);
        }

        .titre { text-align: center; font-size: 12pt; font-weight: bold; margin-bottom: 10px; text-transform: uppercase; }

        .champs-info { font-size: 9pt; margin-bottom: 10px; line-height: 2.2; }
        .champ-ligne { display: flex; align-items: baseline; gap: 5px; }
        .champ-ligne .label { white-space: nowrap; }
        .champ-ligne .valeur { flex: 1; border-bottom: 1px dotted #999; padding-left: 5px; font-weight: bold; }
        .champs-row { display: flex; gap: 40px; }

        table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-top: 8px; }
        th, td { border: 1px solid #333; padding: 5px 8px; }
        thead th { background: #e8e8e8; font-weight: bold; text-align: center; font-size: 8.5pt; text-transform: uppercase; }
        tbody td { vertical-align: middle; }
        tbody td.num { text-align: center; }
        tbody td.montant { text-align: right; font-family: 'Consolas', monospace; }
        tbody td.taux { text-align: center; }
        tbody tr.has-montant { background: #f0f7ff; }
        tfoot td { font-weight: bold; background: #e8e8e8; padding: 6px 8px; }

        /* Info CA */
        .ca-info {
            background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 6px;
            padding: 8px 15px; margin-bottom: 10px; font-size: 9pt;
            display: flex; justify-content: space-between; align-items: center;
        }
        .ca-info strong { color: #3730a3; }

        /* Formulaire ajout */
        .form-ajout {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;
            padding: 10px 15px; margin-bottom: 10px;
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        }
        .form-ajout input { padding: 5px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 9pt; }
        .form-ajout input.code { width: 150px; }
        .form-ajout input.nature { width: 350px; }
        .form-ajout button {
            padding: 5px 14px; background: #2563eb; color: #fff; border: none;
            border-radius: 4px; cursor: pointer; font-size: 9pt; font-weight: 600;
        }
        .form-ajout button:hover { background: #1d4ed8; }

        .btn-suppr {
            background: #dc2626; color: #fff; border: none; border-radius: 3px;
            padding: 2px 8px; cursor: pointer; font-size: 8pt;
        }
        .btn-suppr:hover { background: #b91c1c; }

        .btn-save {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 8px 20px; background: #16a34a; color: #fff; border: none;
            border-radius: 5px; cursor: pointer; font-size: 10pt; font-weight: 600;
            margin-top: 8px;
        }
        .btn-save:hover { background: #15803d; }

        .input-montant {
            width: 130px; padding: 4px 6px; border: 1px solid #cbd5e1;
            border-radius: 3px; text-align: right; font-family: 'Consolas', monospace; font-size: 9pt;
        }
        .input-taux {
            width: 60px; padding: 4px 6px; border: 1px solid #cbd5e1;
            border-radius: 3px; text-align: center; font-size: 9pt;
        }

        .msg-success { background: #dcfce7; color: #166534; padding: 6px 12px; border-radius: 4px; margin-bottom: 8px; font-size: 9pt; }

        /* Boutons nav */
        .actions {
            position: fixed; top: 0; left: 0; right: 0; background: #222;
            padding: 10px 20px; display: flex; justify-content: center; gap: 10px; z-index: 100;
        }
        .actions a, .actions button {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 18px; font-size: 13px; font-weight: 600;
            text-decoration: none; border-radius: 5px; cursor: pointer; border: none; color: #fff;
        }
        .actions .btn-print { background: #dc2626; }
        .actions .btn-back { background: #555; }
        .page-wrapper { padding-top: 55px; }

        @media print {
            .actions, .form-ajout, .ca-info, .btn-suppr, .btn-save, .msg-success, .no-print { display: none !important; }
            .page-wrapper { padding-top: 0; }
            body { background: #fff; }
            .page { width: 100%; margin: 0; padding: 8mm 10mm; box-shadow: none; min-height: auto; }
            thead th, tfoot td { background: #e8e8e8 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            tbody tr.has-montant { background: #f0f7ff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .input-montant, .input-taux { border: none; background: transparent; }
        }
    </style>
</head>
<body>
    <div class="actions">
        <button class="btn-print" onclick="window.print()">🖨️ Imprimer / PDF</button>
        <a href="recapitulatif.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" class="btn-back">← Récapitulatif</a>
        <a href="annexe-tva.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" class="btn-back">📄 Annexe TVA</a>
    </div>

    <div class="page-wrapper">
        <div class="page">

            <div class="titre">FICHE ANNEXE CHIFFRE D'AFFAIRE EXONERES TVA SUIVANT (ART 195 CGI)</div>

            <?php if ($message): ?>
            <div class="msg-success"><?= htmlspecialchars($message ?? '') ?></div>
            <?php endif; ?>

            <!-- Info CA Exonéré -->
            <div class="ca-info no-print">
                <span>CA Exonéré du mois : <strong><?= formatMontant($caExonere) ?> F CFA</strong></span>
                <span><?= $moisNoms[$mois] ?> <?= $annee ?></span>
            </div>

            <!-- Formulaire ajout ligne -->
            <form method="POST" class="form-ajout no-print">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="ajouter">
                <span style="font-weight:600; font-size:9pt;">+ Ajouter :</span>
                <input type="text" name="code_produit" class="code" placeholder="Code (ex: Art 195, 30.04...)">
                <input type="text" name="nature" class="nature" placeholder="Nature du produit/service/activité" required>
                <button type="submit">Ajouter</button>
            </form>

            <!-- Champs identification -->
            <div class="champs-info">
                <div class="champs-row">
                    <div class="champ-ligne" style="flex:2">
                        <span class="label">Identité du déclarant :</span>
                        <span class="valeur"><?= htmlspecialchars(mb_strtoupper($client['nom'] ?? '')) ?></span>
                    </div>
                    <div class="champ-ligne" style="flex:1">
                        <span class="label">ADRESSE :</span>
                        <span class="valeur"><?= htmlspecialchars($client['adresse'] ?? '') ?></span>
                    </div>
                </div>
                <div class="champ-ligne">
                    <span class="label">NIF :</span>
                    <span class="valeur" style="max-width:300px"><?= htmlspecialchars($client['ifu'] ?? '') ?></span>
                </div>
                <div class="champ-ligne">
                    <span class="label">Période :</span>
                    <span class="valeur" style="max-width:300px"><?= $moisNoms[$mois] ?> <?= $annee ?></span>
                </div>
            </div>

            <!-- Tableau -->
            <form method="POST" id="formMontants">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="sauvegarder_montants">

                <table>
                    <thead>
                        <tr>
                            <th style="width:5%">N° Ord</th>
                            <th style="width:14%">Code du Produit</th>
                            <th style="width:40%">NATURE PRODUITS/SERVICES/ACTIVITE</th>
                            <th style="width:16%">MONTANT HT</th>
                            <th style="width:8%">TAUX</th>
                            <th style="width:5%" class="no-print">Suppr.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lignes)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding:20px; color:#999;">
                                Aucune ligne configurée. Utilisez le formulaire ci-dessus pour ajouter des catégories d'exonération.
                            </td>
                        </tr>
                        <?php else:
                            $totalMontant = 0;
                            $num = 0;
                            foreach ($lignes as $ligne):
                                $num++;
                                $montant = (float)$ligne['montant_ht'];
                                $totalMontant += $montant;
                        ?>
                        <tr class="<?= $montant > 0 ? 'has-montant' : '' ?>">
                            <td class="num"><?= $num ?></td>
                            <td><?= htmlspecialchars($ligne['code_produit'] ?? '') ?></td>
                            <td><?= htmlspecialchars($ligne['nature'] ?? '') ?></td>
                            <td class="montant">
                                <input type="hidden" name="ligne_ids[]" value="<?= $ligne['id'] ?>">
                                <input type="text" name="montants[]" class="input-montant" value="<?= $montant > 0 ? formatMontant($montant) : '' ?>" placeholder="0">
                            </td>
                            <td class="taux">
                                <input type="text" name="taux_list[]" class="input-taux" value="<?= htmlspecialchars($ligne['taux'] ?? '') ?>" placeholder="">
                            </td>
                            <td class="no-print" style="text-align:center">
                                <button type="submit" form="formSuppr_<?= $ligne['id'] ?>" class="btn-suppr" title="Supprimer">✕</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($lignes)): ?>
                    <tfoot>
                        <tr>
                            <td colspan="3" style="text-align:right; padding-right:10px;">TOTAL</td>
                            <td class="montant" style="font-family:'Consolas',monospace"><?= $totalMontant > 0 ? formatMontant($totalMontant) : '' ?></td>
                            <td></td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>

                <?php if (!empty($lignes)): ?>
                <div class="no-print" style="text-align:right">
                    <button type="submit" class="btn-save">💾 Sauvegarder les montants</button>
                </div>
                <?php endif; ?>
            </form>

            <!-- Formulaires de suppression cachés -->
            <?php foreach ($lignes as $ligne): ?>
            <form method="POST" id="formSuppr_<?= $ligne['id'] ?>" style="display:none">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="supprimer">
                <input type="hidden" name="ligne_id" value="<?= $ligne['id'] ?>">
            </form>
            <?php endforeach; ?>

        </div>
    </div>
</body>
</html>

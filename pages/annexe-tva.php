<?php
/**
 * ============================================
 * ANNEXE 1.1 - FORMULAIRE DE DÉCLARATION DE TVA
 * TVA facturée sur les achats intérieurs de biens,
 * importations de marchandises et biens d'investissements
 * ============================================
 */

session_start();
define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/classes/Agent.php';
require_once APP_ROOT . '/classes/Client.php';
require_once APP_ROOT . '/classes/Achat.php';

// Vérifier l'authentification
if (!Agent::estConnecte() || !Agent::verifierTimeout()) {
    header('Location: ../index.php');
    exit;
}

$db = Database::getInstance();
$agent = Agent::getAgentConnecte();

// Paramètres
$clientId = isset($_GET['client']) ? (int)$_GET['client'] : 0;
$mois = isset($_GET['mois']) ? (int)$_GET['mois'] : (int)date('n');
$annee = isset($_GET['annee']) ? (int)$_GET['annee'] : (int)date('Y');

// Vérifier que le client existe
$client = $db->fetchOne("SELECT * FROM clients WHERE id = ?", [$clientId]);
if (!$client) {
    header('Location: clients.php');
    exit;
}
if (!$agent->aAccesClient($clientId)) {
    header('Location: clients.php?msg=' . urlencode('Accès non autorisé') . '&type=error');
    exit;
}

$moisNoms = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

// Récupérer le taux TVA du client
$parametresFiscaux = $db->fetchOne("SELECT taux_tva FROM parametres_fiscaux WHERE client_id = ?", [$clientId]);
$tauxTVA = $parametresFiscaux ? (int)$parametresFiscaux['taux_tva'] : 18;

// ============================================
// TRAITEMENT DES ACTIONS (ajout/suppression de services)
// ============================================
$message = '';
$messageType = '';
$csrfToken = Agent::getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Agent::verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = 'Session invalide, veuillez réessayer.';
        $messageType = 'error';
    }
    $action = (!empty($message)) ? '' : ($_POST['action'] ?? '');

    if ($action === 'ajouter_service') {
        $prestataireNom = trim($_POST['prestataire_nom'] ?? '');
        $prestataireAdresse = trim($_POST['prestataire_adresse'] ?? '');
        $prestataireNif = trim($_POST['prestataire_nif'] ?? '');
        $montantHT = floatval($_POST['montant_ht'] ?? 0);
        $tauxTvaService = floatval($_POST['taux_tva_service'] ?? 18);
        $montantTvaService = round($montantHT * $tauxTvaService / 100);
        $referenceDoc = trim($_POST['reference_document'] ?? '');
        $dateDoc = $_POST['date_document'] ?? null;

        if (!empty($prestataireNom) && $montantHT > 0) {
            try {
                $db->query(
                    "INSERT INTO services_annexe_tva (client_id, mois, annee, prestataire_nom, prestataire_adresse, prestataire_nif, montant_ht, taux_tva, montant_tva, reference_document, date_document)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$clientId, $mois, $annee, $prestataireNom, $prestataireAdresse, $prestataireNif, $montantHT, $tauxTvaService, $montantTvaService, $referenceDoc, $dateDoc ?: null]
                );
                $message = "Service ajouté à l'annexe TVA.";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Erreur: " . $e->getMessage();
                $messageType = "error";
            }
        } else {
            $message = "Veuillez remplir le prestataire et le montant HT.";
            $messageType = "error";
        }
    }

    if ($action === 'supprimer_service') {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        if ($serviceId > 0) {
            try {
                $db->query("DELETE FROM services_annexe_tva WHERE id = ? AND client_id = ?", [$serviceId, $clientId]);
                $message = "Service retiré de l'annexe TVA.";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Erreur lors de la suppression.";
                $messageType = "error";
            }
        }
    }
}

// ============================================
// DONNÉES
// ============================================

// 1. Achats avec TVA (montant_tva > 0)
$achats = $db->fetchAll(
    "SELECT a.*, f.nom as fournisseur_nom, f.adresse as fournisseur_adresse, f.ifu as fournisseur_nif
     FROM achats a
     JOIN fournisseurs f ON a.fournisseur_id = f.id
     WHERE a.client_id = ? AND a.mois = ? AND a.annee = ? AND a.montant_tva > 0
     ORDER BY f.nom, a.id",
    [$clientId, $mois, $annee]
);

// 2. Services autorisés dans l'annexe TVA
$services = $db->fetchAll(
    "SELECT * FROM services_annexe_tva 
     WHERE client_id = ? AND mois = ? AND annee = ?
     ORDER BY prestataire_nom, id",
    [$clientId, $mois, $annee]
);

// Totaux
$totalBase = 0;
$totalMontantTVA = 0;

$dateGeneration = date('d/m/Y');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Annexe 1.1 TVA - <?= htmlspecialchars($client['nom'] ?? '') ?> - <?= $moisNoms[$mois] ?> <?= $annee ?></title>
    <style>
        @page {
            size: A4 landscape;
            margin: 10mm;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            color: #000;
            background: #d0d0d0;
        }

        .page {
            width: 297mm;
            min-height: 210mm;
            margin: 15px auto;
            background: #fff;
            padding: 10mm 12mm;
            box-shadow: 0 0 15px rgba(0,0,0,0.2);
        }

        /* En-tête officiel */
        .header-officiel {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 8pt;
            line-height: 1.4;
        }

        .header-left { text-transform: uppercase; }
        .header-right { text-align: right; text-transform: uppercase; }

        .titre-annexe {
            text-align: center;
            font-size: 11pt;
            font-weight: bold;
            margin: 10px 0 5px 0;
            text-transform: uppercase;
        }

        .sous-titre {
            text-align: center;
            font-size: 8pt;
            margin-bottom: 12px;
            line-height: 1.4;
        }

        /* Champs pointillés */
        .champs-info {
            font-size: 9pt;
            margin-bottom: 8px;
            line-height: 2;
        }

        .champ-ligne {
            display: flex;
            align-items: baseline;
            gap: 5px;
        }

        .champ-ligne .label { white-space: nowrap; }

        .champ-ligne .valeur {
            flex: 1;
            border-bottom: 1px dotted #999;
            padding-left: 5px;
            font-weight: bold;
        }

        .champ-ligne .date-val {
            width: 200px;
            border-bottom: 1px dotted #999;
            padding-left: 5px;
        }

        .champs-row {
            display: flex;
            gap: 30px;
        }

        /* Tableau */
        table.annexe-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
            margin-top: 5px;
        }

        table.annexe-table th, table.annexe-table td {
            border: 1px solid #333;
            padding: 4px 6px;
        }

        table.annexe-table thead th {
            background: #e8e8e8;
            font-weight: bold;
            text-align: center;
            font-size: 8pt;
            text-transform: uppercase;
        }

        .th-group { border-bottom: none; }
        .th-sub { border-top: none; font-size: 7.5pt; }

        table.annexe-table tbody td { vertical-align: middle; }

        tbody td.nom { font-weight: bold; font-size: 9pt; }
        tbody td.adresse { font-size: 8pt; }
        tbody td.nif { font-size: 8pt; text-align: center; }
        tbody td.base { text-align: right; font-family: 'Consolas', monospace; font-size: 8.5pt; }
        tbody td.taux { text-align: center; font-size: 8.5pt; }
        tbody td.montant { text-align: right; font-family: 'Consolas', monospace; font-size: 8.5pt; }
        tbody td.ref { font-size: 8pt; text-align: center; }
        tbody td.nature { font-size: 8pt; }

        tfoot td {
            font-weight: bold;
            background: #e8e8e8;
            padding: 6px;
        }
        tfoot td.base, tfoot td.montant { font-size: 9pt; }

        /* Boutons d'actions */
        .actions {
            position: fixed;
            top: 0; left: 0; right: 0;
            background: #222;
            padding: 10px 20px;
            display: flex;
            justify-content: center;
            gap: 10px;
            z-index: 100;
        }

        .actions a, .actions button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            border-radius: 5px;
            cursor: pointer;
            border: none;
            color: #fff;
        }

        .actions .btn-print { background: #dc2626; }
        .actions .btn-print:hover { background: #b91c1c; }
        .actions .btn-back { background: #555; }
        .actions .btn-back:hover { background: #444; }
        .actions .btn-service { background: #2563eb; }
        .actions .btn-service:hover { background: #1d4ed8; }

        .page-wrapper { padding-top: 55px; }

        /* ====== Section gestion services ====== */
        .gestion-services {
            width: 297mm;
            margin: 20px auto;
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0,0,0,0.2);
        }

        .gestion-services h2 {
            font-size: 14pt;
            color: #1e40af;
            margin-bottom: 15px;
            border-bottom: 2px solid #2563eb;
            padding-bottom: 8px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 15px;
        }

        .form-grid .full-width { grid-column: 1 / -1; }

        .form-grid label {
            display: block;
            font-size: 9pt;
            font-weight: bold;
            margin-bottom: 3px;
            color: #333;
        }

        .form-grid input, .form-grid select {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 10pt;
        }

        .form-grid input:focus, .form-grid select:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37,99,235,0.2);
        }

        .btn-ajouter-service {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 20px;
            background: #2563eb;
            color: #fff;
            font-size: 11pt;
            font-weight: 600;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .btn-ajouter-service:hover { background: #1d4ed8; }

        /* Liste services existants */
        .services-liste {
            margin-top: 15px;
        }

        .services-liste table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
        }

        .services-liste th {
            background: #eff6ff;
            padding: 6px 8px;
            text-align: left;
            font-size: 8pt;
            text-transform: uppercase;
            border-bottom: 2px solid #2563eb;
        }

        .services-liste td {
            padding: 6px 8px;
            border-bottom: 1px solid #e2e8f0;
        }

        .btn-suppr {
            padding: 4px 10px;
            background: #dc2626;
            color: #fff;
            border: none;
            border-radius: 3px;
            font-size: 8pt;
            cursor: pointer;
        }
        .btn-suppr:hover { background: #b91c1c; }

        .msg {
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-size: 10pt;
        }
        .msg-success { background: #dcfce7; color: #166534; }
        .msg-error { background: #fee2e2; color: #991b1b; }

        .info-note {
            background: #eff6ff;
            border-left: 4px solid #2563eb;
            padding: 10px 15px;
            font-size: 9pt;
            color: #1e40af;
            margin-bottom: 15px;
        }

        @media print {
            .actions { display: none !important; }
            .gestion-services { display: none !important; }
            .page-wrapper { padding-top: 0; }
            body { background: #fff; }
            .page {
                width: 100%; margin: 0; padding: 8mm 10mm;
                box-shadow: none; min-height: auto;
            }
            table.annexe-table thead th {
                background: #e8e8e8 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            tfoot td {
                background: #e8e8e8 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <div class="actions">
        <button class="btn-print" onclick="window.print()">🖨️ Imprimer / PDF</button>
        <a href="#gestion-services" class="btn-service">⚙️ Gérer Services</a>
        <a href="recapitulatif.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" class="btn-back">← Récapitulatif</a>
        <a href="impots.php?client=<?= $clientId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>" class="btn-back">📊 Impôts</a>
    </div>

    <div class="page-wrapper">
        <div class="page">
            <!-- En-tête officiel -->
            <div class="header-officiel">
                <div class="header-left">
                    MINISTERE DE L'ECONOMIE ET DES FINANCES<br>
                    DIRECTION GENERALE DES IMPOTS<br>
                    CENTRE DES IMPOTS DE :
                </div>
                <div class="header-right">
                    REPUBLIQUE DU MALI<br>
                    UN PEUPLE - UN BUT - UNE FOI
                </div>
            </div>

            <div class="titre-annexe">ANNEXE 1.1 DU FORMULAIRE DE DECLARATION DE TVA</div>

            <div class="sous-titre">
                Concernant la TVA facturée sur les achats intérieurs de biens, sur les importations de marchandises ainsi que les biens d'investissements
            </div>

            <!-- Champs d'identification -->
            <div class="champs-info">
                <div class="champs-row">
                    <div class="champ-ligne" style="flex:2">
                        <span class="label">Identité du déclarant :</span>
                        <span class="valeur"><?= htmlspecialchars(mb_strtoupper($client['nom'] ?? '')) ?></span>
                    </div>
                    <div class="champ-ligne" style="flex:1">
                        <span class="label">Adresse :</span>
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

            <!-- Tableau Annexe -->
            <table class="annexe-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="width:15%">NOM OU RAISON SOCIALE</th>
                        <th rowspan="2" style="width:8%">ADRESSE</th>
                        <th rowspan="2" style="width:9%">NIF</th>
                        <th colspan="3" class="th-group">TVA</th>
                        <th rowspan="2" style="width:7%">DATE</th>
                        <th rowspan="2" style="width:10%">REFERENCES</th>
                        <th rowspan="2" style="width:11%">NATURE DE L'OPERATION</th>
                    </tr>
                    <tr>
                        <th class="th-sub" style="width:9%">BASE</th>
                        <th class="th-sub" style="width:5%">TAUX</th>
                        <th class="th-sub" style="width:9%">MONTANT</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $hasData = !empty($achats) || !empty($services);

                    if (!$hasData):
                    ?>
                    <tr>
                        <td colspan="9" style="text-align:center; padding:20px; color:#999;">Aucune opération pour cette période</td>
                    </tr>
                    <?php
                    else:
                        // ===== SECTION ACHATS =====
                        foreach ($achats as $achat):
                            $montantTVA = $achat['montant_tva'];
                            
                            // Correction : La Base est égale à TVA / 0.18 (Taux standard pour achats)
                            $base = ($montantTVA > 0) ? round($montantTVA / 0.18) : $achat['montant_ht'];
                            
                            $totalBase += $base;
                            $totalMontantTVA += $montantTVA;

                            $refDoc = $achat['reference_document'] ?? '';
                            $typeDoc = ($achat['type_document'] === 'facture') ? 'Fact.' : 'Rel.';
                            $reference = $refDoc ? $typeDoc . ' ' . $refDoc : $typeDoc;

                            $dateDoc = $achat['date_document'] ? date('d/m/Y', strtotime($achat['date_document'])) : '';
                    ?>
                    <tr>
                        <td class="nom"><?= htmlspecialchars(mb_strtoupper($achat['fournisseur_nom'] ?? '')) ?></td>
                        <td class="adresse"><?= htmlspecialchars($achat['fournisseur_adresse'] ?? '') ?></td>
                        <td class="nif"><?= htmlspecialchars($achat['fournisseur_nif'] ?? '') ?></td>
                        <td class="base"><?= $base > 0 ? number_format($base, 0, ',', ' ') : '' ?></td>
                        <td class="taux">18%</td>
                        <td class="montant"><?= $montantTVA > 0 ? number_format($montantTVA, 0, ',', ' ') : '' ?></td>
                        <td class="ref"><?= $dateDoc ?></td>
                        <td class="ref"><?= htmlspecialchars($reference ?? '') ?></td>
                        <td class="nature">Achats</td>
                    </tr>
                    <?php
                        endforeach;

                        // ===== SECTION SERVICES (si autorisés) =====
                        if (!empty($services)):
                            foreach ($services as $service):
                                $montantTVAService = $service['montant_tva'];
                                $tauxService = (int)$service['taux_tva'];
                                
                                // Correction : La Base est égale à TVA / 0.18 (ou le taux correspondant)
                                $tauxDecimal = ($tauxService > 0) ? ($tauxService / 100) : 0.18;
                                $base = ($montantTVAService > 0) ? round($montantTVAService / $tauxDecimal) : $service['montant_ht'];
                                
                                $totalBase += $base;
                                $totalMontantTVA += $montantTVAService;

                                $dateDoc = $service['date_document'] ? date('d/m/Y', strtotime($service['date_document'])) : '';
                                $refDoc = $service['reference_document'] ?? '';
                    ?>
                    <tr>
                        <td class="nom"><?= htmlspecialchars(mb_strtoupper($service['prestataire_nom'] ?? '')) ?></td>
                        <td class="adresse"><?= htmlspecialchars($service['prestataire_adresse'] ?? '') ?></td>
                        <td class="nif"><?= htmlspecialchars($service['prestataire_nif'] ?? '') ?></td>
                        <td class="base"><?= $base > 0 ? number_format($base, 0, ',', ' ') : '' ?></td>
                        <td class="taux"><?= $tauxService ?>%</td>
                        <td class="montant"><?= $montantTVAService > 0 ? number_format($montantTVAService, 0, ',', ' ') : '' ?></td>
                        <td class="ref"><?= $dateDoc ?></td>
                        <td class="ref"><?= htmlspecialchars($refDoc ?? '') ?></td>
                        <td class="nature">Services</td>
                    </tr>
                    <?php
                            endforeach;
                        endif;
                    endif;
                    ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align:right; padding-right:10px;">TOTAUX</td>
                        <td class="base"><?= number_format($totalBase, 0, ',', ' ') ?></td>
                        <td class="taux"></td>
                        <td class="montant"><?= number_format($totalMontantTVA, 0, ',', ' ') ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>

        </div>

        <!-- ====== SECTION GESTION DES SERVICES (masquée à l'impression) ====== -->
        <div class="gestion-services" id="gestion-services">
            <h2>⚙️ Gérer les Services dans l'Annexe TVA</h2>

            <?php if ($message): ?>
            <div class="msg <?= $messageType === 'success' ? 'msg-success' : 'msg-error' ?>">
                <?= htmlspecialchars($message ?? '') ?>
            </div>
            <?php endif; ?>

            <div class="info-note">
                <strong>Note :</strong> Les services (Eau, Électricité, Téléphone...) ne sont inclus dans l'annexe TVA que si vous les ajoutez ici.
                Le taux TVA peut être de <strong>5%</strong> ou <strong>18%</strong> selon le service.
            </div>

            <!-- Formulaire d'ajout -->
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="ajouter_service">
                <div class="form-grid">
                    <div>
                        <label>Prestataire (Nom / Raison Sociale) *</label>
                        <input type="text" name="prestataire_nom" required placeholder="Ex: EDM-SA, SOMAGEP...">
                    </div>
                    <div>
                        <label>Adresse</label>
                        <input type="text" name="prestataire_adresse" placeholder="Adresse du prestataire">
                    </div>
                    <div>
                        <label>NIF</label>
                        <input type="text" name="prestataire_nif" placeholder="N° Identification Fiscale">
                    </div>
                    <div>
                        <label>Montant HT *</label>
                        <input type="number" name="montant_ht" required min="0" step="1" placeholder="0" id="serviceHT" onchange="calculerTvaService()">
                    </div>
                    <div>
                        <label>Taux TVA *</label>
                        <select name="taux_tva_service" id="serviceTaux" onchange="calculerTvaService()">
                            <option value="18">18%</option>
                            <option value="5">5%</option>
                        </select>
                    </div>
                    <div>
                        <label>TVA calculée</label>
                        <input type="text" id="serviceTvaCalculee" readonly style="background:#f1f5f9; font-weight:bold;">
                    </div>
                    <div>
                        <label>Référence document</label>
                        <input type="text" name="reference_document" placeholder="N° facture ou relevé">
                    </div>
                    <div>
                        <label>Date du document</label>
                        <input type="date" name="date_document" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <button type="submit" class="btn-ajouter-service">+ Ajouter le service à l'annexe TVA</button>
            </form>

            <!-- Liste des services déjà autorisés -->
            <?php if (!empty($services)): ?>
            <div class="services-liste">
                <h3 style="margin:15px 0 10px; font-size:11pt; color:#1e40af;">Services autorisés pour <?= $moisNoms[$mois] ?> <?= $annee ?></h3>
                <table>
                    <thead>
                        <tr>
                            <th>Prestataire</th>
                            <th>Adresse</th>
                            <th>NIF</th>
                            <th>Montant HT</th>
                            <th>Taux</th>
                            <th>TVA</th>
                            <th>Référence</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($services as $srv): ?>
                        <tr>
                            <td style="font-weight:bold"><?= htmlspecialchars($srv['prestataire_nom'] ?? '') ?></td>
                            <td><?= htmlspecialchars($srv['prestataire_adresse'] ?? '') ?></td>
                            <td><?= htmlspecialchars($srv['prestataire_nif'] ?? '') ?></td>
                            <td style="text-align:right; font-family:Consolas,monospace"><?= number_format($srv['montant_ht'], 0, ',', ' ') ?></td>
                            <td style="text-align:center"><?= (int)$srv['taux_tva'] ?>%</td>
                            <td style="text-align:right; font-family:Consolas,monospace"><?= number_format($srv['montant_tva'], 0, ',', ' ') ?></td>
                            <td><?= htmlspecialchars($srv['reference_document'] ?? '') ?></td>
                            <td><?= $srv['date_document'] ? date('d/m/Y', strtotime($srv['date_document'])) : '' ?></td>
                            <td>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Retirer ce service de l\'annexe TVA ?')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="supprimer_service">
                                    <input type="hidden" name="service_id" value="<?= $srv['id'] ?>">
                                    <button type="submit" class="btn-suppr">✕ Retirer</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function calculerTvaService() {
            const ht = parseFloat(document.getElementById('serviceHT').value) || 0;
            const taux = parseFloat(document.getElementById('serviceTaux').value) || 18;
            const tva = Math.round(ht * taux / 100);
            document.getElementById('serviceTvaCalculee').value = tva.toLocaleString('fr-FR') + ' F CFA';
        }
    </script>
</body>
</html>

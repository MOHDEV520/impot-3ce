<?php
/**
 * Export / import de transfert client entre ordinateurs (format JSON 3CE)
 */

if (!function_exists('transfer_buildClientPayload')) {
    function transfer_buildClientPayload(Database $db, array $clientData, int $moisExport, int $anneeExport): array
    {
        $clientId = (int)$clientData['id'];

        $transfer = [
            'format'      => '3CE_TRANSFER',
            'version'     => '1.0',
            'export_date' => date('Y-m-d H:i:s'),
            'client'      => $clientData,
        ];

        $transfer['parametres_fiscaux'] = $db->fetchOne(
            "SELECT * FROM parametres_fiscaux WHERE client_id = ?",
            [$clientId]
        );
        $transfer['exonerations'] = $db->fetchAll(
            "SELECT * FROM exonerations_client WHERE client_id = ?",
            [$clientId]
        );
        $transfer['natures_depenses'] = $db->fetchAll(
            "SELECT * FROM natures_depenses ORDER BY ordre_affichage"
        );

        $dateCond = '';
        $dateParams = [$clientId];
        if ($moisExport >= 1 && $moisExport <= 12 && $anneeExport >= 2020) {
            $dateCond = ' AND mois = ? AND annee = ?';
            $dateParams[] = $moisExport;
            $dateParams[] = $anneeExport;
        } elseif ($anneeExport >= 2020) {
            $dateCond = ' AND annee = ?';
            $dateParams[] = $anneeExport;
        }

        $transfer['compte_gestion_mensuel'] = $db->fetchAll(
            "SELECT * FROM compte_gestion_mensuel WHERE client_id = ?" . $dateCond,
            $dateParams
        );
        $transfer['achats'] = $db->fetchAll(
            "SELECT * FROM achats WHERE client_id = ?" . $dateCond,
            $dateParams
        );
        $transfer['depenses'] = $db->fetchAll(
            "SELECT * FROM depenses WHERE client_id = ?" . $dateCond,
            $dateParams
        );
        $transfer['impots_mensuels'] = $db->fetchAll(
            "SELECT * FROM impots_mensuels WHERE client_id = ?" . $dateCond,
            $dateParams
        );
        $transfer['services_annexe_tva'] = $db->fetchAll(
            "SELECT * FROM services_annexe_tva WHERE client_id = ?" . $dateCond,
            $dateParams
        );

        $fIds = array_unique(array_filter(array_column($transfer['achats'], 'fournisseur_id')));
        $transfer['fournisseurs'] = [];
        if (!empty($fIds)) {
            $ph = implode(',', array_fill(0, count($fIds), '?'));
            $transfer['fournisseurs'] = $db->fetchAll(
                "SELECT * FROM fournisseurs WHERE id IN ($ph)",
                array_values($fIds)
            );
        }

        $transfer['stats'] = [
            'mois_count'         => count($transfer['compte_gestion_mensuel']),
            'achats_count'       => count($transfer['achats']),
            'depenses_count'     => count($transfer['depenses']),
            'fournisseurs_count' => count($transfer['fournisseurs']),
            'impots_count'       => count($transfer['impots_mensuels']),
        ];

        return $transfer;
    }

    function transfer_hasData(array $transfer): bool
    {
        return !empty($transfer['compte_gestion_mensuel'])
            || !empty($transfer['achats'])
            || !empty($transfer['depenses']);
    }

    function transfer_exportFilename(array $clientData, int $moisExport, int $anneeExport, string $suffix = ''): string
    {
        $clientNom = preg_replace('/[^a-zA-Z0-9_-]/', '_', $clientData['nom'] ?? 'client');
        $datePart = $anneeExport >= 2020
            ? ($moisExport >= 1 ? sprintf('%02d-%d', $moisExport, $anneeExport) : (string)$anneeExport)
            : 'complet';
        $base = $suffix !== '' ? "transfert_{$suffix}_{$datePart}" : "transfert_{$clientNom}_{$datePart}";

        return $base . '_' . date('Y-m-d') . '.json';
    }

    function transfer_normalizePayload(?array $payload): array
    {
        if (!$payload || !is_array($payload)) {
            throw new InvalidArgumentException('Fichier JSON invalide.');
        }

        $format = $payload['format'] ?? '';
        if ($format === '3CE_TRANSFER') {
            if (empty($payload['client'])) {
                throw new InvalidArgumentException('Le fichier ne contient pas de données client.');
            }
            return [$payload];
        }

        if ($format === '3CE_TRANSFER_BATCH') {
            $exports = $payload['exports'] ?? [];
            if (!is_array($exports) || count($exports) === 0) {
                throw new InvalidArgumentException('Le fichier groupé ne contient aucun client.');
            }
            foreach ($exports as $item) {
                if (($item['format'] ?? '') !== '3CE_TRANSFER' || empty($item['client'])) {
                    throw new InvalidArgumentException('Entrée client invalide dans le fichier groupé.');
                }
            }
            return $exports;
        }

        throw new InvalidArgumentException('Format invalide. Utilisez un fichier .json exporté depuis 3CE FISCUS.');
    }

    function transfer_buildPreview(Database $db, array $transfers, array $moisNoms): array
    {
        $clientsPreview = [];
        $totals = ['mois_count' => 0, 'achats_count' => 0, 'depenses_count' => 0, 'fournisseurs_count' => 0, 'impots_count' => 0];

        foreach ($transfers as $transfer) {
            $clientExiste = false;
            if (!empty($transfer['client']['ifu'])) {
                $clientExiste = (bool)$db->fetchOne(
                    "SELECT id FROM clients WHERE ifu = ?",
                    [$transfer['client']['ifu']]
                );
            }

            $moisList = [];
            foreach ($transfer['compte_gestion_mensuel'] ?? [] as $cg) {
                $moisList[] = ($moisNoms[$cg['mois']] ?? $cg['mois']) . ' ' . $cg['annee'];
            }

            $stats = $transfer['stats'] ?? [];
            foreach ($totals as $key => $val) {
                $totals[$key] += (int)($stats[$key] ?? 0);
            }

            $clientsPreview[] = [
                'client_nom'    => $transfer['client']['nom'] ?? 'Inconnu',
                'client_ifu'    => $transfer['client']['ifu'] ?? '',
                'client_existe' => $clientExiste,
                'mois_list'     => $moisList,
                'stats'         => $stats,
            ];
        }

        return [
            'is_batch'      => count($transfers) > 1,
            'clients_count' => count($transfers),
            'export_date'   => $transfers[0]['export_date'] ?? '',
            'clients'       => $clientsPreview,
            'stats'         => $totals,
        ];
    }

    function transfer_importOne(Database $db, Agent $agent, array $transfer): array
    {
        $idMaps = ['fournisseurs' => [], 'natures_depenses' => [], 'compte_gestion' => []];

        $srcClient = $transfer['client'];
        $newClientId = null;
        if (!empty($srcClient['ifu'])) {
            $exist = $db->fetchOne("SELECT id FROM clients WHERE ifu = ?", [$srcClient['ifu']]);
            if ($exist) {
                $newClientId = (int)$exist['id'];
            }
        }
        if (!$newClientId) {
            unset($srcClient['id'], $srcClient['date_creation']);
            $srcClient['agent_id'] = $agent->getId();
            $cols = array_keys($srcClient);
            $newClientId = $db->insert(
                "INSERT INTO clients (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")",
                array_values($srcClient)
            );
        }

        if (!empty($transfer['parametres_fiscaux'])) {
            $pf = $transfer['parametres_fiscaux'];
            unset($pf['id'], $pf['date_modification']);
            $pf['client_id'] = $newClientId;
            $existPf = $db->fetchOne("SELECT id FROM parametres_fiscaux WHERE client_id = ?", [$newClientId]);
            if ($existPf) {
                $sets = [];
                $vals = [];
                foreach ($pf as $col => $val) {
                    if ($col !== 'client_id') {
                        $sets[] = "`$col` = ?";
                        $vals[] = $val;
                    }
                }
                if ($sets) {
                    $vals[] = $existPf['id'];
                    $db->query("UPDATE parametres_fiscaux SET " . implode(', ', $sets) . " WHERE id = ?", $vals);
                }
            } else {
                $cols = array_keys($pf);
                $db->insert(
                    "INSERT INTO parametres_fiscaux (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")",
                    array_values($pf)
                );
            }
        }

        foreach ($transfer['natures_depenses'] ?? [] as $nd) {
            $oldId = $nd['id'];
            $exist = $db->fetchOne("SELECT id FROM natures_depenses WHERE code = ?", [$nd['code']]);
            if ($exist) {
                $idMaps['natures_depenses'][$oldId] = (int)$exist['id'];
            } else {
                unset($nd['id']);
                $cols = array_keys($nd);
                $idMaps['natures_depenses'][$oldId] = $db->insert(
                    "INSERT INTO natures_depenses (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")",
                    array_values($nd)
                );
            }
        }

        foreach ($transfer['fournisseurs'] ?? [] as $f) {
            $oldId = $f['id'];
            $exist = null;
            if (!empty($f['ifu'])) {
                $exist = $db->fetchOne("SELECT id FROM fournisseurs WHERE ifu = ?", [$f['ifu']]);
            }
            if (!$exist) {
                $exist = $db->fetchOne("SELECT id FROM fournisseurs WHERE nom = ?", [$f['nom']]);
            }
            if ($exist) {
                $idMaps['fournisseurs'][$oldId] = (int)$exist['id'];
            } else {
                unset($f['id'], $f['date_creation']);
                $cols = array_keys($f);
                $idMaps['fournisseurs'][$oldId] = $db->insert(
                    "INSERT INTO fournisseurs (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")",
                    array_values($f)
                );
            }
        }

        foreach ($transfer['compte_gestion_mensuel'] ?? [] as $cg) {
            $oldId = $cg['id'];
            $mCg = $cg['mois'];
            $aCg = $cg['annee'];
            unset($cg['id'], $cg['date_creation'], $cg['date_modification']);
            $cg['client_id'] = $newClientId;
            $cg['valide_par'] = null;

            $exist = $db->fetchOne(
                "SELECT id FROM compte_gestion_mensuel WHERE client_id = ? AND mois = ? AND annee = ?",
                [$newClientId, $mCg, $aCg]
            );
            if ($exist) {
                $idMaps['compte_gestion'][$oldId] = (int)$exist['id'];
                $sets = [];
                $vals = [];
                foreach ($cg as $col => $val) {
                    if (!in_array($col, ['client_id', 'mois', 'annee'], true)) {
                        $sets[] = "`$col` = ?";
                        $vals[] = $val;
                    }
                }
                if ($sets) {
                    $vals[] = $exist['id'];
                    $db->query("UPDATE compte_gestion_mensuel SET " . implode(', ', $sets) . " WHERE id = ?", $vals);
                }
            } else {
                $cols = array_keys($cg);
                $idMaps['compte_gestion'][$oldId] = $db->insert(
                    "INSERT INTO compte_gestion_mensuel (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")",
                    array_values($cg)
                );
            }
        }

        $monthPairs = [];
        foreach ($transfer['compte_gestion_mensuel'] ?? [] as $cg) {
            $monthPairs[] = ['mois' => $cg['mois'], 'annee' => $cg['annee']];
        }

        foreach ($monthPairs as $mp) {
            $db->query(
                "DELETE FROM achats WHERE client_id = ? AND mois = ? AND annee = ?",
                [$newClientId, $mp['mois'], $mp['annee']]
            );
        }
        foreach ($transfer['achats'] ?? [] as $a) {
            $oldCg = $a['compte_gestion_id'];
            $oldF = $a['fournisseur_id'];
            unset($a['id'], $a['date_saisie']);
            $a['client_id'] = $newClientId;
            $a['compte_gestion_id'] = $idMaps['compte_gestion'][$oldCg] ?? $oldCg;
            $a['fournisseur_id'] = $idMaps['fournisseurs'][$oldF] ?? $oldF;
            $a['saisi_par'] = $agent->getId();
            $cols = array_keys($a);
            $db->insert(
                "INSERT INTO achats (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")",
                array_values($a)
            );
        }

        foreach ($monthPairs as $mp) {
            $db->query(
                "DELETE FROM depenses WHERE client_id = ? AND mois = ? AND annee = ?",
                [$newClientId, $mp['mois'], $mp['annee']]
            );
        }
        foreach ($transfer['depenses'] ?? [] as $d) {
            $oldCg = $d['compte_gestion_id'];
            $oldNat = $d['nature_id'];
            unset($d['id'], $d['date_saisie']);
            $d['client_id'] = $newClientId;
            $d['compte_gestion_id'] = $idMaps['compte_gestion'][$oldCg] ?? $oldCg;
            $d['nature_id'] = $idMaps['natures_depenses'][$oldNat] ?? $oldNat;
            $d['saisi_par'] = $agent->getId();
            $cols = array_keys($d);
            $db->insert(
                "INSERT INTO depenses (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")",
                array_values($d)
            );
        }

        foreach ($transfer['impots_mensuels'] ?? [] as $im) {
            $oldCg = $im['compte_gestion_id'];
            $mIm = $im['mois'];
            $aIm = $im['annee'];
            unset($im['id'], $im['date_calcul']);
            $im['client_id'] = $newClientId;
            $im['compte_gestion_id'] = $idMaps['compte_gestion'][$oldCg] ?? $oldCg;

            $exist = $db->fetchOne(
                "SELECT id FROM impots_mensuels WHERE client_id = ? AND mois = ? AND annee = ?",
                [$newClientId, $mIm, $aIm]
            );
            if ($exist) {
                $sets = [];
                $vals = [];
                foreach ($im as $col => $val) {
                    if (!in_array($col, ['client_id', 'mois', 'annee'], true)) {
                        $sets[] = "`$col` = ?";
                        $vals[] = $val;
                    }
                }
                if ($sets) {
                    $vals[] = $exist['id'];
                    $db->query("UPDATE impots_mensuels SET " . implode(', ', $sets) . " WHERE id = ?", $vals);
                }
            } else {
                $cols = array_keys($im);
                $db->insert(
                    "INSERT INTO impots_mensuels (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")",
                    array_values($im)
                );
            }
        }

        foreach ($monthPairs as $mp) {
            $db->query(
                "DELETE FROM services_annexe_tva WHERE client_id = ? AND mois = ? AND annee = ?",
                [$newClientId, $mp['mois'], $mp['annee']]
            );
        }
        foreach ($transfer['services_annexe_tva'] ?? [] as $s) {
            unset($s['id'], $s['date_saisie']);
            $s['client_id'] = $newClientId;
            $cols = array_keys($s);
            $db->insert(
                "INSERT INTO services_annexe_tva (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")",
                array_values($s)
            );
        }

        if (!empty($transfer['exonerations'])) {
            $db->query("DELETE FROM exonerations_client WHERE client_id = ?", [$newClientId]);
            foreach ($transfer['exonerations'] as $ex) {
                unset($ex['id'], $ex['date_creation']);
                $ex['client_id'] = $newClientId;
                $cols = array_keys($ex);
                $db->insert(
                    "INSERT INTO exonerations_client (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")",
                    array_values($ex)
                );
            }
        }

        return [
            'nom'       => $transfer['client']['nom'] ?? 'Client',
            'nb_mois'   => count($transfer['compte_gestion_mensuel'] ?? []),
            'nb_achats' => count($transfer['achats'] ?? []),
            'nb_depenses' => count($transfer['depenses'] ?? []),
        ];
    }
}

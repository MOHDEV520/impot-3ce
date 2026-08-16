<?php
/**
 * ============================================
 * REDIRECTION — la saisie et le calcul officiels
 * vivent dans impots.php (surface unique).
 * Cette page conserve les anciens liens.
 * ============================================
 */

$query = http_build_query(array_merge($_GET, ['type' => 'tva']));
header('Location: impots.php?' . $query);
exit;
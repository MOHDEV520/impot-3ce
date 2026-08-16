<?php
/**
 * ============================================
 * TABLEAU DE BORD (alias)
 * Le tableau de bord est implémenté dans dashboard.php ;
 * cette page ne sert qu'à rediriger les anciens liens.
 * ============================================
 */

$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: dashboard.php' . ($query !== '' ? '?' . $query : ''));
exit;

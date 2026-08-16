<?php
/**
 * ============================================
 * SUPPRIMER UN CLIENT
 * Système de Gestion Fiscale
 * ============================================
 */

define('APP_ROOT', dirname(__DIR__));
session_start();

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/classes/Agent.php';
require_once APP_ROOT . '/classes/Client.php';

if (!Agent::estConnecte() || !Agent::verifierTimeout()) {
    header('Location: ../index.php');
    exit;
}

$agent = Agent::getAgentConnecte();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: clients.php?msg=' . urlencode('Action non autorisée') . '&type=error');
    exit;
}

if (!Agent::verifierCsrfToken($_POST['csrf_token'] ?? null)) {
    header('Location: clients.php?msg=' . urlencode('Session invalide, veuillez réessayer') . '&type=error');
    exit;
}

$clientId = (int) ($_POST['client_id'] ?? 0);

if ($clientId <= 0) {
    header('Location: clients.php?msg=' . urlencode('Client introuvable') . '&type=error');
    exit;
}

$client = new Client($clientId);

if (!$client->getId()) {
    header('Location: clients.php?msg=' . urlencode('Client introuvable') . '&type=error');
    exit;
}

if (!$agent->aAccesClient($clientId)) {
    header('Location: clients.php?msg=' . urlencode('Accès non autorisé') . '&type=error');
    exit;
}

try {
    if ($client->supprimer()) {
        header('Location: clients.php?msg=' . urlencode('Client supprimé avec succès') . '&type=success');
    } else {
        header('Location: clients.php?msg=' . urlencode('Erreur lors de la suppression') . '&type=error');
    }
} catch (Exception $e) {
    header('Location: clients.php?msg=' . urlencode(messageErreurUtilisateur($e, "la suppression de ce client")) . '&type=error');
}
exit;

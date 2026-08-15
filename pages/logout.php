<?php
/**
 * ============================================
 * DÉCONNEXION
 * Système de Gestion Fiscale
 * ============================================
 */

// Définir la racine de l'application
define('APP_ROOT', dirname(__DIR__));

// Démarrer la session
session_start();

// Charger les dépendances
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/classes/Agent.php';

// Déconnecter l'agent
if (Agent::estConnecte()) {
    $agent = Agent::getAgentConnecte();
    if ($agent) {
        $agent->seDeconnecter();
    }
}

// Détruire la session si pas déjà fait
if (session_status() === PHP_SESSION_ACTIVE) {
    session_unset();
    session_destroy();
}

// Rediriger vers la page de connexion
header('Location: ../index.php');
exit;

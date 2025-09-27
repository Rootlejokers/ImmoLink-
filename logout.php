<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Déterminer la page de redirection
$redirect_url = 'index.php';

// Si une URL de redirection est spécifiée et valide
if (isset($_GET['redirect']) && !empty($_GET['redirect'])) {
    $allowed_redirects = ['index.php', 'login.php', 'register.php'];
    $requested_redirect = basename($_GET['redirect']);
    
    if (in_array($requested_redirect, $allowed_redirects)) {
        $redirect_url = $requested_redirect;
    }
}

// Journaliser la déconnexion (simplifié)
if (isset($_SESSION['user_id'])) {
    error_log("Déconnexion utilisateur ID: " . $_SESSION['user_id'] . " - IP: " . $_SERVER['REMOTE_ADDR']);
}

// Nettoyer la session
$_SESSION = array();

// Détruire le cookie de session
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, 
        $params["path"], $params["domain"], 
        $params["secure"], $params["httponly"]
    );
}

// Détruire la session
session_destroy();

// Rediriger
header('Location: ' . $redirect_url . '?logout=success');
exit();
?>
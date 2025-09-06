<?php
// Configuration de la base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'immolink');
define('DB_USER', 'root');
define('DB_PASS', 'jokers');

// Configuration de l'application
define('APP_NAME', 'ImmoLink');
define('APP_URL', 'http://localhost/immolink');
define('UPLOAD_DIR', '/var/www/html/immolink/uploads/');

// Démarrer la session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Configuration du fuseau horaire
date_default_timezone_set('Europe/Paris');
?>
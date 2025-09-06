<?php
require_once 'database.php';
require_once 'functions.php';

function login($email, $password) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $query = "SELECT id, email, password, first_name, last_name, user_type 
              FROM users WHERE email = :email";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    
    if ($stmt->rowCount() == 1) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
            $_SESSION['user_type'] = $user['user_type'];
            return true;
        }
    }
    return false;
}

function register($userData) {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Vérifier si l'email existe déjà
    $query = "SELECT id FROM users WHERE email = :email";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':email', $userData['email']);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        return false; // Email déjà utilisé
    }
    
    // Hasher le mot de passe
    $hashedPassword = password_hash($userData['password'], PASSWORD_DEFAULT);
    
    // Insérer le nouvel utilisateur
    $query = "INSERT INTO users (email, password, first_name, last_name, phone, user_type) 
              VALUES (:email, :password, :first_name, :last_name, :phone, :user_type)";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':email', $userData['email']);
    $stmt->bindParam(':password', $hashedPassword);
    $stmt->bindParam(':first_name', $userData['first_name']);
    $stmt->bindParam(':last_name', $userData['last_name']);
    $stmt->bindParam(':phone', $userData['phone']);
    $stmt->bindParam(':user_type', $userData['user_type']);
    
    return $stmt->execute();
}
?>
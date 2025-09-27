<?php
session_start();
require_once '../../includes/config.php';
require_once '../../includes/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Vérifier si l'utilisateur est connecté et est un propriétaire
if (!isLoggedIn() || !isOwner()) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$db = new Database();
$conn = $db->getConnection();

// Récupérer les informations de l'utilisateur
$user = [];
$error = '';
$success = '';

try {
    $query = "SELECT * FROM users WHERE id = :user_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des informations utilisateur: " . $e->getMessage());
    $error = "Erreur lors de la récupération de vos informations.";
}

// Traitement du formulaire de mise à jour du profil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        // Mise à jour des informations personnelles
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $phone = trim($_POST['phone']);
        $email = trim($_POST['email']);

        // Validation des données
        if (empty($first_name) || empty($last_name) || empty($email)) {
            $error = 'Veuillez remplir tous les champs obligatoires.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Veuillez entrer une adresse email valide.';
        } else {
            try {
                // Vérifier si l'email est déjà utilisé par un autre utilisateur
                $query = "SELECT id FROM users WHERE email = :email AND id != :user_id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':user_id', $user_id);
                $stmt->execute();

                if ($stmt->rowCount() > 0) {
                    $error = 'Cette adresse email est déjà utilisée.';
                } else {
                    // Mettre à jour les informations
                    $query = "UPDATE users SET first_name = :first_name, last_name = :last_name, 
                              phone = :phone, email = :email, updated_at = NOW() 
                              WHERE id = :user_id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':first_name', $first_name);
                    $stmt->bindParam(':last_name', $last_name);
                    $stmt->bindParam(':phone', $phone);
                    $stmt->bindParam(':email', $email);
                    $stmt->bindParam(':user_id', $user_id);

                    if ($stmt->execute()) {
                        // Mettre à jour la session
                        $_SESSION['user_name'] = $first_name . ' ' . $last_name;
                        $_SESSION['user_email'] = $email;
                        
                        $success = 'Votre profil a été mis à jour avec succès.';
                        
                        // Recharger les informations utilisateur
                        $query = "SELECT * FROM users WHERE id = :user_id";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':user_id', $user_id);
                        $stmt->execute();
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    } else {
                        $error = 'Erreur lors de la mise à jour de votre profil.';
                    }
                }
            } catch (PDOException $e) {
                error_log("Erreur lors de la mise à jour du profil: " . $e->getMessage());
                $error = 'Erreur lors de la mise à jour de votre profil.';
            }
        }
    } elseif (isset($_POST['change_password'])) {
        // Changement de mot de passe
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        // Validation des données
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = 'Veuillez remplir tous les champs du mot de passe.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'Les nouveaux mots de passe ne correspondent pas.';
        } elseif (strlen($new_password) < 8) {
            $error = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
        } else {
            try {
                // Vérifier le mot de passe actuel
                $query = "SELECT password FROM users WHERE id = :user_id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':user_id', $user_id);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($result && password_verify($current_password, $result['password'])) {
                    // Hasher le nouveau mot de passe
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                    // Mettre à jour le mot de passe
                    $query = "UPDATE users SET password = :password, updated_at = NOW() WHERE id = :user_id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':password', $hashed_password);
                    $stmt->bindParam(':user_id', $user_id);

                    if ($stmt->execute()) {
                        $success = 'Votre mot de passe a été changé avec succès.';
                    } else {
                        $error = 'Erreur lors du changement de mot de passe.';
                    }
                } else {
                    $error = 'Le mot de passe actuel est incorrect.';
                }
            } catch (PDOException $e) {
                error_log("Erreur lors du changement de mot de passe: " . $e->getMessage());
                $error = 'Erreur lors du changement de mot de passe.';
            }
        }
    } elseif (isset($_POST['update_notifications'])) {
        // Mise à jour des préférences de notifications
        $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
        $sms_notifications = isset($_POST['sms_notifications']) ? 1 : 0;
        $message_notifications = isset($_POST['message_notifications']) ? 1 : 0;
        $visit_notifications = isset($_POST['visit_notifications']) ? 1 : 0;

        try {
            $query = "UPDATE users SET 
                      email_notifications = :email_notifications,
                      sms_notifications = :sms_notifications,
                      message_notifications = :message_notifications,
                      visit_notifications = :visit_notifications,
                      updated_at = NOW() 
                      WHERE id = :user_id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':email_notifications', $email_notifications, PDO::PARAM_INT);
            $stmt->bindParam(':sms_notifications', $sms_notifications, PDO::PARAM_INT);
            $stmt->bindParam(':message_notifications', $message_notifications, PDO::PARAM_INT);
            $stmt->bindParam(':visit_notifications', $visit_notifications, PDO::PARAM_INT);
            $stmt->bindParam(':user_id', $user_id);

            if ($stmt->execute()) {
                $success = 'Vos préférences de notifications ont été mises à jour.';
                
                // Recharger les informations utilisateur
                $query = "SELECT * FROM users WHERE id = :user_id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':user_id', $user_id);
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $error = 'Erreur lors de la mise à jour des préférences.';
            }
        } catch (PDOException $e) {
            error_log("Erreur lors de la mise à jour des notifications: " . $e->getMessage());
            $error = 'Erreur lors de la mise à jour des préférences.';
        }
    }
}

// Récupérer les statistiques du propriétaire
$stats = [
    'properties' => 0,
    'active_listings' => 0,
    'total_views' => 0,
    'total_messages' => 0,
    'scheduled_visits' => 0
];

try {
    // Nombre total de propriétés
    $query = "SELECT COUNT(*) as count FROM properties WHERE owner_id = :user_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $stats['properties'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Propriétés actives
    $query = "SELECT COUNT(*) as count FROM properties WHERE owner_id = :user_id AND status = 'available'";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $stats['active_listings'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Vues totales (simulé)
    $query = "SELECT SUM(views) as total FROM properties WHERE owner_id = :user_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $stats['total_views'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?: 0;

    // Messages totaux
    $query = "SELECT COUNT(*) as total FROM messages m 
              JOIN conversations c ON m.conversation_id = c.id 
              WHERE c.owner_id = :user_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $stats['total_messages'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Visites programmées
    $query = "SELECT COUNT(*) as total FROM visits v 
              JOIN properties p ON v.property_id = p.id 
              WHERE p.owner_id = :user_id AND v.status IN ('pending', 'confirmed')";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $stats['scheduled_visits'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des statistiques: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil - ImmoLink</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary: #64748b;
            --light: #f8fafc;
            --dark: #1e293b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gray: #94a3b8;
            --gray-light: #e2e8f0;
            --sidebar-width: 250px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f1f5f9;
            color: var(--dark);
            line-height: 1.6;
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar (identique aux autres pages) */
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--dark);
            color: white;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-logo {
            font-size: 24px;
            font-weight: 700;
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
        }

        .sidebar-logo i {
            margin-right: 10px;
            color: var(--primary);
        }

        .sidebar-nav {
            flex: 1;
            padding: 20px 0;
        }

        .nav-item {
            padding: 12px 20px;
            display: flex;
            align-items: center;
            color: var(--gray);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }

        .nav-item:hover, .nav-item.active {
            background-color: rgba(255, 255, 255, 0.05);
            color: white;
            border-left-color: var(--primary);
        }

        .nav-item i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
        }

        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .user-info {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            font-weight: 600;
        }

        .user-details h4 {
            font-size: 14px;
            margin-bottom: 2px;
        }

        .user-details span {
            font-size: 12px;
            color: var(--gray);
        }

        .logout-btn {
            display: block;
            width: 100%;
            padding: 10px;
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: 6px;
            text-align: center;
            text-decoration: none;
            transition: all 0.3s;
        }

        .logout-btn:hover {
            background-color: var(--danger);
            color: white;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
        }

        .dashboard-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--gray-light);
        }

        .dashboard-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--dark);
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--secondary);
            font-size: 14px;
            margin-top: 5px;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background-color: var(--primary);
            color: white;
        }

        /* Profile Layout */
        .profile-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        /* Profile Sidebar */
        .profile-sidebar {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 30px;
            text-align: center;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
            font-weight: 600;
        }

        .profile-name {
            font-size: 24px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .profile-role {
            color: var(--primary);
            font-weight: 500;
            margin-bottom: 20px;
        }

        .profile-stats {
            display: grid;
            gap: 15px;
            margin: 25px 0;
        }

        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background-color: var(--light);
            border-radius: 8px;
        }

        .stat-label {
            color: var(--secondary);
            font-size: 14px;
        }

        .stat-value {
            font-weight: 600;
            color: var(--dark);
        }

        .member-since {
            color: var(--secondary);
            font-size: 14px;
            margin-top: 20px;
        }

        /* Profile Content */
        .profile-content {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        .profile-section {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 30px;
        }

        .section-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--gray-light);
        }

        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: var(--primary);
        }

        /* Forms */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
        }

        .form-group label .required {
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--gray-light);
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-light);
        }

        /* Notifications */
        .notifications-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .notification-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            border: 1px solid var(--gray-light);
            border-radius: 8px;
            transition: border-color 0.3s;
        }

        .notification-item:hover {
            border-color: var(--primary);
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background-color: var(--light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 18px;
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 4px;
        }

        .notification-desc {
            color: var(--secondary);
            font-size: 14px;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--gray-light);
            transition: .4s;
            border-radius: 24px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--primary);
        }

        input:checked + .slider:before {
            transform: translateX(26px);
        }

        /* Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-error {
            background-color: #fef2f2;
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }

        .alert-success {
            background-color: #f0fdf4;
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .alert i {
            font-size: 20px;
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .sidebar {
                width: 70px;
                overflow: hidden;
            }

            .sidebar-header, .sidebar-footer {
                padding: 15px 10px;
            }

            .sidebar-logo span, .user-details, .nav-item span {
                display: none;
            }

            .nav-item {
                justify-content: center;
                padding: 15px 10px;
            }

            .nav-item i {
                margin-right: 0;
            }

            .main-content {
                margin-left: 70px;
            }

            .profile-container {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .notifications-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 576px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: fixed;
                bottom: 0;
                top: auto;
                z-index: 1000;
                flex-direction: row;
            }

            .sidebar-nav {
                display: flex;
                flex: 1;
                padding: 0;
            }

            .nav-item {
                flex-direction: column;
                padding: 10px 5px;
                border-left: none;
                border-top: 3px solid transparent;
            }

            .nav-item:hover, .nav-item.active {
                border-left-color: transparent;
                border-top-color: var(--primary);
            }

            .nav-item i {
                margin-right: 0;
                margin-bottom: 5px;
            }

            .sidebar-header, .sidebar-footer {
                display: none;
            }

            .main-content {
                margin-left: 0;
                margin-bottom: 70px;
                padding: 15px;
            }

            .profile-section {
                padding: 20px;
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <a href="../../index.php" class="sidebar-logo">
                <i class="fas fa-home"></i>
                <span>ImmoLink</span>
            </a>
        </div>

        <nav class="sidebar-nav">
            <a href="index.php" class="nav-item">
                <i class="fas fa-tachometer-alt"></i>
                <span>Tableau de bord</span>
            </a>
            <a href="my-properties.php" class="nav-item">
                <i class="fas fa-building"></i>
                <span>Mes biens</span>
            </a>
            <a href="add-property.php" class="nav-item">
                <i class="fas fa-plus-circle"></i>
                <span>Ajouter un bien</span>
            </a>
            <a href="statistics.php" class="nav-item">
                <i class="fas fa-chart-bar"></i>
                <span>Statistiques</span>
            </a>
            <a href="messages.php" class="nav-item">
                <i class="fas fa-envelope"></i>
                <span>Messages</span>
            </a>
            <a href="visits.php" class="nav-item">
                <i class="fas fa-calendar-alt"></i>
                <span>Visites</span>
            </a>
            <a href="profile.php" class="nav-item active">
                <i class="fas fa-user"></i>
                <span>Profil</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
                </div>
                <div class="user-details">
                    <h4><?php echo htmlspecialchars($_SESSION['user_name']); ?></h4>
                    <span>Propriétaire</span>
                </div>
            </div>
            <a href="../../logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Déconnexion
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="dashboard-header">
            <div>
                <h1 class="dashboard-title">Mon Profil</h1>
                <div class="breadcrumb">
                    <a href="index.php">Tableau de bord</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Profil</span>
                </div>
            </div>
            <div>
                <a href="my-properties.php" class="btn btn-outline">
                    <i class="fas fa-building"></i> Mes biens
                </a>
            </div>
        </div>

        <!-- Messages d'alerte -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>

        <div class="profile-container">
            <!-- Sidebar du profil -->
            <div class="profile-sidebar">
                <div class="profile-avatar">
                    <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                </div>
                <h2 class="profile-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h2>
                <div class="profile-role">Propriétaire</div>
                
                <div class="profile-stats">
                    <div class="stat-item">
                        <span class="stat-label">Biens</span>
                        <span class="stat-value"><?php echo $stats['properties']; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Annonces actives</span>
                        <span class="stat-value"><?php echo $stats['active_listings']; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Vues totales</span>
                        <span class="stat-value"><?php echo number_format($stats['total_views']); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Messages</span>
                        <span class="stat-value"><?php echo $stats['total_messages']; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Visites programmées</span>
                        <span class="stat-value"><?php echo $stats['scheduled_visits']; ?></span>
                    </div>
                </div>
                
                <div class="member-since">
                    Membre depuis <?php echo date('d/m/Y', strtotime($user['created_at'])); ?>
                </div>
            </div>

            <!-- Contenu du profil -->
            <div class="profile-content">
                <!-- Informations personnelles -->
                <div class="profile-section">
                    <div class="section-header">
                        <h2 class="section-title">
                            <i class="fas fa-user-circle"></i>
                            Informations personnelles
                        </h2>
                    </div>

                    <form method="POST">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Prénom <span class="required">*</span></label>
                                <input 
                                    type="text" 
                                    name="first_name" 
                                    class="form-control" 
                                    value="<?php echo htmlspecialchars($user['first_name']); ?>"
                                    required
                                >
                            </div>

                            <div class="form-group">
                                <label>Nom <span class="required">*</span></label>
                                <input 
                                    type="text" 
                                    name="last_name" 
                                    class="form-control" 
                                    value="<?php echo htmlspecialchars($user['last_name']); ?>"
                                    required
                                >
                            </div>

                            <div class="form-group">
                                <label>Email <span class="required">*</span></label>
                                <input 
                                    type="email" 
                                    name="email" 
                                    class="form-control" 
                                    value="<?php echo htmlspecialchars($user['email']); ?>"
                                    required
                                >
                            </div>

                            <div class="form-group">
                                <label>Téléphone</label>
                                <input 
                                    type="tel" 
                                    name="phone" 
                                    class="form-control" 
                                    value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>"
                                >
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="update_profile" class="btn btn-primary">
                                <i class="fas fa-save"></i> Enregistrer les modifications
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Mot de passe -->
                <div class="profile-section">
                    <div class="section-header">
                        <h2 class="section-title">
                            <i class="fas fa-lock"></i>
                            Sécurité du compte
                        </h2>
                    </div>

                    <form method="POST">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Mot de passe actuel <span class="required">*</span></label>
                                <input 
                                    type="password" 
                                    name="current_password" 
                                    class="form-control" 
                                    placeholder="Votre mot de passe actuel"
                                    required
                                >
                            </div>

                            <div class="form-group">
                                <label>Nouveau mot de passe <span class="required">*</span></label>
                                <input 
                                    type="password" 
                                    name="new_password" 
                                    class="form-control" 
                                    placeholder="Nouveau mot de passe"
                                    required
                                    minlength="8"
                                >
                            </div>

                            <div class="form-group">
                                <label>Confirmer le mot de passe <span class="required">*</span></label>
                                <input 
                                    type="password" 
                                    name="confirm_password" 
                                    class="form-control" 
                                    placeholder="Confirmer le nouveau mot de passe"
                                    required
                                >
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="change_password" class="btn btn-primary">
                                <i class="fas fa-key"></i> Changer le mot de passe
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Préférences de notifications -->
                <div class="profile-section">
                    <div class="section-header">
                        <h2 class="section-title">
                            <i class="fas fa-bell"></i>
                            Préférences de notifications
                        </h2>
                    </div>

                    <form method="POST">
                        <div class="notifications-grid">
                            <div class="notification-item">
                                <div class="notification-icon">
                                    <i class="fas fa-envelope"></i>
                                </div>
                                <div class="notification-content">
                                    <div class="notification-title">Notifications par email</div>
                                    <div class="notification-desc">Recevoir des emails pour les nouvelles activités</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="email_notifications" <?php echo ($user['email_notifications'] ?? 1) ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>

                            <div class="notification-item">
                                <div class="notification-icon">
                                    <i class="fas fa-sms"></i>
                                </div>
                                <div class="notification-content">
                                    <div class="notification-title">Notifications SMS</div>
                                    <div class="notification-desc">Recevoir des SMS pour les alertes importantes</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="sms_notifications" <?php echo ($user['sms_notifications'] ?? 0) ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>

                            <div class="notification-item">
                                <div class="notification-icon">
                                    <i class="fas fa-comment"></i>
                                </div>
                                <div class="notification-content">
                                    <div class="notification-title">Nouveaux messages</div>
                                    <div class="notification-desc">Être notifié des nouveaux messages</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="message_notifications" <?php echo ($user['message_notifications'] ?? 1) ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>

                            <div class="notification-item">
                                <div class="notification-icon">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <div class="notification-content">
                                    <div class="notification-title">Demandes de visite</div>
                                    <div class="notification-desc">Être notifié des nouvelles demandes de visite</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="visit_notifications" <?php echo ($user['visit_notifications'] ?? 1) ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="update_notifications" class="btn btn-primary">
                                <i class="fas fa-save"></i> Enregistrer les préférences
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Animation des statistiques
            const statNumbers = document.querySelectorAll('.stat-value');
            statNumbers.forEach(stat => {
                const target = parseInt(stat.textContent.replace(/,/g, ''));
                let current = 0;
                const duration = 2000;
                const steps = 50;
                const increment = target / steps;
                
                const timer = setInterval(() => {
                    current += increment;
                    if (current >= target) {
                        clearInterval(timer);
                        stat.textContent = target.toLocaleString();
                    } else {
                        stat.textContent = Math.floor(current).toLocaleString();
                    }
                }, duration / steps);
            });

            // Validation des formulaires
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const passwordForm = this.querySelector('input[type="password"]');
                    if (passwordForm) {
                        const newPassword = this.querySelector('input[name="new_password"]');
                        const confirmPassword = this.querySelector('input[name="confirm_password"]');
                        
                        if (newPassword && confirmPassword && newPassword.value !== confirmPassword.value) {
                            e.preventDefault();
                            alert('Les mots de passe ne correspondent pas.');
                            return false;
                        }
                        
                        if (newPassword && newPassword.value.length < 8) {
                            e.preventDefault();
                            alert('Le mot de passe doit contenir au moins 8 caractères.');
                            return false;
                        }
                    }
                });
            });

            // Auto-hide des messages d'alerte
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });
        });
    </script>
</body>
</html>
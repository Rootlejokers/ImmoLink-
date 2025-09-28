<?php
session_start();
require_once '../../includes/config.php';
require_once '../../includes/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Vérifier si l'utilisateur est connecté et est un locataire
if (!isLoggedIn() || !isTenant()) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$db = new Database();
$conn = $db->getConnection();

// Récupérer les informations du profil
$user = null;
try {
    $query = "SELECT * FROM users WHERE id = :user_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur lors de la récupération du profil: " . $e->getMessage());
}

// Variables pour les messages
$success = '';
$error = '';

// Traitement de la mise à jour du profil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        // Mise à jour des informations de base
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $phone = trim($_POST['phone']);
        $email = trim($_POST['email']);

        // Validation
        if (empty($first_name) || empty($last_name) || empty($email)) {
            $error = 'Veuillez remplir tous les champs obligatoires.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Veuillez entrer une adresse email valide.';
        } else {
            try {
                // Vérifier si l'email est déjà utilisé par un autre utilisateur
                $check_query = "SELECT id FROM users WHERE email = :email AND id != :user_id";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bindParam(':email', $email);
                $check_stmt->bindParam(':user_id', $user_id);
                $check_stmt->execute();

                if ($check_stmt->rowCount() > 0) {
                    $error = 'Cette adresse email est déjà utilisée.';
                } else {
                    // Mettre à jour le profil
                    $update_query = "UPDATE users SET first_name = :first_name, last_name = :last_name, 
                                   phone = :phone, email = :email, updated_at = NOW() 
                                   WHERE id = :user_id";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bindParam(':first_name', $first_name);
                    $update_stmt->bindParam(':last_name', $last_name);
                    $update_stmt->bindParam(':phone', $phone);
                    $update_stmt->bindParam(':email', $email);
                    $update_stmt->bindParam(':user_id', $user_id);

                    if ($update_stmt->execute()) {
                        // Mettre à jour la session
                        $_SESSION['user_name'] = $first_name . ' ' . $last_name;
                        $_SESSION['user_email'] = $email;
                        
                        $success = 'Votre profil a été mis à jour avec succès.';
                        // Recharger les données utilisateur
                        $stmt->execute();
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    } else {
                        $error = 'Une erreur est survenue lors de la mise à jour.';
                    }
                }
            } catch (PDOException $e) {
                error_log("Erreur lors de la mise à jour du profil: " . $e->getMessage());
                $error = 'Une erreur est survenue lors de la mise à jour.';
            }
        }
    } elseif (isset($_POST['change_password'])) {
        // Changement de mot de passe
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        // Validation
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = 'Veuillez remplir tous les champs du mot de passe.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'Les nouveaux mots de passe ne correspondent pas.';
        } elseif (strlen($new_password) < 8) {
            $error = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
        } else {
            try {
                // Vérifier le mot de passe actuel
                $check_query = "SELECT password FROM users WHERE id = :user_id";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bindParam(':user_id', $user_id);
                $check_stmt->execute();
                $user_data = $check_stmt->fetch(PDO::FETCH_ASSOC);

                if (!password_verify($current_password, $user_data['password'])) {
                    $error = 'Le mot de passe actuel est incorrect.';
                } else {
                    // Hasher le nouveau mot de passe
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                    // Mettre à jour le mot de passe
                    $update_query = "UPDATE users SET password = :password, updated_at = NOW() WHERE id = :user_id";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bindParam(':password', $hashed_password);
                    $update_stmt->bindParam(':user_id', $user_id);

                    if ($update_stmt->execute()) {
                        $success = 'Votre mot de passe a été changé avec succès.';
                    } else {
                        $error = 'Une erreur est survenue lors du changement de mot de passe.';
                    }
                }
            } catch (PDOException $e) {
                error_log("Erreur lors du changement de mot de passe: " . $e->getMessage());
                $error = 'Une erreur est survenue lors du changement de mot de passe.';
            }
        }
    } elseif (isset($_POST['update_preferences'])) {
        // Mise à jour des préférences (exemple)
        $notification_email = isset($_POST['notification_email']) ? 1 : 0;
        $notification_sms = isset($_POST['notification_sms']) ? 1 : 0;
        $search_radius = (int)$_POST['search_radius'];
        $budget_min = !empty($_POST['budget_min']) ? (float)$_POST['budget_min'] : null;
        $budget_max = !empty($_POST['budget_max']) ? (float)$_POST['budget_max'] : null;
        $preferred_cities = trim($_POST['preferred_cities']);

        try {
            // Ici vous pourriez avoir une table user_preferences
            // Pour l'exemple, on va stocker en JSON dans un champ de la table users
            $preferences = json_encode([
                'notification_email' => $notification_email,
                'notification_sms' => $notification_sms,
                'search_radius' => $search_radius,
                'budget_min' => $budget_min,
                'budget_max' => $budget_max,
                'preferred_cities' => $preferred_cities
            ]);

            $update_query = "UPDATE users SET preferences = :preferences, updated_at = NOW() WHERE id = :user_id";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bindParam(':preferences', $preferences);
            $update_stmt->bindParam(':user_id', $user_id);

            if ($update_stmt->execute()) {
                $success = 'Vos préférences ont été mises à jour avec succès.';
            } else {
                $error = 'Une erreur est survenue lors de la mise à jour des préférences.';
            }
        } catch (PDOException $e) {
            error_log("Erreur lors de la mise à jour des préférences: " . $e->getMessage());
            $error = 'Une erreur est survenue lors de la mise à jour des préférences.';
        }
    }
}

// Récupérer les statistiques du locataire
$stats = [
    'favorites_count' => 0,
    'messages_count' => 0,
    'visits_count' => 0,
    'properties_viewed' => 0
];

try {
    // Nombre de favoris
    $query = "SELECT COUNT(*) as count FROM favorites WHERE user_id = :user_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $stats['favorites_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Nombre de messages envoyés
    $query = "SELECT COUNT(*) as count FROM messages WHERE sender_id = :user_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $stats['messages_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Nombre de visites planifiées
    $query = "SELECT COUNT(*) as count FROM visits WHERE user_id = :user_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $stats['visits_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Nombre de propriétés consultées (approximatif)
    $stats['properties_viewed'] = $user['views_count'] ?? 0;

} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des statistiques: " . $e->getMessage());
}

// Récupérer les préférences
$preferences = [
    'notification_email' => true,
    'notification_sms' => false,
    'search_radius' => 10,
    'budget_min' => null,
    'budget_max' => null,
    'preferred_cities' => ''
];

if (!empty($user['preferences'])) {
    $user_prefs = json_decode($user['preferences'], true);
    if ($user_prefs) {
        $preferences = array_merge($preferences, $user_prefs);
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon Profil - ImmoLink</title>
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

        /* Sidebar */
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

        /* Profile Container */
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
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            height: fit-content;
            position: sticky;
            top: 20px;
        }

        .profile-avatar {
            text-align: center;
            margin-bottom: 25px;
        }

        .avatar-large {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
            font-weight: 700;
            margin: 0 auto 15px;
            border: 4px solid white;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .profile-name {
            font-size: 24px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .profile-role {
            color: var(--secondary);
            font-size: 14px;
            margin-bottom: 20px;
        }

        .profile-stats {
            display: grid;
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-item {
            display: flex;
            justify-content: between;
            align-items: center;
            padding: 12px;
            background-color: var(--light);
            border-radius: 8px;
        }

        .stat-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stat-icon {
            width: 35px;
            height: 35px;
            border-radius: 8px;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .stat-value {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
        }

        .stat-label {
            font-size: 12px;
            color: var(--secondary);
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
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: var(--primary);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
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

        .input-with-icon {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        .input-with-icon .form-control {
            padding-left: 45px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }

        .checkbox-group label {
            margin-bottom: 0;
            font-weight: normal;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-light);
        }

        /* Danger Zone */
        .danger-zone {
            border: 2px solid var(--danger);
            background-color: rgba(239, 68, 68, 0.05);
        }

        .danger-zone .section-title {
            color: var(--danger);
            border-bottom-color: rgba(239, 68, 68, 0.2);
        }

        .danger-zone .section-title i {
            color: var(--danger);
        }

        .danger-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn-danger {
            background-color: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background-color: #dc2626;
        }

        /* Messages */
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background-color: #f0fdf4;
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background-color: #fef2f2;
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }

        .alert i {
            font-size: 18px;
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

            .profile-sidebar {
                position: static;
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

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .danger-actions {
                flex-direction: column;
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
            <a href="properties.php" class="nav-item">
                <i class="fas fa-building"></i>
                <span>Propriétés</span>
            </a>
            <a href="favorites.php" class="nav-item">
                <i class="fas fa-heart"></i>
                <span>Favoris</span>
            </a>
            <a href="reservations.php" class="nav-item">
                <i class="fas fa-calendar-check"></i>
                <span>Réservations</span>
            </a>
            <a href="messages.php" class="nav-item">
                <i class="fas fa-envelope"></i>
                <span>Messages</span>
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
                    <span>Locataire</span>
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
        </div>

        <!-- Messages d'alerte -->
        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <div class="profile-container">
            <!-- Profile Sidebar -->
            <div class="profile-sidebar">
                <div class="profile-avatar">
                    <div class="avatar-large">
                        <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                    </div>
                    <h2 class="profile-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h2>
                    <div class="profile-role">Locataire</div>
                </div>

                <div class="profile-stats">
                    <div class="stat-item">
                        <div class="stat-info">
                            <div class="stat-icon">
                                <i class="fas fa-heart"></i>
                            </div>
                            <div>
                                <div class="stat-value"><?php echo $stats['favorites_count']; ?></div>
                                <div class="stat-label">Favoris</div>
                            </div>
                        </div>
                    </div>

                    <div class="stat-item">
                        <div class="stat-info">
                            <div class="stat-icon">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <div>
                                <div class="stat-value"><?php echo $stats['messages_count']; ?></div>
                                <div class="stat-label">Messages</div>
                            </div>
                        </div>
                    </div>

                    <div class="stat-item">
                        <div class="stat-info">
                            <div class="stat-icon">
                                <i class="fas fa-calendar"></i>
                            </div>
                            <div>
                                <div class="stat-value"><?php echo $stats['visits_count']; ?></div>
                                <div class="stat-label">Visites</div>
                            </div>
                        </div>
                    </div>

                    <div class="stat-item">
                        <div class="stat-info">
                            <div class="stat-icon">
                                <i class="fas fa-eye"></i>
                            </div>
                            <div>
                                <div class="stat-value"><?php echo $stats['properties_viewed']; ?></div>
                                <div class="stat-label">Propriétés vues</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="account-info">
                    <h3 style="font-size: 16px; margin-bottom: 15px; color: var(--dark);">Informations du compte</h3>
                    <div style="font-size: 14px; color: var(--secondary);">
                        <p>Membre depuis: <?php echo date('d/m/Y', strtotime($user['created_at'])); ?></p>
                        <p>Dernière connexion: <?php echo date('d/m/Y H:i', strtotime($user['last_login'] ?? $user['created_at'])); ?></p>
                    </div>
                </div>
            </div>

            <!-- Profile Content -->
            <div class="profile-content">
                <!-- Informations personnelles -->
                <div class="profile-section">
                    <h2 class="section-title">
                        <i class="fas fa-user-circle"></i>
                        Informations personnelles
                    </h2>

                    <form method="POST" action="profile.php">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="first_name">Prénom <span class="required">*</span></label>
                                <div class="input-with-icon">
                                    <i class="fas fa-user input-icon"></i>
                                    <input 
                                        type="text" 
                                        id="first_name" 
                                        name="first_name" 
                                        class="form-control" 
                                        value="<?php echo htmlspecialchars($user['first_name']); ?>"
                                        required
                                    >
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="last_name">Nom <span class="required">*</span></label>
                                <div class="input-with-icon">
                                    <i class="fas fa-user input-icon"></i>
                                    <input 
                                        type="text" 
                                        id="last_name" 
                                        name="last_name" 
                                        class="form-control" 
                                        value="<?php echo htmlspecialchars($user['last_name']); ?>"
                                        required
                                    >
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="email">Adresse email <span class="required">*</span></label>
                                <div class="input-with-icon">
                                    <i class="fas fa-envelope input-icon"></i>
                                    <input 
                                        type="email" 
                                        id="email" 
                                        name="email" 
                                        class="form-control" 
                                        value="<?php echo htmlspecialchars($user['email']); ?>"
                                        required
                                    >
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="phone">Téléphone</label>
                                <div class="input-with-icon">
                                    <i class="fas fa-phone input-icon"></i>
                                    <input 
                                        type="tel" 
                                        id="phone" 
                                        name="phone" 
                                        class="form-control" 
                                        value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>"
                                        placeholder="+237 XXX XXX XXX"
                                    >
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="update_profile" class="btn btn-primary">
                                <i class="fas fa-save"></i> Enregistrer les modifications
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Changement de mot de passe -->
                <div class="profile-section">
                    <h2 class="section-title">
                        <i class="fas fa-lock"></i>
                        Sécurité du compte
                    </h2>

                    <form method="POST" action="profile.php">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="current_password">Mot de passe actuel <span class="required">*</span></label>
                                <div class="input-with-icon">
                                    <i class="fas fa-key input-icon"></i>
                                    <input 
                                        type="password" 
                                        id="current_password" 
                                        name="current_password" 
                                        class="form-control" 
                                        required
                                    >
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="new_password">Nouveau mot de passe <span class="required">*</span></label>
                                <div class="input-with-icon">
                                    <i class="fas fa-lock input-icon"></i>
                                    <input 
                                        type="password" 
                                        id="new_password" 
                                        name="new_password" 
                                        class="form-control" 
                                        required
                                        minlength="8"
                                    >
                                </div>
                                <small style="color: var(--secondary); font-size: 12px;">
                                    Le mot de passe doit contenir au moins 8 caractères
                                </small>
                            </div>

                            <div class="form-group">
                                <label for="confirm_password">Confirmer le mot de passe <span class="required">*</span></label>
                                <div class="input-with-icon">
                                    <i class="fas fa-lock input-icon"></i>
                                    <input 
                                        type="password" 
                                        id="confirm_password" 
                                        name="confirm_password" 
                                        class="form-control" 
                                        required
                                    >
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="change_password" class="btn btn-primary">
                                <i class="fas fa-key"></i> Changer le mot de passe
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Préférences -->
                <div class="profile-section">
                    <h2 class="section-title">
                        <i class="fas fa-cog"></i>
                        Préférences
                    </h2>

                    <form method="POST" action="profile.php">
                        <div class="form-group">
                            <h3 style="margin-bottom: 15px; color: var(--dark);">Notifications</h3>
                            <div class="checkbox-group">
                                <input 
                                    type="checkbox" 
                                    id="notification_email" 
                                    name="notification_email" 
                                    value="1"
                                    <?php echo $preferences['notification_email'] ? 'checked' : ''; ?>
                                >
                                <label for="notification_email">Recevoir les notifications par email</label>
                            </div>
                            <div class="checkbox-group">
                                <input 
                                    type="checkbox" 
                                    id="notification_sms" 
                                    name="notification_sms" 
                                    value="1"
                                    <?php echo $preferences['notification_sms'] ? 'checked' : ''; ?>
                                >
                                <label for="notification_sms">Recevoir les notifications par SMS</label>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="search_radius">Rayon de recherche (km)</label>
                                <select id="search_radius" name="search_radius" class="form-control">
                                    <option value="5" <?php echo $preferences['search_radius'] == 5 ? 'selected' : ''; ?>>5 km</option>
                                    <option value="10" <?php echo $preferences['search_radius'] == 10 ? 'selected' : ''; ?>>10 km</option>
                                    <option value="20" <?php echo $preferences['search_radius'] == 20 ? 'selected' : ''; ?>>20 km</option>
                                    <option value="50" <?php echo $preferences['search_radius'] == 50 ? 'selected' : ''; ?>>50 km</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="budget_min">Budget minimum (FCFA)</label>
                                <input 
                                    type="number" 
                                    id="budget_min" 
                                    name="budget_min" 
                                    class="form-control" 
                                    value="<?php echo $preferences['budget_min'] ?? ''; ?>"
                                    min="0"
                                >
                            </div>

                            <div class="form-group">
                                <label for="budget_max">Budget maximum (FCFA)</label>
                                <input 
                                    type="number" 
                                    id="budget_max" 
                                    name="budget_max" 
                                    class="form-control" 
                                    value="<?php echo $preferences['budget_max'] ?? ''; ?>"
                                    min="0"
                                >
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="preferred_cities">Villes préférées</label>
                            <textarea 
                                id="preferred_cities" 
                                name="preferred_cities" 
                                class="form-control" 
                                rows="3"
                                placeholder="Séparez les villes par des virgules (ex: Yaoundé, Douala, Bafoussam)"
                            ><?php echo htmlspecialchars($preferences['preferred_cities']); ?></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="update_preferences" class="btn btn-primary">
                                <i class="fas fa-save"></i> Enregistrer les préférences
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Zone de danger -->
                <div class="profile-section danger-zone">
                    <h2 class="section-title">
                        <i class="fas fa-exclamation-triangle"></i>
                        Zone de danger
                    </h2>

                    <p style="margin-bottom: 20px; color: var(--secondary);">
                        Ces actions sont irréversibles. Veuillez être certain de vos choix.
                    </p>

                    <div class="danger-actions">
                        <button type="button" class="btn btn-outline" onclick="exportData()">
                            <i class="fas fa-download"></i> Exporter mes données
                        </button>
                        <button type="button" class="btn btn-danger" onclick="confirmAccountDeletion()">
                            <i class="fas fa-trash"></i> Supprimer mon compte
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Validation des mots de passe
            const newPassword = document.getElementById('new_password');
            const confirmPassword = document.getElementById('confirm_password');

            function validatePasswords() {
                if (newPassword.value !== confirmPassword.value) {
                    confirmPassword.setCustomValidity('Les mots de passe ne correspondent pas');
                } else {
                    confirmPassword.setCustomValidity('');
                }
            }

            newPassword.addEventListener('input', validatePasswords);
            confirmPassword.addEventListener('input', validatePasswords);

            // Validation du budget
            const budgetMin = document.getElementById('budget_min');
            const budgetMax = document.getElementById('budget_max');

            function validateBudget() {
                if (budgetMin.value && budgetMax.value && parseFloat(budgetMin.value) > parseFloat(budgetMax.value)) {
                    budgetMax.setCustomValidity('Le budget maximum doit être supérieur au budget minimum');
                } else {
                    budgetMax.setCustomValidity('');
                }
            }

            budgetMin.addEventListener('input', validateBudget);
            budgetMax.addEventListener('input', validateBudget);
        });

        function exportData() {
            if (confirm('Voulez-vous exporter toutes vos données personnelles ?')) {
                // Simuler l'export (dans une vraie application, cela téléchargerait un fichier)
                alert('Vos données seront préparées et envoyées par email sous 24 heures.');
            }
        }

        function confirmAccountDeletion() {
            const confirmation = confirm(
                'Êtes-vous sûr de vouloir supprimer votre compte ?\n\n' +
                'Cette action est irréversible. Toutes vos données seront définitivement supprimées.\n\n' +
                'Tapez "SUPPRIMER" pour confirmer:'
            );

            if (confirmation) {
                const userInput = prompt('Tapez "SUPPRIMER" pour confirmer la suppression de votre compte:');
                if (userInput === 'SUPPRIMER') {
                    if (confirm('Dernière confirmation : Voulez-vous vraiment supprimer votre compte ?')) {
                        // Redirection vers la page de suppression (à créer)
                        window.location.href = 'delete-account.php';
                    }
                } else {
                    alert('Suppression annulée. Le texte saisi ne correspond pas.');
                }
            }
        }

        // Afficher/masquer les mots de passe
        function togglePasswordVisibility(inputId) {
            const input = document.getElementById(inputId);
            const icon = input.parentNode.querySelector('.input-icon');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'input-icon fas fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'input-icon fas fa-eye';
            }
        }
    </script>
</body>
</html>
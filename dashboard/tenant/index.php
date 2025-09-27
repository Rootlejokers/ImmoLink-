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

// Récupérer les statistiques du locataire
$stats = [
    'favorites' => 0,
    'messages_sent' => 0,
    'visits_scheduled' => 0,
    'visits_completed' => 0,
    'properties_viewed' => 0
];

try {
    // Nombre de favoris
    $query = "SELECT COUNT(*) as count FROM favorites WHERE user_id = :user_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $stats['favorites'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Messages envoyés
    $query = "SELECT COUNT(*) as count FROM messages WHERE sender_id = :user_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $stats['messages_sent'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Visites programmées
    $query = "SELECT COUNT(*) as count FROM visits WHERE user_id = :user_id AND status IN ('pending', 'confirmed')";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $stats['visits_scheduled'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Visites réalisées
    $query = "SELECT COUNT(*) as count FROM visits WHERE user_id = :user_id AND status = 'completed'";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $stats['visits_completed'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Propriétés consultées (simulé)
    $stats['properties_viewed'] = rand(15, 50);

} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des statistiques: " . $e->getMessage());
}

// Récupérer les propriétés récentes (pour la section "Suggestions")
$suggested_properties = [];
try {
    $query = "SELECT 
                p.*,
                u.first_name as owner_first_name,
                u.last_name as owner_last_name,
                (SELECT image_path FROM property_images WHERE property_id = p.id LIMIT 1) as main_image,
                (SELECT COUNT(*) FROM favorites WHERE property_id = p.id AND user_id = :user_id) as is_favorited
              FROM properties p
              JOIN users u ON p.owner_id = u.id
              WHERE p.status = 'available'
              ORDER BY p.created_at DESC
              LIMIT 6";

    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $suggested_properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des propriétés suggérées: " . $e->getMessage());
}

// Récupérer les favoris du locataire
$favorite_properties = [];
try {
    $query = "SELECT 
                p.*,
                u.first_name as owner_first_name,
                u.last_name as owner_last_name,
                (SELECT image_path FROM property_images WHERE property_id = p.id LIMIT 1) as main_image
              FROM properties p
              JOIN favorites f ON p.id = f.property_id
              JOIN users u ON p.owner_id = u.id
              WHERE f.user_id = :user_id AND p.status = 'available'
              ORDER BY f.created_at DESC
              LIMIT 4";

    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $favorite_properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des favoris: " . $e->getMessage());
}

// Récupérer les visites à venir
$upcoming_visits = [];
try {
    $query = "SELECT 
                v.*,
                p.title as property_title,
                p.address as property_address,
                p.city as property_city,
                u.first_name as owner_first_name,
                u.last_name as owner_last_name,
                u.phone as owner_phone
              FROM visits v
              JOIN properties p ON v.property_id = p.id
              JOIN users u ON p.owner_id = u.id
              WHERE v.user_id = :user_id AND v.status IN ('pending', 'confirmed')
              ORDER BY v.visit_date ASC
              LIMIT 5";

    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $upcoming_visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des visites: " . $e->getMessage());
}

// Récupérer les messages récents
$recent_messages = [];
try {
    $query = "SELECT 
                m.*,
                p.title as property_title,
                u.first_name as sender_first_name,
                u.last_name as sender_last_name,
                u.user_type as sender_type
              FROM messages m
              JOIN conversations c ON m.conversation_id = c.id
              JOIN properties p ON c.property_id = p.id
              JOIN users u ON m.sender_id = u.id
              WHERE c.tenant_id = :user_id
              ORDER BY m.created_at DESC
              LIMIT 5";

    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $recent_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des messages: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord Locataire - ImmoLink</title>
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

        .welcome-text {
            color: var(--secondary);
            margin-top: 5px;
        }

        .header-actions {
            display: flex;
            gap: 15px;
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

        .btn-success {
            background-color: var(--success);
            color: white;
        }

        .btn-success:hover {
            background-color: #0da271;
            transform: translateY(-2px);
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background-color: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
            font-size: 24px;
        }

        .stat-icon.favorites { background-color: rgba(236, 72, 153, 0.1); color: #ec4899; }
        .stat-icon.messages { background-color: rgba(168, 85, 247, 0.1); color: #a855f7; }
        .stat-icon.visits { background-color: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .stat-icon.properties { background-color: rgba(16, 185, 129, 0.1); color: var(--success); }
        .stat-icon.completed { background-color: rgba(37, 99, 235, 0.1); color: var(--primary); }

        .stat-info h3 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-info p {
            color: var(--secondary);
            font-size: 14px;
        }

        /* Dashboard Sections */
        .dashboard-section {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
            overflow: hidden;
        }

        .section-header {
            padding: 20px;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            justify-content: between;
            align-items: center;
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
        }

        .section-action {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
        }

        .section-body {
            padding: 20px;
        }

        /* Properties Grid */
        .properties-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }

        .property-card {
            border: 1px solid var(--gray-light);
            border-radius: 12px;
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .property-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }

        .property-image {
            width: 100%;
            height: 180px;
            object-fit: cover;
        }

        .property-content {
            padding: 15px;
        }

        .property-price {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 8px;
        }

        .property-title {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark);
        }

        .property-address {
            color: var(--secondary);
            font-size: 14px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .property-features {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            font-size: 14px;
            color: var(--secondary);
        }

        .property-actions {
            display: flex;
            gap: 10px;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 14px;
            flex: 1;
            justify-content: center;
        }

        .favorite-btn {
            background: none;
            border: 1px solid var(--gray-light);
            color: var(--secondary);
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
        }

        .favorite-btn.active {
            background-color: #fef2f2;
            border-color: #fecaca;
            color: var(--danger);
        }

        .favorite-btn:hover {
            background-color: #fef2f2;
            border-color: #fecaca;
            color: var(--danger);
        }

        /* Visits List */
        .visits-list {
            display: grid;
            gap: 15px;
        }

        .visit-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border: 1px solid var(--gray-light);
            border-radius: 8px;
            transition: all 0.3s;
        }

        .visit-item:hover {
            border-color: var(--primary);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .visit-date {
            width: 70px;
            text-align: center;
            margin-right: 15px;
        }

        .visit-day {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }

        .visit-month {
            font-size: 12px;
            color: var(--secondary);
            text-transform: uppercase;
        }

        .visit-details {
            flex: 1;
        }

        .visit-property {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--dark);
        }

        .visit-address {
            color: var(--secondary);
            font-size: 14px;
            margin-bottom: 5px;
        }

        .visit-status {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-pending { background-color: rgba(100, 116, 139, 0.1); color: var(--secondary); }
        .status-confirmed { background-color: rgba(245, 158, 11, 0.1); color: var(--warning); }

        /* Messages List */
        .messages-list {
            display: grid;
            gap: 15px;
        }

        .message-item {
            display: flex;
            align-items: flex-start;
            padding: 15px;
            border: 1px solid var(--gray-light);
            border-radius: 8px;
            transition: all 0.3s;
        }

        .message-item:hover {
            border-color: var(--primary);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .message-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            margin-right: 15px;
            flex-shrink: 0;
        }

        .message-content {
            flex: 1;
        }

        .message-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 5px;
        }

        .message-sender {
            font-weight: 600;
            color: var(--dark);
        }

        .message-time {
            color: var(--secondary);
            font-size: 12px;
        }

        .message-property {
            color: var(--primary);
            font-size: 14px;
            margin-bottom: 5px;
        }

        .message-text {
            color: var(--secondary);
            font-size: 14px;
            line-height: 1.4;
        }

        .message-unread {
            background-color: rgba(37, 99, 235, 0.05);
            border-left: 3px solid var(--primary);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--secondary);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: var(--gray);
        }

        .empty-state h3 {
            margin-bottom: 10px;
            color: var(--dark);
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .action-card {
            background-color: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            text-align: center;
            text-decoration: none;
            color: var(--dark);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .action-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
            color: var(--dark);
        }

        .action-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            background-color: rgba(37, 99, 235, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            color: var(--primary);
            font-size: 24px;
        }

        .action-title {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .action-desc {
            color: var(--secondary);
            font-size: 14px;
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
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .header-actions {
                width: 100%;
                justify-content: center;
            }

            .properties-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                grid-template-columns: 1fr;
            }

            .visit-item, .message-item {
                flex-direction: column;
                align-items: flex-start;
            }

            .visit-date {
                margin-right: 0;
                margin-bottom: 10px;
                width: 100%;
                text-align: left;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .message-avatar {
                margin-right: 0;
                margin-bottom: 10px;
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

            .property-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
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
            <a href="index.php" class="nav-item active">
                <i class="fas fa-tachometer-alt"></i>
                <span>Tableau de bord</span>
            </a>
            <a href="properties.php" class="nav-item">
                <i class="fas fa-search"></i>
                <span>Rechercher</span>
            </a>
            <a href="favorites.php" class="nav-item">
                <i class="fas fa-heart"></i>
                <span>Favoris</span>
            </a>
            <a href="visits.php" class="nav-item">
                <i class="fas fa-calendar-alt"></i>
                <span>Mes visites</span>
            </a>
            <a href="messages.php" class="nav-item">
                <i class="fas fa-envelope"></i>
                <span>Messages</span>
            </a>
            <a href="profile.php" class="nav-item">
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
                <h1 class="dashboard-title">Tableau de bord</h1>
                <p class="welcome-text">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name']); ?> !</p>
            </div>
            <div class="header-actions">
                <a href="properties.php" class="btn btn-primary">
                    <i class="fas fa-search"></i> Rechercher un bien
                </a>
                <a href="messages.php" class="btn btn-success">
                    <i class="fas fa-envelope"></i> Messages
                </a>
            </div>
        </div>

        <!-- Actions rapides -->
        <div class="quick-actions">
            <a href="properties.php" class="action-card">
                <div class="action-icon">
                    <i class="fas fa-search"></i>
                </div>
                <div class="action-title">Rechercher</div>
                <div class="action-desc">Trouvez votre bien idéal</div>
            </a>
            <a href="favorites.php" class="action-card">
                <div class="action-icon">
                    <i class="fas fa-heart"></i>
                </div>
                <div class="action-title">Favoris</div>
                <div class="action-desc">Vos biens préférés</div>
            </a>
            <a href="visits.php" class="action-card">
                <div class="action-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="action-title">Visites</div>
                <div class="action-desc">Gérez vos rendez-vous</div>
            </a>
            <a href="messages.php" class="action-card">
                <div class="action-icon">
                    <i class="fas fa-comments"></i>
                </div>
                <div class="action-title">Messages</div>
                <div class="action-desc">Contactez les propriétaires</div>
            </a>
        </div>

        <!-- Statistiques -->
        <div class="stats-grid">
            <div class="stat-card" onclick="window.location.href='favorites.php'">
                <div class="stat-icon favorites">
                    <i class="fas fa-heart"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['favorites']; ?></h3>
                    <p>Biens favoris</p>
                </div>
            </div>

            <div class="stat-card" onclick="window.location.href='messages.php'">
                <div class="stat-icon messages">
                    <i class="fas fa-envelope"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['messages_sent']; ?></h3>
                    <p>Messages envoyés</p>
                </div>
            </div>

            <div class="stat-card" onclick="window.location.href='visits.php'">
                <div class="stat-icon visits">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['visits_scheduled']; ?></h3>
                    <p>Visites programmées</p>
                </div>
            </div>

            <div class="stat-card" onclick="window.location.href='visits.php'">
                <div class="stat-icon completed">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['visits_completed']; ?></h3>
                    <p>Visites réalisées</p>
                </div>
            </div>

            <div class="stat-card" onclick="window.location.href='properties.php'">
                <div class="stat-icon properties">
                    <i class="fas fa-eye"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['properties_viewed']; ?></h3>
                    <p>Biens consultés</p>
                </div>
            </div>
        </div>

        <!-- Biens favoris -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2 class="section-title">Mes biens favoris</h2>
                <a href="favorites.php" class="section-action">Voir tout</a>
            </div>
            <div class="section-body">
                <?php if (!empty($favorite_properties)): ?>
                    <div class="properties-grid">
                        <?php foreach ($favorite_properties as $property): ?>
                            <div class="property-card">
                                <img 
                                    src="<?php echo $property['main_image'] ? '../../uploads/properties/' . htmlspecialchars($property['main_image']) : 'https://via.placeholder.com/400x300?text=ImmoLink'; ?>" 
                                    alt="<?php echo htmlspecialchars($property['title']); ?>" 
                                    class="property-image"
                                >
                                <div class="property-content">
                                    <div class="property-price"><?php echo formatPrice($property['price']); ?></div>
                                    <h3 class="property-title"><?php echo htmlspecialchars($property['title']); ?></h3>
                                    <div class="property-address">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <?php echo htmlspecialchars($property['address'] . ', ' . $property['city']); ?>
                                    </div>
                                    <div class="property-features">
                                        <span><?php echo $property['surface_area'] ?: '-'; ?> m²</span>
                                        <span><?php echo $property['rooms']; ?> pièces</span>
                                        <span><?php echo $property['bedrooms']; ?> chambres</span>
                                    </div>
                                    <div class="property-actions">
                                        <a href="../../property-details.php?id=<?php echo $property['id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-eye"></i> Voir
                                        </a>
                                        <button class="favorite-btn active" onclick="toggleFavorite(<?php echo $property['id']; ?>, this)">
                                            <i class="fas fa-heart"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-heart"></i>
                        <h3>Aucun bien favori</h3>
                        <p>Commencez à ajouter des biens à vos favoris pour les retrouver facilement.</p>
                        <a href="properties.php" class="btn btn-primary">Rechercher des biens</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Visites à venir -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2 class="section-title">Visites à venir</h2>
                <a href="visits.php" class="section-action">Voir tout</a>
            </div>
            <div class="section-body">
                <?php if (!empty($upcoming_visits)): ?>
                    <div class="visits-list">
                        <?php foreach ($upcoming_visits as $visit): ?>
                            <div class="visit-item">
                                <div class="visit-date">
                                    <div class="visit-day"><?php echo date('d', strtotime($visit['visit_date'])); ?></div>
                                    <div class="visit-month"><?php echo date('M', strtotime($visit['visit_date'])); ?></div>
                                </div>
                                <div class="visit-details">
                                    <div class="visit-property"><?php echo htmlspecialchars($visit['property_title']); ?></div>
                                    <div class="visit-address"><?php echo htmlspecialchars($visit['property_address'] . ', ' . $visit['property_city']); ?></div>
                                    <div class="visit-time">
                                        <i class="fas fa-clock"></i>
                                        <?php echo date('H:i', strtotime($visit['visit_date'])); ?> - 
                                        Propriétaire: <?php echo htmlspecialchars($visit['owner_first_name'] . ' ' . $visit['owner_last_name']); ?>
                                    </div>
                                </div>
                                <span class="visit-status status-<?php echo $visit['status']; ?>">
                                    <?php echo $visit['status'] === 'pending' ? 'En attente' : 'Confirmée'; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>Aucune visite programmée</h3>
                        <p>Planifiez une visite pour découvrir les biens qui vous intéressent.</p>
                        <a href="properties.php" class="btn btn-primary">Rechercher des biens</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Messages récents -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2 class="section-title">Messages récents</h2>
                <a href="messages.php" class="section-action">Voir tout</a>
            </div>
            <div class="section-body">
                <?php if (!empty($recent_messages)): ?>
                    <div class="messages-list">
                        <?php foreach ($recent_messages as $message): ?>
                            <div class="message-item">
                                <div class="message-avatar">
                                    <?php echo strtoupper(substr($message['sender_first_name'], 0, 1) . substr($message['sender_last_name'], 0, 1)); ?>
                                </div>
                                <div class="message-content">
                                    <div class="message-header">
                                        <span class="message-sender">
                                            <?php echo htmlspecialchars($message['sender_first_name'] . ' ' . $message['sender_last_name']); ?>
                                            (<?php echo $message['sender_type'] === 'owner' ? 'Propriétaire' : 'Locataire'; ?>)
                                        </span>
                                        <span class="message-time"><?php echo time_elapsed_string($message['created_at']); ?></span>
                                    </div>
                                    <div class="message-property"><?php echo htmlspecialchars($message['property_title']); ?></div>
                                    <p class="message-text"><?php echo htmlspecialchars(substr($message['message'], 0, 100) . (strlen($message['message']) > 100 ? '...' : '')); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-comments"></i>
                        <h3>Aucun message</h3>
                        <p>Commencez une conversation avec un propriétaire pour en savoir plus sur un bien.</p>
                        <a href="properties.php" class="btn btn-primary">Rechercher des biens</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Suggestions -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2 class="section-title">Suggestions pour vous</h2>
                <a href="properties.php" class="section-action">Voir plus</a>
            </div>
            <div class="section-body">
                <?php if (!empty($suggested_properties)): ?>
                    <div class="properties-grid">
                        <?php foreach ($suggested_properties as $property): ?>
                            <div class="property-card">
                                <img 
                                    src="<?php echo $property['main_image'] ? '../../uploads/properties/' . htmlspecialchars($property['main_image']) : 'https://via.placeholder.com/400x300?text=ImmoLink'; ?>" 
                                    alt="<?php echo htmlspecialchars($property['title']); ?>" 
                                    class="property-image"
                                >
                                <div class="property-content">
                                    <div class="property-price"><?php echo formatPrice($property['price']); ?></div>
                                    <h3 class="property-title"><?php echo htmlspecialchars($property['title']); ?></h3>
                                    <div class="property-address">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <?php echo htmlspecialchars($property['address'] . ', ' . $property['city']); ?>
                                    </div>
                                    <div class="property-features">
                                        <span><?php echo $property['surface_area'] ?: '-'; ?> m²</span>
                                        <span><?php echo $property['rooms']; ?> pièces</span>
                                        <span><?php echo $property['bedrooms']; ?> chambres</span>
                                    </div>
                                    <div class="property-actions">
                                        <a href="../../property-details.php?id=<?php echo $property['id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-eye"></i> Voir
                                        </a>
                                        <button class="favorite-btn <?php echo $property['is_favorited'] ? 'active' : ''; ?>" 
                                                onclick="toggleFavorite(<?php echo $property['id']; ?>, this)">
                                            <i class="fas fa-heart"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-home"></i>
                        <h3>Aucune suggestion pour le moment</h3>
                        <p>Commencez à rechercher des biens pour recevoir des suggestions personnalisées.</p>
                        <a href="properties.php" class="btn btn-primary">Rechercher des biens</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Animation des statistiques
            const statNumbers = document.querySelectorAll('.stat-info h3');
            statNumbers.forEach(stat => {
                const target = parseInt(stat.textContent);
                let current = 0;
                const duration = 2000;
                const steps = 50;
                const increment = target / steps;
                
                const timer = setInterval(() => {
                    current += increment;
                    if (current >= target) {
                        clearInterval(timer);
                        stat.textContent = target;
                    } else {
                        stat.textContent = Math.floor(current);
                    }
                }, duration / steps);
            });

            // Gestion des favoris
            window.toggleFavorite = function(propertyId, button) {
                const isActive = button.classList.contains('active');
                
                // Simulation d'appel AJAX
                fetch('../../api/toggle-favorite.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        property_id: propertyId,
                        action: isActive ? 'remove' : 'add'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        button.classList.toggle('active');
                        
                        // Mettre à jour le compteur de favoris
                        const favoritesStat = document.querySelector('.stat-icon.favorites').closest('.stat-card');
                        const countElement = favoritesStat.querySelector('h3');
                        let currentCount = parseInt(countElement.textContent);
                        
                        if (isActive) {
                            countElement.textContent = currentCount - 1;
                        } else {
                            countElement.textContent = currentCount + 1;
                        }
                    }
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    alert('Erreur lors de la mise à jour des favoris');
                });
            };
        });

        // Fonction pour formater le temps écoulé (utilisée dans PHP)
        <?php 
        function time_elapsed_string($datetime, $full = false) {
            $now = new DateTime;
            $ago = new DateTime($datetime);
            $diff = $now->diff($ago);

            $diff->w = floor($diff->d / 7);
            $diff->d -= $diff->w * 7;

            $string = array(
                'y' => 'an',
                'm' => 'mois',
                'w' => 'semaine',
                'd' => 'jour',
                'h' => 'heure',
                'i' => 'minute',
                's' => 'seconde',
            );
            
            foreach ($string as $k => &$v) {
                if ($diff->$k) {
                    $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
                } else {
                    unset($string[$k]);
                }
            }

            if (!$full) $string = array_slice($string, 0, 1);
            return $string ? implode(', ', $string) . '' : 'à l\'instant';
        }
        ?>
    </script>
</body>
</html>
<?php
session_start();
require_once '../../includes/config.php';
require_once '../../includes/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Vérifier si l'utilisateur est connecté et est un propriétaire
#if (!isLoggedIn() || !isOwner()) {
#    header('Location: ../../login.php');
#    exit();
#}

// Récupérer les informations du propriétaire
$user_id = $_SESSION['user_id'];
$db = new Database();
$conn = $db->getConnection();

// Récupérer les statistiques du propriétaire
$properties_count = 0;
$properties_available = 0;
$properties_rented = 0;
$properties_sold = 0;
$total_views = 0;
$total_messages = 0;

try {
    // Nombre total de propriétés
    $query = "SELECT COUNT(*) as count FROM properties WHERE owner_id = :user_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $properties_count = $result['count'];

    // Propriétés par statut
    $query = "SELECT status, COUNT(*) as count FROM properties WHERE owner_id = :user_id GROUP BY status";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        switch ($row['status']) {
            case 'available':
                $properties_available = $row['count'];
                break;
            case 'occupied':
                $properties_rented = $row['count'];
                break;
            case 'sold':
                $properties_sold = $row['count'];
                break;
        }
    }

    // Total des vues (simulé)
    $query = "SELECT SUM(views) as total_views FROM properties WHERE owner_id = :user_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_views = $result['total_views'] ?: 0;

    // Total des messages (simulé)
    $query = "SELECT COUNT(*) as total_messages FROM messages m 
              JOIN conversations c ON m.conversation_id = c.id 
              WHERE c.owner_id = :user_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_messages = $result['total_messages'];

} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des statistiques: " . $e->getMessage());
}

// Récupérer les propriétés récentes
$recent_properties = [];
try {
    $query = "SELECT p.*, 
                     (SELECT image_path FROM property_images WHERE property_id = p.id LIMIT 1) as main_image,
                     (SELECT COUNT(*) FROM property_images WHERE property_id = p.id) as images_count
              FROM properties p 
              WHERE p.owner_id = :user_id 
              ORDER BY p.created_at DESC 
              LIMIT 5";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $recent_properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des propriétés récentes: " . $e->getMessage());
}

// Récupérer les messages récents
$recent_messages = [];
try {
    $query = "SELECT m.*, u.first_name, u.last_name, p.title as property_title
              FROM messages m
              JOIN conversations c ON m.conversation_id = c.id
              JOIN users u ON m.sender_id = u.id
              JOIN properties p ON c.property_id = p.id
              WHERE c.owner_id = :user_id
              ORDER BY m.created_at DESC
              LIMIT 5";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $recent_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des messages récents: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord Propriétaire - ImmoLink</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
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

        .stat-icon.properties { background-color: rgba(37, 99, 235, 0.1); color: var(--primary); }
        .stat-icon.available { background-color: rgba(16, 185, 129, 0.1); color: var(--success); }
        .stat-icon.rented { background-color: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .stat-icon.sold { background-color: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .stat-icon.views { background-color: rgba(100, 116, 139, 0.1); color: var(--secondary); }
        .stat-icon.messages { background-color: rgba(168, 85, 247, 0.1); color: #a855f7; }

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

        /* Properties List */
        .properties-list {
            display: grid;
            gap: 15px;
        }

        .property-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border: 1px solid var(--gray-light);
            border-radius: 8px;
            transition: all 0.3s;
        }

        .property-item:hover {
            border-color: var(--primary);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .property-image {
            width: 80px;
            height: 60px;
            border-radius: 6px;
            object-fit: cover;
            margin-right: 15px;
        }

        .property-info {
            flex: 1;
        }

        .property-title {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--dark);
        }

        .property-details {
            display: flex;
            gap: 15px;
            color: var(--secondary);
            font-size: 14px;
        }

        .property-status {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-available { background-color: rgba(16, 185, 129, 0.1); color: var(--success); }
        .status-occupied { background-color: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .status-sold { background-color: rgba(239, 68, 68, 0.1); color: var(--danger); }

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

            .property-item, .message-item {
                flex-direction: column;
                align-items: flex-start;
            }

            .property-image {
                margin-right: 0;
                margin-bottom: 10px;
                width: 100%;
                height: 120px;
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
                <h1 class="dashboard-title">Tableau de bord</h1>
                <p class="welcome-text">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name']); ?> !</p>
            </div>
            <div class="header-actions">
                <a href="add-property.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Ajouter un bien
                </a>
                <a href="messages.php" class="btn btn-success">
                    <i class="fas fa-envelope"></i> Messages
                </a>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon properties">
                    <i class="fas fa-building"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $properties_count; ?></h3>
                    <p>Biens immobiliers</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon available">
                    <i class="fas fa-home"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $properties_available; ?></h3>
                    <p>Biens disponibles</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon rented">
                    <i class="fas fa-key"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $properties_rented; ?></h3>
                    <p>Biens loués</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon sold">
                    <i class="fas fa-tag"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $properties_sold; ?></h3>
                    <p>Biens vendus</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon views">
                    <i class="fas fa-eye"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $total_views; ?></h3>
                    <p>Vues totales</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon messages">
                    <i class="fas fa-comments"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $total_messages; ?></h3>
                    <p>Messages reçus</p>
                </div>
            </div>
        </div>

        <!-- Recent Properties -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2 class="section-title">Mes biens récents</h2>
                <a href="my-properties.php" class="section-action">Voir tout</a>
            </div>
            <div class="section-body">
                <?php if (!empty($recent_properties)): ?>
                    <div class="properties-list">
                        <?php foreach ($recent_properties as $property): ?>
                            <div class="property-item">
                                <img 
                                    src="<?php echo $property['main_image'] ? '../../uploads/properties/' . htmlspecialchars($property['main_image']) : 'https://via.placeholder.com/300x200?text=ImmoLink'; ?>" 
                                    alt="<?php echo htmlspecialchars($property['title']); ?>" 
                                    class="property-image"
                                >
                                <div class="property-info">
                                    <h4 class="property-title"><?php echo htmlspecialchars($property['title']); ?></h4>
                                    <div class="property-details">
                                        <span><?php echo formatPrice($property['price']); ?></span>
                                        <span><?php echo $property['surface_area']; ?> m²</span>
                                        <span><?php echo $property['rooms']; ?> pièces</span>
                                    </div>
                                </div>
                                <span class="property-status status-<?php echo $property['status']; ?>">
                                    <?php 
                                    switch ($property['status']) {
                                        case 'available': echo 'Disponible'; break;
                                        case 'occupied': echo 'Loué'; break;
                                        case 'sold': echo 'Vendu'; break;
                                        default: echo $property['status'];
                                    }
                                    ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-building"></i>
                        <h3>Aucun bien immobilier</h3>
                        <p>Vous n'avez pas encore ajouté de propriété.</p>
                        <a href="add-property.php" class="btn btn-primary">Ajouter votre premier bien</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Messages -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2 class="section-title">Messages récents</h2>
                <a href="messages.php" class="section-action">Voir tout</a>
            </div>
            <div class="section-body">
                <?php if (!empty($recent_messages)): ?>
                    <div class="messages-list">
                        <?php foreach ($recent_messages as $message): ?>
                            <div class="message-item <?php echo !$message['is_read'] ? 'message-unread' : ''; ?>">
                                <div class="message-avatar">
                                    <?php echo strtoupper(substr($message['first_name'], 0, 1) . substr($message['last_name'], 0, 1)); ?>
                                </div>
                                <div class="message-content">
                                    <div class="message-header">
                                        <span class="message-sender"><?php echo htmlspecialchars($message['first_name'] . ' ' . $message['last_name']); ?></span>
                                        <span class="message-time"><?php echo date('d/m/Y H:i', strtotime($message['created_at'])); ?></span>
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
                        <p>Vous n'avez pas encore reçu de messages.</p>
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
                        stat.textContent = target.toLocaleString();
                    } else {
                        stat.textContent = Math.floor(current).toLocaleString();
                    }
                }, duration / steps);
            });

            // Marquer les messages comme lus
            const messageItems = document.querySelectorAll('.message-item');
            messageItems.forEach(item => {
                item.addEventListener('click', function() {
                    this.classList.remove('message-unread');
                    // Envoyer une requête AJAX pour marquer le message comme lu
                });
            });
        });
    </script>
</body>
</html>
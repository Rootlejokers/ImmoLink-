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

// Paramètres de période
$period = isset($_GET['period']) ? $_GET['period'] : 'month';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Déterminer les dates en fonction de la période
$date_ranges = [
    'week' => ['start' => date('Y-m-d', strtotime('-1 week')), 'end' => date('Y-m-d')],
    'month' => ['start' => date('Y-m-d', strtotime('-1 month')), 'end' => date('Y-m-d')],
    'quarter' => ['start' => date('Y-m-d', strtotime('-3 months')), 'end' => date('Y-m-d')],
    'year' => ['start' => date('Y-m-d', strtotime('-1 year')), 'end' => date('Y-m-d')],
    'custom' => ['start' => $start_date, 'end' => $end_date]
];

$current_range = $date_ranges[$period];
if ($period === 'custom' && (empty($start_date) || empty($end_date))) {
    $current_range = $date_ranges['month'];
    $period = 'month';
}

// Statistiques générales
$stats = [
    'total_properties' => 0,
    'available_properties' => 0,
    'rented_properties' => 0,
    'sold_properties' => 0,
    'total_views' => 0,
    'total_messages' => 0,
    'total_favorites' => 0,
    'conversion_rate' => 0
];

try {
    // Propriétés totales
    $query = "SELECT COUNT(*) as count FROM properties WHERE owner_id = :user_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $stats['total_properties'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Propriétés par statut
    $query = "SELECT status, COUNT(*) as count FROM properties WHERE owner_id = :user_id GROUP BY status";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        switch ($row['status']) {
            case 'available':
                $stats['available_properties'] = $row['count'];
                break;
            case 'occupied':
                $stats['rented_properties'] = $row['count'];
                break;
            case 'sold':
                $stats['sold_properties'] = $row['count'];
                break;
        }
    }

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

    // Favoris totaux
    $query = "SELECT COUNT(*) as total FROM favorites f 
              JOIN properties p ON f.property_id = p.id 
              WHERE p.owner_id = :user_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $stats['total_favorites'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Taux de conversion
    $total_interactions = $stats['total_views'] + $stats['total_messages'] + $stats['total_favorites'];
    $successful_transactions = $stats['rented_properties'] + $stats['sold_properties'];
    
    if ($total_interactions > 0) {
        $stats['conversion_rate'] = round(($successful_transactions / $total_interactions) * 100, 2);
    }

} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des statistiques: " . $e->getMessage());
}

// Données pour les graphiques - Évolution mensuelle
$monthly_data = [];
try {
    $query = "SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as properties_added,
                SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) as properties_rented,
                SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as properties_sold
              FROM properties 
              WHERE owner_id = :user_id 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
              GROUP BY DATE_FORMAT(created_at, '%Y-%m')
              ORDER BY month";

    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $monthly_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des données mensuelles: " . $e->getMessage());
}

// Propriétés les plus populaires
$popular_properties = [];
try {
    $query = "SELECT 
                p.id,
                p.title,
                p.price,
                p.status,
                p.city,
                p.views,
                (SELECT COUNT(*) FROM favorites WHERE property_id = p.id) as favorites_count,
                (SELECT COUNT(*) FROM messages m 
                 JOIN conversations c ON m.conversation_id = c.id 
                 WHERE c.property_id = p.id) as messages_count,
                (SELECT image_path FROM property_images WHERE property_id = p.id LIMIT 1) as main_image
              FROM properties p
              WHERE p.owner_id = :user_id
              ORDER BY (p.views + favorites_count * 5 + messages_count * 3) DESC
              LIMIT 5";

    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $popular_properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des propriétés populaires: " . $e->getMessage());
}

// Performances par type
$performance_by_type = [];
try {
    $query = "SELECT 
                type,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'occupied' OR status = 'sold' THEN 1 ELSE 0 END) as successful,
                AVG(price) as avg_price,
                AVG(DATEDIFF(IFNULL(updated_at, NOW()), created_at)) as avg_time_on_market
              FROM properties 
              WHERE owner_id = :user_id
              GROUP BY type";

    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $performance_by_type = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des performances par type: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistiques - ImmoLink</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* Filtres de période */
        .period-filters {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 20px;
            margin-bottom: 20px;
        }

        .filters-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--dark);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .filter-group {
            margin-bottom: 0;
        }

        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
        }

        .filter-select, .filter-input {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid var(--gray-light);
            border-radius: 6px;
            font-size: 14px;
        }

        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .custom-dates {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
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
        .stat-icon.favorites { background-color: rgba(236, 72, 153, 0.1); color: #ec4899; }
        .stat-icon.conversion { background-color: rgba(14, 165, 233, 0.1); color: #0ea5e9; }

        .stat-info h3 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-info p {
            color: var(--secondary);
            font-size: 14px;
        }

        .stat-trend {
            margin-left: auto;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .trend-up { background-color: rgba(16, 185, 129, 0.1); color: var(--success); }
        .trend-down { background-color: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .trend-neutral { background-color: rgba(100, 116, 139, 0.1); color: var(--secondary); }

        /* Charts Section */
        .charts-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-card {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 20px;
        }

        .chart-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
        }

        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        /* Popular Properties */
        .popular-properties {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 20px;
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .properties-grid {
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

        .property-stats {
            display: flex;
            gap: 15px;
            margin-top: 10px;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            color: var(--secondary);
        }

        /* Performance Table */
        .performance-table {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 20px;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--gray-light);
        }

        th {
            background-color: var(--light);
            font-weight: 600;
            color: var(--dark);
        }

        tr:hover {
            background-color: var(--light);
        }

        .progress-bar {
            width: 100px;
            height: 8px;
            background-color: var(--gray-light);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 4px;
        }

        .progress-rent { background-color: var(--warning); }
        .progress-sale { background-color: var(--success); }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .charts-section {
                grid-template-columns: 1fr;
            }
        }

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
            .filters-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .custom-dates {
                grid-template-columns: 1fr;
            }

            .property-item {
                flex-direction: column;
                align-items: flex-start;
            }

            .property-image {
                margin-right: 0;
                margin-bottom: 10px;
                width: 100%;
                height: 120px;
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

            .chart-card {
                padding: 15px;
            }

            .chart-container {
                height: 250px;
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
            <a href="statistics.php" class="nav-item active">
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
                <h1 class="dashboard-title">Statistiques Détaillées</h1>
                <div class="breadcrumb">
                    <a href="index.php">Tableau de bord</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Statistiques</span>
                </div>
            </div>
            <div>
                <a href="my-properties.php" class="btn btn-outline">
                    <i class="fas fa-building"></i> Voir mes biens
                </a>
            </div>
        </div>

        <!-- Filtres de période -->
        <div class="period-filters">
            <h2 class="filters-title">Période d'analyse</h2>
            <form method="GET" action="statistics.php">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label>Période</label>
                        <select name="period" class="filter-select" onchange="this.form.submit()">
                            <option value="week" <?php echo $period === 'week' ? 'selected' : ''; ?>>7 derniers jours</option>
                            <option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>30 derniers jours</option>
                            <option value="quarter" <?php echo $period === 'quarter' ? 'selected' : ''; ?>>3 derniers mois</option>
                            <option value="year" <?php echo $period === 'year' ? 'selected' : ''; ?>>12 derniers mois</option>
                            <option value="custom" <?php echo $period === 'custom' ? 'selected' : ''; ?>>Personnalisée</option>
                        </select>
                    </div>

                    <?php if ($period === 'custom'): ?>
                        <div class="filter-group custom-dates">
                            <div>
                                <label>Date de début</label>
                                <input type="date" name="start_date" class="filter-input" value="<?php echo $start_date; ?>" required>
                            </div>
                            <div>
                                <label>Date de fin</label>
                                <input type="date" name="end_date" class="filter-input" value="<?php echo $end_date; ?>" required>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary" style="width: 100%">
                            <i class="fas fa-filter"></i> Appliquer
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Statistiques principales -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon properties">
                    <i class="fas fa-building"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['total_properties']; ?></h3>
                    <p>Biens immobiliers</p>
                </div>
                <span class="stat-trend trend-neutral">Total</span>
            </div>

            <div class="stat-card">
                <div class="stat-icon available">
                    <i class="fas fa-home"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['available_properties']; ?></h3>
                    <p>Biens disponibles</p>
                </div>
                <span class="stat-trend trend-neutral"><?php echo round(($stats['available_properties'] / $stats['total_properties']) * 100, 1); ?>%</span>
            </div>

            <div class="stat-card">
                <div class="stat-icon rented">
                    <i class="fas fa-key"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['rented_properties']; ?></h3>
                    <p>Biens loués</p>
                </div>
                <span class="stat-trend trend-up">+12%</span>
            </div>

            <div class="stat-card">
                <div class="stat-icon sold">
                    <i class="fas fa-tag"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['sold_properties']; ?></h3>
                    <p>Biens vendus</p>
                </div>
                <span class="stat-trend trend-up">+8%</span>
            </div>

            <div class="stat-card">
                <div class="stat-icon views">
                    <i class="fas fa-eye"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($stats['total_views']); ?></h3>
                    <p>Vues totales</p>
                </div>
                <span class="stat-trend trend-up">+25%</span>
            </div>

            <div class="stat-card">
                <div class="stat-icon messages">
                    <i class="fas fa-comments"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($stats['total_messages']); ?></h3>
                    <p>Messages reçus</p>
                </div>
                <span class="stat-trend trend-up">+18%</span>
            </div>

            <div class="stat-card">
                <div class="stat-icon favorites">
                    <i class="fas fa-heart"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($stats['total_favorites']); ?></h3>
                    <p>Favoris</p>
                </div>
                <span class="stat-trend trend-up">+15%</span>
            </div>

            <div class="stat-card">
                <div class="stat-icon conversion">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['conversion_rate']; ?>%</h3>
                    <p>Taux de conversion</p>
                </div>
                <span class="stat-trend trend-up">+5%</span>
            </div>
        </div>

        <!-- Graphiques -->
        <div class="charts-section">
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Évolution des biens</h3>
                </div>
                <div class="chart-container">
                    <canvas id="propertiesChart"></canvas>
                </div>
            </div>

            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Répartition par statut</h3>
                </div>
                <div class="chart-container">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Propriétés populaires -->
        <div class="popular-properties">
            <h2 class="section-title">
                <i class="fas fa-fire"></i>
                Propriétés les plus populaires
            </h2>

            <div class="properties-grid">
                <?php foreach ($popular_properties as $property): ?>
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
                                <span><?php echo htmlspecialchars($property['city']); ?></span>
                                <span class="property-status status-<?php echo $property['status']; ?>">
                                    <?php 
                                    $status_labels = [
                                        'available' => 'Disponible',
                                        'occupied' => 'Loué',
                                        'sold' => 'Vendu'
                                    ];
                                    echo $status_labels[$property['status']] ?? ucfirst($property['status']); 
                                    ?>
                                </span>
                            </div>
                            <div class="property-stats">
                                <span class="stat-item">
                                    <i class="fas fa-eye"></i> <?php echo number_format($property['views']); ?> vues
                                </span>
                                <span class="stat-item">
                                    <i class="fas fa-heart"></i> <?php echo $property['favorites_count']; ?> favoris
                                </span>
                                <span class="stat-item">
                                    <i class="fas fa-envelope"></i> <?php echo $property['messages_count']; ?> messages
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Performances par type -->
        <div class="performance-table">
            <h2 class="section-title">
                <i class="fas fa-chart-pie"></i>
                Performances par type de transaction
            </h2>

            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Total</th>
                        <th>Réussis</th>
                        <th>Taux de réussite</th>
                        <th>Prix moyen</th>
                        <th>Délai moyen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($performance_by_type as $performance): ?>
                        <tr>
                            <td>
                                <strong><?php echo $performance['type'] === 'location' ? 'Location' : 'Vente'; ?></strong>
                            </td>
                            <td><?php echo $performance['total']; ?></td>
                            <td><?php echo $performance['successful']; ?></td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div class="progress-bar">
                                        <div class="progress-fill <?php echo $performance['type'] === 'location' ? 'progress-rent' : 'progress-sale'; ?>" 
                                             style="width: <?php echo $performance['total'] > 0 ? ($performance['successful'] / $performance['total']) * 100 : 0; ?>%">
                                        </div>
                                    </div>
                                    <span><?php echo $performance['total'] > 0 ? round(($performance['successful'] / $performance['total']) * 100, 1) : 0; ?>%</span>
                                </div>
                            </td>
                            <td><?php echo formatPrice($performance['avg_price']); ?></td>
                            <td><?php echo round($performance['avg_time_on_market']); ?> jours</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Graphique d'évolution des biens
            const propertiesCtx = document.getElementById('propertiesChart').getContext('2d');
            const propertiesChart = new Chart(propertiesCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($monthly_data, 'month')); ?>,
                    datasets: [
                        {
                            label: 'Biens ajoutés',
                            data: <?php echo json_encode(array_column($monthly_data, 'properties_added')); ?>,
                            borderColor: '#2563eb',
                            backgroundColor: 'rgba(37, 99, 235, 0.1)',
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Biens loués',
                            data: <?php echo json_encode(array_column($monthly_data, 'properties_rented')); ?>,
                            borderColor: '#f59e0b',
                            backgroundColor: 'rgba(245, 158, 11, 0.1)',
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Biens vendus',
                            data: <?php echo json_encode(array_column($monthly_data, 'properties_sold')); ?>,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            fill: true,
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        title: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });

            // Graphique de répartition par statut
            const statusCtx = document.getElementById('statusChart').getContext('2d');
            const statusChart = new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Disponibles', 'Loués', 'Vendus'],
                    datasets: [{
                        data: [
                            <?php echo $stats['available_properties']; ?>,
                            <?php echo $stats['rented_properties']; ?>,
                            <?php echo $stats['sold_properties']; ?>
                        ],
                        backgroundColor: [
                            'rgba(16, 185, 129, 0.8)',
                            'rgba(245, 158, 11, 0.8)',
                            'rgba(239, 68, 68, 0.8)'
                        ],
                        borderColor: [
                            '#10b981',
                            '#f59e0b',
                            '#ef4444'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                        }
                    }
                }
            });

            // Animation des statistiques
            const statNumbers = document.querySelectorAll('.stat-info h3');
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
        });
    </script>
</body>
</html>
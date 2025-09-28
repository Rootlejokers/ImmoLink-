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

// Variables pour la pagination et le filtrage
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$items_per_page = 10;

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['reservation_id'])) {
        $reservation_id = (int)$_POST['reservation_id'];
        $action = $_POST['action'];
        
        try {
            // Vérifier que la réservation appartient bien à l'utilisateur
            $check_query = "SELECT id FROM visits WHERE id = :id AND user_id = :user_id";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':id', $reservation_id);
            $check_stmt->bindParam(':user_id', $user_id);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() === 1) {
                if ($action === 'cancel') {
                    $update_query = "UPDATE visits SET status = 'canceled', canceled_at = NOW() WHERE id = :id";
                    $message = "Réservation annulée avec succès";
                } elseif ($action === 'confirm') {
                    $update_query = "UPDATE visits SET status = 'confirmed' WHERE id = :id";
                    $message = "Réservation confirmée avec succès";
                }
                
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bindParam(':id', $reservation_id);
                $update_stmt->execute();
                
                $success = $message;
            } else {
                $error = "Réservation non trouvée ou accès non autorisé";
            }
        } catch (PDOException $e) {
            $error = "Erreur lors de la mise à jour: " . $e->getMessage();
        }
    }
}

// Construire la requête avec filtres
$where_conditions = ["v.user_id = :user_id"];
$params = [':user_id' => $user_id];

if (!empty($status_filter)) {
    $where_conditions[] = "v.status = :status";
    $params[':status'] = $status_filter;
}

if (!empty($search_query)) {
    $where_conditions[] = "(p.title LIKE :search OR p.address LIKE :search OR p.city LIKE :search)";
    $params[':search'] = "%$search_query%";
}

$where_clause = implode(' AND ', $where_conditions);

// Compter le nombre total de réservations
$count_query = "SELECT COUNT(*) as total FROM visits v 
                JOIN properties p ON v.property_id = p.id 
                WHERE $where_clause";
$count_stmt = $conn->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_reservations = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_reservations / $items_per_page);

// Limiter la page aux limites valides
$page = max(1, min($page, $total_pages));
$offset = ($page - 1) * $items_per_page;

// Récupérer les réservations
$query = "SELECT v.*, 
                 p.title as property_title,
                 p.address as property_address,
                 p.city as property_city,
                 p.price as property_price,
                 p.type as property_type,
                 u.first_name as owner_first_name,
                 u.last_name as owner_last_name,
                 u.phone as owner_phone,
                 u.email as owner_email,
                 (SELECT image_path FROM property_images WHERE property_id = p.id AND is_main = 1 LIMIT 1) as property_image
          FROM visits v
          JOIN properties p ON v.property_id = p.id
          JOIN users u ON p.owner_id = u.id
          WHERE $where_clause
          ORDER BY v.visit_date DESC, v.created_at DESC
          LIMIT :limit OFFSET :offset";

$stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les statistiques des réservations
$stats_query = "SELECT status, COUNT(*) as count FROM visits WHERE user_id = :user_id GROUP BY status";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bindParam(':user_id', $user_id);
$stats_stmt->execute();
$reservation_stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);

// Préparer les données pour les statistiques
$stats_data = [
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'canceled' => 0
];

foreach ($reservation_stats as $stat) {
    $stats_data[$stat['status']] = $stat['count'];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Réservations - ImmoLink</title>
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

        .btn-success {
            background-color: var(--success);
            color: white;
        }

        .btn-success:hover {
            background-color: #0da271;
        }

        .btn-warning {
            background-color: var(--warning);
            color: white;
        }

        .btn-warning:hover {
            background-color: #e58e0a;
        }

        .btn-danger {
            background-color: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background-color: #dc2626;
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

        .btn-sm {
            padding: 6px 12px;
            font-size: 14px;
        }

        /* Statistics */
        .stats-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background-color: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            text-align: center;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 24px;
        }

        .stat-icon.pending { background-color: rgba(100, 116, 139, 0.1); color: var(--secondary); }
        .stat-icon.confirmed { background-color: rgba(16, 185, 129, 0.1); color: var(--success); }
        .stat-icon.completed { background-color: rgba(37, 99, 235, 0.1); color: var(--primary); }
        .stat-icon.canceled { background-color: rgba(239, 68, 68, 0.1); color: var(--danger); }

        .stat-number {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-label {
            color: var(--secondary);
            font-size: 14px;
        }

        /* Filtres */
        .filters-section {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 20px;
            margin-bottom: 20px;
        }

        .filters-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 20px;
        }

        .filters-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .filter-group {
            margin-bottom: 15px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
            font-size: 14px;
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

        /* Réservations List */
        .reservations-list {
            display: grid;
            gap: 20px;
        }

        .reservation-card {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .reservation-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }

        .reservation-header {
            display: flex;
            justify-content: between;
            align-items: flex-start;
            padding: 20px;
            border-bottom: 1px solid var(--gray-light);
        }

        .reservation-info {
            flex: 1;
        }

        .reservation-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .reservation-address {
            color: var(--secondary);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 10px;
        }

        .reservation-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }

        .status-pending { background-color: rgba(100, 116, 139, 0.1); color: var(--secondary); }
        .status-confirmed { background-color: rgba(16, 185, 129, 0.1); color: var(--success); }
        .status-completed { background-color: rgba(37, 99, 235, 0.1); color: var(--primary); }
        .status-canceled { background-color: rgba(239, 68, 68, 0.1); color: var(--danger); }

        .reservation-image {
            width: 120px;
            height: 80px;
            border-radius: 8px;
            object-fit: cover;
            margin-left: 15px;
        }

        .reservation-body {
            padding: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .reservation-detail {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .detail-label {
            font-size: 12px;
            color: var(--secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
        }

        .reservation-actions {
            padding: 15px 20px;
            background-color: var(--light);
            border-top: 1px solid var(--gray-light);
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* Messages */
        .error-message {
            background-color: #fef2f2;
            color: var(--danger);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid var(--danger);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .success-message {
            background-color: #f0fdf4;
            color: var(--success);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid var(--success);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .error-message i,
        .success-message i {
            font-size: 18px;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 30px;
            padding: 20px;
        }

        .pagination-item {
            padding: 8px 15px;
            border: 1px solid var(--gray-light);
            border-radius: 6px;
            color: var(--dark);
            text-decoration: none;
            transition: all 0.3s;
        }

        .pagination-item:hover {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .pagination-item.active {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .pagination-item.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .empty-state i {
            font-size: 64px;
            color: var(--gray);
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 20px;
            color: var(--dark);
            margin-bottom: 10px;
        }

        .empty-state p {
            color: var(--secondary);
            margin-bottom: 20px;
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

            .reservation-body {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .stats-section {
                grid-template-columns: repeat(2, 1fr);
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .reservation-header {
                flex-direction: column;
                gap: 15px;
            }

            .reservation-image {
                margin-left: 0;
                width: 100%;
                height: 150px;
            }

            .reservation-actions {
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

            .stats-section {
                grid-template-columns: 1fr;
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
            <a href="reservations.php" class="nav-item active">
                <i class="fas fa-calendar-check"></i>
                <span>Réservations</span>
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
                <h1 class="dashboard-title">Mes Réservations</h1>
                <div class="breadcrumb">
                    <a href="index.php">Tableau de bord</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Réservations</span>
                </div>
            </div>
            <div>
                <a href="properties.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Nouvelle réservation
                </a>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="stats-section">
            <div class="stat-card">
                <div class="stat-icon pending">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-number"><?php echo $stats_data['pending']; ?></div>
                <div class="stat-label">En attente</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon confirmed">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-number"><?php echo $stats_data['confirmed']; ?></div>
                <div class="stat-label">Confirmées</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon completed">
                    <i class="fas fa-home"></i>
                </div>
                <div class="stat-number"><?php echo $stats_data['completed']; ?></div>
                <div class="stat-label">Terminées</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon canceled">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-number"><?php echo $stats_data['canceled']; ?></div>
                <div class="stat-label">Annulées</div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="filters-section">
            <div class="filters-header">
                <h2 class="filters-title">Filtrer les réservations</h2>
                <?php if (!empty($status_filter) || !empty($search_query)): ?>
                    <a href="reservations.php" class="btn btn-outline btn-sm">
                        <i class="fas fa-times"></i> Réinitialiser
                    </a>
                <?php endif; ?>
            </div>

            <form method="GET" action="reservations.php">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label>Recherche</label>
                        <input 
                            type="text" 
                            name="search" 
                            class="filter-input" 
                            placeholder="Propriété, adresse, ville..."
                            value="<?php echo htmlspecialchars($search_query); ?>"
                        >
                    </div>

                    <div class="filter-group">
                        <label>Statut</label>
                        <select name="status" class="filter-select">
                            <option value="">Tous les statuts</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>En attente</option>
                            <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmée</option>
                            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Terminée</option>
                            <option value="canceled" <?php echo $status_filter === 'canceled' ? 'selected' : ''; ?>>Annulée</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary" style="width: 100%">
                            <i class="fas fa-filter"></i> Appliquer les filtres
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Liste des réservations -->
        <div class="reservations-list">
            <?php if (!empty($reservations)): ?>
                <?php foreach ($reservations as $reservation): ?>
                    <div class="reservation-card">
                        <div class="reservation-header">
                            <div class="reservation-info">
                                <h3 class="reservation-title"><?php echo htmlspecialchars($reservation['property_title']); ?></h3>
                                <div class="reservation-address">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($reservation['property_address'] . ', ' . $reservation['property_city']); ?>
                                </div>
                                <span class="reservation-status status-<?php echo $reservation['status']; ?>">
                                    <?php 
                                    $status_labels = [
                                        'pending' => 'En attente de confirmation',
                                        'confirmed' => 'Confirmée',
                                        'completed' => 'Terminée',
                                        'canceled' => 'Annulée'
                                    ];
                                    echo $status_labels[$reservation['status']] ?? ucfirst($reservation['status']); 
                                    ?>
                                </span>
                            </div>
                            <img 
                                src="<?php echo $reservation['property_image'] ? '../../uploads/properties/' . htmlspecialchars($reservation['property_image']) : 'https://via.placeholder.com/300x200?text=ImmoLink'; ?>" 
                                alt="<?php echo htmlspecialchars($reservation['property_title']); ?>" 
                                class="reservation-image"
                            >
                        </div>

                        <div class="reservation-body">
                            <div class="reservation-detail">
                                <span class="detail-label">Date de visite</span>
                                <span class="detail-value">
                                    <?php echo date('d/m/Y à H:i', strtotime($reservation['visit_date'])); ?>
                                </span>
                            </div>
                            <div class="reservation-detail">
                                <span class="detail-label">Propriétaire</span>
                                <span class="detail-value">
                                    <?php echo htmlspecialchars($reservation['owner_first_name'] . ' ' . $reservation['owner_last_name']); ?>
                                </span>
                            </div>
                            <div class="reservation-detail">
                                <span class="detail-label">Contact</span>
                                <span class="detail-value">
                                    <?php echo htmlspecialchars($reservation['owner_phone']); ?>
                                </span>
                            </div>
                            <div class="reservation-detail">
                                <span class="detail-label">Prix</span>
                                <span class="detail-value">
                                    <?php echo formatPrice($reservation['property_price']); ?>
                                    <small class="property-type">
                                        <?php echo $reservation['property_type'] === 'location' ? '/mois' : ''; ?>
                                    </small>
                                </span>
                            </div>
                        </div>

                        <div class="reservation-actions">
                            <?php if ($reservation['status'] === 'pending'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="reservation_id" value="<?php echo $reservation['id']; ?>">
                                    <input type="hidden" name="action" value="confirm">
                                    <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Confirmer cette réservation ?')">
                                        <i class="fas fa-check"></i> Confirmer
                                    </button>
                                </form>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="reservation_id" value="<?php echo $reservation['id']; ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Annuler cette réservation ?')">
                                        <i class="fas fa-times"></i> Annuler
                                    </button>
                                </form>
                            <?php elseif ($reservation['status'] === 'confirmed'): ?>
                                <span class="btn btn-success btn-sm" style="opacity: 0.7;">
                                    <i class="fas fa-check"></i> Confirmée
                                </span>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="reservation_id" value="<?php echo $reservation['id']; ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Annuler cette réservation ?')">
                                        <i class="fas fa-times"></i> Annuler
                                    </button>
                                </form>
                            <?php elseif ($reservation['status'] === 'completed'): ?>
                                <span class="btn btn-primary btn-sm" style="opacity: 0.7;">
                                    <i class="fas fa-home"></i> Terminée
                                </span>
                            <?php elseif ($reservation['status'] === 'canceled'): ?>
                                <span class="btn btn-danger btn-sm" style="opacity: 0.7;">
                                    <i class="fas fa-times"></i> Annulée
                                </span>
                            <?php endif; ?>

                            <a href="messages.php?property=<?php echo $reservation['property_id']; ?>" class="btn btn-outline btn-sm">
                                <i class="fas fa-envelope"></i> Contacter
                            </a>
                            <a href="../../property-details.php?id=<?php echo $reservation['property_id']; ?>" class="btn btn-outline btn-sm">
                                <i class="fas fa-eye"></i> Voir le bien
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <h3>Aucune réservation trouvée</h3>
                    <p>
                        <?php if (!empty($status_filter) || !empty($search_query)): ?>
                            Aucune réservation ne correspond à vos critères de recherche.
                        <?php else: ?>
                            Vous n'avez pas encore de réservation de visite.
                        <?php endif; ?>
                    </p>
                    <a href="properties.php" class="btn btn-primary">
                        <i class="fas fa-search"></i> Parcourir les propriétés
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="pagination-item">
                        <i class="fas fa-angle-double-left"></i>
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="pagination-item">
                        <i class="fas fa-angle-left"></i>
                    </a>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($page + 2, $total_pages); $i++): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="pagination-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="pagination-item">
                        <i class="fas fa-angle-right"></i>
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="pagination-item">
                        <i class="fas fa-angle-double-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Filtres automatiques
            const filterSelects = document.querySelectorAll('.filter-select');
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });

            // Recherche avec délai
            let searchTimeout;
            const searchInput = document.querySelector('input[name="search"]');
            
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    this.form.submit();
                }, 800);
            });

            // Confirmation des actions
            const cancelButtons = document.querySelectorAll('form button[type="submit"]');
            cancelButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    const action = this.closest('form').querySelector('input[name="action"]').value;
                    const message = action === 'cancel' ? 
                        'Êtes-vous sûr de vouloir annuler cette réservation ?' :
                        'Êtes-vous sûr de vouloir confirmer cette réservation ?';
                    
                    if (!confirm(message)) {
                        e.preventDefault();
                    }
                });
            });
        });
    </script>
</body>
</html>
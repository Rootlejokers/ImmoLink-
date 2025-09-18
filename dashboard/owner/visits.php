<?php
session_start();
require_once '../../includes/config.php';
require_once '../../includes/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Vérifier si l'utilisateur est connecté et est un propriétaire
#if (!isLoggedIn() || !isOwner()) {
#    header('Location: ../../login.php');
#   exit();
#}

$user_id = $_SESSION['user_id'];
$db = new Database();
$conn = $db->getConnection();

// Paramètres de filtrage
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$property_filter = isset($_GET['property']) ? (int)$_GET['property'] : 0;
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';

// Récupérer les propriétés du propriétaire pour le filtre
$properties = [];
try {
    $query = "SELECT id, title FROM properties WHERE owner_id = :user_id ORDER BY title";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des propriétés: " . $e->getMessage());
}

// Construire la requête avec filtres
$where_conditions = ["v.property_id = p.id", "p.owner_id = :user_id"];
$params = [':user_id' => $user_id];

if ($status_filter !== 'all') {
    $where_conditions[] = "v.status = :status";
    $params[':status'] = $status_filter;
}

if ($property_filter > 0) {
    $where_conditions[] = "v.property_id = :property_id";
    $params[':property_id'] = $property_filter;
}

if (!empty($date_filter)) {
    $where_conditions[] = "DATE(v.visit_date) = :visit_date";
    $params[':visit_date'] = $date_filter;
}

$where_clause = implode(' AND ', $where_conditions);

// Récupérer les visites
$visits = [];
try {
    $query = "SELECT 
                v.*,
                p.title as property_title,
                p.address as property_address,
                p.city as property_city,
                t.first_name as tenant_first_name,
                t.last_name as tenant_last_name,
                t.phone as tenant_phone,
                t.email as tenant_email
              FROM visits v
              JOIN properties p ON v.property_id = p.id
              JOIN users t ON v.user_id = t.id
              WHERE $where_clause
              ORDER BY v.visit_date DESC";

    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des visites: " . $e->getMessage());
}

// Statistiques des visites
$stats = [
    'total' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'canceled' => 0,
    'completed' => 0
];

try {
    $query = "SELECT 
                status,
                COUNT(*) as count
              FROM visits v
              JOIN properties p ON v.property_id = p.id
              WHERE p.owner_id = :user_id
              GROUP BY status";

    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($results as $result) {
        $stats[$result['status']] = $result['count'];
        $stats['total'] += $result['count'];
    }

} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des statistiques: " . $e->getMessage());
}

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['visit_id'])) {
        $visit_id = (int)$_POST['visit_id'];
        $action = $_POST['action'];
        
        // Vérifier que la visite appartient bien au propriétaire
        try {
            $query = "SELECT v.* FROM visits v 
                      JOIN properties p ON v.property_id = p.id 
                      WHERE v.id = :visit_id AND p.owner_id = :user_id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':visit_id', $visit_id);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $visit = $stmt->fetch(PDO::FETCH_ASSOC);
                
                switch ($action) {
                    case 'confirm':
                        if ($visit['status'] === 'pending') {
                            $update_query = "UPDATE visits SET status = 'confirmed' WHERE id = :visit_id";
                            $stmt = $conn->prepare($update_query);
                            $stmt->bindParam(':visit_id', $visit_id);
                            $stmt->execute();
                        }
                        break;
                        
                    case 'cancel':
                        if (in_array($visit['status'], ['pending', 'confirmed'])) {
                            $update_query = "UPDATE visits SET status = 'canceled' WHERE id = :visit_id";
                            $stmt = $conn->prepare($update_query);
                            $stmt->bindParam(':visit_id', $visit_id);
                            $stmt->execute();
                        }
                        break;
                        
                    case 'complete':
                        if ($visit['status'] === 'confirmed') {
                            $update_query = "UPDATE visits SET status = 'completed' WHERE id = :visit_id";
                            $stmt = $conn->prepare($update_query);
                            $stmt->bindParam(':visit_id', $visit_id);
                            $stmt->execute();
                        }
                        break;
                        
                    case 'delete':
                        $delete_query = "DELETE FROM visits WHERE id = :visit_id";
                        $stmt = $conn->prepare($delete_query);
                        $stmt->bindParam(':visit_id', $visit_id);
                        $stmt->execute();
                        break;
                }
                
                // Rediriger pour éviter le re-soumission
                header("Location: visits.php?" . http_build_query($_GET));
                exit();
            }
        } catch (PDOException $e) {
            error_log("Erreur lors du traitement de l'action: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Visites - ImmoLink</title>
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

        .btn-sm {
            padding: 6px 12px;
            font-size: 14px;
        }

        .btn-success {
            background-color: var(--success);
            color: white;
        }

        .btn-warning {
            background-color: var(--warning);
            color: white;
        }

        .btn-danger {
            background-color: var(--danger);
            color: white;
        }

        /* Stats Grid */
        .stats-grid {
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
            cursor: pointer;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-card.active {
            border: 2px solid var(--primary);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 24px;
        }

        .stat-icon.total { background-color: rgba(37, 99, 235, 0.1); color: var(--primary); }
        .stat-icon.pending { background-color: rgba(100, 116, 139, 0.1); color: var(--secondary); }
        .stat-icon.confirmed { background-color: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .stat-icon.completed { background-color: rgba(16, 185, 129, 0.1); color: var(--success); }
        .stat-icon.canceled { background-color: rgba(239, 68, 68, 0.1); color: var(--danger); }

        .stat-info h3 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-info p {
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

        /* Visites List */
        .visits-container {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .visits-header {
            padding: 20px;
            border-bottom: 1px solid var(--gray-light);
        }

        .visits-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
        }

        .visits-list {
            max-height: 600px;
            overflow-y: auto;
        }

        .visit-item {
            padding: 20px;
            border-bottom: 1px solid var(--gray-light);
            transition: background-color 0.3s;
        }

        .visit-item:hover {
            background-color: var(--light);
        }

        .visit-item:last-child {
            border-bottom: none;
        }

        .visit-header {
            display: flex;
            justify-content: between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .visit-tenant {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .tenant-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        .tenant-info h4 {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 2px;
        }

        .tenant-info p {
            font-size: 14px;
            color: var(--secondary);
        }

        .visit-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-pending { background-color: rgba(100, 116, 139, 0.1); color: var(--secondary); }
        .status-confirmed { background-color: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .status-completed { background-color: rgba(16, 185, 129, 0.1); color: var(--success); }
        .status-canceled { background-color: rgba(239, 68, 68, 0.1); color: var(--danger); }

        .visit-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
            padding: 15px;
            background-color: var(--light);
            border-radius: 8px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 12px;
            color: var(--secondary);
            margin-bottom: 4px;
        }

        .detail-value {
            font-size: 14px;
            font-weight: 500;
            color: var(--dark);
        }

        .visit-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--secondary);
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            color: var(--gray);
        }

        .empty-state h3 {
            font-size: 20px;
            color: var(--dark);
            margin-bottom: 10px;
        }

        /* Modal de confirmation */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: white;
            margin: 15% auto;
            padding: 20px;
            border-radius: 12px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--gray-light);
        }

        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--secondary);
        }

        .modal-body {
            margin-bottom: 20px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
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
                grid-template-columns: repeat(2, 1fr);
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .visit-header {
                flex-direction: column;
                gap: 10px;
            }

            .visit-details {
                grid-template-columns: 1fr;
            }

            .visit-actions {
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

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .modal-content {
                margin: 20% auto;
                width: 95%;
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
            <a href="visits.php" class="nav-item active">
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
                <h1 class="dashboard-title">Gestion des Visites</h1>
                <div class="breadcrumb">
                    <a href="index.php">Tableau de bord</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Visites</span>
                </div>
            </div>
            <div>
                <a href="my-properties.php" class="btn btn-outline">
                    <i class="fas fa-building"></i> Mes biens
                </a>
            </div>
        </div>

        <!-- Statistiques -->
        <div class="stats-grid">
            <div class="stat-card <?php echo $status_filter === 'all' ? 'active' : ''; ?>" 
                 onclick="window.location.href='visits.php?status=all&property=<?php echo $property_filter; ?>&date=<?php echo $date_filter; ?>'">
                <div class="stat-icon total">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['total']; ?></h3>
                    <p>Total des visites</p>
                </div>
            </div>

            <div class="stat-card <?php echo $status_filter === 'pending' ? 'active' : ''; ?>" 
                 onclick="window.location.href='visits.php?status=pending&property=<?php echo $property_filter; ?>&date=<?php echo $date_filter; ?>'">
                <div class="stat-icon pending">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['pending']; ?></h3>
                    <p>En attente</p>
                </div>
            </div>

            <div class="stat-card <?php echo $status_filter === 'confirmed' ? 'active' : ''; ?>" 
                 onclick="window.location.href='visits.php?status=confirmed&property=<?php echo $property_filter; ?>&date=<?php echo $date_filter; ?>'">
                <div class="stat-icon confirmed">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['confirmed']; ?></h3>
                    <p>Confirmées</p>
                </div>
            </div>

            <div class="stat-card <?php echo $status_filter === 'completed' ? 'active' : ''; ?>" 
                 onclick="window.location.href='visits.php?status=completed&property=<?php echo $property_filter; ?>&date=<?php echo $date_filter; ?>'">
                <div class="stat-icon completed">
                    <i class="fas fa-home"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['completed']; ?></h3>
                    <p>Réalisées</p>
                </div>
            </div>

            <div class="stat-card <?php echo $status_filter === 'canceled' ? 'active' : ''; ?>" 
                 onclick="window.location.href='visits.php?status=canceled&property=<?php echo $property_filter; ?>&date=<?php echo $date_filter; ?>'">
                <div class="stat-icon canceled">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['canceled']; ?></h3>
                    <p>Annulées</p>
                </div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="filters-section">
            <div class="filters-header">
                <h2 class="filters-title">Filtres</h2>
                <?php if ($status_filter !== 'all' || $property_filter > 0 || !empty($date_filter)): ?>
                    <a href="visits.php" class="btn btn-outline btn-sm">
                        <i class="fas fa-times"></i> Réinitialiser
                    </a>
                <?php endif; ?>
            </div>

            <form method="GET" action="visits.php">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label>Statut</label>
                        <select name="status" class="filter-select">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>Tous les statuts</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>En attente</option>
                            <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmée</option>
                            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Réalisée</option>
                            <option value="canceled" <?php echo $status_filter === 'canceled' ? 'selected' : ''; ?>>Annulée</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Propriété</label>
                        <select name="property" class="filter-select">
                            <option value="0">Toutes les propriétés</option>
                            <?php foreach ($properties as $property): ?>
                                <option value="<?php echo $property['id']; ?>" 
                                    <?php echo $property_filter == $property['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($property['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Date</label>
                        <input 
                            type="date" 
                            name="date" 
                            class="filter-input" 
                            value="<?php echo htmlspecialchars($date_filter); ?>"
                        >
                    </div>

                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary" style="width: 100%">
                            <i class="fas fa-filter"></i> Appliquer
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Liste des visites -->
        <div class="visits-container">
            <div class="visits-header">
                <h2 class="visits-title">Liste des visites</h2>
            </div>

            <div class="visits-list">
                <?php if (!empty($visits)): ?>
                    <?php foreach ($visits as $visit): ?>
                        <div class="visit-item">
                            <div class="visit-header">
                                <div class="visit-tenant">
                                    <div class="tenant-avatar">
                                        <?php echo strtoupper(substr($visit['tenant_first_name'], 0, 1) . substr($visit['tenant_last_name'], 0, 1)); ?>
                                    </div>
                                    <div class="tenant-info">
                                        <h4><?php echo htmlspecialchars($visit['tenant_first_name'] . ' ' . $visit['tenant_last_name']); ?></h4>
                                        <p><?php echo htmlspecialchars($visit['tenant_email']); ?></p>
                                    </div>
                                </div>
                                <span class="visit-status status-<?php echo $visit['status']; ?>">
                                    <?php 
                                    $status_labels = [
                                        'pending' => 'En attente',
                                        'confirmed' => 'Confirmée',
                                        'completed' => 'Réalisée',
                                        'canceled' => 'Annulée'
                                    ];
                                    echo $status_labels[$visit['status']] ?? ucfirst($visit['status']); 
                                    ?>
                                </span>
                            </div>

                            <div class="visit-details">
                                <div class="detail-item">
                                    <span class="detail-label">Propriété</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($visit['property_title']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Adresse</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($visit['property_address'] . ', ' . $visit['property_city']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Date et heure</span>
                                    <span class="detail-value"><?php echo date('d/m/Y H:i', strtotime($visit['visit_date'])); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Téléphone</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($visit['tenant_phone']); ?></span>
                                </div>
                            </div>

                            <div class="visit-actions">
                                <?php if ($visit['status'] === 'pending'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="visit_id" value="<?php echo $visit['id']; ?>">
                                        <input type="hidden" name="action" value="confirm">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="fas fa-check"></i> Confirmer
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="visit_id" value="<?php echo $visit['id']; ?>">
                                        <input type="hidden" name="action" value="cancel">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="fas fa-times"></i> Refuser
                                        </button>
                                    </form>
                                <?php elseif ($visit['status'] === 'confirmed'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="visit_id" value="<?php echo $visit['id']; ?>">
                                        <input type="hidden" name="action" value="complete">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="fas fa-home"></i> Marquer comme réalisée
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="visit_id" value="<?php echo $visit['id']; ?>">
                                        <input type="hidden" name="action" value="cancel">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="fas fa-times"></i> Annuler
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <?php if (in_array($visit['status'], ['completed', 'canceled'])): ?>
                                    <form method="POST" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cette visite ?')" style="display: inline;">
                                        <input type="hidden" name="visit_id" value="<?php echo $visit['id']; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="fas fa-trash"></i> Supprimer
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <a href="messages.php?property=<?php echo $visit['property_id']; ?>" class="btn btn-outline btn-sm">
                                    <i class="fas fa-envelope"></i> Contacter
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>Aucune visite trouvée</h3>
                        <p>
                            <?php if ($status_filter !== 'all' || $property_filter > 0 || !empty($date_filter)): ?>
                                Aucune visite ne correspond à vos critères de recherche.
                            <?php else: ?>
                                Vous n'avez aucune visite programmée pour le moment.
                            <?php endif; ?>
                        </p>
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

            // Confirmation pour les actions sensibles
            const deleteForms = document.querySelectorAll('form[onsubmit]');
            deleteForms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    if (!confirm('Êtes-vous sûr de vouloir supprimer cette visite ?')) {
                        e.preventDefault();
                    }
                });
            });

            // Filtres automatiques
            const filterSelects = document.querySelectorAll('.filter-select');
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });
        });
    </script>
</body>
</html>
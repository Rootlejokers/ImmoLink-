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

// Variables pour la pagination et le filtrage
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$items_per_page = 10;

// Construire la requête avec filtres
$where_conditions = ["p.owner_id = :user_id"];
$params = [':user_id' => $user_id];

if (!empty($status_filter)) {
    $where_conditions[] = "p.status = :status";
    $params[':status'] = $status_filter;
}

if (!empty($type_filter)) {
    $where_conditions[] = "p.type = :type";
    $params[':type'] = $type_filter;
}

if (!empty($search_query)) {
    $where_conditions[] = "(p.title LIKE :search OR p.address LIKE :search OR p.city LIKE :search)";
    $params[':search'] = "%$search_query%";
}

$where_clause = implode(' AND ', $where_conditions);

// Compter le nombre total de propriétés
$count_query = "SELECT COUNT(*) as total FROM properties p WHERE $where_clause";
$count_stmt = $conn->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_properties = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_properties / $items_per_page);

// Limiter la page aux limites valides
$page = max(1, min($page, $total_pages));
$offset = ($page - 1) * $items_per_page;

// Récupérer les propriétés
$query = "SELECT p.*, 
                 c.name as category_name,
                 (SELECT image_path FROM property_images WHERE property_id = p.id AND is_main = 1 LIMIT 1) as main_image,
                 (SELECT COUNT(*) FROM property_images WHERE property_id = p.id) as images_count,
                 (SELECT COUNT(*) FROM favorites WHERE property_id = p.id) as favorites_count,
                 (SELECT COUNT(*) FROM messages m 
                  JOIN conversations c ON m.conversation_id = c.id 
                  WHERE c.property_id = p.id) as messages_count
          FROM properties p
          LEFT JOIN categories c ON p.category_id = c.id
          WHERE $where_clause
          ORDER BY p.created_at DESC
          LIMIT :limit OFFSET :offset";

$stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les statistiques pour les filtres
$stats_query = "SELECT status, COUNT(*) as count FROM properties WHERE owner_id = :user_id GROUP BY status";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bindParam(':user_id', $user_id);
$stats_stmt->execute();
$status_stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);

$type_stats_query = "SELECT type, COUNT(*) as count FROM properties WHERE owner_id = :user_id GROUP BY type";
$type_stats_stmt = $conn->prepare($type_stats_query);
$type_stats_stmt->bindParam(':user_id', $user_id);
$type_stats_stmt->execute();
$type_stats = $type_stats_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Propriétés - ImmoLink</title>
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

        .btn-sm {
            padding: 6px 12px;
            font-size: 14px;
        }

        /* Filtres et Recherche */
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

        .filter-stats {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 15px;
        }

        .stat-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .stat-badge.all {
            background-color: rgba(37, 99, 235, 0.1);
            color: var(--primary);
        }

        .stat-badge.available {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-badge.occupied {
            background-color: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-badge.sold {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        /* Properties Grid */
        .properties-grid {
            display: grid;
            gap: 20px;
        }

        .property-card {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .property-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }

        .property-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }

        .property-content {
            padding: 20px;
        }

        .property-header {
            display: flex;
            justify-content: between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .property-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .property-address {
            color: var(--secondary);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .property-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-available { background-color: rgba(16, 185, 129, 0.1); color: var(--success); }
        .status-occupied { background-color: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .status-sold { background-color: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .status-pending { background-color: rgba(100, 116, 139, 0.1); color: var(--secondary); }

        .property-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
            padding: 15px;
            background-color: var(--light);
            border-radius: 8px;
        }

        .detail-item {
            text-align: center;
        }

        .detail-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 2px;
        }

        .detail-label {
            font-size: 12px;
            color: var(--secondary);
        }

        .property-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            font-size: 14px;
            color: var(--secondary);
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .property-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
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
        }

        @media (max-width: 768px) {
            .filters-grid {
                grid-template-columns: 1fr;
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .property-header {
                flex-direction: column;
                gap: 10px;
            }

            .property-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .property-details {
                grid-template-columns: repeat(2, 1fr);
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

            .property-details {
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
            <a href="my-properties.php" class="nav-item active">
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
                <h1 class="dashboard-title">Mes Propriétés</h1>
                <div class="breadcrumb">
                    <a href="index.php">Tableau de bord</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Mes biens</span>
                </div>
            </div>
            <div>
                <a href="add-property.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Nouveau bien
                </a>
            </div>
        </div>

        <!-- Filtres et Recherche -->
        <div class="filters-section">
            <div class="filters-header">
                <h2 class="filters-title">Filtres et Recherche</h2>
                <?php if (!empty($status_filter) || !empty($type_filter) || !empty($search_query)): ?>
                    <a href="my-properties.php" class="btn btn-outline btn-sm">
                        <i class="fas fa-times"></i> Réinitialiser
                    </a>
                <?php endif; ?>
            </div>

            <form method="GET" action="my-properties.php">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label>Recherche</label>
                        <input 
                            type="text" 
                            name="search" 
                            class="filter-input" 
                            placeholder="Titre, adresse, ville..."
                            value="<?php echo htmlspecialchars($search_query); ?>"
                        >
                    </div>

                    <div class="filter-group">
                        <label>Statut</label>
                        <select name="status" class="filter-select">
                            <option value="">Tous les statuts</option>
                            <option value="available" <?php echo $status_filter === 'available' ? 'selected' : ''; ?>>Disponible</option>
                            <option value="occupied" <?php echo $status_filter === 'occupied' ? 'selected' : ''; ?>>Occupé/Loué</option>
                            <option value="sold" <?php echo $status_filter === 'sold' ? 'selected' : ''; ?>>Vendu</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>En attente</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Type</label>
                        <select name="type" class="filter-select">
                            <option value="">Tous les types</option>
                            <option value="location" <?php echo $type_filter === 'location' ? 'selected' : ''; ?>>Location</option>
                            <option value="vente" <?php echo $type_filter === 'vente' ? 'selected' : ''; ?>>Vente</option>
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

            <div class="filter-stats">
                <span class="stat-badge all">
                    <i class="fas fa-building"></i>
                    Total: <?php echo $total_properties; ?>
                </span>
                <?php foreach ($status_stats as $stat): ?>
                    <span class="stat-badge <?php echo $stat['status']; ?>">
                        <i class="fas fa-home"></i>
                        <?php 
                        $status_label = [
                            'available' => 'Disponibles',
                            'occupied' => 'Loués',
                            'sold' => 'Vendus',
                            'pending' => 'En attente'
                        ];
                        echo ($status_label[$stat['status']] ?? ucfirst($stat['status'])) . ': ' . $stat['count']; 
                        ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Liste des propriétés -->
        <?php if (!empty($properties)): ?>
            <div class="properties-grid">
                <?php foreach ($properties as $property): ?>
                    <div class="property-card">
                        <img 
                            src="<?php echo $property['main_image'] ? '../../uploads/properties/' . htmlspecialchars($property['main_image']) : 'https://via.placeholder.com/400x300?text=ImmoLink'; ?>" 
                            alt="<?php echo htmlspecialchars($property['title']); ?>" 
                            class="property-image"
                        >
                        
                        <div class="property-content">
                            <div class="property-header">
                                <div>
                                    <h3 class="property-title"><?php echo htmlspecialchars($property['title']); ?></h3>
                                    <div class="property-address">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <?php echo htmlspecialchars($property['address'] . ', ' . $property['city']); ?>
                                    </div>
                                </div>
                                <span class="property-status status-<?php echo $property['status']; ?>">
                                    <?php 
                                    $status_labels = [
                                        'available' => 'Disponible',
                                        'occupied' => 'Loué',
                                        'sold' => 'Vendu',
                                        'pending' => 'En attente'
                                    ];
                                    echo $status_labels[$property['status']] ?? ucfirst($property['status']); 
                                    ?>
                                </span>
                            </div>

                            <div class="property-details">
                                <div class="detail-item">
                                    <div class="detail-value"><?php echo formatPrice($property['price']); ?></div>
                                    <div class="detail-label">Prix</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-value"><?php echo $property['surface_area'] ?: '-'; ?> m²</div>
                                    <div class="detail-label">Surface</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-value"><?php echo $property['rooms'] ?: '-'; ?></div>
                                    <div class="detail-label">Pièces</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-value"><?php echo $property['bedrooms'] ?: '-'; ?></div>
                                    <div class="detail-label">Chambres</div>
                                </div>
                            </div>

                            <div class="property-meta">
                                <div class="meta-item">
                                    <i class="fas fa-images"></i>
                                    <?php echo $property['images_count']; ?> photos
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-heart"></i>
                                    <?php echo $property['favorites_count']; ?> favoris
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-envelope"></i>
                                    <?php echo $property['messages_count']; ?> messages
                                </div>
                            </div>

                            <div class="property-actions">
                                <a href="edit-property.php?id=<?php echo $property['id']; ?>" class="btn btn-outline btn-sm">
                                    <i class="fas fa-edit"></i> Modifier
                                </a>
                                <a href="../../property-details.php?id=<?php echo $property['id']; ?>" class="btn btn-outline btn-sm" target="_blank">
                                    <i class="fas fa-eye"></i> Voir
                                </a>
                                <a href="messages.php?property=<?php echo $property['id']; ?>" class="btn btn-outline btn-sm">
                                    <i class="fas fa-envelope"></i> Messages
                                </a>
                                <?php if ($property['status'] === 'available'): ?>
                                    <form action="update-status.php" method="POST" style="display: inline;">
                                        <input type="hidden" name="property_id" value="<?php echo $property['id']; ?>">
                                        <input type="hidden" name="status" value="occupied">
                                        <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Marquer comme loué ?')">
                                            <i class="fas fa-check"></i> Loué
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
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

        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-building"></i>
                <h3>Aucune propriété trouvée</h3>
                <p>
                    <?php if (!empty($status_filter) || !empty($type_filter) || !empty($search_query)): ?>
                        Aucune propriété ne correspond à vos critères de recherche.
                    <?php else: ?>
                        Vous n'avez pas encore ajouté de propriété.
                    <?php endif; ?>
                </p>
                <a href="add-property.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Ajouter votre première propriété
                </a>
            </div>
        <?php endif; ?>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Confirmation pour les actions sensibles
            const deleteButtons = document.querySelectorAll('.btn-danger');
            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    if (!confirm('Êtes-vous sûr de vouloir supprimer cette propriété ? Cette action est irréversible.')) {
                        e.preventDefault();
                    }
                });
            });

            // Filtres dynamiques
            const filterForm = document.querySelector('form');
            const filterSelects = filterForm.querySelectorAll('select');
            
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    filterForm.submit();
                });
            });

            // Recherche avec délai
            let searchTimeout;
            const searchInput = document.querySelector('input[name="search"]');
            
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    filterForm.submit();
                }, 500);
            });
        });
    </script>
</body>
</html>
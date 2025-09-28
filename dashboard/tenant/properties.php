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
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : '';
$min_price = isset($_GET['min_price']) ? (float)$_GET['min_price'] : '';
$max_price = isset($_GET['max_price']) ? (float)$_GET['max_price'] : '';
$city_filter = isset($_GET['city']) ? $_GET['city'] : '';
$rooms_filter = isset($_GET['rooms']) ? (int)$_GET['rooms'] : '';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$items_per_page = 12;

// Récupérer les catégories
$categories = [];
try {
    $query = "SELECT id, name FROM categories ORDER BY name";
    $stmt = $conn->query($query);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des catégories: " . $e->getMessage());
}

// Récupérer les villes disponibles
$cities = [];
try {
    $query = "SELECT DISTINCT city FROM properties WHERE status = 'available' ORDER BY city";
    $stmt = $conn->query($query);
    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des villes: " . $e->getMessage());
}

// Construire la requête avec filtres
$where_conditions = ["p.status = 'available'"];
$params = [];

if (!empty($type_filter)) {
    $where_conditions[] = "p.type = :type";
    $params[':type'] = $type_filter;
}

if (!empty($category_filter)) {
    $where_conditions[] = "p.category_id = :category_id";
    $params[':category_id'] = $category_filter;
}

if (!empty($min_price)) {
    $where_conditions[] = "p.price >= :min_price";
    $params[':min_price'] = $min_price;
}

if (!empty($max_price)) {
    $where_conditions[] = "p.price <= :max_price";
    $params[':max_price'] = $max_price;
}

if (!empty($city_filter)) {
    $where_conditions[] = "p.city LIKE :city";
    $params[':city'] = "%$city_filter%";
}

if (!empty($rooms_filter)) {
    $where_conditions[] = "p.rooms >= :rooms";
    $params[':rooms'] = $rooms_filter;
}

if (!empty($search_query)) {
    $where_conditions[] = "(p.title LIKE :search OR p.description LIKE :search OR p.address LIKE :search)";
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

// Récupérer les propriétés avec informations supplémentaires
$query = "SELECT p.*, 
                 c.name as category_name,
                 u.first_name as owner_first_name,
                 u.last_name as owner_last_name,
                 (SELECT image_path FROM property_images WHERE property_id = p.id AND is_main = 1 LIMIT 1) as main_image,
                 (SELECT COUNT(*) FROM property_images WHERE property_id = p.id) as images_count,
                 (SELECT COUNT(*) FROM favorites WHERE property_id = p.id AND user_id = :user_id) as is_favorite,
                 (SELECT COUNT(*) FROM favorites WHERE property_id = p.id) as favorites_count
          FROM properties p
          LEFT JOIN categories c ON p.category_id = c.id
          LEFT JOIN users u ON p.owner_id = u.id
          WHERE $where_clause
          ORDER BY p.created_at DESC
          LIMIT :limit OFFSET :offset";

$stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les favoris de l'utilisateur
$favorites = [];
try {
    $query = "SELECT property_id FROM favorites WHERE user_id = :user_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $favorites = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des favoris: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Propriétés Disponibles - ImmoLink</title>
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

        .btn-success {
            background-color: var(--success);
            color: white;
        }

        .btn-success:hover {
            background-color: #0da271;
        }

        .btn-sm {
            padding: 6px 12px;
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

        .price-range {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .filter-stats {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 15px;
            font-size: 14px;
            color: var(--secondary);
        }

        /* Properties Grid */
        .properties-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .property-card {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
            position: relative;
        }

        .property-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .property-badge {
            position: absolute;
            top: 15px;
            left: 15px;
            background-color: var(--primary);
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            z-index: 2;
        }

        .property-image {
            width: 100%;
            height: 220px;
            object-fit: cover;
            transition: transform 0.3s;
        }

        .property-card:hover .property-image {
            transform: scale(1.05);
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
            line-height: 1.3;
        }

        .property-address {
            color: var(--secondary);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .property-price {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 10px;
        }

        .property-type {
            font-size: 12px;
            color: var(--secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .property-details {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            padding: 15px;
            background-color: var(--light);
            border-radius: 8px;
            flex-wrap: wrap;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
            color: var(--secondary);
        }

        .detail-item i {
            color: var(--primary);
            width: 16px;
        }

        .property-owner {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            padding: 10px;
            background-color: rgba(37, 99, 235, 0.05);
            border-radius: 6px;
            font-size: 14px;
        }

        .owner-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 12px;
        }

        .property-actions {
            display: flex;
            gap: 10px;
        }

        .favorite-btn {
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            transition: all 0.3s;
            color: var(--gray);
        }

        .favorite-btn:hover {
            color: var(--danger);
            transform: scale(1.1);
        }

        .favorite-btn.active {
            color: var(--danger);
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
            grid-column: 1 / -1;
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
        @media (max-width: 1200px) {
            .properties-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
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

            .filters-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .properties-grid {
                grid-template-columns: 1fr;
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .price-range {
                grid-template-columns: 1fr;
            }

            .property-actions {
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
            <a href="properties.php" class="nav-item active">
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
                <h1 class="dashboard-title">Propriétés Disponibles</h1>
                <div class="breadcrumb">
                    <a href="index.php">Tableau de bord</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Propriétés</span>
                </div>
            </div>
            <div>
                <a href="favorites.php" class="btn btn-outline">
                    <i class="fas fa-heart"></i> Mes favoris (<?php echo count($favorites); ?>)
                </a>
            </div>
        </div>

        <!-- Filtres -->
        <div class="filters-section">
            <div class="filters-header">
                <h2 class="filters-title">Rechercher une propriété</h2>
                <?php if (!empty($type_filter) || !empty($category_filter) || !empty($search_query)): ?>
                    <a href="properties.php" class="btn btn-outline btn-sm">
                        <i class="fas fa-times"></i> Réinitialiser
                    </a>
                <?php endif; ?>
            </div>

            <form method="GET" action="properties.php">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label>Recherche</label>
                        <input 
                            type="text" 
                            name="search" 
                            class="filter-input" 
                            placeholder="Titre, description, adresse..."
                            value="<?php echo htmlspecialchars($search_query); ?>"
                        >
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
                        <label>Catégorie</label>
                        <select name="category" class="filter-select">
                            <option value="">Toutes les catégories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" 
                                    <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Ville</label>
                        <select name="city" class="filter-select">
                            <option value="">Toutes les villes</option>
                            <?php foreach ($cities as $city): ?>
                                <option value="<?php echo htmlspecialchars($city['city']); ?>" 
                                    <?php echo $city_filter === $city['city'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($city['city']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Prix</label>
                        <div class="price-range">
                            <input 
                                type="number" 
                                name="min_price" 
                                class="filter-input" 
                                placeholder="Min"
                                value="<?php echo $min_price ? htmlspecialchars($min_price) : ''; ?>"
                                min="0"
                            >
                            <input 
                                type="number" 
                                name="max_price" 
                                class="filter-input" 
                                placeholder="Max"
                                value="<?php echo $max_price ? htmlspecialchars($max_price) : ''; ?>"
                                min="0"
                            >
                        </div>
                    </div>

                    <div class="filter-group">
                        <label>Pièces minimum</label>
                        <select name="rooms" class="filter-select">
                            <option value="">Toutes</option>
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <option value="<?php echo $i; ?>" 
                                    <?php echo $rooms_filter == $i ? 'selected' : ''; ?>>
                                    <?php echo $i; ?> pièce<?php echo $i > 1 ? 's' : ''; ?>+
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary" style="width: 100%">
                            <i class="fas fa-search"></i> Rechercher
                        </button>
                    </div>
                </div>
            </form>

            <div class="filter-stats">
                <span><i class="fas fa-building"></i> <?php echo $total_properties; ?> propriété(s) trouvée(s)</span>
                <?php if (!empty($type_filter)): ?>
                    <span>• Type: <?php echo $type_filter === 'location' ? 'Location' : 'Vente'; ?></span>
                <?php endif; ?>
                <?php if (!empty($city_filter)): ?>
                    <span>• Ville: <?php echo htmlspecialchars($city_filter); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Liste des propriétés -->
        <div class="properties-grid">
            <?php if (!empty($properties)): ?>
                <?php foreach ($properties as $property): ?>
                    <div class="property-card">
                        <span class="property-badge">
                            <?php echo $property['type'] === 'location' ? 'À louer' : 'À vendre'; ?>
                        </span>
                        
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
                                <button class="favorite-btn <?php echo $property['is_favorite'] ? 'active' : ''; ?>" 
                                        data-property-id="<?php echo $property['id']; ?>">
                                    <i class="fas fa-heart"></i>
                                </button>
                            </div>

                            <div class="property-price">
                                <?php echo formatPrice($property['price']); ?>
                                <span class="property-type">
                                    <?php echo $property['type'] === 'location' ? '/mois' : ''; ?>
                                </span>
                            </div>

                            <div class="property-details">
                                <div class="detail-item">
                                    <i class="fas fa-ruler-combined"></i>
                                    <span><?php echo $property['surface_area'] ? $property['surface_area'] . ' m²' : '-'; ?></span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-door-open"></i>
                                    <span><?php echo $property['rooms'] ?: '-'; ?> pièces</span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-bed"></i>
                                    <span><?php echo $property['bedrooms'] ?: '-'; ?> ch.</span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-bath"></i>
                                    <span><?php echo $property['bathrooms'] ?: '-'; ?> sdb</span>
                                </div>
                            </div>

                            <?php if ($property['owner_first_name']): ?>
                                <div class="property-owner">
                                    <div class="owner-avatar">
                                        <?php echo strtoupper(substr($property['owner_first_name'], 0, 1) . substr($property['owner_last_name'], 0, 1)); ?>
                                    </div>
                                    <span>Propriétaire: <?php echo htmlspecialchars($property['owner_first_name'] . ' ' . $property['owner_last_name']); ?></span>
                                </div>
                            <?php endif; ?>

                            <div class="property-actions">
                                <a href="../../property-details.php?id=<?php echo $property['id']; ?>" class="btn btn-primary" style="flex: 1;">
                                    <i class="fas fa-eye"></i> Voir détails
                                </a>
                                <a href="messages.php?property=<?php echo $property['id']; ?>" class="btn btn-outline">
                                    <i class="fas fa-envelope"></i> Contacter
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-search"></i>
                    <h3>Aucune propriété trouvée</h3>
                    <p>
                        <?php if (!empty($type_filter) || !empty($category_filter) || !empty($search_query)): ?>
                            Aucune propriété ne correspond à vos critères de recherche. Essayez de modifier vos filtres.
                        <?php else: ?>
                            Aucune propriété n'est actuellement disponible.
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($type_filter) || !empty($category_filter) || !empty($search_query)): ?>
                        <a href="properties.php" class="btn btn-primary">
                            <i class="fas fa-times"></i> Réinitialiser les filtres
                        </a>
                    <?php endif; ?>
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
            // Gestion des favoris
            const favoriteButtons = document.querySelectorAll('.favorite-btn');
            
            favoriteButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const propertyId = this.getAttribute('data-property-id');
                    const isActive = this.classList.contains('active');
                    
                    // Animation visuelle immédiate
                    this.classList.toggle('active');
                    
                    // Envoyer la requête AJAX
                    const formData = new FormData();
                    formData.append('property_id', propertyId);
                    formData.append('action', isActive ? 'remove' : 'add');
                    
                    fetch('../../includes/favorites.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            // Revenir en arrière en cas d'erreur
                            this.classList.toggle('active');
                            alert('Erreur: ' + data.message);
                        } else {
                            // Mettre à jour le compteur des favoris
                            const favoritesCount = document.querySelector('.btn-outline .fa-heart').parentNode;
                            const currentCount = parseInt(favoritesCount.textContent.match(/\d+/)[0]);
                            const newCount = isActive ? currentCount - 1 : currentCount + 1;
                            favoritesCount.innerHTML = `<i class="fas fa-heart"></i> Mes favoris (${newCount})`;
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        this.classList.toggle('active');
                        alert('Une erreur est survenue');
                    });
                });
            });

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
        });
    </script>
</body>
</html>
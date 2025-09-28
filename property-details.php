<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Vérifier que l'ID de la propriété est fourni
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: properties.php');
    exit();
}

$property_id = (int)$_GET['id'];
$db = new Database();
$conn = $db->getConnection();

// Récupérer les détails de la propriété
$property = null;
try {
    $query = "SELECT p.*, 
                     u.first_name as owner_first_name, 
                     u.last_name as owner_last_name,
                     u.phone as owner_phone,
                     u.email as owner_email,
                     c.name as category_name,
                     (SELECT COUNT(*) FROM favorites WHERE property_id = p.id) as favorites_count
              FROM properties p
              LEFT JOIN users u ON p.owner_id = u.id
              LEFT JOIN categories c ON p.category_id = c.id
              WHERE p.id = :property_id AND p.status = 'available'";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':property_id', $property_id);
    $stmt->execute();
    $property = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur lors de la récupération de la propriété: " . $e->getMessage());
}

// Si la propriété n'existe pas ou n'est pas disponible, rediriger
if (!$property) {
    header('Location: properties.php');
    exit();
}

// Récupérer les images de la propriété
$images = [];
try {
    $query = "SELECT image_path, is_main FROM property_images 
              WHERE property_id = :property_id 
              ORDER BY is_main DESC, id ASC";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':property_id', $property_id);
    $stmt->execute();
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des images: " . $e->getMessage());
}

// Récupérer les propriétés similaires
$similar_properties = [];
try {
    $query = "SELECT p.*, 
                     (SELECT image_path FROM property_images WHERE property_id = p.id AND is_main = 1 LIMIT 1) as main_image
              FROM properties p
              WHERE p.status = 'available' 
                AND p.id != :property_id 
                AND (p.city = :city OR p.type = :type OR p.category_id = :category_id)
              ORDER BY RAND()
              LIMIT 4";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':property_id', $property_id);
    $stmt->bindParam(':city', $property['city']);
    $stmt->bindParam(':type', $property['type']);
    $stmt->bindParam(':category_id', $property['category_id']);
    $stmt->execute();
    $similar_properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des propriétés similaires: " . $e->getMessage());
}

// Vérifier si la propriété est dans les favoris de l'utilisateur
$is_favorite = false;
if (isLoggedIn()) {
    try {
        $query = "SELECT id FROM favorites WHERE property_id = :property_id AND user_id = :user_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':property_id', $property_id);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->execute();
        $is_favorite = $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Erreur lors de la vérification des favoris: " . $e->getMessage());
    }
}

// Incrémenter le compteur de vues
try {
    $query = "UPDATE properties SET views = COALESCE(views, 0) + 1 WHERE id = :property_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':property_id', $property_id);
    $stmt->execute();
} catch (PDOException $e) {
    error_log("Erreur lors de l'incrémentation des vues: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($property['title']); ?> - ImmoLink</title>
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
        }

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Header */
        header {
            background-color: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
        }

        .logo {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
        }

        .logo i {
            margin-right: 10px;
        }

        .nav-links {
            display: flex;
            gap: 25px;
        }

        .nav-links a {
            text-decoration: none;
            color: var(--secondary);
            font-weight: 500;
            transition: color 0.3s;
        }

        .nav-links a:hover {
            color: var(--primary);
        }

        .auth-buttons {
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

        .btn-lg {
            padding: 15px 30px;
            font-size: 16px;
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 20px 0;
            color: var(--secondary);
            font-size: 14px;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        /* Property Gallery */
        .property-gallery {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 10px;
            margin-bottom: 30px;
            height: 500px;
        }

        .main-image {
            border-radius: 12px;
            overflow: hidden;
            position: relative;
        }

        .main-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .thumbnail-grid {
            display: grid;
            grid-template-rows: 1fr 1fr;
            gap: 10px;
        }

        .thumbnail {
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            position: relative;
        }

        .thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s;
        }

        .thumbnail:hover img {
            transform: scale(1.05);
        }

        .thumbnail-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(transparent, rgba(0,0,0,0.7));
            color: white;
            padding: 10px;
            text-align: center;
            font-size: 14px;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .thumbnail:hover .thumbnail-overlay {
            opacity: 1;
        }

        /* Property Header */
        .property-header {
            background-color: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .property-title-section {
            display: flex;
            justify-content: between;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .property-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 10px;
        }

        .property-address {
            color: var(--secondary);
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .property-badge {
            background-color: var(--primary);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }

        .property-price {
            font-size: 32px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 20px;
        }

        .property-meta {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--secondary);
        }

        .meta-item i {
            color: var(--primary);
        }

        .property-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        /* Property Details Grid */
        .property-details-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 50px;
        }

        /* Main Content */
        .main-content {
            background-color: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .section-title {
            font-size: 22px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--light);
        }

        .property-description {
            line-height: 1.8;
            margin-bottom: 30px;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background-color: var(--light);
            border-radius: 8px;
        }

        .feature-item i {
            color: var(--primary);
            width: 20px;
        }

        /* Sidebar */
        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .owner-card, .contact-card {
            background-color: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .owner-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .owner-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 20px;
        }

        .owner-info h3 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .owner-info p {
            color: var(--secondary);
            font-size: 14px;
        }

        .contact-info {
            display: grid;
            gap: 10px;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background-color: var(--light);
            border-radius: 6px;
        }

        .contact-item i {
            color: var(--primary);
            width: 20px;
        }

        /* Similar Properties */
        .similar-properties {
            margin-bottom: 50px;
        }

        .properties-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
        }

        .property-card {
            background-color: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .property-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .property-card-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }

        .property-card-content {
            padding: 20px;
        }

        .property-card-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--dark);
        }

        .property-card-price {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 10px;
        }

        .property-card-address {
            color: var(--secondary);
            font-size: 14px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .property-card-features {
            display: flex;
            gap: 15px;
            color: var(--secondary);
            font-size: 14px;
        }

        /* Footer */
        footer {
            background-color: var(--dark);
            color: white;
            padding: 50px 0 20px;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            margin-bottom: 40px;
        }

        .footer-column h3 {
            font-size: 18px;
            margin-bottom: 20px;
            position: relative;
            display: inline-block;
        }

        .footer-column h3::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 0;
            width: 40px;
            height: 3px;
            background-color: var(--primary);
        }

        .footer-column ul {
            list-style: none;
        }

        .footer-column ul li {
            margin-bottom: 10px;
        }

        .footer-column a {
            color: var(--gray);
            text-decoration: none;
            transition: color 0.3s;
        }

        .footer-column a:hover {
            color: white;
        }

        .social-links {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .social-links a {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transition: all 0.3s;
        }

        .social-links a:hover {
            background-color: var(--primary);
            transform: translateY(-3px);
        }

        .copyright {
            text-align: center;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--gray);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.9);
            animation: fadeIn 0.3s;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            position: relative;
            margin: auto;
            width: 90%;
            max-width: 1200px;
            height: 90vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-image {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .close-modal {
            position: absolute;
            top: 20px;
            right: 30px;
            color: white;
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
            z-index: 2001;
        }

        .modal-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 15px;
            cursor: pointer;
            font-size: 24px;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-prev {
            left: 30px;
        }

        .modal-next {
            right: 30px;
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .property-gallery {
                grid-template-columns: 1fr;
                height: auto;
            }

            .thumbnail-grid {
                grid-template-columns: repeat(2, 1fr);
                grid-template-rows: auto;
            }

            .property-details-grid {
                grid-template-columns: 1fr;
            }

            .property-title-section {
                flex-direction: column;
                gap: 15px;
            }

            .properties-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .navbar {
                flex-direction: column;
                gap: 15px;
            }

            .nav-links {
                gap: 15px;
                flex-wrap: wrap;
                justify-content: center;
            }

            .auth-buttons {
                flex-direction: column;
                width: 100%;
            }

            .property-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }

            .footer-content {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .footer-column h3::after {
                left: 50%;
                transform: translateX(-50%);
            }
        }

        @media (max-width: 576px) {
            .property-meta {
                flex-direction: column;
                gap: 10px;
            }

            .property-gallery {
                height: 300px;
            }

            .thumbnail-grid {
                display: none;
            }

            .modal-nav {
                padding: 10px;
                width: 40px;
                height: 40px;
                font-size: 18px;
            }

            .modal-prev {
                left: 10px;
            }

            .modal-next {
                right: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="container">
            <div class="navbar">
                <a href="index.php" class="logo">
                    <i class="fas fa-home"></i>
                    ImmoLink
                </a>
                <div class="nav-links">
                    <a href="index.php">Accueil</a>
                    <a href="properties.php">Propriétés</a>
                    <a href="about.php">À propos</a>
                    <a href="contact.php">Contact</a>
                </div>
                <div class="auth-buttons">
                    <?php if (isLoggedIn()): ?>
                        <a href="dashboard/<?php echo getUserType(); ?>/" class="btn btn-outline">
                            <i class="fas fa-tachometer-alt"></i> Tableau de bord
                        </a>
                        <a href="logout.php" class="btn btn-primary">
                            <i class="fas fa-sign-out-alt"></i> Déconnexion
                        </a>
                    <?php else: ?>
                        <a href="login.php" class="btn btn-outline">Connexion</a>
                        <a href="register.php" class="btn btn-primary">Inscription</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- Breadcrumb -->
    <div class="container">
        <div class="breadcrumb">
            <a href="index.php">Accueil</a>
            <i class="fas fa-chevron-right"></i>
            <a href="properties.php">Propriétés</a>
            <i class="fas fa-chevron-right"></i>
            <span><?php echo htmlspecialchars($property['title']); ?></span>
        </div>
    </div>

    <!-- Property Gallery -->
    <div class="container">
        <div class="property-gallery">
            <div class="main-image" id="mainImage">
                <?php if (!empty($images)): ?>
                    <img src="uploads/properties/<?php echo htmlspecialchars($images[0]['image_path']); ?>" 
                         alt="<?php echo htmlspecialchars($property['title']); ?>" 
                         id="currentImage">
                <?php else: ?>
                    <img src="https://via.placeholder.com/800x500?text=ImmoLink" 
                         alt="<?php echo htmlspecialchars($property['title']); ?>">
                <?php endif; ?>
            </div>
            
            <?php if (count($images) > 1): ?>
                <div class="thumbnail-grid">
                    <?php for ($i = 1; $i < min(5, count($images)); $i++): ?>
                        <div class="thumbnail" onclick="changeImage(<?php echo $i; ?>)">
                            <img src="uploads/properties/<?php echo htmlspecialchars($images[$i]['image_path']); ?>" 
                                 alt="Image <?php echo $i + 1; ?>">
                            <div class="thumbnail-overlay">Voir</div>
                        </div>
                    <?php endfor; ?>
                    <?php if (count($images) > 5): ?>
                        <div class="thumbnail" onclick="openModal()">
                            <img src="uploads/properties/<?php echo htmlspecialchars($images[4]['image_path']); ?>" 
                                 alt="Voir toutes les images">
                            <div class="thumbnail-overlay">+<?php echo count($images) - 5; ?> plus</div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Property Header -->
    <div class="container">
        <div class="property-header">
            <div class="property-title-section">
                <div>
                    <h1 class="property-title"><?php echo htmlspecialchars($property['title']); ?></h1>
                    <div class="property-address">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($property['address'] . ', ' . $property['city'] . ', ' . $property['country']); ?>
                    </div>
                </div>
                <span class="property-badge">
                    <?php echo $property['type'] === 'location' ? 'À louer' : 'À vendre'; ?>
                </span>
            </div>

            <div class="property-price">
                <?php echo formatPrice($property['price']); ?>
                <?php if ($property['type'] === 'location'): ?>
                    <span style="font-size: 16px; color: var(--secondary);">/mois</span>
                <?php endif; ?>
            </div>

            <div class="property-meta">
                <div class="meta-item">
                    <i class="fas fa-eye"></i>
                    <span><?php echo $property['views'] ?: '0'; ?> vues</span>
                </div>
                <div class="meta-item">
                    <i class="fas fa-heart"></i>
                    <span><?php echo $property['favorites_count']; ?> favoris</span>
                </div>
                <div class="meta-item">
                    <i class="fas fa-calendar"></i>
                    <span>Publié le <?php echo date('d/m/Y', strtotime($property['created_at'])); ?></span>
                </div>
                <?php if ($property['category_name']): ?>
                    <div class="meta-item">
                        <i class="fas fa-tag"></i>
                        <span><?php echo htmlspecialchars($property['category_name']); ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="property-actions">
                <?php if (isLoggedIn() && isTenant()): ?>
                    <a href="dashboard/tenant/messages.php?property=<?php echo $property_id; ?>" class="btn btn-primary btn-lg">
                        <i class="fas fa-envelope"></i> Contacter le propriétaire
                    </a>
                    <button class="btn btn-outline btn-lg" id="favoriteBtn">
                        <i class="fas fa-heart"></i>
                        <span id="favoriteText"><?php echo $is_favorite ? 'Retirer des favoris' : 'Ajouter aux favoris'; ?></span>
                    </button>
                <?php elseif (isLoggedIn() && isOwner() && $property['owner_id'] == $_SESSION['user_id']): ?>
                    <a href="dashboard/owner/edit-property.php?id=<?php echo $property_id; ?>" class="btn btn-primary btn-lg">
                        <i class="fas fa-edit"></i> Modifier la propriété
                    </a>
                <?php else: ?>
                    <a href="login.php?redirect=property-details.php?id=<?php echo $property_id; ?>" class="btn btn-primary btn-lg">
                        <i class="fas fa-envelope"></i> Se connecter pour contacter
                    </a>
                    <a href="login.php?redirect=property-details.php?id=<?php echo $property_id; ?>" class="btn btn-outline btn-lg">
                        <i class="fas fa-heart"></i> Ajouter aux favoris
                    </a>
                <?php endif; ?>
                <button class="btn btn-success btn-lg" onclick="window.print()">
                    <i class="fas fa-print"></i> Imprimer
                </button>
            </div>
        </div>
    </div>

    <!-- Property Details -->
    <div class="container">
        <div class="property-details-grid">
            <!-- Main Content -->
            <div class="main-content">
                <h2 class="section-title">Description</h2>
                <div class="property-description">
                    <?php echo nl2br(htmlspecialchars($property['description'])); ?>
                </div>

                <h2 class="section-title">Caractéristiques</h2>
                <div class="features-grid">
                    <?php if ($property['surface_area']): ?>
                        <div class="feature-item">
                            <i class="fas fa-ruler-combined"></i>
                            <span>Surface: <?php echo $property['surface_area']; ?> m²</span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($property['rooms']): ?>
                        <div class="feature-item">
                            <i class="fas fa-door-open"></i>
                            <span><?php echo $property['rooms']; ?> pièces</span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($property['bedrooms']): ?>
                        <div class="feature-item">
                            <i class="fas fa-bed"></i>
                            <span><?php echo $property['bedrooms']; ?> chambres</span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($property['bathrooms']): ?>
                        <div class="feature-item">
                            <i class="fas fa-bath"></i>
                            <span><?php echo $property['bathrooms']; ?> salles de bain</span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="feature-item">
                        <i class="fas fa-home"></i>
                        <span>Type: <?php echo $property['type'] === 'location' ? 'Location' : 'Vente'; ?></span>
                    </div>
                    
                    <div class="feature-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <span>Ville: <?php echo htmlspecialchars($property['city']); ?></span>
                    </div>
                    
                    <?php if ($property['postal_code']): ?>
                        <div class="feature-item">
                            <i class="fas fa-mail-bulk"></i>
                            <span>Code postal: <?php echo htmlspecialchars($property['postal_code']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($property['latitude'] && $property['longitude']): ?>
                    <h2 class="section-title">Localisation</h2>
                    <div id="map" style="height: 300px; border-radius: 8px; background-color: var(--light); display: flex; align-items: center; justify-content: center;">
                        <p>Carte interactive (à intégrer avec Google Maps)</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="sidebar">
                <div class="owner-card">
                    <div class="owner-header">
                        <div class="owner-avatar">
                            <?php echo strtoupper(substr($property['owner_first_name'], 0, 1) . substr($property['owner_last_name'], 0, 1)); ?>
                        </div>
                        <div class="owner-info">
                            <h3><?php echo htmlspecialchars($property['owner_first_name'] . ' ' . $property['owner_last_name']); ?></h3>
                            <p>Propriétaire</p>
                        </div>
                    </div>
                    <div class="contact-info">
                        <?php if ($property['owner_phone']): ?>
                            <div class="contact-item">
                                <i class="fas fa-phone"></i>
                                <span><?php echo htmlspecialchars($property['owner_phone']); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isLoggedIn()): ?>
                            <div class="contact-item">
                                <i class="fas fa-envelope"></i>
                                <span><?php echo htmlspecialchars($property['owner_email']); ?></span>
                            </div>
                        <?php else: ?>
                            <div class="contact-item">
                                <i class="fas fa-envelope"></i>
                                <span>Connectez-vous pour voir l'email</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="contact-card">
                    <h3 style="margin-bottom: 15px;">Visite</h3>
                    <p style="margin-bottom: 15px; color: var(--secondary);">
                        Intéressé par cette propriété ? Planifiez une visite dès maintenant.
                    </p>
                    <?php if (isLoggedIn() && isTenant()): ?>
                        <a href="dashboard/tenant/messages.php?property=<?php echo $property_id; ?>" class="btn btn-primary" style="width: 100%;">
                            <i class="fas fa-calendar-check"></i> Demander une visite
                        </a>
                    <?php else: ?>
                        <a href="login.php?redirect=property-details.php?id=<?php echo $property_id; ?>" class="btn btn-primary" style="width: 100%;">
                            <i class="fas fa-calendar-check"></i> Se connecter pour planifier
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Similar Properties -->
    <?php if (!empty($similar_properties)): ?>
        <div class="container">
            <div class="similar-properties">
                <h2 class="section-title">Propriétés similaires</h2>
                <div class="properties-grid">
                    <?php foreach ($similar_properties as $similar): ?>
                        <a href="property-details.php?id=<?php echo $similar['id']; ?>" class="property-card">
                            <img src="<?php echo $similar['main_image'] ? 'uploads/properties/' . htmlspecialchars($similar['main_image']) : 'https://via.placeholder.com/400x300?text=ImmoLink'; ?>" 
                                 alt="<?php echo htmlspecialchars($similar['title']); ?>" 
                                 class="property-card-image">
                            <div class="property-card-content">
                                <h3 class="property-card-title"><?php echo htmlspecialchars($similar['title']); ?></h3>
                                <div class="property-card-price">
                                    <?php echo formatPrice($similar['price']); ?>
                                    <?php if ($similar['type'] === 'location'): ?>
                                        <span style="font-size: 14px; color: var(--secondary);">/mois</span>
                                    <?php endif; ?>
                                </div>
                                <div class="property-card-address">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($similar['address'] . ', ' . $similar['city']); ?>
                                </div>
                                <div class="property-card-features">
                                    <?php if ($similar['surface_area']): ?>
                                        <span><?php echo $similar['surface_area']; ?> m²</span>
                                    <?php endif; ?>
                                    <?php if ($similar['rooms']): ?>
                                        <span><?php echo $similar['rooms']; ?> pièces</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Image Modal -->
    <div id="imageModal" class="modal">
        <span class="close-modal" onclick="closeModal()">&times;</span>
        <button class="modal-nav modal-prev" onclick="changeModalImage(-1)">❮</button>
        <button class="modal-nav modal-next" onclick="changeModalImage(1)">❯</button>
        <div class="modal-content">
            <img class="modal-image" id="modalImage" src="" alt="">
        </div>
    </div>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="footer-column">
                    <h3>ImmoLink</h3>
                    <p>La plateforme immobilière moderne pour acheter, vendre et louer des biens en toute simplicité.</p>
                    <div class="social-links">
                        <a href="#"><i class="fab fa-facebook-f"></i></a>
                        <a href="#"><i class="fab fa-twitter"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-linkedin-in"></i></a>
                    </div>
                </div>
                <div class="footer-column">
                    <h3>Liens rapides</h3>
                    <ul>
                        <li><a href="index.php">Accueil</a></li>
                        <li><a href="properties.php">Propriétés</a></li>
                        <li><a href="about.php">À propos</a></li>
                        <li><a href="contact.php">Contact</a></li>
                    </ul>
                </div>
                <div class="footer-column">
                    <h3>Légal</h3>
                    <ul>
                        <li><a href="terms.php">Conditions d'utilisation</a></li>
                        <li><a href="privacy.php">Politique de confidentialité</a></li>
                        <li><a href="legal.php">Mentions légales</a></li>
                    </ul>
                </div>
                <div class="footer-column">
                    <h3>Contact</h3>
                    <ul>
                        <li><i class="fas fa-envelope"></i> contact@immolink.cm</li>
                        <li><i class="fas fa-phone"></i> +237 6 99 71 46 82</li>
                        <li><i class="fas fa-map-marker-alt"></i> Yaoundé, Cameroun</li>
                    </ul>
                </div>
            </div>
            <div class="copyright">
                &copy; 2025 ImmoLink - Tous droits réservés
            </div>
        </div>
    </footer>

    <script>
        // Gestion de la galerie d'images
        const images = <?php echo json_encode($images); ?>;
        let currentImageIndex = 0;
        let currentModalIndex = 0;

        function changeImage(index) {
            if (images[index]) {
                document.getElementById('currentImage').src = 'uploads/properties/' + images[index].image_path;
                currentImageIndex = index;
            }
        }

        function openModal() {
            currentModalIndex = currentImageIndex;
            document.getElementById('modalImage').src = 'uploads/properties/' + images[currentModalIndex].image_path;
            document.getElementById('imageModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            document.getElementById('imageModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function changeModalImage(direction) {
            currentModalIndex += direction;
            if (currentModalIndex >= images.length) {
                currentModalIndex = 0;
            } else if (currentModalIndex < 0) {
                currentModalIndex = images.length - 1;
            }
            document.getElementById('modalImage').src = 'uploads/properties/' + images[currentModalIndex].image_path;
        }

        // Fermer la modal avec la touche Échap
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Gestion des favoris
        <?php if (isLoggedIn()): ?>
        document.getElementById('favoriteBtn').addEventListener('click', function() {
            const isCurrentlyFavorite = <?php echo $is_favorite ? 'true' : 'false'; ?>;
            const formData = new FormData();
            formData.append('property_id', <?php echo $property_id; ?>);
            formData.append('action', isCurrentlyFavorite ? 'remove' : 'add');
            
            fetch('includes/favorites.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const favoriteText = document.getElementById('favoriteText');
                    const favoriteBtn = document.getElementById('favoriteBtn');
                    
                    if (isCurrentlyFavorite) {
                        favoriteText.textContent = 'Ajouter aux favoris';
                        favoriteBtn.classList.remove('btn-primary');
                        favoriteBtn.classList.add('btn-outline');
                    } else {
                        favoriteText.textContent = 'Retirer des favoris';
                        favoriteBtn.classList.remove('btn-outline');
                        favoriteBtn.classList.add('btn-primary');
                    }
                    
                    // Mettre à jour le compteur des favoris
                    const favoriteCount = document.querySelector('.fa-heart').parentNode.querySelector('span');
                    const currentCount = parseInt(favoriteCount.textContent);
                    favoriteCount.textContent = isCurrentlyFavorite ? currentCount - 1 : currentCount + 1;
                } else {
                    alert('Erreur: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Une erreur est survenue');
            });
        });
        <?php endif; ?>

        // Partage sur les réseaux sociaux
        function shareOnFacebook() {
            const url = encodeURIComponent(window.location.href);
            window.open(`https://www.facebook.com/sharer/sharer.php?u=${url}`, '_blank');
        }

        function shareOnTwitter() {
            const text = encodeURIComponent('Découvrez cette propriété sur ImmoLink: ' + document.title);
            const url = encodeURIComponent(window.location.href);
            window.open(`https://twitter.com/intent/tweet?text=${text}&url=${url}`, '_blank');
        }
    </script>
</body>
</html>
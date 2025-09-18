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

$user_id = $_SESSION['user_id'];
$db = new Database();
$conn = $db->getConnection();

// Récupérer les catégories
$categories = [];
try {
    $query = "SELECT id, name FROM categories ORDER BY name";
    $stmt = $conn->query($query);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des catégories: " . $e->getMessage());
}

// Variables pour stocker les données du formulaire
$formData = [
    'title' => '',
    'description' => '',
    'price' => '',
    'type' => 'location',
    'category_id' => '',
    'surface_area' => '',
    'rooms' => '',
    'bedrooms' => '',
    'bathrooms' => '',
    'address' => '',
    'city' => '',
    'postal_code' => '',
    'country' => 'Cameroun'
];

$error = '';
$success = '';

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupération et validation des données
    $formData['title'] = trim($_POST['title']);
    $formData['description'] = trim($_POST['description']);
    $formData['price'] = trim($_POST['price']);
    $formData['type'] = $_POST['type'];
    $formData['category_id'] = $_POST['category_id'];
    $formData['surface_area'] = trim($_POST['surface_area']);
    $formData['rooms'] = $_POST['rooms'];
    $formData['bedrooms'] = $_POST['bedrooms'];
    $formData['bathrooms'] = $_POST['bathrooms'];
    $formData['address'] = trim($_POST['address']);
    $formData['city'] = trim($_POST['city']);
    $formData['postal_code'] = trim($_POST['postal_code']);
    $formData['country'] = trim($_POST['country']);

    // Validation
    if (empty($formData['title']) || empty($formData['description']) || 
        empty($formData['price']) || empty($formData['address']) || 
        empty($formData['city']) || empty($formData['postal_code'])) {
        $error = 'Veuillez remplir tous les champs obligatoires.';
    } elseif (!is_numeric($formData['price']) || $formData['price'] <= 0) {
        $error = 'Le prix doit être un nombre positif.';
    } elseif (!empty($formData['surface_area']) && (!is_numeric($formData['surface_area']) || $formData['surface_area'] <= 0)) {
        $error = 'La surface doit être un nombre positif.';
    } else {
        try {
            // Commencer une transaction
            $conn->beginTransaction();

            // Insérer la propriété
            $query = "INSERT INTO properties (
                title, description, price, type, category_id, surface_area, 
                rooms, bedrooms, bathrooms, address, city, postal_code, country, owner_id
            ) VALUES (
                :title, :description, :price, :type, :category_id, :surface_area, 
                :rooms, :bedrooms, :bathrooms, :address, :city, :postal_code, :country, :owner_id
            )";

            $stmt = $conn->prepare($query);
            $stmt->bindParam(':title', $formData['title']);
            $stmt->bindParam(':description', $formData['description']);
            $stmt->bindParam(':price', $formData['price']);
            $stmt->bindParam(':type', $formData['type']);
            $stmt->bindParam(':category_id', $formData['category_id'], PDO::PARAM_INT);
            $stmt->bindParam(':surface_area', $formData['surface_area']);
            $stmt->bindParam(':rooms', $formData['rooms'], PDO::PARAM_INT);
            $stmt->bindParam(':bedrooms', $formData['bedrooms'], PDO::PARAM_INT);
            $stmt->bindParam(':bathrooms', $formData['bathrooms'], PDO::PARAM_INT);
            $stmt->bindParam(':address', $formData['address']);
            $stmt->bindParam(':city', $formData['city']);
            $stmt->bindParam(':postal_code', $formData['postal_code']);
            $stmt->bindParam(':country', $formData['country']);
            $stmt->bindParam(':owner_id', $user_id, PDO::PARAM_INT);

            if ($stmt->execute()) {
                $property_id = $conn->lastInsertId();

                // Traitement des images
                if (!empty($_FILES['images']['name'][0])) {
                    $upload_dir = '../../uploads/properties/' . $property_id . '/';
                    
                    // Créer le dossier s'il n'existe pas
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }

                    $main_image_set = false;

                    foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                        if ($_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                            $file_name = uniqid() . '_' . basename($_FILES['images']['name'][$key]);
                            $file_path = $upload_dir . $file_name;

                            // Vérifier le type de fichier
                            $file_type = mime_content_type($tmp_name);
                            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                            
                            if (in_array($file_type, $allowed_types) && move_uploaded_file($tmp_name, $file_path)) {
                                // Insérer l'image dans la base de données
                                $image_query = "INSERT INTO property_images (property_id, image_path, is_main) 
                                              VALUES (:property_id, :image_path, :is_main)";
                                $image_stmt = $conn->prepare($image_query);
                                $is_main = !$main_image_set ? 1 : 0;
                                $main_image_set = $main_image_set || $is_main;
                                
                                $relative_path = $property_id . '/' . $file_name;
                                $image_stmt->bindParam(':property_id', $property_id, PDO::PARAM_INT);
                                $image_stmt->bindParam(':image_path', $relative_path);
                                $image_stmt->bindParam(':is_main', $is_main, PDO::PARAM_INT);
                                $image_stmt->execute();
                            }
                        }
                    }
                }

                // Valider la transaction
                $conn->commit();
                
                $success = 'Votre propriété a été ajoutée avec succès!';
                // Réinitialiser le formulaire
                $formData = [
                    'title' => '',
                    'description' => '',
                    'price' => '',
                    'type' => 'location',
                    'category_id' => '',
                    'surface_area' => '',
                    'rooms' => '',
                    'bedrooms' => '',
                    'bathrooms' => '',
                    'address' => '',
                    'city' => '',
                    'postal_code' => '',
                    'country' => 'Cameroun'
                ];

            } else {
                throw new Exception("Erreur lors de l'insertion de la propriété.");
            }

        } catch (Exception $e) {
            // Annuler la transaction en cas d'erreur
            $conn->rollBack();
            $error = 'Une erreur est survenue lors de l\'ajout de la propriété: ' . $e->getMessage();
            error_log("Erreur ajout propriété: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajouter une propriété - ImmoLink</title>
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

        /* Sidebar (identique au dashboard) */
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

        /* Form Container */
        .form-container {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 30px;
            margin-bottom: 30px;
        }

        .form-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--gray-light);
        }

        .form-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
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

        .section-title i {
            color: var(--primary);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
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

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 16px;
            padding-right: 40px;
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
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

        .input-with-suffix .form-control {
            padding-right: 60px;
        }

        .input-suffix {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary);
        }

        /* File Upload */
        .file-upload {
            border: 2px dashed var(--gray-light);
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            transition: border-color 0.3s;
            cursor: pointer;
            position: relative;
        }

        .file-upload:hover {
            border-color: var(--primary);
        }

        .file-upload i {
            font-size: 48px;
            color: var(--gray);
            margin-bottom: 15px;
        }

        .file-upload h3 {
            font-size: 18px;
            margin-bottom: 10px;
            color: var(--dark);
        }

        .file-upload p {
            color: var(--secondary);
            margin-bottom: 15px;
        }

        .file-upload input[type="file"] {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            opacity: 0;
            cursor: pointer;
        }

        .file-preview {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .file-preview-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .file-preview-item img {
            width: 100%;
            height: 120px;
            object-fit: cover;
        }

        .file-preview-remove {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 25px;
            height: 25px;
            background-color: var(--danger);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 12px;
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

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-light);
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
            .form-grid {
                grid-template-columns: 1fr;
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
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

            .form-container {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar (identique au dashboard) -->
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
            <a href="add-property.php" class="nav-item active">
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
                <h1 class="dashboard-title">Ajouter une propriété</h1>
                <div class="breadcrumb">
                    <a href="index.php">Tableau de bord</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Nouvelle propriété</span>
                </div>
            </div>
            <div>
                <a href="my-properties.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Retour aux biens
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

        <form action="add-property.php" method="POST" enctype="multipart/form-data" class="form-container">
            <!-- Section 1: Informations de base -->
            <div class="form-section">
                <h2 class="section-title">
                    <i class="fas fa-info-circle"></i>
                    Informations de base
                </h2>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Titre de l'annonce <span class="required">*</span></label>
                        <input 
                            type="text" 
                            name="title" 
                            class="form-control" 
                            placeholder="Ex: Bel appartement au centre-ville"
                            value="<?php echo htmlspecialchars($formData['title']); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>Type de transaction <span class="required">*</span></label>
                        <select name="type" class="form-control" required>
                            <option value="location" <?php echo $formData['type'] === 'location' ? 'selected' : ''; ?>>Location</option>
                            <option value="vente" <?php echo $formData['type'] === 'vente' ? 'selected' : ''; ?>>Vente</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Description détaillée <span class="required">*</span></label>
                    <textarea 
                        name="description" 
                        class="form-control" 
                        placeholder="Décrivez votre propriété en détail..."
                        required
                    ><?php echo htmlspecialchars($formData['description']); ?></textarea>
                </div>
            </div>

            <!-- Section 2: Détails de la propriété -->
            <div class="form-section">
                <h2 class="section-title">
                    <i class="fas fa-home"></i>
                    Détails de la propriété
                </h2>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Catégorie</label>
                        <select name="category_id" class="form-control">
                            <option value="">Sélectionnez une catégorie</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" 
                                    <?php echo $formData['category_id'] == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Surface (m²)</label>
                        <div class="input-with-icon">
                            <i class="fas fa-ruler-combined input-icon"></i>
                            <input 
                                type="number" 
                                name="surface_area" 
                                class="form-control" 
                                placeholder="Ex: 85"
                                value="<?php echo htmlspecialchars($formData['surface_area']); ?>"
                                min="0"
                                step="0.1"
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Nombre de pièces</label>
                        <select name="rooms" class="form-control">
                            <option value="">Sélectionnez</option>
                            <?php for ($i = 1; $i <= 10; $i++): ?>
                                <option value="<?php echo $i; ?>" 
                                    <?php echo $formData['rooms'] == $i ? 'selected' : ''; ?>>
                                    <?php echo $i; ?> pièce<?php echo $i > 1 ? 's' : ''; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Chambres</label>
                        <select name="bedrooms" class="form-control">
                            <option value="">Sélectionnez</option>
                            <?php for ($i = 0; $i <= 10; $i++): ?>
                                <option value="<?php echo $i; ?>" 
                                    <?php echo $formData['bedrooms'] == $i ? 'selected' : ''; ?>>
                                    <?php echo $i; ?> chambre<?php echo $i > 1 ? 's' : ''; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Salles de bain</label>
                        <select name="bathrooms" class="form-control">
                            <option value="">Sélectionnez</option>
                            <?php for ($i = 0; $i <= 5; $i++): ?>
                                <option value="<?php echo $i; ?>" 
                                    <?php echo $formData['bathrooms'] == $i ? 'selected' : ''; ?>>
                                    <?php echo $i; ?> salle<?php echo $i > 1 ? 's' : ''; ?> de bain
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Section 3: Prix -->
            <div class="form-section">
                <h2 class="section-title">
                    <i class="fas fa-tag"></i>
                    Prix
                </h2>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Prix <span class="required">*</span></label>
                        <div class="input-with-icon input-with-suffix">
                            <i class="fas fa-money-bill-wave input-icon"></i>
                            <input 
                                type="number" 
                                name="price" 
                                class="form-control" 
                                placeholder="0.00"
                                value="<?php echo htmlspecialchars($formData['price']); ?>"
                                min="0"
                                step="0.01"
                                required
                            >
                            <span class="input-suffix">FCFA</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 4: Localisation -->
            <div class="form-section">
                <h2 class="section-title">
                    <i class="fas fa-map-marker-alt"></i>
                    Localisation
                </h2>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Adresse <span class="required">*</span></label>
                        <div class="input-with-icon">
                            <i class="fas fa-map input-icon"></i>
                            <input 
                                type="text" 
                                name="address" 
                                class="form-control" 
                                placeholder="Ex: 123 Rue principale"
                                value="<?php echo htmlspecialchars($formData['address']); ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Ville <span class="required">*</span></label>
                        <div class="input-with-icon">
                            <i class="fas fa-city input-icon"></i>
                            <input 
                                type="text" 
                                name="city" 
                                class="form-control" 
                                placeholder="Ex: Yaoundé"
                                value="<?php echo htmlspecialchars($formData['city']); ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Code postal <span class="required">*</span></label>
                        <div class="input-with-icon">
                            <i class="fas fa-mail-bulk input-icon"></i>
                            <input 
                                type="text" 
                                name="postal_code" 
                                class="form-control" 
                                placeholder="Ex: 00237"
                                value="<?php echo htmlspecialchars($formData['postal_code']); ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Pays</label>
                        <div class="input-with-icon">
                            <i class="fas fa-globe input-icon"></i>
                            <input 
                                type="text" 
                                name="country" 
                                class="form-control" 
                                value="<?php echo htmlspecialchars($formData['country']); ?>"
                            >
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 5: Images -->
            <div class="form-section">
                <h2 class="section-title">
                    <i class="fas fa-images"></i>
                    Photos de la propriété
                </h2>

                <div class="file-upload" id="fileUploadArea">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <h3>Glissez-déposez vos images ici</h3>
                    <p>ou</p>
                    <button type="button" class="btn btn-primary" onclick="document.getElementById('images').click()">
                        <i class="fas fa-folder-open"></i> Parcourir les fichiers
                    </button>
                    <input type="file" id="images" name="images[]" multiple accept="image/*" style="display: none;">
                </div>

                <div class="file-preview" id="filePreview"></div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <button type="reset" class="btn btn-outline">
                    <i class="fas fa-times"></i> Annuler
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> Ajouter la propriété
                </button>
            </div>
        </form>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const fileUploadArea = document.getElementById('fileUploadArea');
            const fileInput = document.getElementById('images');
            const filePreview = document.getElementById('filePreview');

            // Drag and drop functionality
            fileUploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.style.borderColor = 'var(--primary)';
                this.style.backgroundColor = 'rgba(37, 99, 235, 0.05)';
            });

            fileUploadArea.addEventListener('dragleave', function() {
                this.style.borderColor = 'var(--gray-light)';
                this.style.backgroundColor = 'transparent';
            });

            fileUploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                this.style.borderColor = 'var(--gray-light)';
                this.style.backgroundColor = 'transparent';
                
                if (e.dataTransfer.files.length > 0) {
                    fileInput.files = e.dataTransfer.files;
                    updateFilePreview();
                }
            });

            // File input change event
            fileInput.addEventListener('change', updateFilePreview);

            function updateFilePreview() {
                filePreview.innerHTML = '';
                
                if (fileInput.files.length === 0) {
                    return;
                }

                for (let i = 0; i < fileInput.files.length; i++) {
                    const file = fileInput.files[i];
                    
                    if (!file.type.startsWith('image/')) {
                        continue;
                    }

                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const previewItem = document.createElement('div');
                        previewItem.className = 'file-preview-item';
                        
                        previewItem.innerHTML = `
                            <img src="${e.target.result}" alt="${file.name}">
                            <div class="file-preview-remove" onclick="removeFile(${i})">
                                <i class="fas fa-times"></i>
                            </div>
                        `;
                        
                        filePreview.appendChild(previewItem);
                    };
                    
                    reader.readAsDataURL(file);
                }
            }

            window.removeFile = function(index) {
                const dt = new DataTransfer();
                const files = Array.from(fileInput.files);
                
                files.splice(index, 1);
                
                files.forEach(file => dt.items.add(file));
                fileInput.files = dt.files;
                
                updateFilePreview();
            };

            // Form validation
            const form = document.querySelector('form');
            form.addEventListener('submit', function(e) {
                const price = document.querySelector('input[name="price"]').value;
                const surface = document.querySelector('input[name="surface_area"]').value;
                
                if (price && parseFloat(price) <= 0) {
                    e.preventDefault();
                    alert('Le prix doit être supérieur à 0.');
                    return false;
                }
                
                if (surface && parseFloat(surface) <= 0) {
                    e.preventDefault();
                    alert('La surface doit être supérieure à 0.');
                    return false;
                }
            });
        });
    </script>
</body>
</html>
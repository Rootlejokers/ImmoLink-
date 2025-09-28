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
$property_id = isset($_GET['property']) ? (int)$_GET['property'] : null;
$db = new Database();
$conn = $db->getConnection();

// Récupérer les conversations de l'utilisateur
$conversations = [];
try {
    $query = "SELECT c.*, p.title as property_title, p.price, 
                     u.first_name as owner_first_name, u.last_name as owner_last_name,
                     (SELECT message FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message,
                     (SELECT created_at FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_time,
                     (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND is_read = 0 AND sender_id != :user_id) as unread_count
              FROM conversations c
              JOIN properties p ON c.property_id = p.id
              JOIN users u ON c.owner_id = u.id
              WHERE c.tenant_id = :user_id";

    if ($property_id) {
        $query .= " AND c.property_id = :property_id";
    }

    $query .= " ORDER BY last_message_time DESC";

    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    if ($property_id) {
        $stmt->bindParam(':property_id', $property_id);
    }
    $stmt->execute();
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des conversations: " . $e->getMessage());
}

// Récupérer les informations de la propriété si spécifiée
$property = null;
if ($property_id) {
    try {
        $query = "SELECT p.*, u.first_name, u.last_name, u.phone,
                         (SELECT image_path FROM property_images WHERE property_id = p.id AND is_main = 1 LIMIT 1) as main_image
                  FROM properties p
                  JOIN users u ON p.owner_id = u.id
                  WHERE p.id = :property_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':property_id', $property_id);
        $stmt->execute();
        $property = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération de la propriété: " . $e->getMessage());
    }
}

// Récupérer les messages si une conversation est sélectionnée
$selected_conversation_id = isset($_GET['conversation']) ? (int)$_GET['conversation'] : null;
$messages = [];
$selected_conversation = null;

if ($selected_conversation_id) {
    // Vérifier que l'utilisateur a accès à cette conversation
    try {
        $query = "SELECT c.*, p.title as property_title, u.first_name as owner_first_name, u.last_name as owner_last_name
                  FROM conversations c
                  JOIN properties p ON c.property_id = p.id
                  JOIN users u ON c.owner_id = u.id
                  WHERE c.id = :conversation_id AND c.tenant_id = :user_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':conversation_id', $selected_conversation_id);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $selected_conversation = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($selected_conversation) {
            // Récupérer les messages
            $query = "SELECT m.*, u.first_name, u.last_name, u.user_type
                      FROM messages m
                      JOIN users u ON m.sender_id = u.id
                      WHERE m.conversation_id = :conversation_id
                      ORDER BY m.created_at ASC";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':conversation_id', $selected_conversation_id);
            $stmt->execute();
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Marquer les messages comme lus
            $update_query = "UPDATE messages SET is_read = 1 
                            WHERE conversation_id = :conversation_id 
                            AND sender_id != :user_id 
                            AND is_read = 0";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bindParam(':conversation_id', $selected_conversation_id);
            $update_stmt->bindParam(':user_id', $user_id);
            $update_stmt->execute();
        }
    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération des messages: " . $e->getMessage());
    }
}

// Traitement de l'envoi de message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $message_text = trim($_POST['message']);
    $conversation_id = isset($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : null;
    $new_property_id = isset($_POST['property_id']) ? (int)$_POST['property_id'] : null;

    if (!empty($message_text)) {
        try {
            // Si pas de conversation_id, créer une nouvelle conversation
            if (!$conversation_id && $new_property_id) {
                // Vérifier si une conversation existe déjà pour cette propriété
                $check_query = "SELECT id FROM conversations 
                               WHERE property_id = :property_id 
                               AND tenant_id = :tenant_id";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bindParam(':property_id', $new_property_id);
                $check_stmt->bindParam(':tenant_id', $user_id);
                $check_stmt->execute();
                $existing_conversation = $check_stmt->fetch(PDO::FETCH_ASSOC);

                if ($existing_conversation) {
                    $conversation_id = $existing_conversation['id'];
                } else {
                    // Créer une nouvelle conversation
                    $property_query = "SELECT owner_id FROM properties WHERE id = :property_id";
                    $property_stmt = $conn->prepare($property_query);
                    $property_stmt->bindParam(':property_id', $new_property_id);
                    $property_stmt->execute();
                    $property_info = $property_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($property_info) {
                        $insert_query = "INSERT INTO conversations (property_id, owner_id, tenant_id) 
                                       VALUES (:property_id, :owner_id, :tenant_id)";
                        $insert_stmt = $conn->prepare($insert_query);
                        $insert_stmt->bindParam(':property_id', $new_property_id);
                        $insert_stmt->bindParam(':owner_id', $property_info['owner_id']);
                        $insert_stmt->bindParam(':tenant_id', $user_id);
                        $insert_stmt->execute();
                        $conversation_id = $conn->lastInsertId();
                    }
                }
            }

            // Insérer le message
            if ($conversation_id) {
                $insert_msg_query = "INSERT INTO messages (conversation_id, sender_id, message) 
                                   VALUES (:conversation_id, :sender_id, :message)";
                $insert_msg_stmt = $conn->prepare($insert_msg_query);
                $insert_msg_stmt->bindParam(':conversation_id', $conversation_id);
                $insert_msg_stmt->bindParam(':sender_id', $user_id);
                $insert_msg_stmt->bindParam(':message', $message_text);
                $insert_msg_stmt->execute();

                // Rediriger pour éviter la resoumission du formulaire
                header("Location: messages.php?" . ($property_id ? "property=$property_id&" : "") . "conversation=$conversation_id");
                exit();
            }
        } catch (PDOException $e) {
            error_log("Erreur lors de l'envoi du message: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messagerie - ImmoLink</title>
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
            display: flex;
            flex-direction: column;
            height: 100vh;
        }

        .dashboard-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 20px;
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

        /* Messagerie Layout */
        .messaging-container {
            display: flex;
            flex: 1;
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        /* Conversations List */
        .conversations-sidebar {
            width: 350px;
            border-right: 1px solid var(--gray-light);
            display: flex;
            flex-direction: column;
        }

        .conversations-header {
            padding: 20px;
            border-bottom: 1px solid var(--gray-light);
        }

        .conversations-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 10px;
        }

        .conversations-search {
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 10px 15px 10px 40px;
            border: 1px solid var(--gray-light);
            border-radius: 6px;
            font-size: 14px;
        }

        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        .conversations-list {
            flex: 1;
            overflow-y: auto;
        }

        .conversation-item {
            padding: 15px 20px;
            border-bottom: 1px solid var(--gray-light);
            cursor: pointer;
            transition: background-color 0.3s;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .conversation-item:hover, .conversation-item.active {
            background-color: var(--light);
        }

        .conversation-item.unread {
            background-color: rgba(37, 99, 235, 0.05);
            border-left: 3px solid var(--primary);
        }

        .conversation-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background-color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            flex-shrink: 0;
        }

        .conversation-info {
            flex: 1;
            min-width: 0;
        }

        .conversation-owner {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
            display: flex;
            justify-content: between;
            align-items: center;
        }

        .conversation-property {
            font-size: 14px;
            color: var(--primary);
            margin-bottom: 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conversation-preview {
            font-size: 13px;
            color: var(--secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conversation-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 5px;
            flex-shrink: 0;
        }

        .conversation-time {
            font-size: 12px;
            color: var(--gray);
        }

        .unread-badge {
            background-color: var(--primary);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
        }

        /* Chat Area */
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .chat-header {
            padding: 20px;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .chat-property-image {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            object-fit: cover;
        }

        .chat-info h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .chat-info p {
            color: var(--secondary);
            font-size: 14px;
        }

        .chat-messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .message {
            max-width: 70%;
            padding: 12px 16px;
            border-radius: 12px;
            position: relative;
            animation: fadeIn 0.3s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .message.sent {
            align-self: flex-end;
            background-color: var(--primary);
            color: white;
            border-bottom-right-radius: 4px;
        }

        .message.received {
            align-self: flex-start;
            background-color: var(--light);
            color: var(--dark);
            border-bottom-left-radius: 4px;
        }

        .message-content {
            margin-bottom: 5px;
        }

        .message-time {
            font-size: 11px;
            opacity: 0.7;
            text-align: right;
        }

        .message.received .message-time {
            text-align: left;
        }

        .no-conversation {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: var(--secondary);
        }

        .no-conversation i {
            font-size: 64px;
            margin-bottom: 20px;
            color: var(--gray);
        }

        .chat-input {
            padding: 20px;
            border-top: 1px solid var(--gray-light);
        }

        .message-form {
            display: flex;
            gap: 10px;
        }

        .message-input {
            flex: 1;
            padding: 12px 15px;
            border: 1px solid var(--gray-light);
            border-radius: 25px;
            font-size: 14px;
            resize: none;
            max-height: 100px;
        }

        .message-input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .send-btn {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background-color: var(--primary);
            color: white;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.3s;
        }

        .send-btn:hover {
            background-color: var(--primary-dark);
        }

        .send-btn:disabled {
            background-color: var(--gray);
            cursor: not-allowed;
        }

        /* Property Info */
        .property-info-sidebar {
            width: 300px;
            border-left: 1px solid var(--gray-light);
            padding: 20px;
            background-color: var(--light);
        }

        .property-info h3 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--dark);
        }

        .property-image-large {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .property-details {
            display: grid;
            gap: 10px;
        }

        .detail-item {
            display: flex;
            justify-content: between;
            font-size: 14px;
        }

        .detail-label {
            color: var(--secondary);
        }

        .detail-value {
            color: var(--dark);
            font-weight: 500;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .property-info-sidebar {
                display: none;
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

            .conversations-sidebar {
                width: 300px;
            }
        }

        @media (max-width: 768px) {
            .messaging-container {
                flex-direction: column;
            }

            .conversations-sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid var(--gray-light);
                max-height: 300px;
            }

            .chat-area {
                min-height: 400px;
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
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

            .message {
                max-width: 85%;
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
            <a href="messages.php" class="nav-item active">
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
                <h1 class="dashboard-title">Messagerie</h1>
                <div class="breadcrumb">
                    <a href="index.php">Tableau de bord</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Messages</span>
                    <?php if ($property): ?>
                        <i class="fas fa-chevron-right"></i>
                        <span><?php echo htmlspecialchars($property['title']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <a href="properties.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Retour aux propriétés
                </a>
            </div>
        </div>

        <div class="messaging-container">
            <!-- Conversations List -->
            <div class="conversations-sidebar">
                <div class="conversations-header">
                    <h3 class="conversations-title">Conversations</h3>
                    <div class="conversations-search">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" class="search-input" placeholder="Rechercher une conversation...">
                    </div>
                </div>
                <div class="conversations-list">
                    <?php if (!empty($conversations)): ?>
                        <?php foreach ($conversations as $conversation): ?>
                            <a href="?<?php echo ($property_id ? "property=$property_id&" : "") . "conversation=" . $conversation['id']; ?>" 
                               class="conversation-item <?php echo $selected_conversation_id == $conversation['id'] ? 'active' : ''; ?> <?php echo $conversation['unread_count'] > 0 ? 'unread' : ''; ?>">
                                <div class="conversation-avatar">
                                    <?php echo strtoupper(substr($conversation['owner_first_name'], 0, 1) . substr($conversation['owner_last_name'], 0, 1)); ?>
                                </div>
                                <div class="conversation-info">
                                    <div class="conversation-owner">
                                        <?php echo htmlspecialchars($conversation['owner_first_name'] . ' ' . $conversation['owner_last_name']); ?>
                                    </div>
                                    <div class="conversation-property">
                                        <?php echo htmlspecialchars($conversation['property_title']); ?>
                                    </div>
                                    <div class="conversation-preview">
                                        <?php echo htmlspecialchars($conversation['last_message'] ? substr($conversation['last_message'], 0, 50) . (strlen($conversation['last_message']) > 50 ? '...' : '') : 'Aucun message'); ?>
                                    </div>
                                </div>
                                <div class="conversation-meta">
                                    <div class="conversation-time">
                                        <?php echo $conversation['last_message_time'] ? date('d/m H:i', strtotime($conversation['last_message_time'])) : ''; ?>
                                    </div>
                                    <?php if ($conversation['unread_count'] > 0): ?>
                                        <div class="unread-badge"><?php echo $conversation['unread_count']; ?></div>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-conversation" style="padding: 20px;">
                            <div>
                                <i class="fas fa-comments"></i>
                                <h3>Aucune conversation</h3>
                                <p>Vous n'avez pas encore de conversation.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Chat Area -->
            <div class="chat-area">
                <?php if ($selected_conversation): ?>
                    <div class="chat-header">
                        <img src="<?php echo $property['main_image'] ? '../../uploads/properties/' . htmlspecialchars($property['main_image']) : 'https://via.placeholder.com/300x200?text=ImmoLink'; ?>" 
                             alt="<?php echo htmlspecialchars($selected_conversation['property_title']); ?>" 
                             class="chat-property-image">
                        <div class="chat-info">
                            <h3><?php echo htmlspecialchars($selected_conversation['property_title']); ?></h3>
                            <p>Avec <?php echo htmlspecialchars($selected_conversation['owner_first_name'] . ' ' . $selected_conversation['owner_last_name']); ?></p>
                        </div>
                    </div>

                    <div class="chat-messages" id="chatMessages">
                        <?php if (!empty($messages)): ?>
                            <?php foreach ($messages as $message): ?>
                                <div class="message <?php echo $message['sender_id'] == $user_id ? 'sent' : 'received'; ?>">
                                    <div class="message-content">
                                        <?php echo htmlspecialchars($message['message']); ?>
                                    </div>
                                    <div class="message-time">
                                        <?php echo date('d/m H:i', strtotime($message['created_at'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-conversation">
                                <div>
                                    <i class="fas fa-comment-dots"></i>
                                    <h3>Commencez la conversation</h3>
                                    <p>Envoyez votre premier message au propriétaire</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="chat-input">
                        <form method="POST" class="message-form" id="messageForm">
                            <input type="hidden" name="conversation_id" value="<?php echo $selected_conversation['id']; ?>">
                            <textarea name="message" class="message-input" placeholder="Tapez votre message..." rows="1" required></textarea>
                            <button type="submit" class="send-btn">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                    </div>
                <?php elseif ($property && empty($conversations)): ?>
                    <!-- Nouvelle conversation pour une propriété spécifique -->
                    <div class="chat-header">
                        <img src="<?php echo $property['main_image'] ? '../../uploads/properties/' . htmlspecialchars($property['main_image']) : 'https://via.placeholder.com/300x200?text=ImmoLink'; ?>" 
                             alt="<?php echo htmlspecialchars($property['title']); ?>" 
                             class="chat-property-image">
                        <div class="chat-info">
                            <h3><?php echo htmlspecialchars($property['title']); ?></h3>
                            <p>Propriétaire: <?php echo htmlspecialchars($property['first_name'] . ' ' . $property['last_name']); ?></p>
                        </div>
                    </div>

                    <div class="no-conversation">
                        <div>
                            <i class="fas fa-comment-medical"></i>
                            <h3>Nouvelle conversation</h3>
                            <p>Commencez à discuter avec le propriétaire de cette propriété</p>
                        </div>
                    </div>

                    <div class="chat-input">
                        <form method="POST" class="message-form" id="messageForm">
                            <input type="hidden" name="property_id" value="<?php echo $property_id; ?>">
                            <textarea name="message" class="message-input" placeholder="Tapez votre premier message..." rows="1" required></textarea>
                            <button type="submit" class="send-btn">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="no-conversation">
                        <div>
                            <i class="fas fa-comments"></i>
                            <h3>Sélectionnez une conversation</h3>
                            <p>Choisissez une conversation dans la liste pour commencer à discuter</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Property Info Sidebar -->
            <?php if ($property): ?>
                <div class="property-info-sidebar">
                    <h3>Informations de la propriété</h3>
                    <img src="<?php echo $property['main_image'] ? '../../uploads/properties/' . htmlspecialchars($property['main_image']) : 'https://via.placeholder.com/300x200?text=ImmoLink'; ?>" 
                         alt="<?php echo htmlspecialchars($property['title']); ?>" 
                         class="property-image-large">
                    
                    <div class="property-details">
                        <div class="detail-item">
                            <span class="detail-label">Prix:</span>
                            <span class="detail-value"><?php echo formatPrice($property['price']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Surface:</span>
                            <span class="detail-value"><?php echo $property['surface_area'] ? $property['surface_area'] . ' m²' : '-'; ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Pièces:</span>
                            <span class="detail-value"><?php echo $property['rooms'] ?: '-'; ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Chambres:</span>
                            <span class="detail-value"><?php echo $property['bedrooms'] ?: '-'; ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Ville:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($property['city']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Téléphone:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($property['phone'] ?: 'Non disponible'); ?></span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const messageForm = document.getElementById('messageForm');
            const messageInput = document.querySelector('.message-input');
            const chatMessages = document.getElementById('chatMessages');

            // Auto-resize textarea
            messageInput.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });

            // Scroll to bottom of messages
            function scrollToBottom() {
                if (chatMessages) {
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                }
            }

            scrollToBottom();

            // Message form submission
            if (messageForm) {
                messageForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const message = messageInput.value.trim();
                    if (message) {
                        // Create temporary message for immediate feedback
                        const tempMessage = document.createElement('div');
                        tempMessage.className = 'message sent';
                        tempMessage.innerHTML = `
                            <div class="message-content">${message}</div>
                            <div class="message-time">Envoi...</div>
                        `;
                        
                        if (chatMessages) {
                            chatMessages.appendChild(tempMessage);
                            scrollToBottom();
                        }

                        // Reset input
                        messageInput.value = '';
                        messageInput.style.height = 'auto';

                        // Submit form
                        const formData = new FormData(messageForm);
                        
                        fetch('messages.php?<?php echo $property_id ? "property=$property_id&" : ""; ?><?php echo $selected_conversation_id ? "conversation=$selected_conversation_id" : ""; ?>', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.text())
                        .then(() => {
                            // Remove temporary message and reload page to show actual message
                            if (tempMessage.parentNode) {
                                tempMessage.parentNode.removeChild(tempMessage);
                            }
                            window.location.reload();
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            if (tempMessage.parentNode) {
                                tempMessage.parentNode.removeChild(tempMessage);
                            }
                            alert('Erreur lors de l\'envoi du message');
                        });
                    }
                });
            }

            // Auto-refresh messages every 30 seconds
            setInterval(() => {
                if (<?php echo $selected_conversation_id ? 'true' : 'false'; ?>) {
                    fetch('messages.php?<?php echo $property_id ? "property=$property_id&" : ""; ?>conversation=<?php echo $selected_conversation_id; ?>&ajax=1')
                        .then(response => response.text())
                        .then(html => {
                            // Vous pourriez mettre à jour uniquement les nouveaux messages ici
                            // Pour simplifier, on recharge la page si des nouveaux messages sont détectés
                            const currentMessageCount = document.querySelectorAll('.message').length;
                            const parser = new DOMParser();
                            const doc = parser.parseFromString(html, 'text/html');
                            const newMessageCount = doc.querySelectorAll('.message').length;
                            
                            if (newMessageCount > currentMessageCount) {
                                window.location.reload();
                            }
                        })
                        .catch(error => console.error('Error refreshing messages:', error));
                }
            }, 30000);

            // Search conversations
            const searchInput = document.querySelector('.search-input');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const conversationItems = document.querySelectorAll('.conversation-item');
                    
                    conversationItems.forEach(item => {
                        const text = item.textContent.toLowerCase();
                        if (text.includes(searchTerm)) {
                            item.style.display = 'flex';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            }
        });
    </script>

</body>
</html>
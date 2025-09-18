<?php
session_start();
require_once '../../includes/config.php';
require_once '../../includes/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Vérifier si l'utilisateur est connecté et est un propriétaire


$user_id = $_SESSION['user_id'];
$db = new Database();
$conn = $db->getConnection();

// Paramètres de filtrage
$property_filter = isset($_GET['property']) ? (int)$_GET['property'] : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Récupérer la conversation sélectionnée
$selected_conversation = isset($_GET['conversation']) ? (int)$_GET['conversation'] : 0;
$current_conversation = null;
$conversation_messages = [];

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

// Récupérer les conversations
$conversations = [];
$where_conditions = ["c.owner_id = :user_id"];
$params = [':user_id' => $user_id];

if ($property_filter > 0) {
    $where_conditions[] = "c.property_id = :property_id";
    $params[':property_id'] = $property_filter;
}

if ($status_filter === 'unread') {
    $where_conditions[] = "EXISTS (SELECT 1 FROM messages m WHERE m.conversation_id = c.id AND m.is_read = 0 AND m.sender_id != :user_id)";
}

$where_clause = implode(' AND ', $where_conditions);

try {
    $query = "SELECT 
                c.id,
                c.property_id,
                p.title as property_title,
                p.price,
                p.type,
                t.id as tenant_id,
                t.first_name as tenant_first_name,
                t.last_name as tenant_last_name,
                t.avatar as tenant_avatar,
                (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.is_read = 0 AND m.sender_id != :user_id) as unread_count,
                (SELECT message FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message,
                (SELECT created_at FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_time
              FROM conversations c
              JOIN properties p ON c.property_id = p.id
              JOIN users t ON c.tenant_id = t.id
              WHERE $where_clause
              ORDER BY last_message_time DESC";

    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':user_id', $user_id);
    $stmt->execute();
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des conversations: " . $e->getMessage());
}

// Filtrer par recherche si nécessaire
if (!empty($search_query)) {
    $conversations = array_filter($conversations, function($conv) use ($search_query) {
        return stripos($conv['property_title'], $search_query) !== false ||
               stripos($conv['tenant_first_name'] . ' ' . $conv['tenant_last_name'], $search_query) !== false ||
               stripos($conv['last_message'], $search_query) !== false;
    });
}

// Récupérer les messages de la conversation sélectionnée
if ($selected_conversation > 0) {
    try {
        // Vérifier que la conversation appartient bien au propriétaire
        $query = "SELECT id FROM conversations WHERE id = :conversation_id AND owner_id = :user_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':conversation_id', $selected_conversation);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $current_conversation = $selected_conversation;
            
            // Marquer les messages comme lus
            $query = "UPDATE messages SET is_read = 1 WHERE conversation_id = :conversation_id AND sender_id != :user_id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':conversation_id', $selected_conversation);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            
            // Récupérer les messages
            $query = "SELECT 
                        m.*,
                        u.first_name,
                        u.last_name,
                        u.avatar,
                        u.user_type
                      FROM messages m
                      JOIN users u ON m.sender_id = u.id
                      WHERE m.conversation_id = :conversation_id
                      ORDER BY m.created_at ASC";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':conversation_id', $selected_conversation);
            $stmt->execute();
            $conversation_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération des messages: " . $e->getMessage());
    }
}

// Traitement de l'envoi de message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message']) && $current_conversation > 0) {
    $message = trim($_POST['message']);
    
    if (!empty($message)) {
        try {
            $query = "INSERT INTO messages (conversation_id, sender_id, message) 
                      VALUES (:conversation_id, :sender_id, :message)";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':conversation_id', $current_conversation);
            $stmt->bindParam(':sender_id', $user_id);
            $stmt->bindParam(':message', $message);
            
            if ($stmt->execute()) {
                // Rediriger pour éviter le re-soumission du formulaire
                header("Location: messages.php?conversation=$current_conversation");
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
            display: flex;
            flex-direction: column;
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
            margin-bottom: 15px;
            color: var(--dark);
        }

        .conversations-filters {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
            margin-bottom: 15px;
        }

        .filter-select {
            padding: 8px 12px;
            border: 1px solid var(--gray-light);
            border-radius: 6px;
            font-size: 14px;
        }

        .search-box {
            position: relative;
            grid-column: 1 / -1;
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
            left: 12px;
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
            position: relative;
        }

        .conversation-item:hover {
            background-color: var(--light);
        }

        .conversation-item.active {
            background-color: rgba(37, 99, 235, 0.05);
            border-right: 3px solid var(--primary);
        }

        .conversation-header {
            display: flex;
            justify-content: between;
            align-items: flex-start;
            margin-bottom: 8px;
        }

        .conversation-tenant {
            font-weight: 600;
            color: var(--dark);
        }

        .conversation-time {
            font-size: 12px;
            color: var(--gray);
        }

        .conversation-property {
            font-size: 14px;
            color: var(--primary);
            margin-bottom: 5px;
        }

        .conversation-preview {
            font-size: 14px;
            color: var(--secondary);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .conversation-badge {
            position: absolute;
            top: 15px;
            right: 20px;
            background-color: var(--primary);
            color: white;
            width: 20px;
            height: 20px;
            border-radius: 50%;
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

        .chat-tenant-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background-color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
        }

        .chat-tenant-info h3 {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 2px;
        }

        .chat-tenant-info p {
            font-size: 14px;
            color: var(--secondary);
        }

        .chat-property-info {
            margin-left: auto;
            text-align: right;
        }

        .chat-property-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 2px;
        }

        .chat-property-price {
            font-size: 14px;
            color: var(--success);
            font-weight: 600;
        }

        .messages-container {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            background-color: #f8fafc;
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

        .message.received {
            align-self: flex-start;
            background-color: white;
            border: 1px solid var(--gray-light);
        }

        .message.sent {
            align-self: flex-end;
            background-color: var(--primary);
            color: white;
        }

        .message-content {
            margin-bottom: 5px;
        }

        .message-time {
            font-size: 12px;
            opacity: 0.7;
        }

        .message.sent .message-time {
            text-align: right;
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
            font-size: 48px;
            margin-bottom: 15px;
            color: var(--gray);
        }

        .no-conversation h3 {
            margin-bottom: 10px;
            color: var(--dark);
        }

        .message-input-container {
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
            max-height: 120px;
        }

        .message-input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .send-button {
            width: 50px;
            height: 50px;
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

        .send-button:hover {
            background-color: var(--primary-dark);
        }

        .send-button:disabled {
            background-color: var(--gray);
            cursor: not-allowed;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .conversations-sidebar {
                width: 300px;
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
                min-height: 500px;
            }
        }

        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .conversations-filters {
                grid-template-columns: 1fr;
            }

            .chat-header {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }

            .chat-property-info {
                margin-left: 0;
                text-align: center;
            }

            .message {
                max-width: 85%;
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

            .conversation-item {
                padding: 12px 15px;
            }

            .messages-container {
                padding: 15px;
            }

            .message-input-container {
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
            <a href="messages.php" class="nav-item active">
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
                <h1 class="dashboard-title">Messagerie</h1>
                <div class="breadcrumb">
                    <a href="index.php">Tableau de bord</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Messages</span>
                </div>
            </div>
            <div>
                <a href="my-properties.php" class="btn btn-outline">
                    <i class="fas fa-building"></i> Mes biens
                </a>
            </div>
        </div>

        <div class="messaging-container">
            <!-- Conversations List -->
            <div class="conversations-sidebar">
                <div class="conversations-header">
                    <h2 class="conversations-title">Conversations</h2>
                    
                    <form method="GET" action="messages.php">
                        <div class="conversations-filters">
                            <select name="property" class="filter-select" onchange="this.form.submit()">
                                <option value="0">Toutes les propriétés</option>
                                <?php foreach ($properties as $property): ?>
                                    <option value="<?php echo $property['id']; ?>" 
                                        <?php echo $property_filter == $property['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($property['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <select name="status" class="filter-select" onchange="this.form.submit()">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>Tous</option>
                                <option value="unread" <?php echo $status_filter === 'unread' ? 'selected' : ''; ?>>Non lus</option>
                            </select>
                            
                            <div class="search-box">
                                <i class="fas fa-search search-icon"></i>
                                <input 
                                    type="text" 
                                    name="search" 
                                    class="search-input" 
                                    placeholder="Rechercher..." 
                                    value="<?php echo htmlspecialchars($search_query); ?>"
                                >
                            </div>
                        </div>
                    </form>
                </div>
                
                <div class="conversations-list">
                    <?php if (!empty($conversations)): ?>
                        <?php foreach ($conversations as $conversation): ?>
                            <div class="conversation-item <?php echo $current_conversation == $conversation['id'] ? 'active' : ''; ?>" 
                                 onclick="window.location.href='messages.php?conversation=<?php echo $conversation['id']; ?>&property=<?php echo $property_filter; ?>&status=<?php echo $status_filter; ?>'">
                                
                                <div class="conversation-header">
                                    <span class="conversation-tenant">
                                        <?php echo htmlspecialchars($conversation['tenant_first_name'] . ' ' . $conversation['tenant_last_name']); ?>
                                    </span>
                                    <span class="conversation-time">
                                        <?php echo time_elapsed_string($conversation['last_message_time']); ?>
                                    </span>
                                </div>
                                
                                <div class="conversation-property">
                                    <?php echo htmlspecialchars($conversation['property_title']); ?>
                                </div>
                                
                                <div class="conversation-preview">
                                    <?php echo htmlspecialchars(substr($conversation['last_message'], 0, 60) . (strlen($conversation['last_message']) > 60 ? '...' : '')); ?>
                                </div>
                                
                                <?php if ($conversation['unread_count'] > 0): ?>
                                    <span class="conversation-badge">
                                        <?php echo $conversation['unread_count']; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="padding: 20px; text-align: center; color: var(--secondary);">
                            <i class="fas fa-comments" style="font-size: 48px; margin-bottom: 15px;"></i>
                            <p>Aucune conversation trouvée</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Chat Area -->
            <div class="chat-area">
                <?php if ($current_conversation && !empty($conversation_messages)): 
                    $conversation = current(array_filter($conversations, function($c) use ($current_conversation) {
                        return $c['id'] == $current_conversation;
                    }));
                ?>
                    <div class="chat-header">
                        <div class="chat-tenant-avatar">
                            <?php echo strtoupper(substr($conversation['tenant_first_name'], 0, 1) . substr($conversation['tenant_last_name'], 0, 1)); ?>
                        </div>
                        <div class="chat-tenant-info">
                            <h3><?php echo htmlspecialchars($conversation['tenant_first_name'] . ' ' . $conversation['tenant_last_name']); ?></h3>
                            <p>Locataire</p>
                        </div>
                        <div class="chat-property-info">
                            <div class="chat-property-title"><?php echo htmlspecialchars($conversation['property_title']); ?></div>
                            <div class="chat-property-price"><?php echo formatPrice($conversation['price']); ?></div>
                        </div>
                    </div>

                    <div class="messages-container" id="messagesContainer">
                        <?php foreach ($conversation_messages as $message): ?>
                            <div class="message <?php echo $message['sender_id'] == $user_id ? 'sent' : 'received'; ?>">
                                <div class="message-content">
                                    <?php echo nl2br(htmlspecialchars($message['message'])); ?>
                                </div>
                                <div class="message-time">
                                    <?php echo date('d/m/Y H:i', strtotime($message['created_at'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="message-input-container">
                        <form method="POST" class="message-form">
                            <textarea 
                                name="message" 
                                class="message-input" 
                                placeholder="Tapez votre message..." 
                                rows="1"
                                oninput="autoResize(this)"
                                required
                            ></textarea>
                            <button type="submit" class="send-button">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                    </div>

                <?php else: ?>
                    <div class="no-conversation">
                        <div>
                            <i class="fas fa-comments"></i>
                            <h3>Aucune conversation sélectionnée</h3>
                            <p>Sélectionnez une conversation pour commencer à discuter</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-resize textarea
            function autoResize(textarea) {
                textarea.style.height = 'auto';
                textarea.style.height = (textarea.scrollHeight) + 'px';
                
                // Limiter la hauteur maximale
                if (textarea.scrollHeight > 120) {
                    textarea.style.overflowY = 'auto';
                } else {
                    textarea.style.overflowY = 'hidden';
                }
            }

            // Faire défiler vers le bas des messages
            const messagesContainer = document.getElementById('messagesContainer');
            if (messagesContainer) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }

            // Recherche avec délai
            let searchTimeout;
            const searchInput = document.querySelector('input[name="search"]');
            
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    this.form.submit();
                }, 500);
            });

            // Prévention de la soumission du formulaire avec Enter
            const messageForms = document.querySelectorAll('.message-form');
            messageForms.forEach(form => {
                form.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        if (this.checkValidity()) {
                            this.submit();
                        }
                    }
                });
            });
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
            return $string ? implode(', ', $string) . ' ago' : 'à l\'instant';
        }
        ?>
    </script>
</body>
</html>
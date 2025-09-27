<?php
function redirect($url) {
    header("Location: " . $url);
    exit();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getUserType() {
    return $_SESSION['user_type'] ?? null;
}

function isOwner() {
    return isLoggedIn() && getUserType() === 'owner';
}

function isTenant() {
    return isLoggedIn() && getUserType() === 'tenant';
}

function isAdmin() {
    return isLoggedIn() && getUserType() === 'admin';
}

function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function formatPrice($price) {
    return number_format($price, 2, ',', ' ') . ' Fcfa';
}

function getPropertyTypeLabel($type) {
    $types = [
        'location' => 'À louer',
        'vente' => 'À vendre'
    ];
    return $types[$type] ?? $type;
}
?>
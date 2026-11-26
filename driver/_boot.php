<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/stock.php';

// Only accounts with the "deliver" permission (Warehouse / Delivery Staff) can use the delivery app.
function driver_user(): ?array {
    $user = auth_user();
    return ($user && role_can($user['role'], 'deliver')) ? $user : null;
}

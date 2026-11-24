<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

function driver_user(): ?array {
    $user = auth_user();
    return ($user && role_can($user['role'], 'gps_ping')) ? $user : null;
}

function driver_mapbox_token(): string {
    $file = __DIR__ . '/../config/mapbox.php';
    if (!is_file($file)) return '';
    require_once $file;
    return function_exists('mapbox_token') ? mapbox_token() : '';
}

<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json');

function json_ok(mixed $data = null, int $code = 200): never {
    http_response_code($code);
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function json_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function input(): array {
    $body = file_get_contents('php://input');
    return json_decode($body, true) ?? [];
}

$method = $_SERVER['REQUEST_METHOD'];
$id     = $_GET['id'] ?? null;

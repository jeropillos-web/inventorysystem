<?php
// Shared start of every JSON endpoint in /api: database, session, helpers,
// and a sign-in check. Each endpoint then checks the role permission it needs.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/stock.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function json_ok($data = null, int $code = 200) {
    http_response_code($code);
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function json_err(string $msg, int $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function input(): array {
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($data) ? $data : [];
}

// Stops with 403 unless the signed-in user has at least one of the permissions.
function need(string ...$perms): void {
    global $user;
    if (!role_can_any($user['role'], ...$perms)) json_err('Your account is not allowed to do this.', 403);
}

set_exception_handler(function (Throwable $e) {
    $pdo = null;
    try { $pdo = db(); } catch (Throwable $ignored) {}
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();

    if ($e instanceof StockError) json_err($e->getMessage(), 409);
    if ($e instanceof PDOException) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'foreign key constraint') || str_contains($msg, 'FOREIGN KEY')) {
            json_err('This record is used by other records (returns, disposals, transfers or orders), so it cannot be deleted.', 409);
        }
        if (str_contains($msg, 'Unknown column') || str_contains($msg, "doesn't exist") || str_contains($msg, 'no such')) {
            json_err('The database is out of date. Open upgrade.php or re-import sql/invtrack.sql.', 500);
        }
    }
    json_err('Server error: ' . $e->getMessage(), 500);
});

if (!db_available()) json_err('The database is offline. Start MySQL and try again.', 503);

$user = auth_user();
if (!$user) json_err('Please sign in again.', 401);

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? trim((string)$_GET['id']) : null;
$action = $_GET['action'] ?? '';
$uid    = (int)$user['id'];

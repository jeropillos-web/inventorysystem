<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

const VALID_ROLES = ['admin', 'staff', 'warehouse'];

// What each role may do.
//  read              view records
//  write             create records (items, categories, POs, shipments, returns, disposal and transfer requests)
//  delete            delete records
//  approve           decide returns, approve disposals and transfers, manage warehouses
//  stock_ops         physical warehouse work: record returns, dispatch and receive transfers
//  track             add text updates to a tracking log
//  process_orders    pick and prepare shop orders, approve shipments
//  deliver           use the delivery app
//  reports           view reports and analytics
const ROLE_PERMS = [
    'admin'     => ['read', 'write', 'delete', 'approve', 'stock_ops', 'track', 'reports'],
    'staff'     => ['read', 'write', 'reports'],
    'warehouse' => ['read', 'stock_ops', 'track', 'process_orders', 'deliver'],
];

const ROLE_LABELS = [
    'admin'     => 'Administrator',
    'staff'     => 'Staff',
    'warehouse' => 'Warehouse / Delivery Staff',
];

function role_can(string $role, string $action): bool {
    return in_array($action, ROLE_PERMS[$role] ?? [], true);
}

function role_can_any(string $role, string ...$actions): bool {
    foreach ($actions as $a) if (role_can($role, $a)) return true;
    return false;
}

function auth_user(): ?array {
    $user = $_SESSION['user'] ?? null;
    if (!$user || !in_array($user['role'] ?? '', VALID_ROLES, true)) {
        unset($_SESSION['user']);
        return null;
    }
    return $user;
}

function require_auth(string $redirect = '../index.php'): array {
    $user = auth_user();
    if (!$user) { header("Location: $redirect"); exit; }
    return $user;
}

function login(string $email, string $password): bool {
    require_once __DIR__ . '/db.php';

    if (db_available()) {
        $stmt = db()->prepare('SELECT id, name, email, password, role, avatar FROM users WHERE email = ?');
        $stmt->execute([strtolower(trim($email))]);
        $user = $stmt->fetch();
        if ($user && in_array($user['role'], VALID_ROLES, true) && password_verify($password, $user['password'])) {
            unset($user['password']);
            session_regenerate_id(true);
            $_SESSION['user'] = $user;
            return true;
        }
    }

    return false;
}

// The Administrator role can be chosen on the public sign-up page only until
// the first administrator exists. After that, only Staff and Warehouse/Delivery
// Staff can sign up; a new administrator must be promoted in the database.
function admin_signup_open(): bool {
    require_once __DIR__ . '/db.php';
    if (!db_available()) return false;
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() === 0;
}

function register_user(string $name, string $email, string $password, string $role): array {
    require_once __DIR__ . '/db.php';
    if (!db_available()) return [false, 'The database is unavailable. Start MySQL and try again.'];

    $name = trim($name);
    $email = strtolower(trim($email));
    if ($name === '') return [false, 'Enter your full name.'];
    if (mb_strlen($name) > 100) return [false, 'The name is too long.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, 'Enter a valid email address.'];
    if (strlen($password) < 8) return [false, 'Password must be at least 8 characters.'];
    if (!in_array($role, VALID_ROLES, true)) return [false, 'Select a valid account role.'];
    if ($role === 'admin' && !admin_signup_open()) return [false, 'An administrator already exists. Choose Staff or Warehouse / Delivery Staff.'];

    $check = db()->prepare('SELECT id FROM users WHERE email = ?');
    $check->execute([$email]);
    if ($check->fetch()) return [false, 'An account with this email already exists.'];

    $parts = preg_split('/\s+/', $name);
    $first = $parts[0] ?? 'U';
    $last = $parts[count($parts) - 1] ?? '';
    $avatar = strtoupper(mb_substr($first, 0, 1) . ($last !== $first ? mb_substr($last, 0, 1) : ''));

    $stmt = db()->prepare('INSERT INTO users (name, email, password, role, avatar) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $avatar ?: 'U']);
    return [true, 'Account created.'];
}

function logout(): void {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

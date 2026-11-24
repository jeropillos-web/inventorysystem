<?php
if (session_status() === PHP_SESSION_NONE) session_start();

const VALID_ROLES = ['admin', 'staff', 'warehouse'];

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
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([strtolower(trim($email))]);
        $user = $stmt->fetch();
        if ($user && in_array($user['role'], VALID_ROLES, true) && password_verify($password, $user['password'])) {
            $_SESSION['user'] = $user;
            return true;
        }
    }

    return false;
}

function register_user(string $name, string $email, string $password, string $role): array {
    require_once __DIR__ . '/db.php';
    if (!db_available()) return [false, 'The database is unavailable. Start MySQL and try again.'];

    $name = trim($name);
    $email = strtolower(trim($email));
    if ($name === '') return [false, 'Enter your full name.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, 'Enter a valid email address.'];
    if (strlen($password) < 8) return [false, 'Password must be at least 8 characters.'];
    if (!in_array($role, VALID_ROLES, true)) return [false, 'Select a valid account role.'];

    $check = db()->prepare('SELECT id FROM users WHERE email = ?');
    $check->execute([$email]);
    if ($check->fetch()) return [false, 'An account with this email already exists.'];

    $parts = preg_split('/\s+/', $name);
    $first = $parts[0] ?? 'U';
    $last = $parts[count($parts) - 1] ?? '';
    $avatar = strtoupper(substr($first, 0, 1) . ($last !== $first ? substr($last, 0, 1) : ''));

    $stmt = db()->prepare('INSERT INTO users (name, email, password, role, avatar) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $avatar ?: 'U']);
    return [true, 'Account created.'];
}

function logout(): void {
    session_destroy();
}

function role_can(string $role, string $action): bool {
    $perms = [
        'admin'     => ['read','write','delete','gps','gps_ping','scanner'],
        'staff'     => ['read','write'],
        'warehouse' => ['read','process_orders','gps_ping'],
    ];
    return in_array($action, $perms[$role] ?? []);
}

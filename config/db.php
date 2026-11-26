<?php
// Database connection.
// XAMPP (local): the defaults below work as-is.
// Hosting: create config/db.local.php that returns your host's settings, e.g.
//   <?php return ['host' => 'sql123.example.com', 'name' => 'epiz_1_invtrack', 'user' => 'epiz_1', 'pass' => 'secret'];
// db.local.php is listed in .gitignore so the password never goes to GitHub.

if (!function_exists('db_config')) {
    function db_config(): array {
        $cfg = ['host' => 'localhost', 'name' => 'invtrack', 'user' => 'root', 'pass' => '', 'charset' => 'utf8mb4'];
        $local = __DIR__ . '/db.local.php';
        if (is_file($local)) {
            $over = require $local;
            if (is_array($over)) $cfg = array_merge($cfg, $over);
        }
        return $cfg;
    }
}

if (!function_exists('db')) {
    function db(): PDO {
        static $pdo = null;
        if ($pdo === null) {
            $c   = db_config();
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $c['host'], $c['name'], $c['charset']);
            $pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("SET time_zone = '+08:00'");
        }
        return $pdo;
    }
}

if (!function_exists('db_available')) {
    function db_available(): bool {
        static $ok = null;
        if ($ok === null) {
            try { db(); $ok = true; } catch (Throwable $e) { $ok = false; }
        }
        return $ok;
    }
}

date_default_timezone_set('Asia/Manila');

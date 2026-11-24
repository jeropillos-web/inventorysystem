<?php

require_once __DIR__ . '/config/db.php';

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Run this page on the computer that hosts INVTrack (http://localhost/invtrack/upgrade.php).');
}

$results = [];
function step(string $label, callable $fn): void {
    global $results;
    try {
        $msg = $fn();
        $results[] = ['ok', $label, $msg ?: 'Done'];
    } catch (Throwable $e) {
        $results[] = ['fail', $label, $e->getMessage()];
    }
}

try {
    $pdo = db();
} catch (Throwable $e) {
    exit('<h2>Cannot connect to MySQL</h2><p>Start MySQL in the XAMPP Control Panel, then reload this page.</p><pre>' . htmlspecialchars($e->getMessage()) . '</pre>');
}
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();

function table_exists(PDO $pdo, string $t): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$t]);
    return (bool)$st->fetchColumn();
}
function column(PDO $pdo, string $t, string $c): ?array {
    $st = $pdo->prepare('SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$t, $c]);
    return $st->fetch() ?: null;
}
function index_exists(PDO $pdo, string $t, string $i): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $st->execute([$t, $i]);
    return (bool)$st->fetchColumn();
}
function fk_exists(PDO $pdo, string $t, string $fk): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
    $st->execute([$t, $fk]);
    return (bool)$st->fetchColumn();
}
function add_columns(PDO $pdo, string $t, array $cols): string {
    $added = [];
    foreach ($cols as $name => $def) {
        if (!column($pdo, $t, $name)) { $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$name` $def"); $added[] = $name; }
    }
    return $added ? 'Added: ' . implode(', ', $added) : 'Already up to date';
}

step('Shop orders: address and map location', fn() => add_columns($pdo, 'shop_orders', [
    'address'  => "VARCHAR(200) NOT NULL DEFAULT '' AFTER units",
    'dest_lat' => 'DECIMAL(9,6) NULL AFTER address',
    'dest_lng' => 'DECIMAL(9,6) NULL AFTER dest_lat',
]));
step('Shop orders: "Out for Delivery" status', function () use ($pdo) {
    $pdo->exec("ALTER TABLE shop_orders MODIFY status ENUM('Pending','Picking','Ready for Delivery','Out for Delivery','Completed') NOT NULL DEFAULT 'Pending'");
});

step('Shipments: approval and map columns', fn() => add_columns($pdo, 'shipments', [
    'dest_lat'    => 'DECIMAL(9,6) NULL AFTER destination',
    'dest_lng'    => 'DECIMAL(9,6) NULL AFTER dest_lat',
    'created_by'  => 'INT NULL',
    'created_at'  => 'DATETIME NULL',
    'approved_by' => 'INT NULL',
    'approved_at' => 'DATETIME NULL',
    'review_note' => "VARCHAR(255) NOT NULL DEFAULT ''",
]));
step('Shipments: new shipments start as "For Approval"', function () use ($pdo) {
    $pdo->exec("ALTER TABLE shipments MODIFY status VARCHAR(30) NOT NULL DEFAULT 'For Approval'");
});

step('Deliveries table', function () use ($pdo) {
    if (table_exists($pdo, 'deliveries')) return 'Already exists';
    $pdo->exec("CREATE TABLE deliveries (
      id             INT AUTO_INCREMENT PRIMARY KEY,
      shop_order_id  VARCHAR(30)  NULL,
      shipment_id    VARCHAR(20)  NULL,
      driver_user_id INT          NOT NULL,
      vehicle_id     VARCHAR(16)  NOT NULL,
      status         ENUM('Accepted','In Transit','Delivered') NOT NULL DEFAULT 'Accepted',
      accepted_at    DATETIME     NOT NULL,
      started_at     DATETIME     NULL,
      delivered_at   DATETIME     NULL,
      recipient_name VARCHAR(100) NOT NULL DEFAULT '',
      notes          VARCHAR(255) NOT NULL DEFAULT '',
      UNIQUE KEY uq_delivery_order (shop_order_id),
      UNIQUE KEY uq_delivery_shipment (shipment_id),
      INDEX idx_delivery_driver (driver_user_id, status),
      CONSTRAINT fk_delivery_order    FOREIGN KEY (shop_order_id)  REFERENCES shop_orders(id) ON DELETE CASCADE,
      CONSTRAINT fk_delivery_shipment FOREIGN KEY (shipment_id)    REFERENCES shipments(id)   ON DELETE CASCADE,
      CONSTRAINT fk_delivery_driver   FOREIGN KEY (driver_user_id) REFERENCES users(id)       ON DELETE CASCADE,
      CONSTRAINT fk_delivery_vehicle  FOREIGN KEY (vehicle_id)     REFERENCES vehicles(id)
    ) ENGINE=InnoDB");
    return 'Created';
});
step('Deliveries: shipment_id column', fn() => add_columns($pdo, 'deliveries', [
    'shipment_id' => 'VARCHAR(20) NULL AFTER shop_order_id',
]));
step('Deliveries: allow shipment-only deliveries', function () use ($pdo) {
    $col = column($pdo, 'deliveries', 'shop_order_id');
    if ($col && $col['IS_NULLABLE'] === 'YES') return 'Already up to date';
    if (fk_exists($pdo, 'deliveries', 'fk_delivery_order')) $pdo->exec('ALTER TABLE deliveries DROP FOREIGN KEY fk_delivery_order');
    $pdo->exec('ALTER TABLE deliveries MODIFY shop_order_id VARCHAR(30) NULL');
    $pdo->exec('ALTER TABLE deliveries ADD CONSTRAINT fk_delivery_order FOREIGN KEY (shop_order_id) REFERENCES shop_orders(id) ON DELETE CASCADE');
    return 'Updated';
});
step('Deliveries: one driver per shipment', function () use ($pdo) {
    if (index_exists($pdo, 'deliveries', 'uq_delivery_shipment')) return 'Already up to date';
    $pdo->exec('ALTER TABLE deliveries ADD UNIQUE KEY uq_delivery_shipment (shipment_id)');
    return 'Added';
});
step('Deliveries: link to shipments', function () use ($pdo) {
    if (fk_exists($pdo, 'deliveries', 'fk_delivery_shipment')) return 'Already up to date';
    $pdo->exec('ALTER TABLE deliveries ADD CONSTRAINT fk_delivery_shipment FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE');
    return 'Added';
});

step('Delivery cancellations table', function () use ($pdo) {
    if (table_exists($pdo, 'delivery_cancellations')) return 'Already exists';
    $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_cancellations (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  kind           ENUM('order','shipment') NOT NULL,
  job_id         VARCHAR(30)  NOT NULL,
  driver_user_id INT          NOT NULL,
  vehicle_id     VARCHAR(16)  NOT NULL,
  reason         VARCHAR(255) NOT NULL,
  was_started    TINYINT(1)   NOT NULL DEFAULT 0,
  cancelled_at   DATETIME     NOT NULL,
  INDEX idx_cancel_job (kind, job_id, cancelled_at),
  INDEX idx_cancel_driver (driver_user_id, cancelled_at),
  CONSTRAINT fk_cancel_driver FOREIGN KEY (driver_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB");
    return 'Created';
});

step('Sample Naga shipments (SHP-1001, SHP-1002)', function () use ($pdo) {
    $n = $pdo->exec("INSERT IGNORE INTO shipments (id, origin, destination, dest_lat, dest_lng, status, eta, carrier, items, weight, created_at) VALUES
      ('SHP-1001', 'PCS Computers Warehouse, Naga City', 'SM City Naga, CBD II, Naga City',      13.624500, 123.186800, 'For Approval', DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'In-house', 12, '35 kg', NOW()),
      ('SHP-1002', 'PCS Computers Warehouse, Naga City', 'Iriga City Public Market, Iriga City', 13.420700, 123.412300, 'Approved',     DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'In-house',  6, '18 kg', NOW())");
    return $n ? "Added $n" : 'Already there';
});

$failed = count(array_filter($results, fn($r) => $r[0] === 'fail'));
$server = $pdo->query('SELECT VERSION()')->fetchColumn();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>INVTrack Upgrade</title>
<style>
  body { font: 15px/1.5 system-ui, sans-serif; background: #f1f5f9; color: #0f172a; margin: 0; padding: 32px 16px; }
  main { max-width: 760px; margin: 0 auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 24px; }
  h1 { margin: 0 0 4px; font-size: 22px; } .muted { color: #64748b; font-size: 13px; }
  .banner { margin: 16px 0; padding: 12px 14px; border-radius: 10px; font-weight: 600; }
  .good { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; } .bad { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; } td { padding: 9px 8px; border-top: 1px solid #f1f5f9; vertical-align: top; }
  .ok { color: #059669; font-weight: 700; } .fail { color: #dc2626; font-weight: 700; } code { font-size: 12px; color: #991b1b; word-break: break-word; }
  a { color: #4f46e5; }
</style>
</head>
<body>
<main>
  <h1>INVTrack database upgrade</h1>
  <p class="muted">Database <b><?= htmlspecialchars($dbName) ?></b> on <?= htmlspecialchars($server) ?></p>
  <?php if ($failed): ?>
  <div class="banner bad"><?= $failed ?> step(s) failed. Take a screenshot of this page and send it for help.</div>
  <?php else: ?>
  <div class="banner good">All done. Your database is up to date.</div>
  <?php endif; ?>
  <table>
    <?php foreach ($results as [$state, $label, $msg]): ?>
    <tr>
      <td class="<?= $state ?>"><?= $state === 'ok' ? '✓' : '✗' ?></td>
      <td><?= htmlspecialchars($label) ?></td>
      <td><?= $state === 'ok' ? htmlspecialchars($msg) : '<code>' . htmlspecialchars($msg) . '</code>' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p style="margin-top:20px"><a href="dashboard.php">Back to INVTrack</a> · <a href="driver/">Driver app</a></p>
</main>
</body>
</html>

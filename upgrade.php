<?php
// Upgrades an existing INVTrack database to the current version without
// deleting its data. Safe to run more than once. For a brand-new database,
// import sql/invtrack.sql instead.

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/stock.php';

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
function column_exists(PDO $pdo, string $t, string $c): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$t, $c]);
    return (bool)$st->fetchColumn();
}
function create_table(PDO $pdo, string $t, string $ddl): string {
    if (table_exists($pdo, $t)) return 'Already exists';
    $pdo->exec("CREATE TABLE `$t` ($ddl) ENGINE=InnoDB");
    return 'Created';
}

// ── Warehouses and per-warehouse stock ──────────────────────────────────────
step('Warehouses table', fn() => create_table($pdo, 'warehouses', "
    id VARCHAR(20) PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE, address VARCHAR(200) NOT NULL DEFAULT '',
    is_main TINYINT(1) NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1"));
step('Main warehouse', function () use ($pdo) {
    if ((int)$pdo->query('SELECT COUNT(*) FROM warehouses')->fetchColumn() > 0) return 'Already there';
    $pdo->exec("INSERT INTO warehouses (id, name, address, is_main, active) VALUES
                ('WH-01', 'PCS Computers Naga', 'Yashano Mall, Diversion Road, Brgy. Triangulo, Naga City, Camarines Sur', 1, 1),
                ('WH-02', 'PCS Computers Legazpi', 'Legazpi City, Albay', 0, 1)");
    return 'Added PCS Computers Naga (main) and PCS Computers Legazpi';
});
step('Stock levels table', fn() => create_table($pdo, 'stock_levels', "
    item_id VARCHAR(20) NOT NULL, warehouse_id VARCHAR(20) NOT NULL, qty INT NOT NULL DEFAULT 0,
    PRIMARY KEY (item_id, warehouse_id),
    CONSTRAINT fk_level_item FOREIGN KEY (item_id) REFERENCES inventory(id) ON DELETE CASCADE,
    CONSTRAINT fk_level_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)"));
step('Stock movement history table', fn() => create_table($pdo, 'stock_movements', "
    id BIGINT AUTO_INCREMENT PRIMARY KEY, item_id VARCHAR(20) NOT NULL, warehouse_id VARCHAR(20) NOT NULL,
    qty_change INT NOT NULL, balance_after INT NOT NULL, type VARCHAR(30) NOT NULL,
    reference VARCHAR(30) NOT NULL DEFAULT '', note VARCHAR(255) NOT NULL DEFAULT '', user_id INT NULL, created_at DATETIME NOT NULL,
    INDEX idx_move_item (item_id, created_at), INDEX idx_move_time (created_at),
    CONSTRAINT fk_move_item FOREIGN KEY (item_id) REFERENCES inventory(id) ON DELETE CASCADE,
    CONSTRAINT fk_move_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_move_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL"));
step('Move current stock into the main warehouse', function () use ($pdo) {
    if ((int)$pdo->query('SELECT COUNT(*) FROM stock_levels')->fetchColumn() > 0) return 'Already done';
    $main = main_warehouse_id($pdo);
    $n = $pdo->prepare('INSERT INTO stock_levels (item_id, warehouse_id, qty) SELECT id, ?, qty FROM inventory WHERE qty > 0');
    $n->execute([$main]);
    $pdo->prepare("INSERT INTO stock_movements (item_id, warehouse_id, qty_change, balance_after, type, reference, note, user_id, created_at)
                   SELECT id, ?, qty, qty, 'Initial Stock', '', 'Opening balance (upgrade)', NULL, NOW() FROM inventory WHERE qty > 0")
        ->execute([$main]);
    return 'Moved ' . $n->rowCount() . ' product(s)';
});
step('Recompute stock status from quantity and reorder level', function () use ($pdo) {
    $n = $pdo->exec("UPDATE inventory SET status = CASE WHEN qty <= 0 THEN 'Out of Stock' WHEN qty <= reorder THEN 'Low Stock' ELSE 'In Stock' END");
    return "Checked $n product(s)";
});

// ── Returns, disposal, transfers, tracking log ──────────────────────────────
step('Returns table', fn() => create_table($pdo, 'item_returns', "
    id INT AUTO_INCREMENT PRIMARY KEY, item_id VARCHAR(20) NOT NULL, warehouse_id VARCHAR(20) NOT NULL, qty INT NOT NULL,
    source VARCHAR(20) NOT NULL, source_ref VARCHAR(120) NOT NULL DEFAULT '', reason VARCHAR(60) NOT NULL,
    item_condition VARCHAR(20) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'Pending Inspection', disposition VARCHAR(30) NULL,
    supplier VARCHAR(100) NOT NULL DEFAULT '', notes VARCHAR(255) NOT NULL DEFAULT '', decision_note VARCHAR(255) NOT NULL DEFAULT '',
    created_by INT NULL, created_at DATETIME NOT NULL, decided_by INT NULL, decided_at DATETIME NULL,
    INDEX idx_return_status (status),
    CONSTRAINT fk_return_item FOREIGN KEY (item_id) REFERENCES inventory(id),
    CONSTRAINT fk_return_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_return_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_return_decider FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL"));
step('Disposal table', fn() => create_table($pdo, 'disposals', "
    id INT AUTO_INCREMENT PRIMARY KEY, item_id VARCHAR(20) NOT NULL, warehouse_id VARCHAR(20) NOT NULL, qty INT NOT NULL,
    reason VARCHAR(40) NOT NULL, method VARCHAR(60) NOT NULL, return_id INT NULL, unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status VARCHAR(20) NOT NULL DEFAULT 'Pending Approval', notes VARCHAR(255) NOT NULL DEFAULT '',
    review_note VARCHAR(255) NOT NULL DEFAULT '', reference VARCHAR(80) NOT NULL DEFAULT '',
    requested_by INT NULL, requested_at DATETIME NOT NULL, approved_by INT NULL, approved_at DATETIME NULL,
    disposed_by INT NULL, disposed_at DATETIME NULL, INDEX idx_disposal_status (status),
    CONSTRAINT fk_disposal_item FOREIGN KEY (item_id) REFERENCES inventory(id),
    CONSTRAINT fk_disposal_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_disposal_return FOREIGN KEY (return_id) REFERENCES item_returns(id) ON DELETE SET NULL,
    CONSTRAINT fk_disposal_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_disposal_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_disposal_disposer FOREIGN KEY (disposed_by) REFERENCES users(id) ON DELETE SET NULL"));
step('Transfers table', fn() => create_table($pdo, 'transfers', "
    id INT AUTO_INCREMENT PRIMARY KEY, from_warehouse VARCHAR(20) NOT NULL, to_warehouse VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Requested', notes VARCHAR(255) NOT NULL DEFAULT '', review_note VARCHAR(255) NOT NULL DEFAULT '',
    requested_by INT NULL, requested_at DATETIME NOT NULL, approved_by INT NULL, approved_at DATETIME NULL,
    dispatched_by INT NULL, dispatched_at DATETIME NULL, received_by INT NULL, received_at DATETIME NULL,
    INDEX idx_transfer_status (status),
    CONSTRAINT fk_transfer_from FOREIGN KEY (from_warehouse) REFERENCES warehouses(id),
    CONSTRAINT fk_transfer_to FOREIGN KEY (to_warehouse) REFERENCES warehouses(id),
    CONSTRAINT fk_transfer_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_transfer_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_transfer_sender FOREIGN KEY (dispatched_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_transfer_receiver FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL"));
step('Transfer items table', fn() => create_table($pdo, 'transfer_items', "
    transfer_id INT NOT NULL, item_id VARCHAR(20) NOT NULL, qty INT NOT NULL, PRIMARY KEY (transfer_id, item_id),
    CONSTRAINT fk_titem_transfer FOREIGN KEY (transfer_id) REFERENCES transfers(id) ON DELETE CASCADE,
    CONSTRAINT fk_titem_item FOREIGN KEY (item_id) REFERENCES inventory(id)"));
step('Tracking log table', fn() => create_table($pdo, 'tracking_logs', "
    id BIGINT AUTO_INCREMENT PRIMARY KEY, ref_type VARCHAR(20) NOT NULL, ref_id VARCHAR(30) NOT NULL, status VARCHAR(40) NOT NULL,
    location VARCHAR(150) NOT NULL DEFAULT '', remarks VARCHAR(255) NOT NULL DEFAULT '', logged_by INT NULL, logged_at DATETIME NOT NULL,
    INDEX idx_track_ref (ref_type, ref_id, logged_at),
    CONSTRAINT fk_track_user FOREIGN KEY (logged_by) REFERENCES users(id) ON DELETE SET NULL"));

// ── Shop orders, shipments, deliveries (older versions may lack these) ──────
step('Shop orders: address and "Out for Delivery"', function () use ($pdo) {
    if (!column_exists($pdo, 'shop_orders', 'address')) $pdo->exec("ALTER TABLE shop_orders ADD COLUMN address VARCHAR(200) NOT NULL DEFAULT '' AFTER units");
    $pdo->exec("ALTER TABLE shop_orders MODIFY status ENUM('Pending','Picking','Ready for Delivery','Out for Delivery','Completed') NOT NULL DEFAULT 'Pending'");
});
step('Shipments: approval columns', function () use ($pdo) {
    $added = [];
    foreach (['created_by' => 'INT NULL', 'created_at' => 'DATETIME NULL', 'approved_by' => 'INT NULL',
              'approved_at' => 'DATETIME NULL', 'review_note' => "VARCHAR(255) NOT NULL DEFAULT ''"] as $c => $def) {
        if (!column_exists($pdo, 'shipments', $c)) { $pdo->exec("ALTER TABLE shipments ADD COLUMN $c $def"); $added[] = $c; }
    }
    $pdo->exec("ALTER TABLE shipments MODIFY status VARCHAR(30) NOT NULL DEFAULT 'For Approval'");
    return $added ? 'Added: ' . implode(', ', $added) : 'Already up to date';
});
step('Vehicles table', function () use ($pdo) {
    if (!table_exists($pdo, 'vehicles')) {
        $pdo->exec("CREATE TABLE vehicles (id VARCHAR(16) PRIMARY KEY, plate VARCHAR(20) NOT NULL DEFAULT '',
                    type VARCHAR(40) NOT NULL DEFAULT '', active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO vehicles (id, plate, type, active) VALUES ('V-01','','Delivery van',1),('V-02','','Motorcycle',1),('V-03','','Pickup truck',1)");
        return 'Created';
    }
    if (!column_exists($pdo, 'vehicles', 'type')) $pdo->exec("ALTER TABLE vehicles ADD COLUMN type VARCHAR(40) NOT NULL DEFAULT ''");
    // Old GPS columns that are no longer used: make them optional so new vehicles can be added.
    if (column_exists($pdo, 'vehicles', 'driver_name')) $pdo->exec("ALTER TABLE vehicles MODIFY driver_name VARCHAR(100) NOT NULL DEFAULT ''");
    return 'Up to date';
});
step('Deliveries table', fn() => create_table($pdo, 'deliveries', "
    id INT AUTO_INCREMENT PRIMARY KEY, shop_order_id VARCHAR(30) NULL, shipment_id VARCHAR(20) NULL,
    driver_user_id INT NOT NULL, vehicle_id VARCHAR(16) NOT NULL,
    status ENUM('Accepted','In Transit','Delivered') NOT NULL DEFAULT 'Accepted',
    accepted_at DATETIME NOT NULL, started_at DATETIME NULL, delivered_at DATETIME NULL,
    recipient_name VARCHAR(100) NOT NULL DEFAULT '', notes VARCHAR(255) NOT NULL DEFAULT '',
    UNIQUE KEY uq_delivery_order (shop_order_id), UNIQUE KEY uq_delivery_shipment (shipment_id),
    INDEX idx_delivery_driver (driver_user_id, status),
    CONSTRAINT fk_delivery_order FOREIGN KEY (shop_order_id) REFERENCES shop_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_shipment FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_driver FOREIGN KEY (driver_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)"));
step('Delivery cancellations table', fn() => create_table($pdo, 'delivery_cancellations', "
    id INT AUTO_INCREMENT PRIMARY KEY, kind ENUM('order','shipment') NOT NULL, job_id VARCHAR(30) NOT NULL,
    driver_user_id INT NOT NULL, vehicle_id VARCHAR(16) NOT NULL, reason VARCHAR(255) NOT NULL,
    was_started TINYINT(1) NOT NULL DEFAULT 0, cancelled_at DATETIME NOT NULL,
    INDEX idx_cancel_job (kind, job_id, cancelled_at), INDEX idx_cancel_driver (driver_user_id, cancelled_at),
    CONSTRAINT fk_cancel_driver FOREIGN KEY (driver_user_id) REFERENCES users(id) ON DELETE CASCADE"));

// ── Remove the real-time GPS data ───────────────────────────────────────────
step('Remove GPS position history (real-time GPS was replaced by the tracking log)', function () use ($pdo) {
    if (!table_exists($pdo, 'vehicle_positions')) return 'Already removed';
    $pdo->exec('DROP TABLE vehicle_positions');
    return 'Removed';
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
  a { color: #257d3d; }
</style>
</head>
<body>
<main>
  <h1>INVTrack database upgrade</h1>
  <p class="muted">Database <b><?= htmlspecialchars((string)$dbName) ?></b> on <?= htmlspecialchars((string)$server) ?></p>
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
      <td><?= $state === 'ok' ? htmlspecialchars((string)$msg) : '<code>' . htmlspecialchars($msg) . '</code>' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p style="margin-top:20px"><a href="dashboard.php">Back to INVTrack</a> · <a href="driver/">Delivery app</a></p>
</main>
</body>
</html>

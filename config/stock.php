<?php
// Shared stock and tracking helpers. Every change to a stock quantity goes
// through stock_change(), so the per-warehouse balance, the product total, the
// stock status and the stock movement history always agree.

require_once __DIR__ . '/db.php';

class StockError extends RuntimeException {}

// In Stock / Low Stock / Out of Stock, from the quantity and the reorder level.
function stock_status(int $qty, int $reorder): string {
    if ($qty <= 0) return 'Out of Stock';
    if ($qty <= $reorder) return 'Low Stock';
    return 'In Stock';
}

// Document numbers shown to users, e.g. RET-00012, DSP-00003, TRF-00007.
function doc_no(string $prefix, $id): string {
    return $prefix . '-' . str_pad((string)(int)$id, 5, '0', STR_PAD_LEFT);
}

// Turns a document number back into its row id (TRF-00007 -> 7). Returns 0 when it does not match.
function doc_id(string $prefix, string $no): int {
    return preg_match('/^' . preg_quote($prefix, '/') . '-0*(\d+)$/i', trim($no), $m) ? (int)$m[1] : 0;
}

// Adds (positive $delta) or removes (negative $delta) stock of one product in
// one warehouse. Must be called inside a transaction. Throws StockError when
// the warehouse does not have enough stock.
function stock_change(PDO $pdo, string $itemId, string $warehouseId, int $delta, string $type,
                      string $reference, ?int $userId, string $note = ''): int {
    if ($delta === 0) throw new StockError('The quantity must not be zero.');

    $item = $pdo->prepare('SELECT id, name FROM inventory WHERE id = ?');
    $item->execute([$itemId]);
    $itemRow = $item->fetch();
    if (!$itemRow) throw new StockError("Product $itemId does not exist.");

    $wh = $pdo->prepare('SELECT name FROM warehouses WHERE id = ?');
    $wh->execute([$warehouseId]);
    $whName = $wh->fetchColumn();
    if ($whName === false) throw new StockError("Warehouse $warehouseId does not exist.");

    $cur = $pdo->prepare('SELECT qty FROM stock_levels WHERE item_id = ? AND warehouse_id = ? FOR UPDATE');
    $cur->execute([$itemId, $warehouseId]);
    $have = $cur->fetchColumn();
    $have = $have === false ? null : (int)$have;

    $after = ($have ?? 0) + $delta;
    if ($after < 0) {
        throw new StockError(sprintf('Not enough stock of %s in %s: %d on hand, %d needed.',
            $itemRow['name'], $whName, $have ?? 0, -$delta));
    }

    if ($have === null) {
        $pdo->prepare('INSERT INTO stock_levels (item_id, warehouse_id, qty) VALUES (?, ?, ?)')
            ->execute([$itemId, $warehouseId, $after]);
    } else {
        $pdo->prepare('UPDATE stock_levels SET qty = ? WHERE item_id = ? AND warehouse_id = ?')
            ->execute([$after, $itemId, $warehouseId]);
    }

    refresh_item_total($pdo, $itemId);

    $pdo->prepare('INSERT INTO stock_movements (item_id, warehouse_id, qty_change, balance_after, type, reference, note, user_id, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$itemId, $warehouseId, $delta, $after, mb_substr($type, 0, 30), mb_substr($reference, 0, 30),
                   mb_substr($note, 0, 255), $userId]);

    return $after;
}

// Recomputes a product's total quantity (all warehouses) and its stock status.
function refresh_item_total(PDO $pdo, string $itemId): void {
    $sum = $pdo->prepare('SELECT COALESCE(SUM(qty), 0) FROM stock_levels WHERE item_id = ?');
    $sum->execute([$itemId]);
    $total = (int)$sum->fetchColumn();

    $r = $pdo->prepare('SELECT reorder FROM inventory WHERE id = ?');
    $r->execute([$itemId]);
    $reorder = (int)$r->fetchColumn();

    $pdo->prepare('UPDATE inventory SET qty = ?, status = ? WHERE id = ?')
        ->execute([$total, stock_status($total, $reorder), $itemId]);
}

// Adds one line to the text tracking log of a shipment, shop order or transfer.
function track_log(PDO $pdo, string $refType, string $refId, string $status, string $location = '',
                   string $remarks = '', ?int $userId = null): void {
    $pdo->prepare('INSERT INTO tracking_logs (ref_type, ref_id, status, location, remarks, logged_by, logged_at)
                   VALUES (?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$refType, $refId, mb_substr($status, 0, 40), mb_substr($location, 0, 150),
                   mb_substr($remarks, 0, 255), $userId]);
}

function warehouse_name(PDO $pdo, string $id): string {
    $st = $pdo->prepare('SELECT name FROM warehouses WHERE id = ?');
    $st->execute([$id]);
    return (string)($st->fetchColumn() ?: $id);
}

function main_warehouse_id(PDO $pdo): ?string {
    $id = $pdo->query('SELECT id FROM warehouses WHERE active = 1 ORDER BY is_main DESC, id LIMIT 1')->fetchColumn();
    return $id === false ? null : (string)$id;
}

// Small text helpers used by the API files.
function clean_text($v, int $max): string {
    return mb_substr(trim((string)($v ?? '')), 0, $max);
}

function pick(string $value, array $allowed, ?string $default = null): ?string {
    return in_array($value, $allowed, true) ? $value : $default;
}

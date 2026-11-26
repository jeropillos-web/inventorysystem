<?php
require_once __DIR__ . '/_base.php';

const ADJUST_REASONS = ['Received from supplier', 'Physical count correction', 'Found during inventory', 'Other'];

switch ($method) {
    case 'GET':
        need('read');
        if (isset($_GET['history'])) {
            // Stock card: every quantity change of one product, newest first.
            $st = db()->prepare(
                "SELECT m.created_at, m.type, m.reference, m.qty_change, m.balance_after, m.note,
                        w.name AS warehouse, u.name AS user_name
                 FROM stock_movements m
                 LEFT JOIN warehouses w ON w.id = m.warehouse_id
                 LEFT JOIN users u ON u.id = m.user_id
                 WHERE m.item_id = ?
                 ORDER BY m.created_at DESC, m.id DESC LIMIT 200");
            $st->execute([(string)$_GET['history']]);
            json_ok($st->fetchAll());
        }
        $rows = db()->query('SELECT * FROM inventory ORDER BY id')->fetchAll();
        json_ok($rows);

    case 'POST':
        if ($action === 'adjust') {
            // Add or remove stock in one warehouse, e.g. a supplier delivery or a count correction.
            need('stock_ops');
            $d      = input();
            $itemId = clean_text($d['item_id'] ?? $id, 20);
            $whId   = clean_text($d['warehouse_id'] ?? '', 20);
            $qty    = (int)($d['qty'] ?? 0);
            $reason = pick((string)($d['reason'] ?? ''), ADJUST_REASONS);
            $note   = clean_text($d['note'] ?? '', 255);
            if ($itemId === '' || $whId === '') json_err('Choose the product and the warehouse.');
            if ($qty === 0) json_err('Enter a quantity other than zero.');
            if (!$reason) json_err('Choose a reason for the adjustment.');
            if ($reason === 'Received from supplier' && $qty < 0) json_err('A supplier delivery must add stock (positive quantity).');
            if ($qty < 0 && !role_can($user['role'], 'approve')) json_err('Only an administrator can remove stock with an adjustment.', 403);
            if ($reason === 'Other' && $note === '') json_err('Explain the adjustment in the note.');

            $pdo = db();
            $pdo->beginTransaction();
            $type = $reason === 'Received from supplier' ? 'Stock In' : 'Adjustment';
            $balance = stock_change($pdo, $itemId, $whId, $qty, $type, '', $uid, trim($reason . ($note !== '' ? ': ' . $note : '')));
            $pdo->commit();
            json_ok(['balance' => $balance]);
        }

        need('write');
        $d    = input();
        $name = clean_text($d['name'] ?? '', 150);
        if ($name === '') json_err('Enter the item name.');
        $category = clean_text($d['category'] ?? '', 80);
        $reorder  = max(0, (int)($d['reorder'] ?? 5));
        $cost     = max(0, (float)($d['cost'] ?? 0));
        $qty      = max(0, (int)($d['qty'] ?? 0));
        $barcode  = clean_text($d['barcode'] ?? '', 50);
        $location = clean_text($d['location'] ?? '', 30);

        $pdo  = db();
        $whId = clean_text($d['warehouse_id'] ?? '', 20) ?: main_warehouse_id($pdo);
        if ($qty > 0 && !$whId) json_err('Add a warehouse first.');

        $pdo->beginTransaction();
        $newId = null;
        for ($try = 0; $try < 5 && !$newId; $try++) {
            $candidate = 'SKU-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            $exists = $pdo->prepare('SELECT COUNT(*) FROM inventory WHERE id = ?');
            $exists->execute([$candidate]);
            if (!$exists->fetchColumn()) $newId = $candidate;
        }
        if (!$newId) json_err('Could not create a product code. Try again.', 500);

        $pdo->prepare('INSERT INTO inventory (id, name, category, qty, reorder, status, location, cost, barcode)
                       VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?)')
            ->execute([$newId, $name, $category, $reorder, stock_status(0, $reorder), $location, $cost, $barcode]);
        if ($qty > 0) stock_change($pdo, $newId, $whId, $qty, 'Initial Stock', '', $uid, 'Opening balance');
        $pdo->commit();

        $row = $pdo->prepare('SELECT * FROM inventory WHERE id = ?');
        $row->execute([$newId]);
        json_ok($row->fetch(), 201);

    case 'PUT':
        need('write');
        if (!$id) json_err('Missing product id.');
        $d    = input();
        $name = clean_text($d['name'] ?? '', 150);
        if ($name === '') json_err('Enter the item name.');
        $pdo = db();
        $st = $pdo->prepare('UPDATE inventory SET name = ?, category = ?, reorder = ?, location = ?, cost = ?, barcode = ? WHERE id = ?');
        $st->execute([$name, clean_text($d['category'] ?? '', 80), max(0, (int)($d['reorder'] ?? 0)),
                      clean_text($d['location'] ?? '', 30), max(0, (float)($d['cost'] ?? 0)),
                      clean_text($d['barcode'] ?? '', 50), $id]);
        refresh_item_total($pdo, $id);   // the reorder level may have changed the stock status
        json_ok();

    case 'DELETE':
        need('delete');
        if (!$id) json_err('Missing product id.');
        $q = db()->prepare('SELECT qty FROM inventory WHERE id = ?');
        $q->execute([$id]);
        $qty = $q->fetchColumn();
        if ($qty === false) json_err('Product not found.', 404);
        if ((int)$qty > 0) json_err('This product still has stock. Transfer or dispose of it before deleting.', 409);
        db()->prepare('DELETE FROM inventory WHERE id = ?')->execute([$id]);
        json_ok();

    default:
        json_err('Method not allowed', 405);
}

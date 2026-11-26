<?php
require_once __DIR__ . '/_base.php';

switch ($method) {
    case 'GET':
        need('read');
        json_ok(db()->query(
            'SELECT w.*, COALESCE(SUM(s.qty), 0) AS units
             FROM warehouses w LEFT JOIN stock_levels s ON s.warehouse_id = w.id
             GROUP BY w.id, w.name, w.address, w.is_main, w.active
             ORDER BY w.is_main DESC, w.name')->fetchAll());

    case 'POST':
        need('approve');
        $d    = input();
        $name = clean_text($d['name'] ?? '', 100);
        if ($name === '') json_err('Enter the warehouse name.');
        $pdo = db();
        $dup = $pdo->prepare('SELECT COUNT(*) FROM warehouses WHERE name = ?');
        $dup->execute([$name]);
        if ($dup->fetchColumn()) json_err('A warehouse with this name already exists.', 409);
        $n = (int)$pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(id, 4) AS UNSIGNED)), 0) FROM warehouses WHERE id LIKE 'WH-%'")->fetchColumn();
        $newId = 'WH-' . str_pad((string)($n + 1), 2, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO warehouses (id, name, address, is_main, active) VALUES (?, ?, ?, 0, 1)')
            ->execute([$newId, $name, clean_text($d['address'] ?? '', 200)]);
        json_ok(['id' => $newId], 201);

    case 'PUT':
        need('approve');
        if (!$id) json_err('Missing warehouse id.');
        $d    = input();
        $name = clean_text($d['name'] ?? '', 100);
        if ($name === '') json_err('Enter the warehouse name.');
        $active = !empty($d['active']) ? 1 : 0;
        $pdo = db();
        $w = $pdo->prepare('SELECT is_main FROM warehouses WHERE id = ?');
        $w->execute([$id]);
        $isMain = $w->fetchColumn();
        if ($isMain === false) json_err('Warehouse not found.', 404);
        if ((int)$isMain === 1 && !$active) json_err('The main warehouse cannot be deactivated.');
        if (!$active) {
            $units = $pdo->prepare('SELECT COALESCE(SUM(qty), 0) FROM stock_levels WHERE warehouse_id = ?');
            $units->execute([$id]);
            if ((int)$units->fetchColumn() > 0) json_err('This warehouse still holds stock. Transfer it out before deactivating.', 409);
        }
        $pdo->prepare('UPDATE warehouses SET name = ?, address = ?, active = ? WHERE id = ?')
            ->execute([$name, clean_text($d['address'] ?? '', 200), $active, $id]);
        json_ok();

    default:
        json_err('Method not allowed', 405);
}

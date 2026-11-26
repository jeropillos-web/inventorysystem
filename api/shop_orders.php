<?php
require_once __DIR__ . '/_base.php';

switch ($method) {
    case 'GET':
        need('read');
        json_ok(db()->query('SELECT * FROM shop_orders ORDER BY requested_at DESC')->fetchAll());

    case 'POST':
        need('write');
        $d       = input();
        $shop    = clean_text($d['shop'] ?? '', 120);
        $address = clean_text($d['address'] ?? '', 200);
        $items   = clean_text($d['items'] ?? '', 500);
        $units   = (int)($d['units'] ?? 0);
        if ($shop === '' || $address === '' || $items === '') json_err('Enter the shop, the address and the requested items.');
        if ($units < 1) json_err('Total units must be at least 1.');

        $pdo = db();
        $pdo->beginTransaction();
        $n = (int)$pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(id, 4) AS UNSIGNED)), 1000) FROM shop_orders WHERE id LIKE 'SO-%'")->fetchColumn();
        $newId = 'SO-' . ($n + 1);
        $pdo->prepare("INSERT INTO shop_orders (id, shop, items, units, address, requested_at, status) VALUES (?, ?, ?, ?, ?, NOW(), 'Pending')")
            ->execute([$newId, $shop, $items, $units, $address]);
        track_log($pdo, 'shop_order', $newId, 'Created', $shop, 'Order recorded: ' . $units . ' unit(s)', $uid);
        $pdo->commit();
        json_ok(['id' => $newId], 201);

    default:
        json_err('Method not allowed', 405);
}

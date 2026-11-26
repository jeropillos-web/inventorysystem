<?php
require_once __DIR__ . '/_base.php';

switch ($method) {
    case 'GET':
        need('read');
        json_ok(db()->query('SELECT * FROM suppliers ORDER BY name')->fetchAll());

    case 'POST':
        need('write');
        $d    = input();
        $name = clean_text($d['name'] ?? '', 100);
        if ($name === '') json_err('Enter the supplier name.');
        $status = pick((string)($d['status'] ?? 'Active'), ['Active', 'Under Review', 'Inactive'], 'Active');
        $pdo = db();
        $n = (int)$pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(id, 5) AS UNSIGNED)), 0) FROM suppliers WHERE id LIKE 'SUP-%'")->fetchColumn();
        $newId = 'SUP-' . str_pad((string)($n + 1), 3, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO suppliers (id, name, country, category, rating, orders, spend, on_time, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $newId, $name, clean_text($d['country'] ?? 'Philippines', 60), clean_text($d['category'] ?? '', 60),
                min(5, max(0, (float)($d['rating'] ?? 0))), max(0, (int)($d['orders'] ?? 0)),
                max(0, (float)($d['spend'] ?? 0)), min(100, max(0, (int)($d['on_time'] ?? 0))), $status,
            ]);
        $row = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
        $row->execute([$newId]);
        json_ok($row->fetch(), 201);

    case 'DELETE':
        need('delete');
        if (!$id) json_err('Missing supplier id.');
        db()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]);
        json_ok();

    default:
        json_err('Method not allowed', 405);
}

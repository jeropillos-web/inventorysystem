<?php
require_once __DIR__ . '/_base.php';

switch ($method) {
    case 'GET':
        json_ok(db()->query('SELECT * FROM suppliers ORDER BY name')->fetchAll());

    case 'POST':
        $d  = input();
        $id = 'SUP-' . str_pad(rand(6, 999), 3, '0', STR_PAD_LEFT);
        db()->prepare('INSERT INTO suppliers (id,name,country,category,rating,orders,spend,on_time,status) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([
               $id, $d['name'] ?? '', $d['country'] ?? '', $d['category'] ?? '',
               (float)($d['rating'] ?? 0), (int)($d['orders'] ?? 0),
               (float)($d['spend'] ?? 0), (int)($d['on_time'] ?? 0),
               $d['status'] ?? 'Active',
           ]);
        $row = db()->query("SELECT * FROM suppliers WHERE id='$id'")->fetch();
        json_ok($row, 201);

    case 'DELETE':
        if (!$id) json_err('Missing id');
        db()->prepare('DELETE FROM suppliers WHERE id=?')->execute([$id]);
        json_ok();

    default:
        json_err('Method not allowed', 405);
}

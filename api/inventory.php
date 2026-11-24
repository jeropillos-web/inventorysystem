<?php
require_once __DIR__ . '/_base.php';

switch ($method) {
    case 'GET':
        $rows = db()->query('SELECT * FROM inventory ORDER BY id')->fetchAll();
        json_ok($rows);

    case 'POST':
        $d = input();
        $id = 'SKU-' . strtoupper(uniqid());
        db()->prepare('INSERT INTO inventory (id,name,category,qty,reorder,status,location,cost,barcode)
                       VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([
               $id,
               $d['name'] ?? '',
               $d['category'] ?? '',
               (int)($d['qty'] ?? 0),
               (int)($d['reorder'] ?? 5),
               $d['status'] ?? 'In Stock',
               $d['location'] ?? '',
               (float)($d['cost'] ?? 0),
               $d['barcode'] ?? (string)time(),
           ]);
        $row = db()->query("SELECT * FROM inventory WHERE id='$id'")->fetch();
        json_ok($row, 201);

    case 'PUT':
        if (!$id) json_err('Missing id');
        $d = input();
        db()->prepare('UPDATE inventory SET name=?,category=?,qty=?,reorder=?,status=?,location=?,cost=?,barcode=? WHERE id=?')
           ->execute([
               $d['name'], $d['category'], (int)$d['qty'], (int)$d['reorder'],
               $d['status'], $d['location'], (float)$d['cost'], $d['barcode'], $id
           ]);
        json_ok();

    case 'DELETE':
        if (!$id) json_err('Missing id');
        db()->prepare('DELETE FROM inventory WHERE id=?')->execute([$id]);
        json_ok();

    default:
        json_err('Method not allowed', 405);
}

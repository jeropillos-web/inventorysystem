<?php
require_once __DIR__ . '/_base.php';

switch ($method) {
    case 'GET':
        json_ok(db()->query('SELECT * FROM categories ORDER BY name')->fetchAll());

    case 'POST':
        $d  = input();
        $id = 'CAT-' . strtoupper(substr(md5(uniqid()), 0, 6));
        db()->prepare('INSERT INTO categories (id,name,color,description) VALUES (?,?,?,?)')
           ->execute([$id, $d['name'] ?? 'New Category', $d['color'] ?? '#6366F1', $d['description'] ?? '']);
        $row = db()->query("SELECT * FROM categories WHERE id='$id'")->fetch();
        json_ok($row, 201);

    case 'DELETE':
        if (!$id) json_err('Missing id');
        db()->prepare('DELETE FROM categories WHERE id=?')->execute([$id]);
        json_ok();

    default:
        json_err('Method not allowed', 405);
}

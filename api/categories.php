<?php
require_once __DIR__ . '/_base.php';

switch ($method) {
    case 'GET':
        need('read');
        json_ok(db()->query('SELECT * FROM categories ORDER BY name')->fetchAll());

    case 'POST':
        need('write');
        $d     = input();
        $name  = clean_text($d['name'] ?? '', 80);
        if ($name === '') json_err('Enter the category name.');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($d['color'] ?? '')) ? $d['color'] : '#6366F1';
        $dup = db()->prepare('SELECT COUNT(*) FROM categories WHERE name = ?');
        $dup->execute([$name]);
        if ($dup->fetchColumn()) json_err('A category with this name already exists.', 409);
        $newId = 'CAT-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        db()->prepare('INSERT INTO categories (id, name, color, description) VALUES (?, ?, ?, ?)')
            ->execute([$newId, $name, $color, clean_text($d['description'] ?? '', 200)]);
        $row = db()->prepare('SELECT * FROM categories WHERE id = ?');
        $row->execute([$newId]);
        json_ok($row->fetch(), 201);

    case 'DELETE':
        need('delete');
        if (!$id) json_err('Missing category id.');
        $cat = db()->prepare('SELECT name FROM categories WHERE id = ?');
        $cat->execute([$id]);
        $name = $cat->fetchColumn();
        if ($name === false) json_err('Category not found.', 404);
        $used = db()->prepare('SELECT COUNT(*) FROM inventory WHERE category = ?');
        $used->execute([$name]);
        if ($used->fetchColumn()) json_err('Products still use this category. Move them to another category first.', 409);
        db()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        json_ok();

    default:
        json_err('Method not allowed', 405);
}

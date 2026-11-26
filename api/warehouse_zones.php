<?php
require_once __DIR__ . '/_base.php';

switch ($method) {
    case 'GET':
        need('read');
        json_ok(db()->query('SELECT * FROM warehouse_zones ORDER BY zone')->fetchAll());

    case 'PUT':
        need('approve');
        $d        = input();
        $capacity = max(0, (int)($d['capacity'] ?? 0));
        $occupied = max(0, (int)($d['occupied'] ?? 0));
        if ($occupied > $capacity) json_err('Occupied space cannot be more than the capacity.');
        $st = db()->prepare('UPDATE warehouse_zones SET capacity = ?, occupied = ?, type = ?, temp = ? WHERE zone = ?');
        $st->execute([$capacity, $occupied, clean_text($d['type'] ?? '', 60), clean_text($d['temp'] ?? 'Ambient', 30), clean_text($d['zone'] ?? '', 30)]);
        json_ok();

    default:
        json_err('Method not allowed', 405);
}

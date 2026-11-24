<?php
require_once __DIR__ . '/_base.php';

switch ($method) {
    case 'GET':
        json_ok(db()->query('SELECT * FROM warehouse_zones ORDER BY zone')->fetchAll());

    case 'PUT':
        $d = input();
        db()->prepare('UPDATE warehouse_zones SET capacity=?,occupied=?,type=?,temp=? WHERE zone=?')
           ->execute([(int)$d['capacity'], (int)$d['occupied'], $d['type'], $d['temp'], $d['zone']]);
        json_ok();

    default:
        json_err('Method not allowed', 405);
}

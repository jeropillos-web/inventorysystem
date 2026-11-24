<?php
require_once __DIR__ . '/_base.php';

switch ($method) {
    case 'GET':
        $q   = trim($_GET['q'] ?? '');
        $sql = 'SELECT * FROM purchase_orders';
        $params = [];
        if ($q !== '') {
            $sql .= ' WHERE id LIKE ? OR supplier LIKE ? OR status LIKE ?';
            $like = "%$q%";
            $params = [$like, $like, $like];
        }
        $sql .= ' ORDER BY order_date DESC';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        json_ok($stmt->fetchAll());

    case 'POST':
        $d  = input();
        $n  = db()->query('SELECT COUNT(*)+446 AS n FROM purchase_orders')->fetch()['n'];
        $id = 'PO-' . date('Y') . '-' . str_pad($n, 4, '0', STR_PAD_LEFT);
        db()->prepare('INSERT INTO purchase_orders (id,supplier,items,value,order_date,delivery_date,status) VALUES (?,?,?,?,?,?,?)')
           ->execute([
               $id,
               $d['supplier'] ?? '',
               (int)($d['items'] ?? 0),
               (float)($d['value'] ?? 0),
               $d['date'] ?? date('Y-m-d'),
               $d['deliveryDate'] ?? date('Y-m-d', strtotime('+14 days')),
               $d['status'] ?? 'Pending',
           ]);
        $row = db()->query("SELECT * FROM purchase_orders WHERE id='$id'")->fetch();
        json_ok($row, 201);

    case 'DELETE':
        if (!$id) json_err('Missing id');
        db()->prepare('DELETE FROM purchase_orders WHERE id=?')->execute([$id]);
        json_ok();

    default:
        json_err('Method not allowed', 405);
}

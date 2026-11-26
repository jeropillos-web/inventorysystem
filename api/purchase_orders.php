<?php
require_once __DIR__ . '/_base.php';

const PO_STATUSES = ['Pending', 'In Review', 'Confirmed', 'Delivered', 'Cancelled'];

function valid_date($v): ?string {
    $v = (string)$v;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
    [$y, $m, $d] = array_map('intval', explode('-', $v));
    return checkdate($m, $d, $y) ? $v : null;
}

switch ($method) {
    case 'GET':
        need('read');
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
        need('write');
        $d        = input();
        $supplier = clean_text($d['supplier'] ?? '', 100);
        if ($supplier === '') json_err('Choose a supplier.');
        $orderDate    = valid_date($d['order_date'] ?? $d['date'] ?? '') ?? date('Y-m-d');
        $deliveryDate = valid_date($d['delivery_date'] ?? $d['deliveryDate'] ?? '') ?? date('Y-m-d', strtotime($orderDate . ' +14 days'));
        if ($deliveryDate < $orderDate) json_err('The delivery date cannot be earlier than the order date.');
        $items  = max(1, (int)($d['items'] ?? 1));
        $value  = max(0, (float)($d['value'] ?? 0));
        $status = pick((string)($d['status'] ?? 'Pending'), PO_STATUSES, 'Pending');

        $pdo    = db();
        $year   = date('Y');
        $prefix = "PO-$year-";
        $max = $pdo->prepare('SELECT COALESCE(MAX(CAST(SUBSTRING(id, 9) AS UNSIGNED)), 0) FROM purchase_orders WHERE id LIKE ?');
        $max->execute([$prefix . '%']);
        $n = (int)$max->fetchColumn();
        $insert = $pdo->prepare('INSERT INTO purchase_orders (id, supplier, items, value, order_date, delivery_date, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
        for ($try = 1; ; $try++) {
            $newId = $prefix . str_pad((string)($n + $try), 4, '0', STR_PAD_LEFT);
            try {
                $insert->execute([$newId, $supplier, $items, $value, $orderDate, $deliveryDate, $status]);
                break;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000' || $try >= 5) throw $e;
            }
        }
        $row = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ?');
        $row->execute([$newId]);
        json_ok($row->fetch(), 201);

    case 'PUT':
        need('write');
        if (!$id) json_err('Missing PO number.');
        $status = pick((string)(input()['status'] ?? ''), PO_STATUSES);
        if (!$status) json_err('Choose a valid status.');
        db()->prepare('UPDATE purchase_orders SET status = ? WHERE id = ?')->execute([$status, $id]);
        json_ok();

    case 'DELETE':
        need('delete');
        if (!$id) json_err('Missing PO number.');
        db()->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$id]);
        json_ok();

    default:
        json_err('Method not allowed', 405);
}

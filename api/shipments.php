<?php
require_once __DIR__ . '/_base.php';

$user = auth_user();
if (!$user) json_err('Not signed in', 401);
$action = $_GET['action'] ?? '';

function coord_or_null($v, float $limit): ?float {
    if ($v === null || $v === '') return null;
    $f = filter_var($v, FILTER_VALIDATE_FLOAT);
    return ($f === false || abs($f) > $limit) ? null : $f;
}

switch ($method) {
    case 'GET':
        json_ok(db()->query("SELECT * FROM shipments
                             ORDER BY FIELD(status,'For Approval','Approved','Picked Up','In Transit') DESC, eta DESC")->fetchAll());

    case 'POST':
        if ($action === 'approve' || $action === 'reject') {
            if ($user['role'] !== 'warehouse') json_err('Only warehouse personnel can review shipments', 403);
            if (!$id) json_err('Missing id');
            $d    = input();
            $note = mb_substr(trim((string)($d['note'] ?? '')), 0, 255);
            if ($action === 'reject' && $note === '') json_err('Give a reason for rejecting the shipment');

            $stmt = db()->prepare("UPDATE shipments
                                   SET status = ?, approved_by = ?, approved_at = NOW(), review_note = ?
                                   WHERE id = ? AND status = 'For Approval'");
            $stmt->execute([$action === 'approve' ? 'Approved' : 'Rejected', (int)$user['id'], $note, $id]);
            if (!$stmt->rowCount()) json_err('This shipment was already reviewed', 409);
            json_ok();
        }

        if (!role_can($user['role'], 'write')) json_err('Your account cannot create shipments', 403);
        $d           = input();
        $origin      = trim((string)($d['origin'] ?? ''));
        $destination = trim((string)($d['destination'] ?? ''));
        if ($origin === '' || $destination === '') json_err('Origin and destination are required');
        $eta = (string)($d['eta'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eta)) $eta = date('Y-m-d', strtotime('+1 day'));

        $insert = db()->prepare('INSERT INTO shipments
            (id, origin, destination, dest_lat, dest_lng, status, eta, carrier, items, weight, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, \'For Approval\', ?, ?, ?, ?, ?, NOW())');
        for ($try = 0; ; $try++) {
            $newId = 'SHP-' . random_int(10000, 99999);
            try {
                $insert->execute([
                    $newId, mb_substr($origin, 0, 100), mb_substr($destination, 0, 100),
                    coord_or_null($d['dest_lat'] ?? null, 90), coord_or_null($d['dest_lng'] ?? null, 180),
                    $eta, mb_substr(trim((string)($d['carrier'] ?? '')) ?: 'In-house', 0, 80),
                    max(1, (int)($d['items'] ?? 1)), mb_substr(trim((string)($d['weight'] ?? '')), 0, 30),
                    (int)$user['id'],
                ]);
                break;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000' || $try >= 5) throw $e;
            }
        }
        $row = db()->prepare('SELECT * FROM shipments WHERE id = ?');
        $row->execute([$newId]);
        json_ok($row->fetch(), 201);

    case 'DELETE':
        if (!role_can($user['role'], 'delete')) json_err('Only administrators can delete shipments', 403);
        if (!$id) json_err('Missing id');
        $pdo = db();
        $pdo->beginTransaction();
        $v = $pdo->prepare("SELECT vehicle_id FROM deliveries WHERE shipment_id = ? AND status IN ('Accepted','In Transit')");
        $v->execute([$id]);
        if ($vid = $v->fetchColumn()) {
            $pdo->prepare("UPDATE vehicles SET route_label = 'Idle', dest_lat = NULL, dest_lng = NULL WHERE id = ?")->execute([$vid]);
        }
        $pdo->prepare('DELETE FROM deliveries WHERE shipment_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM shipments WHERE id = ?')->execute([$id]);
        $pdo->commit();
        json_ok();

    default:
        json_err('Method not allowed', 405);
}

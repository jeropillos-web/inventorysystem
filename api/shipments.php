<?php
require_once __DIR__ . '/_base.php';

// Shipment flow: Admin/Staff create it (For Approval) -> Warehouse approves (Approved)
// or rejects (Rejected) -> a driver accepts it in the delivery app (In Transit)
// -> the driver confirms delivery (Delivered). Every step is written to the tracking log.

switch ($method) {
    case 'GET':
        need('read');
        json_ok(db()->query("SELECT * FROM shipments ORDER BY created_at DESC, id DESC")->fetchAll());

    case 'POST':
        if ($action === 'approve' || $action === 'reject') {
            need('process_orders');
            if (!$id) json_err('Missing shipment id.');
            $note = clean_text(input()['note'] ?? '', 255);
            if ($action === 'reject' && $note === '') json_err('Give a reason for rejecting the shipment.');

            $pdo = db();
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE shipments SET status = ?, approved_by = ?, approved_at = NOW(), review_note = ?
                                   WHERE id = ? AND status = 'For Approval'");
            $stmt->execute([$action === 'approve' ? 'Approved' : 'Rejected', $uid, $note, $id]);
            if (!$stmt->rowCount()) { $pdo->rollBack(); json_err('This shipment was already reviewed.', 409); }
            $origin = $pdo->prepare('SELECT origin FROM shipments WHERE id = ?');
            $origin->execute([$id]);
            track_log($pdo, 'shipment', $id, $action === 'approve' ? 'Approved' : 'Rejected', (string)$origin->fetchColumn(),
                      $action === 'approve' ? 'Approved by the warehouse; waiting for a driver' : 'Rejected: ' . $note, $uid);
            $pdo->commit();
            json_ok();
        }

        need('write');
        $d           = input();
        $origin      = clean_text($d['origin'] ?? '', 100);
        $destination = clean_text($d['destination'] ?? '', 100);
        if ($origin === '' || $destination === '') json_err('Origin and destination are required.');
        $eta = (string)($d['eta'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eta)) $eta = date('Y-m-d', strtotime('+1 day'));
        if ($eta < date('Y-m-d')) json_err('The delivery date cannot be in the past.');

        $pdo = db();
        $pdo->beginTransaction();
        $insert = $pdo->prepare("INSERT INTO shipments
            (id, origin, destination, status, eta, carrier, items, weight, created_by, created_at)
            VALUES (?, ?, ?, 'For Approval', ?, ?, ?, ?, ?, NOW())");
        $exists = $pdo->prepare('SELECT COUNT(*) FROM shipments WHERE id = ?');
        $newId = null;
        for ($try = 0; $try < 10 && !$newId; $try++) {
            $candidate = 'SHP-' . random_int(10000, 99999);
            $exists->execute([$candidate]);
            if (!$exists->fetchColumn()) $newId = $candidate;
        }
        if (!$newId) json_err('Could not create a shipment number. Try again.', 500);
        $insert->execute([
            $newId, $origin, $destination, $eta,
            clean_text($d['carrier'] ?? '', 80) ?: 'In-house',
            max(1, (int)($d['items'] ?? 1)), clean_text($d['weight'] ?? '', 30), $uid,
        ]);
        track_log($pdo, 'shipment', $newId, 'Created', $origin, 'Shipment to ' . $destination . ' sent for warehouse approval', $uid);
        $pdo->commit();

        $row = $pdo->prepare('SELECT * FROM shipments WHERE id = ?');
        $row->execute([$newId]);
        json_ok($row->fetch(), 201);

    case 'DELETE':
        need('delete');
        if (!$id) json_err('Missing shipment id.');
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM deliveries WHERE shipment_id = ?')->execute([$id]);
        $pdo->prepare("DELETE FROM delivery_cancellations WHERE kind = 'shipment' AND job_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM tracking_logs WHERE ref_type = 'shipment' AND ref_id = ?")->execute([$id]);
        $pdo->prepare('DELETE FROM shipments WHERE id = ?')->execute([$id]);
        $pdo->commit();
        json_ok();

    default:
        json_err('Method not allowed', 405);
}

<?php
require_once __DIR__ . '/_base.php';

// Text tracking log. Every shipment, shop order and transfer has a tracking
// number (its own number: SHP-12345, SO-1048, TRF-00007). Status changes made in
// the system add log lines automatically; warehouse and delivery personnel can
// also add manual updates such as "Arrived at checkpoint" or "Delayed".

const MANUAL_STATUSES = ['Packed', 'In Transit', 'Arrived at checkpoint', 'Delayed', 'Note'];

// Finds what a tracking number refers to. Returns [type, id, summary] or null.
function resolve_ref(PDO $pdo, string $ref): ?array {
    $ref = strtoupper(trim($ref));
    if (preg_match('/^SHP-\d+$/', $ref)) {
        $st = $pdo->prepare('SELECT id, origin, destination, status, eta, items, carrier FROM shipments WHERE id = ?');
        $st->execute([$ref]);
        if ($r = $st->fetch()) return ['shipment', $ref, [
            'title' => 'Shipment ' . $ref, 'from' => $r['origin'], 'to' => $r['destination'], 'status' => $r['status'],
            'detail' => $r['items'] . ' item(s) · deliver by ' . $r['eta'] . ' · ' . $r['carrier']]];
    } elseif (preg_match('/^SO-\d+$/', $ref)) {
        $st = $pdo->prepare('SELECT id, shop, items, units, status, address FROM shop_orders WHERE id = ?');
        $st->execute([$ref]);
        if ($r = $st->fetch()) return ['shop_order', $ref, [
            'title' => 'Shop order ' . $ref, 'from' => 'PCS Computers warehouse', 'to' => $r['shop'] . ($r['address'] ? ', ' . $r['address'] : ''),
            'status' => $r['status'], 'detail' => $r['items'] . ' · ' . $r['units'] . ' unit(s)']];
    } elseif ($tid = doc_id('TRF', $ref)) {
        $st = $pdo->prepare('SELECT t.*, f.name AS from_name, w.name AS to_name FROM transfers t
                             JOIN warehouses f ON f.id = t.from_warehouse JOIN warehouses w ON w.id = t.to_warehouse WHERE t.id = ?');
        $st->execute([$tid]);
        if ($r = $st->fetch()) {
            $units = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(qty), 0) AS u FROM transfer_items WHERE transfer_id = ?');
            $units->execute([$tid]);
            $u = $units->fetch();
            $no = doc_no('TRF', $tid);
            return ['transfer', $no, [
                'title' => 'Stock transfer ' . $no, 'from' => $r['from_name'], 'to' => $r['to_name'], 'status' => $r['status'],
                'detail' => $u['n'] . ' product(s) · ' . $u['u'] . ' unit(s)']];
        }
    }
    return null;
}

switch ($method) {
    case 'GET':
        need('read');
        $ref = (string)($_GET['ref'] ?? '');
        if ($ref === '') json_err('Enter a tracking number.');
        $pdo = db();
        $found = resolve_ref($pdo, $ref);
        if (!$found) json_err('No shipment, shop order or transfer has the tracking number ' . strtoupper(trim($ref)) . '.', 404);
        [$type, $refId, $summary] = $found;
        $st = $pdo->prepare('SELECT l.status, l.location, l.remarks, l.logged_at, u.name AS logged_by
                             FROM tracking_logs l LEFT JOIN users u ON u.id = l.logged_by
                             WHERE l.ref_type = ? AND l.ref_id = ? ORDER BY l.logged_at DESC, l.id DESC');
        $st->execute([$type, $refId]);
        json_ok(['type' => $type, 'ref' => $refId, 'summary' => $summary, 'logs' => $st->fetchAll()]);

    case 'POST':
        need('track');
        $d      = input();
        $status = pick((string)($d['status'] ?? ''), MANUAL_STATUSES);
        $loc    = clean_text($d['location'] ?? '', 150);
        $rem    = clean_text($d['remarks'] ?? '', 255);
        if (!$status) json_err('Choose an update type.');
        if ($loc === '' && $rem === '') json_err('Enter the location or a remark.');
        $pdo = db();
        $found = resolve_ref($pdo, (string)($d['ref'] ?? ''));
        if (!$found) json_err('Unknown tracking number.', 404);
        [$type, $refId, $summary] = $found;
        if (in_array($summary['status'], ['Delivered', 'Completed', 'Received', 'Rejected', 'Cancelled'], true)) {
            json_err('This ' . str_replace('_', ' ', $type) . ' is already closed (' . $summary['status'] . ').', 409);
        }
        track_log($pdo, $type, $refId, $status, $loc, $rem, $uid);
        json_ok();

    default:
        json_err('Method not allowed', 405);
}

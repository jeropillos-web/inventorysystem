<?php
require_once __DIR__ . '/_boot.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function ok($data = null) { echo json_encode(['ok' => true, 'data' => $data]); exit; }
function fail(string $msg, int $code = 400) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; }

set_exception_handler(function (Throwable $e) {
    try { $pdo = db(); if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $ignored) {}
    $msg = $e->getMessage();
    if (str_contains($msg, 'Unknown column') || str_contains($msg, "doesn't exist") || str_contains($msg, 'no such')) {
        $msg = 'The database is out of date. Open upgrade.php on the server computer. (' . $msg . ')';
    }
    fail($msg, 500);
});

if (!db_available()) fail('The database is offline.', 503);
$user = driver_user();
if (!$user) fail('Please sign in again.', 401);

$uid    = (int)$user['id'];
$pdo    = db();
$action = $_GET['action'] ?? '';

const UPDATE_TYPES = ['In Transit', 'Arrived at checkpoint', 'Delayed', 'Note'];

const JOB_FIELDS = "
    CASE WHEN d.shipment_id IS NULL THEN 'order' ELSE 'shipment' END AS kind,
    COALESCE(d.shop_order_id, d.shipment_id)  AS job_id,
    COALESCE(o.shop, s.destination)           AS title,
    COALESCE(o.items, CONCAT(s.items, ' items', CASE WHEN s.weight <> '' THEN CONCAT(' · ', s.weight) ELSE '' END)) AS items,
    COALESCE(o.units, s.items)                AS units,
    COALESCE(NULLIF(o.address, ''), s.destination, '') AS address,
    COALESCE(s.origin, '')                    AS origin";
const JOB_JOINS = "
    FROM deliveries d
    LEFT JOIN shop_orders o ON o.id = d.shop_order_id
    LEFT JOIN shipments   s ON s.id = d.shipment_id";

function ref_type(string $kind): string { return $kind === 'shipment' ? 'shipment' : 'shop_order'; }

function active_delivery(PDO $pdo, int $uid): ?array {
    $st = $pdo->prepare("SELECT d.id, d.vehicle_id, d.status, d.accepted_at, d.started_at, " . JOB_FIELDS . JOB_JOINS . "
                         WHERE d.driver_user_id = ? AND d.status IN ('Accepted','In Transit') LIMIT 1");
    $st->execute([$uid]);
    $d = $st->fetch();
    if (!$d) return null;
    $logs = $pdo->prepare('SELECT status, location, remarks, logged_at FROM tracking_logs WHERE ref_type = ? AND ref_id = ? ORDER BY logged_at DESC, id DESC LIMIT 20');
    $logs->execute([ref_type($d['kind']), $d['job_id']]);
    $d['logs'] = $logs->fetchAll();
    return $d;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action !== 'state') fail('Unknown action', 404);

    $available = $pdo->query(
        "SELECT 'order' AS kind, o.id AS job_id, o.shop AS title, o.items, o.units, o.address,
                o.requested_at AS ready_at, NULL AS due
         FROM shop_orders o LEFT JOIN deliveries d ON d.shop_order_id = o.id
         WHERE o.status = 'Ready for Delivery' AND d.id IS NULL
         UNION ALL
         SELECT 'shipment', s.id, s.destination,
                CONCAT(s.items, ' items', CASE WHEN s.weight <> '' THEN CONCAT(' · ', s.weight) ELSE '' END, ' from ', s.origin),
                s.items, s.destination, s.approved_at, s.eta
         FROM shipments s LEFT JOIN deliveries d ON d.shipment_id = s.id
         WHERE s.status = 'Approved' AND d.id IS NULL
         ORDER BY ready_at ASC"
    )->fetchAll();

    $vs = $pdo->prepare(
        "SELECT v.id, v.plate, v.type FROM vehicles v
         WHERE v.active = 1 AND v.id NOT IN (
           SELECT vehicle_id FROM deliveries WHERE status IN ('Accepted','In Transit') AND driver_user_id <> ?
         ) ORDER BY v.id"
    );
    $vs->execute([$uid]);

    $hist = $pdo->prepare("SELECT 'Delivered' AS outcome, d.vehicle_id, d.delivered_at AS at, d.recipient_name, '' AS reason, " . JOB_FIELDS . JOB_JOINS . "
                           WHERE d.driver_user_id = ? AND d.status = 'Delivered'
                           ORDER BY d.delivered_at DESC LIMIT 30");
    $hist->execute([$uid]);
    $history = $hist->fetchAll();

    $canc = $pdo->prepare("SELECT 'Cancelled' AS outcome, c.vehicle_id, c.cancelled_at AS at, '' AS recipient_name, c.reason,
                                  c.kind, c.job_id, COALESCE(o.shop, s.destination) AS title, COALESCE(o.units, s.items) AS units
                           FROM delivery_cancellations c
                           LEFT JOIN shop_orders o ON c.kind = 'order'    AND o.id = c.job_id
                           LEFT JOIN shipments   s ON c.kind = 'shipment' AND s.id = c.job_id
                           WHERE c.driver_user_id = ? ORDER BY c.cancelled_at DESC LIMIT 30");
    $canc->execute([$uid]);
    $history = array_merge($history, $canc->fetchAll());
    usort($history, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
    $history = array_slice($history, 0, 30);

    ok([
        'driver'    => ['name' => $user['name']],
        'active'    => active_delivery($pdo, $uid),
        'available' => $available,
        'vehicles'  => $vs->fetchAll(),
        'history'   => $history,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed', 405);
$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) $in = [];

switch ($action) {

    case 'accept':
        $kind      = ($in['kind'] ?? 'order') === 'shipment' ? 'shipment' : 'order';
        $jobId     = trim((string)($in['job_id'] ?? ''));
        $vehicleId = trim((string)($in['vehicle_id'] ?? ''));
        if ($jobId === '' || $vehicleId === '') fail('Choose a vehicle first.');
        if (active_delivery($pdo, $uid)) fail('Finish your current delivery before accepting another.', 409);

        $pdo->beginTransaction();
        try {
            if ($kind === 'order') {
                $st = $pdo->prepare("SELECT shop AS title, status FROM shop_orders WHERE id = ? FOR UPDATE");
                $readyStatus = 'Ready for Delivery';
            } else {
                $st = $pdo->prepare("SELECT destination AS title, origin, status FROM shipments WHERE id = ? FOR UPDATE");
                $readyStatus = 'Approved';
            }
            $st->execute([$jobId]);
            $job = $st->fetch();
            if (!$job || $job['status'] !== $readyStatus) {
                $pdo->rollBack(); fail('This delivery was already taken or is no longer available.', 409);
            }

            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM vehicles WHERE id = ? AND active = 1 AND id NOT IN
                 (SELECT vehicle_id FROM deliveries WHERE status IN ('Accepted','In Transit'))"
            );
            $st->execute([$vehicleId]);
            if (!$st->fetchColumn()) { $pdo->rollBack(); fail('That vehicle is in use. Pick another.', 409); }

            $pdo->prepare("INSERT INTO deliveries (shop_order_id, shipment_id, driver_user_id, vehicle_id, status, accepted_at, started_at)
                           VALUES (?, ?, ?, ?, 'In Transit', NOW(), NOW())")
                ->execute([$kind === 'order' ? $jobId : null, $kind === 'shipment' ? $jobId : null, $uid, $vehicleId]);

            if ($kind === 'order') {
                $pdo->prepare("UPDATE shop_orders SET status = 'Out for Delivery' WHERE id = ?")->execute([$jobId]);
                $from = ($m = main_warehouse_id($pdo)) ? warehouse_name($pdo, $m) : 'Warehouse';
            } else {
                $pdo->prepare("UPDATE shipments SET status = 'In Transit', carrier = ? WHERE id = ?")
                    ->execute([mb_substr($user['name'] . ' · ' . $vehicleId, 0, 80), $jobId]);
                $from = $job['origin'];
            }
            track_log($pdo, ref_type($kind), $jobId, 'Out for Delivery', $from,
                      'Accepted by ' . $user['name'] . ' (vehicle ' . $vehicleId . ')', $uid);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() === '23000') fail('This delivery was already taken.', 409);
            throw $e;
        }
        ok(active_delivery($pdo, $uid));

    case 'update':
        $d = active_delivery($pdo, $uid);
        if (!$d) fail('You have no active delivery.', 409);
        $type     = in_array($in['status'] ?? '', UPDATE_TYPES, true) ? $in['status'] : null;
        $location = mb_substr(trim((string)($in['location'] ?? '')), 0, 150);
        $remarks  = mb_substr(trim((string)($in['remarks'] ?? '')), 0, 255);
        if (!$type) fail('Choose the type of update.');
        if ($location === '' && $remarks === '') fail('Enter where you are or a short remark.');
        track_log($pdo, ref_type($d['kind']), $d['job_id'], $type, $location, $remarks, $uid);
        ok(active_delivery($pdo, $uid));

    case 'cancel':
    case 'release':
        $reason = trim((string)($in['reason'] ?? ''));
        if ($reason === '') fail('Choose a reason for cancelling.');
        $d = active_delivery($pdo, $uid);
        if (!$d) fail('You have no active delivery to cancel.', 409);
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO delivery_cancellations (kind, job_id, driver_user_id, vehicle_id, reason, was_started, cancelled_at)
                       VALUES (?, ?, ?, ?, ?, ?, NOW())")
            ->execute([$d['kind'], $d['job_id'], $uid, $d['vehicle_id'], mb_substr($reason, 0, 255), $d['status'] === 'In Transit' ? 1 : 0]);
        $pdo->prepare("DELETE FROM deliveries WHERE id = ?")->execute([$d['id']]);
        if ($d['kind'] === 'order') {
            $pdo->prepare("UPDATE shop_orders SET status = 'Ready for Delivery' WHERE id = ?")->execute([$d['job_id']]);
        } else {
            $pdo->prepare("UPDATE shipments SET status = 'Approved', carrier = 'In-house' WHERE id = ?")->execute([$d['job_id']]);
        }
        track_log($pdo, ref_type($d['kind']), $d['job_id'], 'Delivery cancelled', '',
                  'Cancelled by ' . $user['name'] . ': ' . $reason . '. Back in the queue for another driver.', $uid);
        $pdo->commit();
        ok();

    case 'deliver':
        $recipient = mb_substr(trim((string)($in['recipient'] ?? '')), 0, 100);
        $notes     = mb_substr(trim((string)($in['notes'] ?? '')), 0, 255);
        if ($recipient === '') fail('Enter the name of the person who received the items.');
        $d = active_delivery($pdo, $uid);
        if (!$d) fail('You have no active delivery.', 409);
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE deliveries SET status = 'Delivered', started_at = COALESCE(started_at, NOW()), delivered_at = NOW(),
                       recipient_name = ?, notes = ? WHERE id = ?")
            ->execute([$recipient, $notes, $d['id']]);
        if ($d['kind'] === 'order') {
            $pdo->prepare("UPDATE shop_orders SET status = 'Completed' WHERE id = ?")->execute([$d['job_id']]);
        } else {
            $pdo->prepare("UPDATE shipments SET status = 'Delivered' WHERE id = ?")->execute([$d['job_id']]);
        }
        track_log($pdo, ref_type($d['kind']), $d['job_id'], 'Delivered', $d['address'],
                  'Received by ' . $recipient . ($notes !== '' ? '. ' . $notes : ''), $uid);
        $pdo->commit();
        ok();

    default:
        fail('Unknown action', 404);
}

<?php
require_once __DIR__ . '/_base.php';

$user = auth_user();
if (!$user) json_err('Not signed in', 401);

const GPS_STALE_SECONDS  = 120;  
const GPS_MOVING_KMH     = 3;    
const GPS_MAX_ACCURACY_M = 150;  
const GPS_MIN_INTERVAL_S = 3;    
const GPS_MAX_JUMP_KMH   = 200;  
const GPS_ROAD_FACTOR    = 1.3; 
const GPS_KEEP_DAYS      = 30;   

function haversine_m(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * 6371000 * asin(min(1, sqrt($a)));
}

function bearing_deg(float $lat1, float $lng1, float $lat2, float $lng2): int {
    $p1 = deg2rad($lat1); $p2 = deg2rad($lat2); $dl = deg2rad($lng2 - $lng1);
    $y = sin($dl) * cos($p2);
    $x = cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl);
    return ((int)round(rad2deg(atan2($y, $x))) + 360) % 360;
}

function num_or_null(mixed $v): ?float {
    if ($v === null || $v === '') return null;
    $f = filter_var($v, FILTER_VALIDATE_FLOAT);
    return ($f === false || is_nan($f)) ? null : $f;
}

switch ($method) {

    case 'GET':
        if (!role_can($user['role'], 'gps')) json_err('Forbidden', 403);

        if (isset($_GET['trail'])) {
            $minutes = max(5, min(1440, (int)($_GET['minutes'] ?? 60)));
            $stmt = db()->prepare(
                'SELECT lat, lng FROM vehicle_positions
                 WHERE vehicle_id = ? AND recorded_at >= NOW(3) - INTERVAL ? MINUTE
                 ORDER BY recorded_at ASC LIMIT 3000'
            );
            $stmt->execute([(string)$_GET['trail'], $minutes]);
            json_ok(array_map(fn($p) => [(float)$p['lat'], (float)$p['lng']], $stmt->fetchAll()));
        }

        $rows = db()->query(
            'SELECT *, TIMESTAMPDIFF(SECOND, last_seen, NOW(3)) AS age_s
             FROM vehicles WHERE active = 1 ORDER BY id'
        )->fetchAll();

        // Active deliveries (accepted or on the road), keyed by vehicle.
        $active = [];
        foreach (db()->query(
            "SELECT d.vehicle_id, d.status, d.accepted_at, d.started_at,
                    COALESCE(d.shop_order_id, d.shipment_id) AS shop_order_id,
                    COALESCE(o.shop, s.destination)          AS shop,
                    COALESCE(o.units, s.items)               AS units,
                    COALESCE(NULLIF(o.address, ''), s.destination) AS address,
                    IF(d.shipment_id IS NULL, 'order', 'shipment') AS kind,
                    u.name AS driver
             FROM deliveries d
             LEFT JOIN shop_orders o ON o.id = d.shop_order_id
             LEFT JOIN shipments   s ON s.id = d.shipment_id
             JOIN users u ON u.id = d.driver_user_id
             WHERE d.status IN ('Accepted','In Transit')"
        ) as $d) {
            $active[$d['vehicle_id']] = $d;
        }

        $out = [];
        foreach ($rows as $r) {
            $hasFix = $r['last_lat'] !== null;
            $age    = $hasFix ? (int)$r['age_s'] : null;
            $speed  = $hasFix ? (float)($r['last_speed_kmh'] ?? 0) : 0.0;

            $delivery = $active[$r['id']] ?? null;

            if (!$hasFix || $age > GPS_STALE_SECONDS) $status = $delivery ? 'Assigned' : 'Offline';
            elseif ($speed >= GPS_MOVING_KMH)         $status = 'Moving';
            else                                      $status = 'Idle';

            $remainingKm = null;
            $etaMin = null;
            if ($hasFix && $r['dest_lat'] !== null) {
                $remainingKm = haversine_m((float)$r['last_lat'], (float)$r['last_lng'],
                                           (float)$r['dest_lat'], (float)$r['dest_lng']) / 1000;
                if ($status === 'Moving') {
                    $etaMin = (int)ceil($remainingKm * GPS_ROAD_FACTOR / $speed * 60);
                }
            }

            $out[] = [
                'id'           => $r['id'],
                'driver'       => $r['driver_name'],
                'plate'        => $r['plate'],
                'route'        => $r['route_label'],
                'status'       => $status,
                'lat'          => $hasFix ? (float)$r['last_lat'] : null,
                'lng'          => $hasFix ? (float)$r['last_lng'] : null,
                'speed_kmh'    => $hasFix ? round($speed) : null,
                'heading'      => $r['last_heading'] !== null ? (int)$r['last_heading'] : null,
                'accuracy_m'   => $r['last_accuracy_m'] !== null ? (float)$r['last_accuracy_m'] : null,
                'age_s'        => $age,
                'dest'         => $r['dest_lat'] !== null ? [(float)$r['dest_lat'], (float)$r['dest_lng']] : null,
                'remaining_km' => $remainingKm !== null ? round($remainingKm, 1) : null,
                'eta_min'      => $etaMin,
                'delivery'     => $delivery ? [
                    'order_id'    => $delivery['shop_order_id'],
                    'kind'        => $delivery['kind'],
                    'shop'        => $delivery['shop'],
                    'address'     => $delivery['address'],
                    'units'       => (int)$delivery['units'],
                    'driver'      => $delivery['driver'],
                    'status'      => $delivery['status'],
                    'accepted_at' => $delivery['accepted_at'],
                    'started_at'  => $delivery['started_at'],
                ] : null,
            ];
        }
        json_ok($out);

    case 'POST':
        if (!role_can($user['role'], 'gps_ping')) json_err('Forbidden', 403);

        $d   = input();
        $vid = trim((string)($d['vehicle_id'] ?? ''));
        $lat = num_or_null($d['lat'] ?? null);
        $lng = num_or_null($d['lng'] ?? null);
        $acc = num_or_null($d['accuracy'] ?? null);
        $spd = num_or_null($d['speed'] ?? null);    
        $hdg = num_or_null($d['heading'] ?? null);  

        if ($vid === '' || $lat === null || $lng === null) json_err('vehicle_id, lat and lng are required');
        if (abs($lat) > 90 || abs($lng) > 180)              json_err('Coordinates out of range');
        if ($acc !== null && $acc > GPS_MAX_ACCURACY_M)     json_ok(['stored' => false, 'reason' => 'low_accuracy']);

        $stmt = db()->prepare(
            'SELECT last_lat, last_lng, last_heading,
                    TIMESTAMPDIFF(MICROSECOND, last_seen, NOW(3)) / 1000000 AS dt
             FROM vehicles WHERE id = ? AND active = 1'
        );
        $stmt->execute([$vid]);
        $v = $stmt->fetch();
        if (!$v) json_err('Unknown vehicle', 404);

        // Drivers may only report for the vehicle on their own in-transit delivery.
        if ($user['role'] !== 'admin') {
            $own = db()->prepare("SELECT COUNT(*) FROM deliveries WHERE driver_user_id = ? AND vehicle_id = ? AND status = 'In Transit'");
            $own->execute([(int)$user['id'], $vid]);
            if (!$own->fetchColumn()) json_err('Start a delivery trip for this vehicle first', 403);
        }

        $hasPrev = $v['last_lat'] !== null && $v['dt'] !== null;
        $dt      = $hasPrev ? (float)$v['dt'] : null;
        $moved   = $hasPrev ? haversine_m((float)$v['last_lat'], (float)$v['last_lng'], $lat, $lng) : null;

        if ($hasPrev && $dt < GPS_MIN_INTERVAL_S) json_ok(['stored' => false, 'reason' => 'too_soon']);

        $speedKmh = null;
        if ($spd !== null && $spd >= 0) {
            $speedKmh = $spd * 3.6;
        } elseif ($hasPrev && $dt > 0 && $dt < GPS_STALE_SECONDS) {
            $speedKmh = $moved / $dt * 3.6;
            if ($speedKmh > GPS_MAX_JUMP_KMH) json_ok(['stored' => false, 'reason' => 'jump']);
        }

        if ($hdg !== null) $heading = ((int)round($hdg) + 360) % 360;
        elseif ($hasPrev && $moved > 10) $heading = bearing_deg((float)$v['last_lat'], (float)$v['last_lng'], $lat, $lng);
        else $heading = $v['last_heading'] !== null ? (int)$v['last_heading'] : null;

        $speedKmh = $speedKmh !== null ? round($speedKmh, 1) : 0.0;

        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO vehicle_positions (vehicle_id, lat, lng, speed_kmh, heading, accuracy_m)
                       VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$vid, $lat, $lng, $speedKmh, $heading, $acc]);
        $pdo->prepare('UPDATE vehicles SET last_lat=?, last_lng=?, last_speed_kmh=?, last_heading=?,
                              last_accuracy_m=?, last_seen=NOW(3) WHERE id=?')
            ->execute([$lat, $lng, $speedKmh, $heading, $acc, $vid]);
        $pdo->commit();

        if (random_int(1, 500) === 1) {
            $pdo->prepare('DELETE FROM vehicle_positions WHERE recorded_at < NOW() - INTERVAL ? DAY')
                ->execute([GPS_KEEP_DAYS]);
        }

        json_ok(['stored' => true, 'speed_kmh' => $speedKmh, 'heading' => $heading]);

    default:
        json_err('Method not allowed', 405);
}

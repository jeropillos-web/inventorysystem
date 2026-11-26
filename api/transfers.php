<?php
require_once __DIR__ . '/_base.php';

// Warehouse-to-warehouse stock transfer flow, recorded in the tracking log:
//   Requested  (Staff, Warehouse personnel or Administrator)
//   Approved / Rejected (Administrator)
//   In Transit (warehouse personnel dispatch it: stock leaves the source warehouse)
//   Received   (warehouse personnel receive it: stock enters the destination warehouse)
//   A request can be Cancelled before it is dispatched.

function load_transfer(PDO $pdo, int $tid): array {
    $st = $pdo->prepare('SELECT * FROM transfers WHERE id = ? FOR UPDATE');
    $st->execute([$tid]);
    $row = $st->fetch();
    if (!$row) { if ($pdo->inTransaction()) $pdo->rollBack(); json_err('Transfer not found.', 404); }
    return $row;
}

function transfer_lines(PDO $pdo, int $tid): array {
    $st = $pdo->prepare('SELECT t.item_id, t.qty, i.name FROM transfer_items t JOIN inventory i ON i.id = t.item_id WHERE t.transfer_id = ? ORDER BY i.name');
    $st->execute([$tid]);
    return $st->fetchAll();
}

function must_be(PDO $pdo, array $row, array $allowed, string $msg): void {
    if (!in_array($row['status'], $allowed, true)) { $pdo->rollBack(); json_err($msg, 409); }
}

switch ($method) {
    case 'GET':
        need('read');
        if ($id) {
            $tid = doc_id('TRF', $id) ?: (int)$id;
            $pdo = db();
            $st = $pdo->prepare('SELECT * FROM transfers WHERE id = ?');
            $st->execute([$tid]);
            $row = $st->fetch();
            if (!$row) json_err('Transfer not found.', 404);
            $row['items'] = transfer_lines($pdo, $tid);
            json_ok($row);
        }
        json_ok(db()->query('SELECT * FROM transfers ORDER BY requested_at DESC, id DESC LIMIT 500')->fetchAll());

    case 'POST':
        $pdo = db();
        $tid = doc_id('TRF', (string)$id) ?: (int)$id;

        if ($action === '') {
            need('write', 'stock_ops');
            $d    = input();
            $from = clean_text($d['from_warehouse'] ?? '', 20);
            $to   = clean_text($d['to_warehouse'] ?? '', 20);
            if ($from === '' || $to === '') json_err('Choose both warehouses.');
            if ($from === $to) json_err('The source and destination must be different warehouses.');
            $act = $pdo->prepare('SELECT COUNT(*) FROM warehouses WHERE id IN (?, ?) AND active = 1');
            $act->execute([$from, $to]);
            if ((int)$act->fetchColumn() !== 2) json_err('Both warehouses must exist and be active.');

            $lines = [];
            foreach ((array)($d['items'] ?? []) as $line) {
                $itemId = clean_text($line['item_id'] ?? '', 20);
                $qty    = (int)($line['qty'] ?? 0);
                if ($itemId === '') continue;
                if ($qty < 1) json_err('Each product needs a quantity of at least 1.');
                $lines[$itemId] = ($lines[$itemId] ?? 0) + $qty;
            }
            if (!$lines) json_err('Add at least one product to transfer.');
            if (count($lines) > 30) json_err('A transfer can have at most 30 products.');

            $have = $pdo->prepare('SELECT i.name, COALESCE(s.qty, 0) AS qty FROM inventory i
                                   LEFT JOIN stock_levels s ON s.item_id = i.id AND s.warehouse_id = ? WHERE i.id = ?');
            foreach ($lines as $itemId => $qty) {
                $have->execute([$from, $itemId]);
                $r = $have->fetch();
                if (!$r) json_err("Product $itemId does not exist.");
                if ((int)$r['qty'] < $qty) json_err(sprintf('Only %d unit(s) of %s are in the source warehouse.', $r['qty'], $r['name']), 409);
            }

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO transfers (from_warehouse, to_warehouse, status, notes, requested_by, requested_at)
                           VALUES (?, ?, 'Requested', ?, ?, NOW())")
                ->execute([$from, $to, clean_text($d['notes'] ?? '', 255), $uid]);
            $newId = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO transfer_items (transfer_id, item_id, qty) VALUES (?, ?, ?)');
            foreach ($lines as $itemId => $qty) $ins->execute([$newId, $itemId, $qty]);
            $no = doc_no('TRF', $newId);
            track_log($pdo, 'transfer', $no, 'Requested', warehouse_name($pdo, $from),
                      sprintf('%d product(s), %d unit(s) to %s', count($lines), array_sum($lines), warehouse_name($pdo, $to)), $uid);
            $pdo->commit();
            json_ok(['id' => $no], 201);
        }

        if (!$tid) json_err('Missing transfer number.');
        $no = doc_no('TRF', $tid);
        $d  = input();

        switch ($action) {
            case 'approve':
            case 'reject':
                need('approve');
                $note = clean_text($d['note'] ?? '', 255);
                if ($action === 'reject' && $note === '') json_err('Give a reason for rejecting the transfer.');
                $pdo->beginTransaction();
                $row = load_transfer($pdo, $tid);
                must_be($pdo, $row, ['Requested'], 'This transfer was already reviewed.');
                $status = $action === 'approve' ? 'Approved' : 'Rejected';
                $pdo->prepare('UPDATE transfers SET status = ?, review_note = ?, approved_by = ?, approved_at = NOW() WHERE id = ?')
                    ->execute([$status, $note, $uid, $tid]);
                track_log($pdo, 'transfer', $no, $status, warehouse_name($pdo, $row['from_warehouse']),
                          $action === 'approve' ? 'Approved for dispatch' : 'Rejected: ' . $note, $uid);
                $pdo->commit();
                json_ok();

            case 'cancel':
                $note = clean_text($d['note'] ?? '', 255);
                if ($note === '') json_err('Give a reason for cancelling.');
                $pdo->beginTransaction();
                $row = load_transfer($pdo, $tid);
                if ((int)$row['requested_by'] !== $uid && !role_can($user['role'], 'approve')) {
                    $pdo->rollBack(); json_err('Only the requester or an administrator can cancel this transfer.', 403);
                }
                must_be($pdo, $row, ['Requested', 'Approved'], 'Only transfers that have not been dispatched can be cancelled.');
                $pdo->prepare("UPDATE transfers SET status = 'Cancelled', review_note = ? WHERE id = ?")->execute([$note, $tid]);
                track_log($pdo, 'transfer', $no, 'Cancelled', warehouse_name($pdo, $row['from_warehouse']), $note, $uid);
                $pdo->commit();
                json_ok();

            case 'dispatch':
                need('stock_ops');
                $pdo->beginTransaction();
                $row = load_transfer($pdo, $tid);
                must_be($pdo, $row, ['Approved'], 'Only approved transfers can be dispatched.');
                foreach (transfer_lines($pdo, $tid) as $line) {
                    stock_change($pdo, $line['item_id'], $row['from_warehouse'], -(int)$line['qty'], 'Transfer Out', $no, $uid,
                                 'To ' . warehouse_name($pdo, $row['to_warehouse']));
                }
                $pdo->prepare("UPDATE transfers SET status = 'In Transit', dispatched_by = ?, dispatched_at = NOW() WHERE id = ?")
                    ->execute([$uid, $tid]);
                $remarks = clean_text($d['remarks'] ?? '', 200);
                track_log($pdo, 'transfer', $no, 'Dispatched', warehouse_name($pdo, $row['from_warehouse']),
                          'Left the warehouse for ' . warehouse_name($pdo, $row['to_warehouse']) . ($remarks !== '' ? '. ' . $remarks : ''), $uid);
                $pdo->commit();
                json_ok();

            case 'receive':
                need('stock_ops');
                $pdo->beginTransaction();
                $row = load_transfer($pdo, $tid);
                must_be($pdo, $row, ['In Transit'], 'Only transfers in transit can be received.');
                foreach (transfer_lines($pdo, $tid) as $line) {
                    stock_change($pdo, $line['item_id'], $row['to_warehouse'], (int)$line['qty'], 'Transfer In', $no, $uid,
                                 'From ' . warehouse_name($pdo, $row['from_warehouse']));
                }
                $pdo->prepare("UPDATE transfers SET status = 'Received', received_by = ?, received_at = NOW() WHERE id = ?")
                    ->execute([$uid, $tid]);
                $remarks = clean_text($d['remarks'] ?? '', 200);
                track_log($pdo, 'transfer', $no, 'Received', warehouse_name($pdo, $row['to_warehouse']),
                          'All items received' . ($remarks !== '' ? '. ' . $remarks : ''), $uid);
                $pdo->commit();
                json_ok();

            default:
                json_err('Unknown action.', 404);
        }

    default:
        json_err('Method not allowed', 405);
}

<?php
require_once __DIR__ . '/_base.php';

// Disposal (stock write-off) flow:
//   Staff or warehouse personnel request a disposal of damaged, defective,
//   obsolete or lost stock (Pending Approval).
//   The Administrator approves it (Approved: the quantity is removed from the
//   warehouse's stock) or rejects it with a reason (Rejected).
//   After the items physically leave, the record is closed as Disposed with the
//   reference number of the recycler's certificate or receipt, if any.
const DISPOSAL_REASONS = ['Damaged', 'Defective', 'Obsolete', 'Lost or missing', 'Other'];
const DISPOSAL_METHODS = ['Accredited e-waste recycler', 'Sold as scrap', 'Destroyed on site', 'Write-off only (lost or missing)'];

function load_disposal(PDO $pdo, int $did): array {
    $st = $pdo->prepare('SELECT * FROM disposals WHERE id = ? FOR UPDATE');
    $st->execute([$did]);
    $row = $st->fetch();
    if (!$row) { if ($pdo->inTransaction()) $pdo->rollBack(); json_err('Disposal record not found.', 404); }
    return $row;
}

switch ($method) {
    case 'GET':
        need('read');
        json_ok(db()->query('SELECT * FROM disposals ORDER BY requested_at DESC, id DESC LIMIT 500')->fetchAll());

    case 'POST':
        $did = doc_id('DSP', (string)$id) ?: (int)$id;
        $pdo = db();

        if ($action === 'approve') {
            need('approve');
            $pdo->beginTransaction();
            $row = load_disposal($pdo, $did);
            if ($row['status'] !== 'Pending Approval') { $pdo->rollBack(); json_err('This request was already reviewed.', 409); }
            $no = doc_no('DSP', $row['id']);
            if ($row['return_id'] === null) {
                stock_change($pdo, $row['item_id'], $row['warehouse_id'], -(int)$row['qty'], 'Disposal', $no, $uid, $row['reason']);
            }
            $pdo->prepare("UPDATE disposals SET status = 'Approved', approved_by = ?, approved_at = NOW() WHERE id = ?")
                ->execute([$uid, (int)$row['id']]);
            $pdo->commit();
            json_ok();
        }

        if ($action === 'reject') {
            need('approve');
            $note = clean_text(input()['note'] ?? '', 255);
            if ($note === '') json_err('Give a reason for rejecting the request.');
            $pdo->beginTransaction();
            $row = load_disposal($pdo, $did);
            if ($row['status'] !== 'Pending Approval') { $pdo->rollBack(); json_err('This request was already reviewed.', 409); }
            $pdo->prepare("UPDATE disposals SET status = 'Rejected', review_note = ?, approved_by = ?, approved_at = NOW() WHERE id = ?")
                ->execute([$note, $uid, (int)$row['id']]);
            $pdo->commit();
            json_ok();
        }

        if ($action === 'complete') {
            need('approve', 'stock_ops');
            $d = input();
            $pdo->beginTransaction();
            $row = load_disposal($pdo, $did);
            if ($row['status'] !== 'Approved') { $pdo->rollBack(); json_err('Only approved disposals can be marked as disposed.', 409); }
            $pdo->prepare("UPDATE disposals SET status = 'Disposed', reference = ?, disposed_by = ?, disposed_at = NOW() WHERE id = ?")
                ->execute([clean_text($d['reference'] ?? '', 80), $uid, (int)$row['id']]);
            if ($row['return_id'] !== null) {
                $pdo->prepare("UPDATE item_returns SET status = 'Disposed' WHERE id = ? AND status = 'For Disposal'")->execute([(int)$row['return_id']]);
            }
            $pdo->commit();
            json_ok();
        }

        need('write', 'stock_ops');
        $d      = input();
        $itemId = clean_text($d['item_id'] ?? '', 20);
        $whId   = clean_text($d['warehouse_id'] ?? '', 20);
        $qty    = (int)($d['qty'] ?? 0);
        $reason = pick((string)($d['reason'] ?? ''), DISPOSAL_REASONS);
        $how    = pick((string)($d['method'] ?? ''), DISPOSAL_METHODS);
        $notes  = clean_text($d['notes'] ?? '', 255);
        if ($itemId === '' || $whId === '') json_err('Choose the product and the warehouse.');
        if ($qty < 1) json_err('The quantity must be at least 1.');
        if (!$reason || !$how) json_err('Choose the reason and the disposal method.');
        if ($reason === 'Other' && $notes === '') json_err('Describe the reason in the notes.');
        if ($reason === 'Lost or missing' && $how !== 'Write-off only (lost or missing)') json_err('Lost or missing items can only be written off.');

        $have = $pdo->prepare('SELECT qty FROM stock_levels WHERE item_id = ? AND warehouse_id = ?');
        $have->execute([$itemId, $whId]);
        $onHand = (int)$have->fetchColumn();
        $pending = $pdo->prepare("SELECT COALESCE(SUM(qty), 0) FROM disposals
                                  WHERE item_id = ? AND warehouse_id = ? AND status = 'Pending Approval' AND return_id IS NULL");
        $pending->execute([$itemId, $whId]);
        $alreadyRequested = (int)$pending->fetchColumn();
        if ($qty + $alreadyRequested > $onHand) {
            json_err("Only $onHand unit(s) are in this warehouse" . ($alreadyRequested ? " and $alreadyRequested are already requested for disposal" : '') . '.', 409);
        }

        $cost = $pdo->prepare('SELECT cost FROM inventory WHERE id = ?');
        $cost->execute([$itemId]);
        $pdo->prepare("INSERT INTO disposals (item_id, warehouse_id, qty, reason, method, unit_cost, status, notes, requested_by, requested_at)
                       VALUES (?, ?, ?, ?, ?, ?, 'Pending Approval', ?, ?, NOW())")
            ->execute([$itemId, $whId, $qty, $reason, $how, (float)$cost->fetchColumn(), $notes, $uid]);
        json_ok(['id' => doc_no('DSP', $pdo->lastInsertId())], 201);

    case 'DELETE':
        need('delete');
        $did = doc_id('DSP', (string)$id) ?: (int)$id;
        $st = db()->prepare("DELETE FROM disposals WHERE id = ? AND status IN ('Pending Approval', 'Rejected') AND return_id IS NULL");
        $st->execute([$did]);
        if (!$st->rowCount()) json_err('Only pending or rejected requests can be deleted.', 409);
        json_ok();

    default:
        json_err('Method not allowed', 405);
}

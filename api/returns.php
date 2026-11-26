<?php
require_once __DIR__ . '/_base.php';

// Returns flow:
//   Staff or warehouse personnel record a returned item (Pending Inspection).
//   The Administrator inspects the record and chooses what happens to it:
//     Restock            -> the quantity goes back into the chosen warehouse's stock
//     Return to Supplier -> the item is sent back to the supplier (no stock change)
//     Dispose            -> a disposal record is created for the item (no stock change,
//                           because a returned item was never added back to stock)
//     Reject             -> the return is not accepted
const RETURN_SOURCES    = ['Shop', 'Customer', 'Delivery'];
const RETURN_REASONS    = ['Defective', 'Damaged', 'Wrong item delivered', 'Excess or unsold stock', 'Warranty claim', 'Other'];
const RETURN_CONDITIONS = ['Good', 'Damaged', 'Defective'];
const RETURN_DECISIONS  = ['Restock', 'Return to Supplier', 'Dispose', 'Reject'];
const DISPOSAL_METHODS  = ['Accredited e-waste recycler', 'Sold as scrap', 'Destroyed on site', 'Write-off only (lost or missing)'];

switch ($method) {
    case 'GET':
        need('read');
        json_ok(db()->query('SELECT * FROM item_returns ORDER BY created_at DESC, id DESC LIMIT 500')->fetchAll());

    case 'POST':
        if ($action === 'decide') {
            need('approve');
            $rid = doc_id('RET', (string)$id) ?: (int)$id;
            $d        = input();
            $decision = pick((string)($d['decision'] ?? ''), RETURN_DECISIONS);
            $note     = clean_text($d['note'] ?? '', 255);
            if (!$decision) json_err('Choose what to do with the returned item.');
            if ($decision === 'Reject' && $note === '') json_err('Give a reason for rejecting the return.');

            $pdo = db();
            $pdo->beginTransaction();
            $r = $pdo->prepare('SELECT * FROM item_returns WHERE id = ? FOR UPDATE');
            $r->execute([$rid]);
            $ret = $r->fetch();
            if (!$ret) { $pdo->rollBack(); json_err('Return not found.', 404); }
            if ($ret['status'] !== 'Pending Inspection') { $pdo->rollBack(); json_err('This return was already decided.', 409); }
            $no = doc_no('RET', $ret['id']);

            $supplier = '';
            switch ($decision) {
                case 'Restock':
                    if ($ret['item_condition'] !== 'Good' && empty($d['confirm_restock'])) {
                        $pdo->rollBack();
                        json_err('This item is marked ' . $ret['item_condition'] . '. Only items in good condition should be restocked.', 409);
                    }
                    stock_change($pdo, $ret['item_id'], $ret['warehouse_id'], (int)$ret['qty'], 'Return Restock', $no, $uid, $note);
                    $status = 'Restocked';
                    break;
                case 'Return to Supplier':
                    $supplier = clean_text($d['supplier'] ?? '', 100);
                    if ($supplier === '') { $pdo->rollBack(); json_err('Choose the supplier the item goes back to.'); }
                    $status = 'Returned to Supplier';
                    break;
                case 'Dispose':
                    $methodName = pick((string)($d['disposal_method'] ?? ''), DISPOSAL_METHODS);
                    if (!$methodName) { $pdo->rollBack(); json_err('Choose how the item will be disposed of.'); }
                    $cost = $pdo->prepare('SELECT cost FROM inventory WHERE id = ?');
                    $cost->execute([$ret['item_id']]);
                    $reason = $ret['item_condition'] === 'Good' ? 'Obsolete' : $ret['item_condition'];
                    // Approved immediately: the administrator is the approver, and no stock is deducted.
                    $pdo->prepare("INSERT INTO disposals (item_id, warehouse_id, qty, reason, method, return_id, unit_cost, status, notes,
                                                         requested_by, requested_at, approved_by, approved_at)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, 'Approved', ?, ?, NOW(), ?, NOW())")
                        ->execute([$ret['item_id'], $ret['warehouse_id'], (int)$ret['qty'], $reason, $methodName, (int)$ret['id'],
                                   (float)$cost->fetchColumn(), 'From return ' . $no . ($note !== '' ? ': ' . $note : ''), $uid, $uid]);
                    $status = 'For Disposal';
                    break;
                default:
                    $status = 'Rejected';
            }

            $pdo->prepare('UPDATE item_returns SET status = ?, disposition = ?, supplier = ?, decision_note = ?, decided_by = ?, decided_at = NOW()
                           WHERE id = ?')
                ->execute([$status, $decision === 'Reject' ? null : $decision, $supplier, $note, $uid, (int)$ret['id']]);
            $pdo->commit();
            json_ok(['status' => $status]);
        }

        need('write', 'stock_ops');
        $d         = input();
        $itemId    = clean_text($d['item_id'] ?? '', 20);
        $whId      = clean_text($d['warehouse_id'] ?? '', 20);
        $qty       = (int)($d['qty'] ?? 0);
        $source    = pick((string)($d['source'] ?? ''), RETURN_SOURCES);
        $reason    = pick((string)($d['reason'] ?? ''), RETURN_REASONS);
        $condition = pick((string)($d['item_condition'] ?? ''), RETURN_CONDITIONS);
        $sourceRef = clean_text($d['source_ref'] ?? '', 120);
        $notes     = clean_text($d['notes'] ?? '', 255);

        if ($itemId === '' || $whId === '') json_err('Choose the product and the receiving warehouse.');
        if ($qty < 1) json_err('The quantity must be at least 1.');
        if (!$source || !$reason || !$condition) json_err('Choose the source, reason and condition.');
        if ($reason === 'Other' && $notes === '') json_err('Describe the reason in the notes.');

        $pdo = db();
        $chk = $pdo->prepare('SELECT (SELECT COUNT(*) FROM inventory WHERE id = ?) + (SELECT COUNT(*) FROM warehouses WHERE id = ? AND active = 1)');
        $chk->execute([$itemId, $whId]);
        if ((int)$chk->fetchColumn() !== 2) json_err('Unknown product or inactive warehouse.');

        $pdo->prepare("INSERT INTO item_returns (item_id, warehouse_id, qty, source, source_ref, reason, item_condition, status, notes, created_by, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending Inspection', ?, ?, NOW())")
            ->execute([$itemId, $whId, $qty, $source, $sourceRef, $reason, $condition, $notes, $uid]);
        json_ok(['id' => doc_no('RET', $pdo->lastInsertId())], 201);

    case 'DELETE':
        need('delete');
        $rid = doc_id('RET', (string)$id) ?: (int)$id;
        $st = db()->prepare("DELETE FROM item_returns WHERE id = ? AND status IN ('Pending Inspection', 'Rejected')");
        $st->execute([$rid]);
        if (!$st->rowCount()) json_err('Only pending or rejected returns can be deleted.', 409);
        json_ok();

    default:
        json_err('Method not allowed', 405);
}

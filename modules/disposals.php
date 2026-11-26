<?php
if (!db_available()) { needs_database('Disposal'); return; }

$user       = $_SESSION['user'];
$role       = $user['role'];
$canRequest = role_can_any($role, 'write', 'stock_ops');
$canApprove = role_can($role, 'approve');
$canClose   = role_can_any($role, 'approve', 'stock_ops');
$canDelete  = role_can($role, 'delete');
$pdo        = db();
$show       = $_GET['dsp_status'] ?? 'Open';

$rows = $pdo->query(
    "SELECT d.*, i.name AS item_name, w.name AS warehouse_name, r.name AS requested_by_name, a.name AS approved_by_name, x.name AS disposed_by_name
     FROM disposals d
     JOIN inventory i ON i.id = d.item_id
     JOIN warehouses w ON w.id = d.warehouse_id
     LEFT JOIN users r ON r.id = d.requested_by
     LEFT JOIN users a ON a.id = d.approved_by
     LEFT JOIN users x ON x.id = d.disposed_by
     ORDER BY d.requested_at DESC, d.id DESC LIMIT 300")->fetchAll();

$count = fn(string $s) => count(array_filter($rows, fn($r) => $r['status'] === $s));
$value = array_sum(array_map(fn($r) => in_array($r['status'], ['Approved', 'Disposed'], true) ? $r['qty'] * $r['unit_cost'] : 0, $rows));
$list  = $show === 'All' ? $rows : array_values(array_filter($rows, fn($r) => in_array($r['status'], ['Pending Approval', 'Approved'], true)));

// Stock per warehouse for the request form.
$stock = [];
foreach ($pdo->query('SELECT item_id, warehouse_id, qty FROM stock_levels WHERE qty > 0') as $s) {
    $stock[$s['warehouse_id']][$s['item_id']] = (int)$s['qty'];
}
?>

<div class="flex flex-col gap-6">
  <?php
  page_header('Disposal', 'Remove damaged, defective, obsolete or lost stock from the inventory, with approval and a record of how it was disposed of',
      $canRequest ? '<button type="button" onclick="openDisposal()" class="' . BTN_PRIMARY . '">+ Request Disposal</button>' : '');
  stat_cards([
      ['Waiting for approval',          $count('Pending Approval'), 'text-amber-600'],
      ['Approved, to be disposed of',   $count('Approved'),         'text-blue-600'],
      ['Disposed',                      $count('Disposed'),         'text-emerald-600'],
      ['Written-off value (₱)',         number_format($value, 2),  'text-red-600'],
  ]);
  ?>

  <div class="flex gap-2">
    <?php foreach (['Open' => 'Open requests', 'All' => 'All records'] as $k => $label): ?>
    <a href="?module=disposals&dsp_status=<?= $k ?>" class="px-3 py-2 rounded-lg text-xs font-medium <?= $show === $k ? 'bg-indigo-600 text-white' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>

  <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="bg-slate-50 border-b border-slate-200">
          <?php foreach (['Disposal No.', 'Product', 'Qty', 'Value (₱)', 'Reason / Method', 'Warehouse', 'Status', 'Requested', ''] as $hd): ?>
          <th class="text-left text-xs font-bold text-slate-400 px-4 py-3 uppercase tracking-wider whitespace-nowrap"><?= $hd ?></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
          <?php if (!$list): ?><tr><td colspan="9" class="py-10 text-center text-slate-400"><?= $show === 'All' ? 'No disposal records yet.' : 'No open disposal requests.' ?></td></tr><?php endif; ?>
          <?php foreach ($list as $d): $no = doc_no('DSP', $d['id']); $url = 'api/disposals.php?id=' . $no . '&action='; ?>
          <tr class="border-b border-slate-100 last:border-0 align-top hover:bg-slate-50">
            <td class="px-4 py-3 font-mono text-xs font-bold text-indigo-600 whitespace-nowrap"><?= $no ?>
              <?php if ($d['return_id']): ?><div class="font-normal text-slate-400">from <?= doc_no('RET', $d['return_id']) ?></div><?php endif; ?></td>
            <td class="px-4 py-3"><div class="font-semibold text-slate-900"><?= h($d['item_name']) ?></div><div class="font-mono text-[11px] text-slate-400"><?= h($d['item_id']) ?></div></td>
            <td class="px-4 py-3 font-mono font-bold"><?= (int)$d['qty'] ?></td>
            <td class="px-4 py-3 font-mono whitespace-nowrap"><?= number_format($d['qty'] * $d['unit_cost'], 2) ?></td>
            <td class="px-4 py-3 text-slate-600"><?= h($d['reason']) ?><div class="text-xs text-slate-400"><?= h($d['method']) ?></div>
              <?php if ($d['notes'] !== ''): ?><div class="text-xs text-slate-500 max-w-xs"><?= h($d['notes']) ?></div><?php endif; ?></td>
            <td class="px-4 py-3 text-slate-600"><?= h($d['warehouse_name']) ?></td>
            <td class="px-4 py-3"><?= badge($d['status']) ?>
              <?php if ($d['review_note'] !== ''): ?><div class="mt-1 text-xs text-red-600 max-w-[14rem]"><?= h($d['review_note']) ?></div><?php endif; ?>
              <?php if ($d['status'] === 'Disposed'): ?><div class="mt-1 text-[11px] text-slate-400"><?= h(fmt_dt($d['disposed_at'], 'M d, Y')) ?><?= $d['reference'] !== '' ? ' · Ref. ' . h($d['reference']) : '' ?></div><?php endif; ?></td>
            <td class="px-4 py-3 text-xs text-slate-500 whitespace-nowrap"><?= h(fmt_dt($d['requested_at'])) ?><?= $d['requested_by_name'] ? '<div>' . h($d['requested_by_name']) . '</div>' : '' ?></td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <?php if ($d['status'] === 'Pending Approval' && $canApprove): ?>
              <button type="button" onclick="actWithReason('<?= $url ?>reject', 'Reason for rejecting <?= $no ?>:')" class="<?= BTN_DANGER ?> !py-1.5 !px-3 !text-xs">Reject</button>
              <button type="button" onclick="act('<?= $url ?>approve', 'POST', {}, 'Approve <?= $no ?>? <?= (int)$d['qty'] ?> unit(s) will be removed from <?= h(addslashes($d['warehouse_name'])) ?>.')" class="<?= BTN_GO ?> !py-1.5 !px-3 !text-xs">Approve</button>
              <?php elseif ($d['status'] === 'Pending Approval'): ?>
              <span class="text-xs font-semibold text-amber-600">Waiting for administrator</span>
              <?php elseif ($d['status'] === 'Approved' && $canClose): ?>
              <button type="button" onclick="markDisposed('<?= $no ?>')" class="<?= BTN_PRIMARY ?> !py-1.5 !px-3 !text-xs">Mark disposed</button>
              <?php endif; ?>
              <?php if ($canDelete && in_array($d['status'], ['Pending Approval', 'Rejected'], true) && !$d['return_id']): ?>
              <button type="button" onclick="act('api/disposals.php?id=<?= $no ?>', 'DELETE', null, 'Delete <?= $no ?>?')" class="ml-1 text-xs text-slate-400 hover:text-red-600">Delete</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <p class="text-xs text-slate-400 -mt-3">Electronic waste should go to a recycler accredited by the DENR Environmental Management Bureau. Keep the recycler's certificate or receipt number as the disposal reference.</p>
</div>

<script>
function markDisposed(no) {
  const ref = prompt('Mark ' + no + ' as disposed.\nCertificate, receipt or document number (optional):', '');
  if (ref === null) return;
  act('api/disposals.php?id=' + no + '&action=complete', 'POST', { reference: ref });
}
</script>

<?php if ($canRequest): ?>
<?php modal_open('modal-disposal', 'Request a disposal', 'Stock is removed only when the administrator approves the request.'); ?>
  <form id="form-disposal" class="px-6 py-5 flex flex-col gap-4">
    <div class="grid grid-cols-2 gap-4">
      <div class="col-span-2"><label class="<?= LABEL ?>">Product *</label>
        <select name="item_id" required class="<?= FIELD ?>" onchange="dspAvail()"><?php foreach (products_list() as $p): ?><option value="<?= h($p['id']) ?>"><?= h($p['name']) ?> (<?= h($p['id']) ?>)</option><?php endforeach; ?></select></div>
      <div><label class="<?= LABEL ?>">Warehouse *</label>
        <select name="warehouse_id" required class="<?= FIELD ?>" onchange="dspAvail()"><?php foreach (warehouses_list() as $w): ?><option value="<?= h($w['id']) ?>"><?= h($w['name']) ?></option><?php endforeach; ?></select></div>
      <div><label class="<?= LABEL ?>">Quantity *</label><input name="qty" type="number" min="1" value="1" required class="<?= FIELD ?>"><p id="dsp-avail" class="mt-1 text-[11px] text-slate-400"></p></div>
      <div><label class="<?= LABEL ?>">Reason *</label>
        <select name="reason" required class="<?= FIELD ?>" onchange="dspReason()"><option>Damaged</option><option>Defective</option><option>Obsolete</option><option>Lost or missing</option><option>Other</option></select></div>
      <div><label class="<?= LABEL ?>">Disposal method *</label>
        <select name="method" required class="<?= FIELD ?>"><option>Accredited e-waste recycler</option><option>Sold as scrap</option><option>Destroyed on site</option><option>Write-off only (lost or missing)</option></select></div>
      <div class="col-span-2"><label class="<?= LABEL ?>">Notes</label><textarea name="notes" rows="2" maxlength="250" placeholder="How was the problem found?" class="<?= FIELD ?>"></textarea></div>
    </div>
    <p data-error class="hidden text-xs text-red-600"></p>
    <div class="flex gap-3 pt-1">
      <button type="button" onclick="closeModal('modal-disposal')" class="flex-1 <?= BTN_GHOST ?>">Cancel</button>
      <button type="submit" class="flex-1 <?= BTN_PRIMARY ?>">Submit Request</button>
    </div>
  </form>
<?php modal_close(); ?>
<script>
const DSP_STOCK = <?= json_encode($stock ?: new stdClass()) ?>;
function dspAvail() {
  const f = document.getElementById('form-disposal');
  const have = (DSP_STOCK[f.warehouse_id.value] || {})[f.item_id.value] || 0;
  document.getElementById('dsp-avail').textContent = have + ' in this warehouse';
}
function dspReason() {
  const f = document.getElementById('form-disposal');
  if (f.reason.value === 'Lost or missing') f.method.value = 'Write-off only (lost or missing)';
}
function openDisposal() { document.getElementById('form-disposal').reset(); dspAvail(); openModal('modal-disposal'); }
bindForm('form-disposal', 'api/disposals.php');
</script>
<?php endif; ?>

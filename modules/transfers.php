<?php
if (!db_available()) { needs_database('Stock Transfers'); return; }

$user       = $_SESSION['user'];
$role       = $user['role'];
$canRequest = role_can_any($role, 'write', 'stock_ops');
$canApprove = role_can($role, 'approve');
$canMove    = role_can($role, 'stock_ops');
$pdo        = db();
$show       = $_GET['tr_status'] ?? 'Open';

$warehouses = warehouses_list();
$rows = $pdo->query(
    "SELECT t.*, f.name AS from_name, w.name AS to_name, r.name AS requested_by_name, a.name AS approved_by_name,
            d.name AS dispatched_by_name, v.name AS received_by_name
     FROM transfers t
     JOIN warehouses f ON f.id = t.from_warehouse
     JOIN warehouses w ON w.id = t.to_warehouse
     LEFT JOIN users r ON r.id = t.requested_by
     LEFT JOIN users a ON a.id = t.approved_by
     LEFT JOIN users d ON d.id = t.dispatched_by
     LEFT JOIN users v ON v.id = t.received_by
     ORDER BY t.requested_at DESC, t.id DESC LIMIT 300")->fetchAll();

$lines = [];
foreach ($pdo->query('SELECT t.transfer_id, t.item_id, t.qty, i.name FROM transfer_items t JOIN inventory i ON i.id = t.item_id ORDER BY i.name') as $l) {
    $lines[$l['transfer_id']][] = $l;
}

$count = fn(string $s) => count(array_filter($rows, fn($r) => $r['status'] === $s));
$open  = ['Requested', 'Approved', 'In Transit'];
$list  = $show === 'All' ? $rows : array_values(array_filter($rows, fn($r) => in_array($r['status'], $open, true)));

// Stock per warehouse for the request form: {warehouse: {item: qty}}
$stock = [];
foreach ($pdo->query('SELECT item_id, warehouse_id, qty FROM stock_levels WHERE qty > 0') as $s) {
    $stock[$s['warehouse_id']][$s['item_id']] = (int)$s['qty'];
}
$products = products_list();
?>

<div class="flex flex-col gap-6">
  <?php
  page_header('Stock Transfers', 'Move stock from one warehouse to another: request, approve, dispatch, receive',
      $canRequest && count($warehouses) >= 2 ? '<button type="button" onclick="openTransfer()" class="' . BTN_PRIMARY . '">+ New Transfer</button>' : '');
  stat_cards([
      ['Waiting for approval', $count('Requested'), 'text-amber-600'],
      ['Approved, to dispatch', $count('Approved'), 'text-blue-600'],
      ['In transit',           $count('In Transit'), 'text-violet-600'],
      ['Received',             $count('Received'),   'text-emerald-600'],
  ]);
  ?>
  <?php if (count($warehouses) < 2): ?>
  <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">Transfers need at least two active warehouses. <?= $canApprove ? 'Add one in Warehouse &amp; Logistics.' : 'Ask the administrator to add one.' ?></div>
  <?php endif; ?>

  <div class="flex gap-2">
    <?php foreach (['Open' => 'Open transfers', 'All' => 'All transfers'] as $k => $label): ?>
    <a href="?module=transfers&tr_status=<?= $k ?>" class="px-3 py-2 rounded-lg text-xs font-medium <?= $show === $k ? 'bg-indigo-600 text-white' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>

  <div class="flex flex-col gap-3">
    <?php if (!$list): ?>
    <div class="rounded-xl border border-slate-200 bg-white px-5 py-10 text-center text-sm text-slate-400"><?= $show === 'All' ? 'No transfers yet.' : 'No open transfers.' ?></div>
    <?php endif; ?>
    <?php foreach ($list as $t): $no = doc_no('TRF', $t['id']); $mine = (int)$t['requested_by'] === (int)$user['id']; ?>
    <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-mono text-sm font-bold text-indigo-600"><?= $no ?></span><?= badge($t['status']) ?>
            <a href="?module=tracking&ref=<?= $no ?>" class="text-xs font-semibold text-slate-500 hover:text-indigo-600 underline">Tracking log</a>
          </div>
          <p class="mt-1 font-semibold text-slate-900"><?= h($t['from_name']) ?> → <?= h($t['to_name']) ?></p>
          <ul class="mt-1 text-sm text-slate-600">
            <?php foreach ($lines[$t['id']] ?? [] as $l): ?><li><?= h($l['name']) ?> <span class="font-mono text-slate-400">× <?= (int)$l['qty'] ?></span></li><?php endforeach; ?>
          </ul>
          <p class="mt-2 text-xs text-slate-400">
            Requested <?= h(fmt_dt($t['requested_at'])) ?><?= $t['requested_by_name'] ? ' by ' . h($t['requested_by_name']) : '' ?>
            <?= $t['approved_at'] && $t['status'] !== 'Rejected' ? ' · Approved ' . h(fmt_dt($t['approved_at'])) . ($t['approved_by_name'] ? ' by ' . h($t['approved_by_name']) : '') : '' ?>
            <?= $t['dispatched_at'] ? ' · Dispatched ' . h(fmt_dt($t['dispatched_at'])) . ($t['dispatched_by_name'] ? ' by ' . h($t['dispatched_by_name']) : '') : '' ?>
            <?= $t['received_at'] ? ' · Received ' . h(fmt_dt($t['received_at'])) . ($t['received_by_name'] ? ' by ' . h($t['received_by_name']) : '') : '' ?>
          </p>
          <?php if ($t['notes'] !== ''): ?><p class="mt-1 text-xs text-slate-500">Note: <?= h($t['notes']) ?></p><?php endif; ?>
          <?php if ($t['review_note'] !== ''): ?><p class="mt-1 text-xs text-red-600"><?= h($t['status']) ?>: <?= h($t['review_note']) ?></p><?php endif; ?>
        </div>
        <div class="flex flex-wrap gap-2 lg:justify-end">
          <?php $url = 'api/transfers.php?id=' . $no . '&action='; ?>
          <?php if ($t['status'] === 'Requested' && $canApprove): ?>
          <button type="button" onclick="actWithReason('<?= $url ?>reject', 'Reason for rejecting <?= $no ?>:')" class="<?= BTN_DANGER ?>">Reject</button>
          <button type="button" onclick="act('<?= $url ?>approve', 'POST', {}, 'Approve <?= $no ?>?')" class="<?= BTN_GO ?>">Approve</button>
          <?php endif; ?>
          <?php if ($t['status'] === 'Approved' && $canMove): ?>
          <button type="button" onclick="dispatchTransfer('<?= $no ?>')" class="<?= BTN_PRIMARY ?>">Dispatch</button>
          <?php endif; ?>
          <?php if ($t['status'] === 'In Transit' && $canMove): ?>
          <button type="button" onclick="receiveTransfer('<?= $no ?>')" class="<?= BTN_GO ?>">Receive</button>
          <?php endif; ?>
          <?php if (in_array($t['status'], ['Requested', 'Approved'], true) && ($mine || $canApprove)): ?>
          <button type="button" onclick="actWithReason('<?= $url ?>cancel', 'Reason for cancelling <?= $no ?>:')" class="<?= BTN_GHOST ?>">Cancel</button>
          <?php endif; ?>
          <?php if ($t['status'] === 'Requested' && !$canApprove): ?><span class="text-sm font-semibold text-amber-600">Waiting for administrator approval</span><?php endif; ?>
          <?php if ($t['status'] === 'Approved' && !$canMove): ?><span class="text-sm font-semibold text-blue-600">Waiting for the warehouse to dispatch</span><?php endif; ?>
          <?php if ($t['status'] === 'In Transit' && !$canMove): ?><span class="text-sm font-semibold text-violet-600">On the way</span><?php endif; ?>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
</div>

<script>
async function dispatchTransfer(no) {
  const remarks = prompt('Dispatch ' + no + '. The items will be deducted from the source warehouse.\nVehicle, driver or other remarks (optional):', '');
  if (remarks === null) return;
  act('api/transfers.php?id=' + no + '&action=dispatch', 'POST', { remarks });
}
async function receiveTransfer(no) {
  const remarks = prompt('Receive ' + no + '. The items will be added to the destination warehouse.\nRemarks (optional):', '');
  if (remarks === null) return;
  act('api/transfers.php?id=' + no + '&action=receive', 'POST', { remarks });
}
</script>

<?php if ($canRequest && count($warehouses) >= 2): ?>
<?php modal_open('modal-transfer', 'New stock transfer', 'An administrator approves it before the warehouse dispatches it.', 'max-w-2xl'); ?>
  <form id="form-transfer" class="px-6 py-5 flex flex-col gap-4">
    <div class="grid grid-cols-2 gap-4">
      <div><label class="<?= LABEL ?>">From warehouse *</label>
        <select name="from_warehouse" required class="<?= FIELD ?>" onchange="refreshAvail()"><?php foreach ($warehouses as $w): ?><option value="<?= h($w['id']) ?>"><?= h($w['name']) ?></option><?php endforeach; ?></select></div>
      <div><label class="<?= LABEL ?>">To warehouse *</label>
        <select name="to_warehouse" required class="<?= FIELD ?>"><?php foreach ($warehouses as $i => $w): ?><option value="<?= h($w['id']) ?>" <?= $i === 1 ? 'selected' : '' ?>><?= h($w['name']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div>
      <label class="<?= LABEL ?>">Products *</label>
      <div id="transfer-lines" class="flex flex-col gap-2"></div>
      <button type="button" onclick="addLine()" class="mt-2 text-xs font-semibold text-indigo-600 hover:underline">+ Add another product</button>
    </div>
    <div><label class="<?= LABEL ?>">Note</label><input name="notes" maxlength="200" placeholder="Purpose of the transfer" class="<?= FIELD ?>"></div>
    <p data-error class="hidden text-xs text-red-600"></p>
    <div class="flex gap-3 pt-1">
      <button type="button" onclick="closeModal('modal-transfer')" class="flex-1 <?= BTN_GHOST ?>">Cancel</button>
      <button type="submit" class="flex-1 <?= BTN_PRIMARY ?>">Submit Request</button>
    </div>
  </form>
<?php modal_close(); ?>
<script>
const STOCK = <?= json_encode($stock ?: new stdClass()) ?>;
const PRODUCTS = <?= json_encode(array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name']], $products)) ?>;
function lineHtml() {
  return `<div class="flex gap-2 items-center" data-line>
    <select data-item class="${'<?= FIELD ?>'} flex-1" onchange="refreshAvail()">${PRODUCTS.map(p => `<option value="${esc(p.id)}">${esc(p.name)}</option>`).join('')}</select>
    <input data-qty type="number" min="1" value="1" class="${'<?= FIELD ?>'} w-24">
    <span data-avail class="w-28 text-xs text-slate-500"></span>
    <button type="button" onclick="this.closest('[data-line]').remove()" class="p-2 text-slate-400 hover:text-red-600" title="Remove">✕</button></div>`;
}
function addLine() { document.getElementById('transfer-lines').insertAdjacentHTML('beforeend', lineHtml()); refreshAvail(); }
function refreshAvail() {
  const from = document.querySelector('#form-transfer [name=from_warehouse]').value;
  document.querySelectorAll('#transfer-lines [data-line]').forEach(l => {
    const have = (STOCK[from] || {})[l.querySelector('[data-item]').value] || 0;
    l.querySelector('[data-avail]').textContent = have + ' available';
    l.querySelector('[data-qty]').max = have || 1;
  });
}
function openTransfer() {
  const f = document.getElementById('form-transfer'); f.reset();
  document.getElementById('transfer-lines').innerHTML = '';
  addLine(); openModal('modal-transfer');
}
bindForm('form-transfer', 'api/transfers.php', 'POST', (body, form) => {
  body.items = [...form.querySelectorAll('[data-line]')].map(l => ({ item_id: l.querySelector('[data-item]').value, qty: Number(l.querySelector('[data-qty]').value) }));
  return body;
});
</script>
<?php endif; ?>

<?php
if (!db_available()) { needs_database('Returns'); return; }

$user      = $_SESSION['user'];
$role      = $user['role'];
$canRecord = role_can_any($role, 'write', 'stock_ops');
$canDecide = role_can($role, 'approve');
$canDelete = role_can($role, 'delete');
$pdo       = db();
$show      = $_GET['ret_status'] ?? 'Pending Inspection';

$rows = $pdo->query(
    "SELECT r.*, i.name AS item_name, i.cost, w.name AS warehouse_name, c.name AS created_by_name, d.name AS decided_by_name
     FROM item_returns r
     JOIN inventory i ON i.id = r.item_id
     JOIN warehouses w ON w.id = r.warehouse_id
     LEFT JOIN users c ON c.id = r.created_by
     LEFT JOIN users d ON d.id = r.decided_by
     ORDER BY r.created_at DESC, r.id DESC LIMIT 300")->fetchAll();
$statuses = ['Pending Inspection', 'Restocked', 'Returned to Supplier', 'For Disposal', 'Disposed', 'Rejected'];
$count    = fn(string $s) => count(array_filter($rows, fn($r) => $r['status'] === $s));
$list     = $show === 'All' ? $rows : array_values(array_filter($rows, fn($r) => $r['status'] === $show));
$suppliers = $pdo->query("SELECT name FROM suppliers WHERE status <> 'Inactive' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="flex flex-col gap-6">
  <?php
  page_header('Returns', 'Items returned by shops, customers or drivers: record, inspect, then restock, return to supplier or dispose',
      $canRecord ? '<button type="button" onclick="openModal(\'modal-return\')" class="' . BTN_PRIMARY . '">+ Record Return</button>' : '');
  stat_cards([
      ['Waiting for inspection', $count('Pending Inspection'), 'text-amber-600'],
      ['Restocked',              $count('Restocked'),          'text-emerald-600'],
      ['Returned to supplier',   $count('Returned to Supplier'), 'text-blue-600'],
      ['For / already disposed', $count('For Disposal') + $count('Disposed'), 'text-red-600'],
  ]);
  ?>

  <div class="flex flex-wrap gap-2">
    <?php foreach (array_merge($statuses, ['All']) as $s): ?>
    <a href="?module=returns&ret_status=<?= urlencode($s) ?>" class="px-3 py-2 rounded-lg text-xs font-medium <?= $show === $s ? 'bg-indigo-600 text-white' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' ?>"><?= h($s) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="bg-slate-50 border-b border-slate-200">
          <?php foreach (['Return No.', 'Product', 'Qty', 'From', 'Reason / Condition', 'Warehouse', 'Status', 'Recorded', ''] as $hd): ?>
          <th class="text-left text-xs font-bold text-slate-400 px-4 py-3 uppercase tracking-wider whitespace-nowrap"><?= $hd ?></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
          <?php if (!$list): ?><tr><td colspan="9" class="py-10 text-center text-slate-400">No returns in this list.</td></tr><?php endif; ?>
          <?php foreach ($list as $r): $no = doc_no('RET', $r['id']); ?>
          <tr class="border-b border-slate-100 last:border-0 align-top hover:bg-slate-50">
            <td class="px-4 py-3 font-mono text-xs font-bold text-indigo-600 whitespace-nowrap"><?= $no ?></td>
            <td class="px-4 py-3"><div class="font-semibold text-slate-900"><?= h($r['item_name']) ?></div><div class="font-mono text-[11px] text-slate-400"><?= h($r['item_id']) ?></div></td>
            <td class="px-4 py-3 font-mono font-bold"><?= (int)$r['qty'] ?></td>
            <td class="px-4 py-3 text-slate-600"><?= h($r['source']) ?><?php if ($r['source_ref'] !== ''): ?><div class="text-xs text-slate-400"><?= h($r['source_ref']) ?></div><?php endif; ?></td>
            <td class="px-4 py-3 text-slate-600"><?= h($r['reason']) ?><div class="text-xs text-slate-400">Condition: <?= h($r['item_condition']) ?></div>
              <?php if ($r['notes'] !== ''): ?><div class="text-xs text-slate-500 max-w-xs"><?= h($r['notes']) ?></div><?php endif; ?></td>
            <td class="px-4 py-3 text-slate-600"><?= h($r['warehouse_name']) ?></td>
            <td class="px-4 py-3"><?= badge($r['status']) ?>
              <?php if ($r['supplier'] !== ''): ?><div class="mt-1 text-xs text-slate-500">To <?= h($r['supplier']) ?></div><?php endif; ?>
              <?php if ($r['decision_note'] !== ''): ?><div class="mt-1 text-xs text-slate-500 max-w-[14rem]"><?= h($r['decision_note']) ?></div><?php endif; ?>
              <?php if ($r['decided_by_name']): ?><div class="mt-1 text-[11px] text-slate-400">by <?= h($r['decided_by_name']) ?></div><?php endif; ?></td>
            <td class="px-4 py-3 text-xs text-slate-500 whitespace-nowrap"><?= h(fmt_dt($r['created_at'])) ?><?= $r['created_by_name'] ? '<div>' . h($r['created_by_name']) . '</div>' : '' ?></td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <?php if ($r['status'] === 'Pending Inspection' && $canDecide): ?>
              <button type="button" onclick='openDecide(<?= h(json_encode(['no' => $no, 'item' => $r['item_name'], 'qty' => (int)$r['qty'], 'condition' => $r['item_condition']])) ?>)' class="<?= BTN_PRIMARY ?> !py-1.5 !px-3 !text-xs">Inspect &amp; decide</button>
              <?php elseif ($r['status'] === 'Pending Inspection'): ?>
              <span class="text-xs font-semibold text-amber-600">Waiting for administrator</span>
              <?php elseif ($r['status'] === 'For Disposal'): ?>
              <a href="?module=disposals" class="text-xs font-semibold text-indigo-600 hover:underline">See disposal</a>
              <?php endif; ?>
              <?php if ($canDelete && in_array($r['status'], ['Pending Inspection', 'Rejected'], true)): ?>
              <button type="button" onclick="act('api/returns.php?id=<?= $no ?>', 'DELETE', null, 'Delete return <?= $no ?>?')" class="ml-1 text-xs text-slate-400 hover:text-red-600">Delete</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($canRecord): ?>
<?php modal_open('modal-return', 'Record a returned item', 'The administrator inspects it and decides what happens next.'); ?>
  <form id="form-return" class="px-6 py-5 flex flex-col gap-4">
    <div class="grid grid-cols-2 gap-4">
      <div class="col-span-2"><label class="<?= LABEL ?>">Product *</label>
        <select name="item_id" required class="<?= FIELD ?>"><?php foreach (products_list() as $p): ?><option value="<?= h($p['id']) ?>"><?= h($p['name']) ?> (<?= h($p['id']) ?>)</option><?php endforeach; ?></select></div>
      <div><label class="<?= LABEL ?>">Quantity *</label><input name="qty" type="number" min="1" value="1" required class="<?= FIELD ?>"></div>
      <div><label class="<?= LABEL ?>">Received at *</label>
        <select name="warehouse_id" required class="<?= FIELD ?>"><?php foreach (warehouses_list() as $w): ?><option value="<?= h($w['id']) ?>"><?= h($w['name']) ?></option><?php endforeach; ?></select></div>
      <div><label class="<?= LABEL ?>">Returned by *</label>
        <select name="source" required class="<?= FIELD ?>"><option>Shop</option><option>Customer</option><option>Delivery</option></select></div>
      <div><label class="<?= LABEL ?>">Reference</label><input name="source_ref" maxlength="120" placeholder="Shop name, order no., receipt no." class="<?= FIELD ?>"></div>
      <div><label class="<?= LABEL ?>">Reason *</label>
        <select name="reason" required class="<?= FIELD ?>"><option>Defective</option><option>Damaged</option><option>Wrong item delivered</option><option>Excess or unsold stock</option><option>Warranty claim</option><option>Other</option></select></div>
      <div><label class="<?= LABEL ?>">Condition *</label>
        <select name="item_condition" required class="<?= FIELD ?>"><option>Good</option><option>Damaged</option><option>Defective</option></select></div>
      <div class="col-span-2"><label class="<?= LABEL ?>">Notes</label><textarea name="notes" rows="2" maxlength="250" placeholder="What is wrong with the item?" class="<?= FIELD ?>"></textarea></div>
    </div>
    <p data-error class="hidden text-xs text-red-600"></p>
    <div class="flex gap-3 pt-1">
      <button type="button" onclick="closeModal('modal-return')" class="flex-1 <?= BTN_GHOST ?>">Cancel</button>
      <button type="submit" class="flex-1 <?= BTN_PRIMARY ?>">Record Return</button>
    </div>
  </form>
<?php modal_close(); ?>
<script>bindForm('form-return', 'api/returns.php');</script>
<?php endif; ?>

<?php if ($canDecide): ?>
<?php modal_open('modal-decide', 'Inspect and decide'); ?>
  <form id="form-decide" class="px-6 py-5 flex flex-col gap-4">
    <input type="hidden" name="no">
    <p id="decide-summary" class="text-sm text-slate-700"></p>
    <div><label class="<?= LABEL ?>">Decision *</label>
      <select name="decision" required class="<?= FIELD ?>" onchange="decideFields()">
        <option value="Restock">Restock: put it back into stock</option>
        <option value="Return to Supplier">Return to supplier</option>
        <option value="Dispose">Dispose of it</option>
        <option value="Reject">Reject the return</option>
      </select></div>
    <div data-for="Return to Supplier" class="hidden"><label class="<?= LABEL ?>">Supplier *</label>
      <select name="supplier" class="<?= FIELD ?>"><?php foreach ($suppliers as $s): ?><option><?= h($s) ?></option><?php endforeach; ?></select></div>
    <div data-for="Dispose" class="hidden"><label class="<?= LABEL ?>">Disposal method *</label>
      <select name="disposal_method" class="<?= FIELD ?>"><option>Accredited e-waste recycler</option><option>Sold as scrap</option><option>Destroyed on site</option></select></div>
    <label data-for="Restock" class="hidden items-start gap-2 text-xs text-amber-700"><input type="checkbox" name="confirm_restock" value="1" class="mt-0.5"> The item is not marked Good. I checked it and it can be sold.</label>
    <div><label class="<?= LABEL ?>">Inspection note <span data-required-note class="hidden">*</span></label><input name="note" maxlength="250" class="<?= FIELD ?>" placeholder="What you found"></div>
    <p data-error class="hidden text-xs text-red-600"></p>
    <div class="flex gap-3 pt-1">
      <button type="button" onclick="closeModal('modal-decide')" class="flex-1 <?= BTN_GHOST ?>">Cancel</button>
      <button type="submit" class="flex-1 <?= BTN_PRIMARY ?>">Save Decision</button>
    </div>
  </form>
<?php modal_close(); ?>
<script>
let decideCondition = 'Good';
function decideFields() {
  const f = document.getElementById('form-decide'), v = f.decision.value;
  f.querySelectorAll('[data-for]').forEach(el => {
    let show = el.dataset.for === v;
    if (el.dataset.for === 'Restock') show = show && decideCondition !== 'Good';
    el.classList.toggle('hidden', !show); el.classList.toggle('flex', show && el.tagName === 'LABEL');
  });
  f.querySelector('[data-required-note]').classList.toggle('hidden', v !== 'Reject');
}
function openDecide(r) {
  const f = document.getElementById('form-decide'); f.reset();
  f.no.value = r.no; decideCondition = r.condition;
  document.getElementById('decide-summary').innerHTML = `<b>${esc(r.no)}</b> · ${esc(r.item)} × ${r.qty} · condition: <b>${esc(r.condition)}</b>`;
  f.decision.value = r.condition === 'Good' ? 'Restock' : 'Return to Supplier';
  decideFields(); openModal('modal-decide');
}
bindForm('form-decide', f => 'api/returns.php?action=decide&id=' + encodeURIComponent(f.no.value));
</script>
<?php endif; ?>

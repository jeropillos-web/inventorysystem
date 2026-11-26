<?php
require_once __DIR__ . '/../config/fallback.php';

$user          = $_SESSION['user'];
$role          = $user['role'];
$availableOnly = $role === 'warehouse';
$canWrite      = role_can($role, 'write');
$canDelete     = role_can($role, 'delete');
$canAdjust     = role_can($role, 'stock_ops');
$canRemove     = role_can($role, 'approve');
$search        = trim($_GET['inv_q'] ?? '');
$filter        = $_GET['inv_status'] ?? 'All';
$whFilter      = trim($_GET['inv_wh'] ?? '');
$offline       = !db_available();

if (!in_array($filter, ['All', 'In Stock', 'Low Stock', 'Out of Stock'], true)) $filter = 'All';

$byWarehouse = [];
if ($offline) {
    $items = $FB['inventory'];
    $cats  = array_column($FB['categories'], 'name');
    $warehouses = [];
} else {
    $warehouses = warehouses_list(false);
    $whIds = array_column($warehouses, 'id');
    if ($whFilter !== '' && !in_array($whFilter, $whIds, true)) $whFilter = '';

    $sql    = 'SELECT * FROM inventory WHERE 1';
    $params = [];
    if ($search !== '') { $sql .= ' AND (name LIKE ? OR id LIKE ? OR barcode LIKE ?)'; $like = "%$search%"; array_push($params, $like, $like, $like); }
    if ($whFilter !== '') { $sql .= ' AND id IN (SELECT item_id FROM stock_levels WHERE warehouse_id = ? AND qty > 0)'; $params[] = $whFilter; }
    $sql .= ' ORDER BY id';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
    $cats  = db()->query('SELECT name FROM categories ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    foreach (db()->query('SELECT s.item_id, s.warehouse_id, s.qty, w.name FROM stock_levels s JOIN warehouses w ON w.id = s.warehouse_id WHERE s.qty > 0 ORDER BY w.is_main DESC, w.name') as $r) {
        $byWarehouse[$r['item_id']][] = $r;
    }
}

if ($availableOnly) $items = array_values(array_filter($items, fn($i) => (int)$i['qty'] > 0));
if ($offline && $search !== '') {
    $items = array_values(array_filter($items, fn($i) => stripos($i['name'], $search) !== false || stripos($i['id'], $search) !== false));
}

$total      = count($items);
$inStock    = count(array_filter($items, fn($i) => $i['status'] === 'In Stock'));
$lowStock   = count(array_filter($items, fn($i) => $i['status'] === 'Low Stock'));
$outOfStock = count(array_filter($items, fn($i) => $i['status'] === 'Out of Stock'));
if ($filter !== 'All') $items = array_values(array_filter($items, fn($i) => $i['status'] === $filter));

$qs = fn(array $over) => '?' . http_build_query(array_merge(['module' => 'inventory', 'inv_q' => $search, 'inv_status' => $filter, 'inv_wh' => $whFilter], $over));
?>

<?php if ($offline) offline_notice(); ?>

<div class="flex flex-col gap-6">
  <?php
  $actions = '';
  if ($canAdjust && !$offline) $actions .= '<button type="button" onclick="openAdjust()" class="' . BTN_GHOST . '">Stock In / Adjust</button>';
  if ($canWrite && !$offline) $actions .= '<button type="button" onclick="openItem()" class="' . BTN_PRIMARY . '">+ Add Item</button>';
  page_header($availableOnly ? 'Available Products' : 'Inventory Control',
              $availableOnly ? 'Products with stock in the warehouses' : 'Stock levels per warehouse, reorder levels and stock status',
              $actions);
  stat_cards([
      ['Total SKUs',   $total,      'text-slate-900'],
      ['In Stock',     $inStock,    'text-emerald-600'],
      ['Low Stock',    $lowStock,   'text-amber-600'],
      ['Out of Stock', $outOfStock, 'text-red-600'],
  ]);
  ?>

  <form method="GET" class="flex flex-wrap items-center gap-3">
    <input type="hidden" name="module" value="inventory" />
    <input type="hidden" name="inv_status" value="<?= h($filter) ?>" />
    <div class="flex-1 min-w-[200px] relative">
      <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      <input name="inv_q" value="<?= h($search) ?>" placeholder="Search name, SKU or barcode…"
        class="w-full bg-white border border-slate-200 rounded-lg pl-9 pr-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" />
    </div>
    <?php if ($warehouses): ?>
    <select name="inv_wh" onchange="this.form.submit()" class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm">
      <option value="">All warehouses</option>
      <?php foreach ($warehouses as $w): ?>
      <option value="<?= h($w['id']) ?>" <?= $whFilter === $w['id'] ? 'selected' : '' ?>><?= h($w['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php foreach (['All', 'In Stock', 'Low Stock', 'Out of Stock'] as $s): if ($availableOnly && $s === 'Out of Stock') continue; ?>
    <a href="<?= h($qs(['inv_status' => $s])) ?>"
      class="px-3 py-2 rounded-lg text-xs font-medium transition <?= $filter === $s ? 'bg-indigo-600 text-white' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' ?>"><?= $s ?></a>
    <?php endforeach; ?>
  </form>

  <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-slate-50 border-b border-slate-200">
            <?php foreach (['SKU', 'Item Name', 'Category', 'Total Qty', 'By Warehouse', 'Reorder', 'Status', 'Bin', 'Unit Cost (₱)', ''] as $hd): ?>
            <th class="text-left text-xs font-bold text-slate-400 px-4 py-3 uppercase tracking-wider whitespace-nowrap"><?= $hd ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (!$items): ?>
          <tr><td colspan="10" class="text-center py-12 text-slate-400">No items found.</td></tr>
          <?php endif; ?>
          <?php foreach ($items as $item): ?>
          <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50 transition">
            <td class="px-4 py-3 font-mono text-xs text-slate-500"><?= h($item['id']) ?></td>
            <td class="px-4 py-3 font-semibold text-slate-900"><?= h($item['name']) ?><?php if ($item['barcode'] !== ''): ?><div class="font-mono text-[11px] font-normal text-slate-400"><?= h($item['barcode']) ?></div><?php endif; ?></td>
            <td class="px-4 py-3 text-slate-600"><?= h($item['category']) ?></td>
            <td class="px-4 py-3 font-mono font-bold text-slate-900"><?= number_format((int)$item['qty']) ?></td>
            <td class="px-4 py-3 text-xs text-slate-500 whitespace-nowrap">
              <?php foreach ($byWarehouse[$item['id']] ?? [] as $b): ?>
              <div><?= h($b['name']) ?>: <b class="font-mono <?= $whFilter === $b['warehouse_id'] ? 'text-indigo-600' : 'text-slate-700' ?>"><?= (int)$b['qty'] ?></b></div>
              <?php endforeach; ?>
              <?php if (!$offline && empty($byWarehouse[$item['id']])): ?>—<?php endif; ?>
            </td>
            <td class="px-4 py-3 font-mono text-slate-500"><?= (int)$item['reorder'] ?></td>
            <td class="px-4 py-3"><?= badge($item['status']) ?></td>
            <td class="px-4 py-3 font-mono text-xs text-slate-500"><?= h($item['location']) ?></td>
            <td class="px-4 py-3 font-mono font-semibold text-slate-900 whitespace-nowrap"><?= number_format((float)$item['cost'], 2) ?></td>
            <td class="px-4 py-3 whitespace-nowrap text-right">
              <?php if (!$offline): ?>
              <button type="button" onclick="openCard('<?= h($item['id']) ?>', <?= h(json_encode($item['name'])) ?>)" class="px-2 py-1 rounded-lg text-xs font-semibold text-indigo-600 hover:bg-indigo-50">Stock card</button>
              <?php if ($canWrite): ?>
              <button type="button" onclick='openItem(<?= h(json_encode($item)) ?>)' class="px-2 py-1 rounded-lg text-xs font-semibold text-slate-600 hover:bg-slate-100">Edit</button>
              <?php endif; ?>
              <?php if ($canDelete): ?>
              <button type="button" onclick="act('api/inventory.php?id=<?= h(rawurlencode($item['id'])) ?>', 'DELETE', null, 'Delete <?= h(addslashes($item['name'])) ?>? This cannot be undone.')" title="Delete" class="p-1.5 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 transition align-middle">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
              </button>
              <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <p class="text-xs text-slate-400 -mt-3">Stock status is set automatically: Out of Stock at 0, Low Stock at or below the reorder level, otherwise In Stock.</p>
</div>

<?php if (!$offline): ?>
<?php modal_open('modal-card', 'Stock card', 'Every change to this product\'s quantity', 'max-w-3xl'); ?>
  <div class="px-6 py-5">
    <p id="card-title" class="font-semibold text-slate-900 mb-3"></p>
    <div class="overflow-x-auto"><table class="w-full text-sm">
      <thead><tr class="bg-slate-50 border-b border-slate-200">
        <?php foreach (['Date', 'Warehouse', 'Type', 'Reference', 'Change', 'Balance', 'By', 'Note'] as $hd): ?>
        <th class="text-left text-xs font-bold text-slate-400 px-3 py-2 uppercase tracking-wider whitespace-nowrap"><?= $hd ?></th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody id="card-rows"><tr><td colspan="8" class="py-6 text-center text-slate-400">Loading…</td></tr></tbody>
    </table></div>
  </div>
<?php modal_close(); ?>
<script>
async function openCard(id, name) {
  document.getElementById('card-title').textContent = id + ' · ' + name;
  const body = document.getElementById('card-rows');
  body.innerHTML = '<tr><td colspan="8" class="py-6 text-center text-slate-400">Loading…</td></tr>';
  openModal('modal-card');
  try {
    const rows = await api('api/inventory.php?history=' + encodeURIComponent(id));
    body.innerHTML = rows.length ? rows.map(r => `<tr class="border-b border-slate-100">
      <td class="px-3 py-2 whitespace-nowrap text-xs">${esc(r.created_at)}</td>
      <td class="px-3 py-2 text-xs">${esc(r.warehouse)}</td>
      <td class="px-3 py-2 text-xs font-semibold">${esc(r.type)}</td>
      <td class="px-3 py-2 font-mono text-xs text-indigo-600">${esc(r.reference)}</td>
      <td class="px-3 py-2 font-mono font-bold ${r.qty_change > 0 ? 'text-emerald-600' : 'text-red-600'}">${r.qty_change > 0 ? '+' : ''}${esc(r.qty_change)}</td>
      <td class="px-3 py-2 font-mono">${esc(r.balance_after)}</td>
      <td class="px-3 py-2 text-xs">${esc(r.user_name || 'System')}</td>
      <td class="px-3 py-2 text-xs text-slate-500">${esc(r.note)}</td></tr>`).join('')
      : '<tr><td colspan="8" class="py-6 text-center text-slate-400">No stock movements yet.</td></tr>';
  } catch (e) { body.innerHTML = `<tr><td colspan="8" class="py-6 text-center text-red-600">${esc(e.message)}</td></tr>`; }
}
</script>
<?php endif; ?>

<?php if ($canWrite && !$offline): ?>
<?php modal_open('modal-item', 'Inventory item'); ?>
  <form id="form-item" class="px-6 py-5 flex flex-col gap-4">
    <input type="hidden" name="sku">
    <div class="grid grid-cols-2 gap-4">
      <div class="col-span-2"><label class="<?= LABEL ?>">Item Name *</label><input name="name" required maxlength="150" class="<?= FIELD ?>"></div>
      <div><label class="<?= LABEL ?>">Category</label>
        <select name="category" class="<?= FIELD ?>"><?php foreach ($cats as $c): ?><option><?= h($c) ?></option><?php endforeach; ?></select></div>
      <div><label class="<?= LABEL ?>">Reorder Level</label><input name="reorder" type="number" min="0" value="5" required class="<?= FIELD ?>"></div>
      <div data-new-only><label class="<?= LABEL ?>">Opening Quantity</label><input name="qty" type="number" min="0" value="0" class="<?= FIELD ?>"></div>
      <div data-new-only><label class="<?= LABEL ?>">Warehouse</label>
        <select name="warehouse_id" class="<?= FIELD ?>"><?php foreach ($warehouses as $w): if (!$w['active']) continue; ?><option value="<?= h($w['id']) ?>"><?= h($w['name']) ?></option><?php endforeach; ?></select></div>
      <div><label class="<?= LABEL ?>">Bin Location</label><input name="location" maxlength="30" placeholder="A-01-1" class="<?= FIELD ?>"></div>
      <div><label class="<?= LABEL ?>">Unit Cost (₱)</label><input name="cost" type="number" min="0" step="0.01" value="0" class="<?= FIELD ?>"></div>
      <div class="col-span-2"><label class="<?= LABEL ?>">Barcode (optional)</label><input name="barcode" maxlength="50" class="<?= FIELD ?> font-mono"></div>
    </div>
    <p class="text-xs text-slate-500" data-edit-only>To change the quantity, use Stock In / Adjust, Returns, Disposal or Stock Transfers so every change is recorded.</p>
    <p data-error class="hidden text-xs text-red-600"></p>
    <div class="flex gap-3 pt-1">
      <button type="button" onclick="closeModal('modal-item')" class="flex-1 <?= BTN_GHOST ?>">Cancel</button>
      <button type="submit" class="flex-1 <?= BTN_PRIMARY ?>">Save Item</button>
    </div>
  </form>
<?php modal_close(); ?>
<script>
function openItem(item) {
  const f = document.getElementById('form-item');
  f.reset();
  const editing = !!item;
  f.closest('[data-modal]').querySelector('h2').textContent = editing ? 'Edit ' + item.id : 'Add Inventory Item';
  f.querySelectorAll('[data-new-only]').forEach(el => el.classList.toggle('hidden', editing));
  f.querySelectorAll('[data-edit-only]').forEach(el => el.classList.toggle('hidden', !editing));
  f.sku.value = editing ? item.id : '';
  if (editing) for (const k of ['name', 'category', 'reorder', 'location', 'cost', 'barcode']) if (f[k]) f[k].value = item[k] ?? '';
  openModal('modal-item');
}
bindForm('form-item',
  f => f.sku.value ? 'api/inventory.php?id=' + encodeURIComponent(f.sku.value) : 'api/inventory.php',
  f => f.sku.value ? 'PUT' : 'POST');
</script>
<?php endif; ?>

<?php if ($canAdjust && !$offline): ?>
<?php modal_open('modal-adjust', 'Stock In / Adjust', $canRemove ? 'Record a supplier delivery or correct a count' : 'Record items received from a supplier'); ?>
  <form id="form-adjust" class="px-6 py-5 flex flex-col gap-4">
    <div class="grid grid-cols-2 gap-4">
      <div class="col-span-2"><label class="<?= LABEL ?>">Product *</label>
        <select name="item_id" required class="<?= FIELD ?>"><?php foreach (products_list() as $p): ?><option value="<?= h($p['id']) ?>"><?= h($p['name']) ?> (<?= h($p['id']) ?>)</option><?php endforeach; ?></select></div>
      <div><label class="<?= LABEL ?>">Warehouse *</label>
        <select name="warehouse_id" required class="<?= FIELD ?>"><?php foreach ($warehouses as $w): if (!$w['active']) continue; ?><option value="<?= h($w['id']) ?>"><?= h($w['name']) ?></option><?php endforeach; ?></select></div>
      <div><label class="<?= LABEL ?>">Quantity *</label><input name="qty" type="number" <?= $canRemove ? '' : 'min="1"' ?> value="1" required class="<?= FIELD ?>">
        <?php if ($canRemove): ?><p class="mt-1 text-[11px] text-slate-400">Use a negative number to remove stock.</p><?php endif; ?></div>
      <div class="col-span-2"><label class="<?= LABEL ?>">Reason *</label>
        <select name="reason" required class="<?= FIELD ?>">
          <option>Received from supplier</option>
          <?php if ($canRemove): ?><option>Physical count correction</option><option>Found during inventory</option><option>Other</option><?php endif; ?>
        </select></div>
      <div class="col-span-2"><label class="<?= LABEL ?>">Note</label><input name="note" maxlength="200" placeholder="e.g. PO-2026-0442, delivery receipt no." class="<?= FIELD ?>"></div>
    </div>
    <p data-error class="hidden text-xs text-red-600"></p>
    <div class="flex gap-3 pt-1">
      <button type="button" onclick="closeModal('modal-adjust')" class="flex-1 <?= BTN_GHOST ?>">Cancel</button>
      <button type="submit" class="flex-1 <?= BTN_PRIMARY ?>">Save</button>
    </div>
  </form>
<?php modal_close(); ?>
<script>
function openAdjust() { document.getElementById('form-adjust').reset(); openModal('modal-adjust'); }
bindForm('form-adjust', 'api/inventory.php?action=adjust');
</script>
<?php endif; ?>

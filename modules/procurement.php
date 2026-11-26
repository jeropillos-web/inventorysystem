<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/fallback.php';

$user     = $_SESSION['user'];
$readonly  = !role_can($user['role'], 'write');
$canDelete = role_can($user['role'], 'delete');
$offline  = !db_available();
$search   = trim($_GET['po_q'] ?? '');

if ($offline) {
    $suppliers = $FB['suppliers'];
    $pos       = $FB['purchase_orders'];
} else {
    $suppliers = db()->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();
    $sql = 'SELECT * FROM purchase_orders WHERE 1';
    $params = [];
    if ($search !== '') {
        $sql .= ' AND (id LIKE ? OR supplier LIKE ? OR status LIKE ?)';
        $like = "%$search%"; $params = [$like,$like,$like];
    }
    $sql .= ' ORDER BY order_date DESC';
    $stmt = db()->prepare($sql); $stmt->execute($params);
    $pos = $stmt->fetchAll();
}

if ($offline && $search !== '') {
    $pos = array_values(array_filter($pos, fn($p) =>
        stripos($p['id'],$search)!==false || stripos($p['supplier'],$search)!==false || stripos($p['status'],$search)!==false
    ));
}

function poStatusBadge(string $s): string {
    $map = ['Confirmed'=>'bg-blue-100 text-blue-700','In Review'=>'bg-purple-100 text-purple-700','Pending'=>'bg-amber-100 text-amber-700','Delivered'=>'bg-emerald-100 text-emerald-700'];
    $cls = $map[$s] ?? 'bg-slate-100 text-slate-600';
    return "<span class=\"text-xs font-semibold px-2 py-0.5 rounded-full $cls\">".htmlspecialchars($s)."</span>";
}
?>

<?php if ($offline): ?>
<div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-4 text-sm text-amber-700 flex items-center gap-2">
  <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
  <span><strong>Demo mode</strong> — database not connected. Import <code>sql/invtrack.sql</code> to enable full CRUD.</span>
</div>
<?php endif; ?>

<div class="flex flex-col gap-6">
  <div class="flex flex-wrap items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-slate-900">Procurement &amp; Suppliers</h1>
      <p class="text-sm text-slate-500 mt-0.5">Manage vendors, purchase orders, and procurement pipeline</p>
    </div>
    <?php if (!$readonly && !$offline): ?>
    <div class="flex gap-2">
    <button type="button" onclick="openModal('modal-add-sup')" class="<?= BTN_GHOST ?>">+ Add Supplier</button>
    <button onclick="document.getElementById('modal-add-po').classList.remove('hidden')"
      class="flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-bold hover:bg-indigo-500 transition shadow-lg shadow-indigo-500/30">
      + Create PO
    </button>
    </div>
    <?php endif; ?>
  </div>

  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    <?php
    $active  = count(array_filter($suppliers, fn($s)=>$s['status']==='Active'));
    $ytd     = array_sum(array_column($suppliers, 'spend'));
    $openPOs = count(array_filter($pos, fn($p)=>!in_array($p['status'],['Confirmed','Delivered'])));
    $avgOT   = $suppliers ? round(array_sum(array_column($suppliers,'on_time'))/count($suppliers)) : 0;
    foreach ([
        ['Active Vendors', $active,            'bg-emerald-100 text-emerald-600'],
        ['Open POs',       $openPOs,           'bg-amber-100 text-amber-600'],
        ['YTD Spend',      '₱'.number_format($ytd), 'bg-violet-100 text-violet-600'],
        ['Avg On-Time',    "$avgOT%",          'bg-blue-100 text-blue-600'],
    ] as [$label,$val,$cls]):
    ?>
    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <div class="w-9 h-9 rounded-lg <?= $cls ?> flex items-center justify-center mb-3 text-sm font-bold"><?= is_int($val) ? $val : '' ?></div>
      <div class="text-2xl font-bold text-slate-900"><?= $val ?></div>
      <div class="text-sm text-slate-500 mt-0.5"><?= $label ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-200"><h3 class="text-sm font-semibold text-slate-900">Supplier Directory</h3></div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-slate-50 border-b border-slate-200">
            <?php foreach (['Supplier','Country','Category','Rating','Orders','YTD Spend (₱)','On-Time','Status'] as $h): ?>
            <th class="text-left text-xs font-bold text-slate-400 px-5 py-3 uppercase tracking-wider whitespace-nowrap"><?= $h ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($suppliers as $s): ?>
          <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50 transition">
            <td class="px-5 py-3 font-semibold text-slate-900"><?= htmlspecialchars($s['name']) ?></td>
            <td class="px-5 py-3 text-slate-600"><?= htmlspecialchars($s['country']) ?></td>
            <td class="px-5 py-3 text-slate-600"><?= htmlspecialchars($s['category']) ?></td>
            <td class="px-5 py-3">
              <span class="flex items-center gap-1 font-mono text-amber-600 font-bold">
                <svg class="w-3.5 h-3.5 fill-amber-400" viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
                <?= number_format($s['rating'],1) ?>
              </span>
            </td>
            <td class="px-5 py-3 font-mono text-slate-700"><?= $s['orders'] ?></td>
            <td class="px-5 py-3 font-mono font-semibold text-slate-900">₱<?= number_format($s['spend']) ?></td>
            <td class="px-5 py-3">
              <div class="flex items-center gap-2">
                <div class="w-16 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                  <div class="h-full rounded-full <?= $s['on_time']>=90?'bg-emerald-500':($s['on_time']>=80?'bg-amber-500':'bg-red-500') ?>" style="width:<?= $s['on_time'] ?>%"></div>
                </div>
                <span class="text-xs font-mono text-slate-600"><?= $s['on_time'] ?>%</span>
              </div>
            </td>
            <td class="px-5 py-3">
              <?php $sc = $s['status']==='Active'?'bg-emerald-100 text-emerald-700':'bg-amber-100 text-amber-700'; ?>
              <span class="text-xs font-semibold px-2 py-0.5 rounded-full <?= $sc ?>"><?= htmlspecialchars($s['status']) ?></span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-b border-slate-200">
      <h3 class="text-sm font-semibold text-slate-900">Recent Purchase Orders</h3>
      <form method="GET" class="flex items-center gap-2">
        <input type="hidden" name="module" value="procurement" />
        <div class="relative">
          <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
          <input name="po_q" value="<?= htmlspecialchars($search) ?>" placeholder="Search PO, supplier…"
            class="bg-slate-50 border border-slate-200 rounded-lg pl-9 pr-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 w-56" />
        </div>
        <button type="submit" class="px-3 py-2 bg-indigo-600 text-white rounded-lg text-xs font-bold hover:bg-indigo-500">Search</button>
        <?php if ($search): ?><a href="?module=procurement" class="px-3 py-2 text-xs text-slate-500 hover:text-slate-700">Clear</a><?php endif; ?>
      </form>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-slate-50 border-b border-slate-200">
            <?php foreach (['PO Number','Supplier','Items','Value (₱)','Order Date','Delivery Date','Status'] as $h): ?>
            <th class="text-left text-xs font-bold text-slate-400 px-5 py-3 uppercase tracking-wider whitespace-nowrap"><?= $h ?></th>
            <?php endforeach; ?>
            <?php if ($canDelete && !$offline): ?><th class="px-5 py-3"></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($pos)): ?>
          <tr><td colspan="8" class="text-center py-12 text-slate-400">No purchase orders found<?= $search ? ' matching "'.htmlspecialchars($search).'"' : '' ?>.</td></tr>
          <?php else: ?>
          <?php foreach ($pos as $po): ?>
          <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50 transition">
            <td class="px-5 py-3 font-mono text-xs text-indigo-600 font-medium"><?= htmlspecialchars($po['id']) ?></td>
            <td class="px-5 py-3 font-semibold text-slate-900"><?= htmlspecialchars($po['supplier']) ?></td>
            <td class="px-5 py-3 font-mono text-slate-700"><?= $po['items'] ?></td>
            <td class="px-5 py-3 font-mono font-semibold text-slate-900">₱<?= number_format($po['value'],2) ?></td>
            <td class="px-5 py-3 text-slate-600"><?= htmlspecialchars($po['order_date']) ?></td>
            <td class="px-5 py-3 text-slate-600"><?= htmlspecialchars($po['delivery_date']) ?></td>
            <td class="px-5 py-3">
              <?php if (!$readonly && !$offline): ?>
              <select onchange="act('api/purchase_orders.php?id=<?= h(rawurlencode($po['id'])) ?>', 'PUT', {status: this.value})" class="text-xs border border-slate-200 rounded-lg px-2 py-1 bg-white" title="Change status">
                <?php foreach (['Pending', 'In Review', 'Confirmed', 'Delivered', 'Cancelled'] as $st): ?><option <?= $po['status'] === $st ? 'selected' : '' ?>><?= $st ?></option><?php endforeach; ?>
              </select>
              <?php else: ?><?= poStatusBadge($po['status']) ?><?php endif; ?>
            </td>
            <?php if ($canDelete && !$offline): ?>
            <td class="px-5 py-3">
              <button onclick="deletePO('<?= htmlspecialchars($po['id']) ?>')"
                class="p-1.5 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
              </button>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if (!$readonly && !$offline): ?>
<div id="modal-add-po" data-modal class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg" onclick="event.stopPropagation()">
    <div class="flex items-center justify-between px-6 py-5 border-b border-slate-200">
      <h2 class="font-bold text-slate-900">Create Purchase Order</h2>
      <button onclick="document.getElementById('modal-add-po').classList.add('hidden')" class="p-1.5 hover:bg-slate-100 rounded-lg text-slate-500">✕</button>
    </div>
    <form id="form-add-po" class="px-6 py-5 flex flex-col gap-4">
      <div class="grid grid-cols-2 gap-4">
        <div class="col-span-2"><label class="block text-xs font-semibold text-slate-600 mb-1">Supplier *</label>
          <select name="supplier" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
            <?php foreach ($suppliers as $s) echo "<option>".htmlspecialchars($s['name'])."</option>"; ?>
          </select></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Items Count</label>
          <input name="items" type="number" min="1" value="1" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" /></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Total Value (₱)</label>
          <input name="value" type="number" min="0" step="0.01" value="0" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" /></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Order Date</label>
          <input name="order_date" type="date" value="<?= date('Y-m-d') ?>" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" /></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Delivery Date</label>
          <input name="delivery_date" type="date" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" /></div>
        <div class="col-span-2"><label class="block text-xs font-semibold text-slate-600 mb-1">Status</label>
          <select name="status" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
            <option>Pending</option><option>In Review</option><option>Confirmed</option>
          </select></div>
      </div>
      <p id="po-form-error" class="text-xs text-red-600 hidden"></p>
      <div class="flex gap-3 pt-1">
        <button type="button" onclick="document.getElementById('modal-add-po').classList.add('hidden')"
          class="flex-1 py-2.5 rounded-xl border border-slate-200 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
        <button type="submit" class="flex-1 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-bold hover:bg-indigo-500 transition">Create PO</button>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('form-add-po').addEventListener('submit', async function(e) {
  e.preventDefault();
  const fd = new FormData(this); const body = Object.fromEntries(fd.entries());
  body.items = parseInt(body.items); body.value = parseFloat(body.value);
  try { await api('api/purchase_orders.php', 'POST', body); location.reload(); }
  catch (ex) { const el=document.getElementById('po-form-error'); el.textContent=ex.message; el.classList.remove('hidden'); }
});
function deletePO(id) {
  act('api/purchase_orders.php?id=' + encodeURIComponent(id), 'DELETE', null, 'Delete this purchase order?');
}
</script>
<?php endif; ?>

<?php if (!$readonly && !$offline): ?>
<?php modal_open('modal-add-sup', 'Add Supplier'); ?>
  <form id="form-add-sup" class="px-6 py-5 flex flex-col gap-4">
    <div class="grid grid-cols-2 gap-4">
      <div class="col-span-2"><label class="<?= LABEL ?>">Supplier name *</label><input name="name" required maxlength="100" class="<?= FIELD ?>"></div>
      <div><label class="<?= LABEL ?>">Country</label><input name="country" maxlength="60" value="Philippines" class="<?= FIELD ?>"></div>
      <div><label class="<?= LABEL ?>">Main category</label><input name="category" maxlength="60" placeholder="e.g. Laptops" class="<?= FIELD ?>"></div>
      <div><label class="<?= LABEL ?>">Rating (0–5)</label><input name="rating" type="number" min="0" max="5" step="0.1" value="0" class="<?= FIELD ?>"></div>
      <div><label class="<?= LABEL ?>">On-time delivery (%)</label><input name="on_time" type="number" min="0" max="100" value="0" class="<?= FIELD ?>"></div>
      <div class="col-span-2"><label class="<?= LABEL ?>">Status</label><select name="status" class="<?= FIELD ?>"><option>Active</option><option>Under Review</option><option>Inactive</option></select></div>
    </div>
    <p data-error class="hidden text-xs text-red-600"></p>
    <div class="flex gap-3 pt-1">
      <button type="button" onclick="closeModal('modal-add-sup')" class="flex-1 <?= BTN_GHOST ?>">Cancel</button>
      <button type="submit" class="flex-1 <?= BTN_PRIMARY ?>">Add Supplier</button>
    </div>
  </form>
<?php modal_close(); ?>
<script>bindForm('form-add-sup', 'api/suppliers.php');</script>
<?php endif; ?>

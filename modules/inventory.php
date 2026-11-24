<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/fallback.php';

$user     = $_SESSION['user'];
$readonly = $user['role'] === 'warehouse';
$availableOnly = $user['role'] === 'warehouse';
$search   = trim($_GET['inv_q'] ?? '');
$filter   = $_GET['inv_status'] ?? 'All';
$offline  = !db_available();

if ($offline) {
    $items = $FB['inventory'];
    if ($availableOnly) $items = array_values(array_filter($items, fn($item) => (int)$item['qty'] > 0));
    $cats  = array_unique(array_column($FB['categories'], 'name'));
} else {
    $sql    = 'SELECT * FROM inventory WHERE 1';
    $params = [];
    if ($availableOnly) $sql .= ' AND qty > 0';
    if ($search !== '') { $sql .= ' AND (name LIKE ? OR id LIKE ?)'; $like = "%$search%"; $params = [$like,$like]; }
    if ($filter !== 'All') { $sql .= ' AND status=?'; $params[] = $filter; }
    $sql .= ' ORDER BY id';
    $stmt = db()->prepare($sql); $stmt->execute($params);
    $items = $stmt->fetchAll();
    $cats  = db()->query('SELECT name FROM categories ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
}

if ($search !== '' || $filter !== 'All') {
    $items = array_filter($items, function($i) use ($search, $filter) {
        $matchQ = $search === '' || stripos($i['name'],$search)!==false || stripos($i['id'],$search)!==false;
        $matchF = $filter === 'All' || $i['status'] === $filter;
        return $matchQ && $matchF;
    });
    $items = array_values($items);
}

$total      = count($items);
$inStock    = count(array_filter($items, fn($i)=>$i['status']==='In Stock'));
$lowStock   = count(array_filter($items, fn($i)=>$i['status']==='Low Stock'));
$outOfStock = count(array_filter($items, fn($i)=>$i['status']==='Out of Stock'));

function statusBadge(string $s): string {
    $map = ['In Stock'=>'bg-emerald-100 text-emerald-700','Low Stock'=>'bg-amber-100 text-amber-700','Out of Stock'=>'bg-red-100 text-red-700'];
    $cls = $map[$s] ?? 'bg-slate-100 text-slate-700';
    return "<span class=\"text-xs font-semibold px-2 py-0.5 rounded-full $cls\">".htmlspecialchars($s)."</span>";
}
?>

<?php if ($offline): ?>
<div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-4 text-sm text-amber-700 flex items-center gap-2">
  <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
  <span><strong>Demo mode</strong> — database not connected. Import <code>sql/invtrack.sql</code> in phpMyAdmin to enable full CRUD.</span>
</div>
<?php endif; ?>

<div class="flex flex-col gap-6">
  <div class="flex flex-wrap items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-slate-900"><?= $availableOnly ? 'Available Products' : 'Inventory Control' ?></h1>
      <p class="text-sm text-slate-500 mt-0.5"><?= $availableOnly ? 'Products currently available inside the warehouse' : 'Track stock levels, locations, and reorder thresholds' ?></p>
    </div>
    <?php if (!$readonly && !$offline): ?>
    <button onclick="document.getElementById('modal-add-item').classList.remove('hidden')"
      class="flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-bold hover:bg-indigo-500 transition shadow-lg shadow-indigo-500/30">
      + Add Item
    </button>
    <?php endif; ?>
  </div>

  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
    <?php foreach ([['Total SKUs',$total,'bg-blue-100 text-blue-600'],['In Stock',$inStock,'bg-emerald-100 text-emerald-600'],['Low Stock',$lowStock,'bg-amber-100 text-amber-600'],['Out of Stock',$outOfStock,'bg-red-100 text-red-600']] as [$label,$val,$cls]): ?>
    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <div class="w-9 h-9 rounded-lg <?= $cls ?> flex items-center justify-center mb-3 text-sm font-bold"><?= $val ?></div>
      <div class="text-2xl font-bold text-slate-900"><?= number_format($val) ?></div>
      <div class="text-sm text-slate-500 mt-0.5"><?= $label ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <form method="GET" class="flex flex-wrap items-center gap-3">
    <input type="hidden" name="module" value="inventory" />
    <div class="flex-1 min-w-[200px] relative">
      <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      <input name="inv_q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name or SKU…"
        class="w-full bg-white border border-slate-200 rounded-lg pl-9 pr-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" />
    </div>
    <?php foreach (['All','In Stock','Low Stock','Out of Stock'] as $s): ?>
    <button type="submit" name="inv_status" value="<?= $s ?>"
      class="px-3 py-2 rounded-lg text-xs font-medium transition <?= $filter===$s ? 'bg-indigo-600 text-white' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' ?>">
      <?= $s ?>
    </button>
    <?php endforeach; ?>
  </form>

  <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-slate-50 border-b border-slate-200">
            <?php foreach (['SKU ID','Item Name','Category','Qty','Reorder','Status','Location','Unit Cost (₱)','Barcode'] as $h): ?>
            <th class="text-left text-xs font-bold text-slate-400 px-5 py-4 uppercase tracking-wider whitespace-nowrap"><?= $h ?></th>
            <?php endforeach; ?>
            <?php if (!$readonly && !$offline): ?><th class="px-5 py-4"></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($items)): ?>
          <tr><td colspan="10" class="text-center py-12 text-slate-400">No items found.</td></tr>
          <?php else: ?>
          <?php foreach ($items as $i => $item): ?>
          <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50 transition <?= $i%2!==0?'bg-slate-50/50':'' ?>">
            <td class="px-5 py-4 font-mono text-xs text-slate-500"><?= htmlspecialchars($item['id']) ?></td>
            <td class="px-5 py-4 font-semibold text-slate-900"><?= htmlspecialchars($item['name']) ?></td>
            <td class="px-5 py-4 text-slate-600"><?= htmlspecialchars($item['category']) ?></td>
            <td class="px-5 py-4 font-mono font-bold text-slate-900"><?= number_format($item['qty']) ?></td>
            <td class="px-5 py-4 font-mono text-slate-500"><?= $item['reorder'] ?></td>
            <td class="px-5 py-4"><?= statusBadge($item['status']) ?></td>
            <td class="px-5 py-4 font-mono text-xs text-slate-500"><?= htmlspecialchars($item['location']) ?></td>
            <td class="px-5 py-4 font-mono font-semibold text-slate-900">₱<?= number_format($item['cost'],2) ?></td>
            <td class="px-5 py-4 font-mono text-xs text-slate-500"><?= htmlspecialchars($item['barcode']) ?></td>
            <?php if (!$readonly && !$offline): ?>
            <td class="px-5 py-4">
              <button onclick="deleteInventory('<?= htmlspecialchars($item['id']) ?>')"
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
<div id="modal-add-item" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg" onclick="event.stopPropagation()">
    <div class="flex items-center justify-between px-6 py-5 border-b border-slate-200">
      <h2 class="font-bold text-slate-900">Add Inventory Item</h2>
      <button onclick="document.getElementById('modal-add-item').classList.add('hidden')" class="p-1.5 hover:bg-slate-100 rounded-lg text-slate-500">✕</button>
    </div>
    <form id="form-add-item" class="px-6 py-5 flex flex-col gap-4">
      <div class="grid grid-cols-2 gap-4">
        <div class="col-span-2"><label class="block text-xs font-semibold text-slate-600 mb-1">Item Name *</label>
          <input name="name" required class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" /></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Category</label>
          <select name="category" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
            <?php foreach ($cats as $c) echo "<option>".htmlspecialchars($c)."</option>"; ?>
          </select></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Status</label>
          <select name="status" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
            <option>In Stock</option><option>Low Stock</option><option>Out of Stock</option>
          </select></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Qty on Hand</label>
          <input name="qty" type="number" min="0" value="0" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" /></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Reorder Point</label>
          <input name="reorder" type="number" min="0" value="5" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" /></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Location</label>
          <input name="location" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" placeholder="A-01-1" /></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Unit Cost (₱)</label>
          <input name="cost" type="number" min="0" step="0.01" value="0" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" /></div>
        <div class="col-span-2"><label class="block text-xs font-semibold text-slate-600 mb-1">Barcode</label>
          <input name="barcode" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-300" placeholder="Auto-generated if blank" /></div>
      </div>
      <p id="inv-form-error" class="text-xs text-red-600 hidden"></p>
      <div class="flex gap-3 pt-1">
        <button type="button" onclick="document.getElementById('modal-add-item').classList.add('hidden')"
          class="flex-1 py-2.5 rounded-xl border border-slate-200 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
        <button type="submit" class="flex-1 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-bold hover:bg-indigo-500 transition">Add Item</button>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('form-add-item').addEventListener('submit', async function(e) {
  e.preventDefault();
  const fd = new FormData(this); const body = Object.fromEntries(fd.entries());
  body.qty=parseInt(body.qty); body.reorder=parseInt(body.reorder); body.cost=parseFloat(body.cost);
  const res = await fetch('api/inventory.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
  const json = await res.json();
  if (json.ok) { location.reload(); }
  else { const el=document.getElementById('inv-form-error'); el.textContent=json.error; el.classList.remove('hidden'); }
});
function deleteInventory(id) {
  if (!confirm('Delete this product?')) return;
  fetch('api/inventory.php?id='+encodeURIComponent(id),{method:'DELETE'}).then(()=>location.reload());
}
</script>
<?php endif; ?>

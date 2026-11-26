<?php
require_once __DIR__ . '/../config/fallback.php';

$user      = $_SESSION['user'];
$offline   = !db_available();
$canAdd    = !$offline && role_can($user['role'], 'write');
$canDelete = !$offline && role_can($user['role'], 'delete');
$canManage = !$offline && role_can($user['role'], 'approve');

if ($offline) {
    $zones      = $FB['warehouse_zones'];
    $shipments  = $FB['shipments'];
    $warehouses = [];
} else {
    $zones      = db()->query('SELECT * FROM warehouse_zones ORDER BY zone')->fetchAll();
    $warehouses = db()->query(
        'SELECT w.*, COALESCE(SUM(s.qty), 0) AS units, COUNT(CASE WHEN s.qty > 0 THEN 1 END) AS products
         FROM warehouses w LEFT JOIN stock_levels s ON s.warehouse_id = w.id
         GROUP BY w.id, w.name, w.address, w.is_main, w.active
         ORDER BY w.is_main DESC, w.name')->fetchAll();
    $shipments = db()->query(
        "SELECT s.*, u.name AS driver_name, d.vehicle_id, c.name AS created_by_name, a.name AS reviewed_by_name
         FROM shipments s
         LEFT JOIN deliveries d ON d.shipment_id = s.id
         LEFT JOIN users u ON u.id = d.driver_user_id
         LEFT JOIN users c ON c.id = s.created_by
         LEFT JOIN users a ON a.id = s.approved_by
         ORDER BY FIELD(s.status, 'For Approval', 'Approved', 'In Transit', 'Picked Up', 'Delivered', 'Rejected'), s.eta DESC")->fetchAll();
}

$totalCap = array_sum(array_column($zones, 'capacity'));
$totalOcc = array_sum(array_column($zones, 'occupied'));
$utilPct  = $totalCap > 0 ? round($totalOcc / $totalCap * 100) : 0;
?>

<?php if ($offline) offline_notice(); ?>

<div class="flex flex-col gap-6">
  <?php
  $actions = '';
  if ($canManage) $actions .= '<button type="button" onclick="openWarehouse()" class="' . BTN_GHOST . '">+ Add Warehouse</button>';
  if ($canAdd) $actions .= '<button type="button" onclick="openModal(\'modal-add-ship\')" class="' . BTN_PRIMARY . '">+ Add Shipment</button>';
  page_header('Warehouse & Logistics', 'Warehouses, zone utilization, and outbound shipments with their tracking logs', $actions);
  ?>

  <?php if (!$offline): ?>
  <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
    <?php foreach ($warehouses as $w): ?>
    <div class="bg-white rounded-xl border <?= $w['active'] ? 'border-slate-200' : 'border-dashed border-slate-300 opacity-70' ?> p-5 shadow-sm">
      <div class="flex items-start justify-between gap-2">
        <div>
          <div class="flex items-center gap-2"><h3 class="font-bold text-slate-900"><?= h($w['name']) ?></h3>
            <?= $w['is_main'] ? '<span class="text-[10px] font-bold uppercase tracking-wider bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full">Main</span>' : '' ?>
            <?= $w['active'] ? '' : badge('Inactive') ?></div>
          <p class="text-xs text-slate-500"><?= h($w['id']) ?> · <?= h($w['address'] ?: 'No address') ?></p>
        </div>
        <?php if ($canManage): ?>
        <button type="button" onclick='openWarehouse(<?= h(json_encode($w)) ?>)' class="text-xs font-semibold text-slate-500 hover:text-indigo-600">Edit</button>
        <?php endif; ?>
      </div>
      <div class="mt-3 flex gap-6">
        <div><div class="text-xl font-bold text-slate-900"><?= number_format((int)$w['units']) ?></div><div class="text-xs text-slate-500">units in stock</div></div>
        <div><div class="text-xl font-bold text-slate-900"><?= (int)$w['products'] ?></div><div class="text-xs text-slate-500">products</div></div>
      </div>
      <a href="?module=inventory&inv_wh=<?= urlencode($w['id']) ?>" class="mt-3 inline-block text-xs font-semibold text-indigo-600 hover:underline">View stock</a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <div class="text-sm text-slate-500 mb-1">Main warehouse zone capacity</div>
      <div class="text-2xl font-bold text-slate-900"><?= number_format($totalCap) ?> <span class="text-base font-normal text-slate-400">slots</span></div>
    </div>
    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <div class="text-sm text-slate-500 mb-1">Occupied</div>
      <div class="text-2xl font-bold text-slate-900"><?= number_format($totalOcc) ?> <span class="text-base font-normal text-slate-400">slots</span></div>
    </div>
    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <div class="text-sm text-slate-500 mb-1">Utilization</div>
      <div class="text-2xl font-bold <?= $utilPct >= 90 ? 'text-red-600' : ($utilPct >= 70 ? 'text-amber-600' : 'text-emerald-600') ?>"><?= $utilPct ?>%</div>
      <div class="mt-2 h-2 bg-slate-100 rounded-full overflow-hidden"><div class="h-full rounded-full <?= $utilPct >= 90 ? 'bg-red-500' : ($utilPct >= 70 ? 'bg-amber-500' : 'bg-emerald-500') ?>" style="width:<?= $utilPct ?>%"></div></div>
    </div>
  </div>

  <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-200"><h3 class="text-sm font-semibold text-slate-900">Zone Utilization</h3></div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="bg-slate-50 border-b border-slate-200">
          <?php foreach (['Zone', 'Type', 'Storage', 'Capacity', 'Occupied', 'Utilization'] as $hd): ?>
          <th class="text-left text-xs font-bold text-slate-400 px-5 py-3 uppercase tracking-wider whitespace-nowrap"><?= $hd ?></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
          <?php foreach ($zones as $z): $pct = $z['capacity'] > 0 ? round($z['occupied'] / $z['capacity'] * 100) : 0; ?>
          <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50 transition">
            <td class="px-5 py-3 font-semibold text-slate-900"><?= h($z['zone']) ?></td>
            <td class="px-5 py-3 text-slate-600"><?= h($z['type']) ?></td>
            <td class="px-5 py-3"><span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-600"><?= h($z['temp']) ?></span></td>
            <td class="px-5 py-3 font-mono text-slate-600"><?= number_format((int)$z['capacity']) ?></td>
            <td class="px-5 py-3 font-mono font-bold text-slate-900"><?= number_format((int)$z['occupied']) ?></td>
            <td class="px-5 py-3 w-40">
              <div class="flex items-center gap-2">
                <div class="flex-1 h-2 bg-slate-100 rounded-full overflow-hidden"><div class="h-full rounded-full <?= $pct >= 90 ? 'bg-red-500' : ($pct >= 70 ? 'bg-amber-500' : 'bg-emerald-500') ?>" style="width:<?= $pct ?>%"></div></div>
                <span class="text-xs font-mono text-slate-600 w-8"><?= $pct ?>%</span>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-200">
      <h3 class="text-sm font-semibold text-slate-900">Shipments</h3>
      <p class="mt-0.5 text-xs text-slate-400">For Approval → Approved (warehouse) → In Transit → Delivered (delivery app). Open the tracking log to see every step.</p>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="bg-slate-50 border-b border-slate-200">
          <?php foreach (['Tracking No.', 'Origin', 'Destination', 'Status', 'Driver', 'Deliver By', 'Items', 'Weight', ''] as $hd): ?>
          <th class="text-left text-xs font-bold text-slate-400 px-5 py-3 uppercase tracking-wider whitespace-nowrap"><?= $hd ?></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
          <?php if (!$shipments): ?><tr><td colspan="9" class="text-center py-12 text-slate-400">No shipments found.</td></tr><?php endif; ?>
          <?php foreach ($shipments as $s): ?>
          <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50 transition align-top">
            <td class="px-5 py-3 font-mono text-xs text-indigo-600 font-bold whitespace-nowrap"><?= h($s['id']) ?></td>
            <td class="px-5 py-3 text-slate-600"><?= h($s['origin']) ?></td>
            <td class="px-5 py-3 text-slate-600"><?= h($s['destination']) ?></td>
            <td class="px-5 py-3"><?= badge($s['status']) ?>
              <?php if ($s['status'] === 'Rejected' && !empty($s['review_note'])): ?><div class="mt-1 max-w-[14rem] text-xs text-red-600"><?= h($s['review_note']) ?></div><?php endif; ?></td>
            <td class="px-5 py-3 text-slate-600 whitespace-nowrap">
              <?php if (!empty($s['driver_name'])): ?><?= h($s['driver_name']) ?> <span class="text-xs text-slate-400">· <?= h($s['vehicle_id']) ?></span>
              <?php elseif ($s['status'] === 'Approved'): ?><span class="text-xs text-blue-600">Waiting for a driver</span>
              <?php elseif ($s['status'] === 'For Approval'): ?><span class="text-xs text-amber-600">Waiting for warehouse approval</span>
              <?php else: ?><span class="text-xs text-slate-400"><?= h($s['carrier']) ?></span><?php endif; ?>
            </td>
            <td class="px-5 py-3 font-mono text-sm text-slate-700 whitespace-nowrap"><?= h($s['eta']) ?></td>
            <td class="px-5 py-3 font-mono text-slate-700"><?= number_format((int)$s['items']) ?></td>
            <td class="px-5 py-3 font-mono text-slate-600"><?= h($s['weight']) ?></td>
            <td class="px-5 py-3 whitespace-nowrap text-right">
              <?php if (!$offline): ?><a href="?module=tracking&ref=<?= urlencode($s['id']) ?>" class="text-xs font-semibold text-indigo-600 hover:underline">Tracking log</a><?php endif; ?>
              <?php if ($canDelete): ?>
              <button type="button" onclick="act('api/shipments.php?id=<?= h(rawurlencode($s['id'])) ?>', 'DELETE', null, 'Delete shipment <?= h($s['id']) ?> and its tracking log?')" title="Delete shipment" class="ml-2 text-xs text-slate-400 hover:text-red-600">Delete</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($canAdd): ?>
<?php modal_open('modal-add-ship', 'Add Shipment', 'It goes to the warehouse for approval, then to the delivery app.'); ?>
  <form id="form-add-ship" class="px-6 py-5 flex flex-col gap-4">
    <div class="grid grid-cols-2 gap-4">
      <div class="col-span-2"><label class="<?= LABEL ?>">Origin *</label>
        <input name="origin" required maxlength="100" value="<?= h(($warehouses[0]['name'] ?? 'Main Warehouse') . ', ' . ($warehouses[0]['address'] ?? 'Naga City')) ?>" class="<?= FIELD ?>"></div>
      <div class="col-span-2"><label class="<?= LABEL ?>">Destination *</label>
        <input name="destination" required maxlength="100" placeholder="Store or full address, city" class="<?= FIELD ?>"></div>
      <div><label class="<?= LABEL ?>">Deliver By</label>
        <input name="eta" type="date" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" class="<?= FIELD ?>"></div>
      <div><label class="<?= LABEL ?>">Items Count</label><input name="items" type="number" min="1" value="1" class="<?= FIELD ?>"></div>
      <div><label class="<?= LABEL ?>">Weight</label><input name="weight" maxlength="30" placeholder="e.g. 20 kg" class="<?= FIELD ?>"></div>
      <div><label class="<?= LABEL ?>">Carrier</label><input name="carrier" maxlength="80" value="In-house" class="<?= FIELD ?>"></div>
    </div>
    <p data-error class="hidden text-xs text-red-600"></p>
    <div class="flex gap-3 pt-1">
      <button type="button" onclick="closeModal('modal-add-ship')" class="flex-1 <?= BTN_GHOST ?>">Cancel</button>
      <button type="submit" class="flex-1 <?= BTN_PRIMARY ?>">Send for Approval</button>
    </div>
  </form>
<?php modal_close(); ?>
<script>bindForm('form-add-ship', 'api/shipments.php');</script>
<?php endif; ?>

<?php if ($canManage): ?>
<?php modal_open('modal-warehouse', 'Warehouse'); ?>
  <form id="form-warehouse" class="px-6 py-5 flex flex-col gap-4">
    <input type="hidden" name="wh_id">
    <div><label class="<?= LABEL ?>">Name *</label><input name="name" required maxlength="100" class="<?= FIELD ?>"></div>
    <div><label class="<?= LABEL ?>">Address</label><input name="address" maxlength="200" placeholder="Street, barangay, city" class="<?= FIELD ?>"></div>
    <label data-edit-only class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="active" value="1"> Active (can hold stock and receive transfers)</label>
    <p data-error class="hidden text-xs text-red-600"></p>
    <div class="flex gap-3 pt-1">
      <button type="button" onclick="closeModal('modal-warehouse')" class="flex-1 <?= BTN_GHOST ?>">Cancel</button>
      <button type="submit" class="flex-1 <?= BTN_PRIMARY ?>">Save Warehouse</button>
    </div>
  </form>
<?php modal_close(); ?>
<script>
function openWarehouse(w) {
  const f = document.getElementById('form-warehouse'); f.reset();
  f.closest('[data-modal]').querySelector('h2').textContent = w ? 'Edit ' + w.name : 'Add Warehouse';
  f.querySelector('[data-edit-only]').classList.toggle('hidden', !w);
  f.wh_id.value = w ? w.id : '';
  if (w) { f.name.value = w.name; f.address.value = w.address; f.active.checked = w.active == 1; }
  openModal('modal-warehouse');
}
bindForm('form-warehouse',
  f => f.wh_id.value ? 'api/warehouses.php?id=' + encodeURIComponent(f.wh_id.value) : 'api/warehouses.php',
  f => f.wh_id.value ? 'PUT' : 'POST',
  (body, f) => { body.active = f.active.checked; return body; });
</script>
<?php endif; ?>

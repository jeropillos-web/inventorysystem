<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/fallback.php';

$user    = $_SESSION['user'];
$offline = !db_available();

// Who can do what with shipments
$canAdd    = !$offline && role_can($user['role'], 'write');   // admin, staff
$canDelete = !$offline && role_can($user['role'], 'delete');  // admin
$canReview = !$offline && $user['role'] === 'warehouse';      // warehouse personnel

if ($offline) {
    $zones     = $FB['warehouse_zones'];
    $shipments = $FB['shipments'];
} else {
    $zones     = db()->query('SELECT * FROM warehouse_zones ORDER BY zone')->fetchAll();
    $shipments = db()->query(
        "SELECT s.*, u.name AS driver_name, d.vehicle_id, c.name AS created_by_name, a.name AS reviewed_by_name, lc.cancelled_by, lc.cancel_reason
         FROM shipments s
         LEFT JOIN deliveries d ON d.shipment_id = s.id
         LEFT JOIN users u ON u.id = d.driver_user_id
         LEFT JOIN users c ON c.id = s.created_by
         LEFT JOIN users a ON a.id = s.approved_by
         LEFT JOIN (SELECT c.job_id, c.reason AS cancel_reason, c.cancelled_at, cu.name AS cancelled_by
                    FROM delivery_cancellations c JOIN users cu ON cu.id = c.driver_user_id
                    WHERE c.kind = 'shipment' AND c.id = (SELECT MAX(c2.id) FROM delivery_cancellations c2
                                                        WHERE c2.kind = c.kind AND c2.job_id = c.job_id)) lc ON lc.job_id = s.id
         ORDER BY FIELD(s.status,'Delivered','Rejected','In Transit','Picked Up','Approved','For Approval') DESC, s.eta DESC"
    )->fetchAll();
}
$forApproval = array_values(array_filter($shipments, fn($s) => $s['status'] === 'For Approval'));

$totalCap = array_sum(array_column($zones, 'capacity'));
$totalOcc = array_sum(array_column($zones, 'occupied'));
$utilPct  = $totalCap > 0 ? round($totalOcc / $totalCap * 100) : 0;

function shipStatusBadge(string $s): string {
    $map = [
        'For Approval' => 'bg-orange-100 text-orange-700',
        'Approved'     => 'bg-blue-100 text-blue-700',
        'Rejected'     => 'bg-red-100 text-red-700',
        'Picked Up'    => 'bg-violet-100 text-violet-700',
        'In Transit'   => 'bg-cyan-100 text-cyan-700',
        'Customs'      => 'bg-amber-100 text-amber-700',
        'Delivered'    => 'bg-emerald-100 text-emerald-700',
        'Pending'      => 'bg-slate-100 text-slate-600',
    ];
    $cls = $map[$s] ?? 'bg-slate-100 text-slate-600';
    return "<span class=\"text-xs font-semibold px-2 py-0.5 rounded-full whitespace-nowrap $cls\">" . htmlspecialchars($s) . "</span>";
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
      <h1 class="text-2xl font-bold text-slate-900">Warehouse &amp; Logistics</h1>
      <p class="text-sm text-slate-500 mt-0.5">Zone utilization, shipment approval, and delivery tracking</p>
    </div>
    <?php if ($canAdd): ?>
    <button onclick="openShipModal()"
      class="flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-bold hover:bg-indigo-500 transition shadow-lg shadow-indigo-500/30">
      + Add Shipment
    </button>
    <?php endif; ?>
  </div>

  <?php if ($forApproval): ?>
  <div class="bg-white rounded-xl border-2 <?= $canReview ? 'border-orange-300' : 'border-slate-200' ?> shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-200 flex items-center gap-2">
      <span class="inline-flex min-w-6 justify-center rounded-full bg-orange-500 px-2 py-0.5 text-xs font-bold text-white"><?= count($forApproval) ?></span>
      <h3 class="text-sm font-semibold text-slate-900"><?= $canReview ? 'Shipments waiting for your approval' : 'Waiting for warehouse approval' ?></h3>
    </div>
    <div class="divide-y divide-slate-100">
      <?php foreach ($forApproval as $s): ?>
      <div class="flex flex-col gap-3 px-5 py-4 md:flex-row md:items-center">
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-mono text-xs font-bold text-indigo-600"><?= htmlspecialchars($s['id']) ?></span>
            <?= shipStatusBadge($s['status']) ?>
          </div>
          <p class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars($s['origin']) ?> → <?= htmlspecialchars($s['destination']) ?></p>
          <p class="mt-1 text-xs text-slate-500">
            <?= number_format((int)$s['items']) ?> items<?= $s['weight'] !== '' ? ' · ' . htmlspecialchars($s['weight']) : '' ?>
            · Deliver by <?= htmlspecialchars($s['eta']) ?>
            <?= !empty($s['created_by_name']) ? ' · Added by ' . htmlspecialchars($s['created_by_name']) : '' ?>
            <?= empty($s['dest_lat']) ? ' · <span class="text-amber-600">no map pin</span>' : '' ?>
          </p>
        </div>
        <?php if ($canReview): ?>
        <div class="flex gap-2">
          <button onclick="reviewShipment('<?= htmlspecialchars($s['id']) ?>','reject')"
            class="rounded-xl border border-red-200 px-4 py-2.5 text-sm font-bold text-red-600 transition hover:bg-red-50">Reject</button>
          <button onclick="reviewShipment('<?= htmlspecialchars($s['id']) ?>','approve')"
            class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-500">Approve</button>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <div class="text-sm text-slate-500 mb-1">Total Capacity</div>
      <div class="text-2xl font-bold text-slate-900"><?= number_format($totalCap) ?> <span class="text-base font-normal text-slate-400">units</span></div>
    </div>
    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <div class="text-sm text-slate-500 mb-1">Occupied</div>
      <div class="text-2xl font-bold text-slate-900"><?= number_format($totalOcc) ?> <span class="text-base font-normal text-slate-400">units</span></div>
    </div>
    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <div class="text-sm text-slate-500 mb-1">Utilization</div>
      <div class="text-2xl font-bold <?= $utilPct >= 90 ? 'text-red-600' : ($utilPct >= 70 ? 'text-amber-600' : 'text-emerald-600') ?>"><?= $utilPct ?>%</div>
      <div class="mt-2 h-2 bg-slate-100 rounded-full overflow-hidden">
        <div class="h-full rounded-full <?= $utilPct >= 90 ? 'bg-red-500' : ($utilPct >= 70 ? 'bg-amber-500' : 'bg-emerald-500') ?>" style="width:<?= $utilPct ?>%"></div>
      </div>
    </div>
  </div>

  <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-200"><h3 class="text-sm font-semibold text-slate-900">Zone Utilization</h3></div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-slate-50 border-b border-slate-200">
            <?php foreach (['Zone','Type','Temp','Capacity','Occupied','Utilization'] as $h): ?>
            <th class="text-left text-xs font-bold text-slate-400 px-5 py-3 uppercase tracking-wider whitespace-nowrap"><?= $h ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($zones as $z): ?>
          <?php $pct = $z['capacity'] > 0 ? round($z['occupied'] / $z['capacity'] * 100) : 0; ?>
          <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50 transition">
            <td class="px-5 py-3 font-semibold text-slate-900"><?= htmlspecialchars($z['zone']) ?></td>
            <td class="px-5 py-3 text-slate-600"><?= htmlspecialchars($z['type']) ?></td>
            <td class="px-5 py-3"><span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-600"><?= htmlspecialchars($z['temp']) ?></span></td>
            <td class="px-5 py-3 font-mono text-slate-600"><?= number_format($z['capacity']) ?></td>
            <td class="px-5 py-3 font-mono font-bold text-slate-900"><?= number_format($z['occupied']) ?></td>
            <td class="px-5 py-3 w-40">
              <div class="flex items-center gap-2">
                <div class="flex-1 h-2 bg-slate-100 rounded-full overflow-hidden">
                  <div class="h-full rounded-full <?= $pct >= 90 ? 'bg-red-500' : ($pct >= 70 ? 'bg-amber-500' : 'bg-emerald-500') ?>" style="width:<?= $pct ?>%"></div>
                </div>
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
      <h3 class="text-sm font-semibold text-slate-900">Shipment Tracker</h3>
      <p class="mt-0.5 text-xs text-slate-400">For Approval → Approved (warehouse) → Picked Up → In Transit → Delivered (driver app)</p>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-slate-50 border-b border-slate-200">
            <?php foreach (['Shipment ID','Origin','Destination','Status','Driver','Deliver By','Items','Weight'] as $h): ?>
            <th class="text-left text-xs font-bold text-slate-400 px-5 py-3 uppercase tracking-wider whitespace-nowrap"><?= $h ?></th>
            <?php endforeach; ?>
            <?php if ($canDelete): ?><th class="px-5 py-3"></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($shipments)): ?>
          <tr><td colspan="9" class="text-center py-12 text-slate-400">No shipments found.</td></tr>
          <?php else: ?>
          <?php foreach ($shipments as $s): ?>
          <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50 transition">
            <td class="px-5 py-3 font-mono text-xs text-indigo-600 font-medium"><?= htmlspecialchars($s['id']) ?></td>
            <td class="px-5 py-3 text-slate-600"><?= htmlspecialchars($s['origin']) ?></td>
            <td class="px-5 py-3 text-slate-600"><?= htmlspecialchars($s['destination']) ?></td>
            <td class="px-5 py-3">
              <?= shipStatusBadge($s['status']) ?>
              <?php if ($s['status'] === 'Rejected' && !empty($s['review_note'])): ?>
              <div class="mt-1 max-w-[14rem] text-xs text-red-600"><?= htmlspecialchars($s['review_note']) ?></div>
              <?php endif; ?>
            </td>
            <td class="px-5 py-3 text-slate-600 whitespace-nowrap">
              <?php if (!empty($s['driver_name'])): ?>
                <?= htmlspecialchars($s['driver_name']) ?> <span class="text-xs text-slate-400">· <?= htmlspecialchars($s['vehicle_id']) ?></span>
              <?php elseif ($s['status'] === 'Approved'): ?>
                <span class="text-xs text-blue-600">Waiting for a driver</span><?php if (!empty($s['cancelled_by'])): ?><span class="block text-xs font-normal text-red-600">Cancelled by <?= htmlspecialchars($s['cancelled_by']) ?>: <?= htmlspecialchars($s['cancel_reason']) ?></span><?php endif; ?>
              <?php else: ?>
                <span class="text-xs text-slate-400"><?= htmlspecialchars($s['carrier']) ?></span>
              <?php endif; ?>
            </td>
            <td class="px-5 py-3 font-mono text-sm text-slate-700"><?= htmlspecialchars($s['eta']) ?></td>
            <td class="px-5 py-3 font-mono text-slate-700"><?= number_format($s['items']) ?></td>
            <td class="px-5 py-3 font-mono text-slate-600"><?= htmlspecialchars($s['weight']) ?></td>
            <?php if ($canDelete): ?>
            <td class="px-5 py-3">
              <button onclick="deleteShipment('<?= htmlspecialchars($s['id']) ?>')" title="Delete shipment"
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

<?php if ($canAdd): ?>
<div id="modal-add-ship" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[95vh] overflow-y-auto" onclick="event.stopPropagation()">
    <div class="flex items-center justify-between px-6 py-5 border-b border-slate-200">
      <div>
        <h2 class="font-bold text-slate-900">Add Shipment</h2>
        <p class="text-xs text-slate-500">It will be sent to the warehouse for approval.</p>
      </div>
      <button onclick="closeShipModal()" class="p-1.5 hover:bg-slate-100 rounded-lg text-slate-500">✕</button>
    </div>
    <form id="form-add-ship" class="px-6 py-5 flex flex-col gap-4">
      <div class="grid grid-cols-2 gap-4">
        <div class="col-span-2"><label class="block text-xs font-semibold text-slate-600 mb-1">Origin *</label>
          <input name="origin" required value="PCS Computers Warehouse, Naga City" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" /></div>
        <div class="col-span-2"><label class="block text-xs font-semibold text-slate-600 mb-1">Destination *</label>
          <input name="destination" required class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" placeholder="Store or address, city" /></div>
        <div class="col-span-2">
          <label class="block text-xs font-semibold text-slate-600 mb-1">Destination on map <span class="font-normal text-slate-400">(click to drop a pin; used for driver navigation and ETA)</span></label>
          <div class="mb-2 flex gap-2">
            <input id="ship-search" class="flex-1 border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" placeholder="Search a place, e.g. SM City Naga">
            <button type="button" onclick="findPlace(document.getElementById('ship-search').value)" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Find</button>
          </div>
          <div id="ship-pick-map" class="h-56 w-full rounded-lg border border-slate-200" style="position:relative;z-index:0"></div>
          <p id="ship-pick-label" class="mt-1 text-xs text-slate-400">No pin yet</p>
          <input type="hidden" name="dest_lat"><input type="hidden" name="dest_lng">
        </div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Deliver By</label>
          <input name="eta" type="date" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" /></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Items Count</label>
          <input name="items" type="number" min="1" value="1" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" /></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Weight</label>
          <input name="weight" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" placeholder="e.g. 20 kg" /></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Carrier</label>
          <input name="carrier" value="In-house" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" /></div>
      </div>
      <p id="ship-form-error" class="text-xs text-red-600 hidden"></p>
      <div class="flex gap-3 pt-1">
        <button type="button" onclick="closeShipModal()"
          class="flex-1 py-2.5 rounded-xl border border-slate-200 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
        <button type="submit" class="flex-1 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-bold hover:bg-indigo-500 transition">Send for Approval</button>
      </div>
    </form>
  </div>
</div>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>
<script>
let shipPickMap = null, shipPin = null;
function openShipModal() {
  document.getElementById('modal-add-ship').classList.remove('hidden');
  if (!shipPickMap) {
    shipPickMap = L.map('ship-pick-map').setView([13.6218, 123.1948], 12);   // Naga City
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(shipPickMap);
    shipPickMap.on('click', e => setPin(e.latlng.lat, e.latlng.lng, 'Pin set'));
  }
  setTimeout(() => shipPickMap.invalidateSize(), 50);
}
function setPin(lat, lng, label) {
  const f = document.getElementById('form-add-ship');
  if (shipPin) shipPin.setLatLng([lat, lng]); else shipPin = L.marker([lat, lng]).addTo(shipPickMap);
  f.dest_lat.value = (+lat).toFixed(6); f.dest_lng.value = (+lng).toFixed(6);
  document.getElementById('ship-pick-label').textContent = `${label} at ${(+lat).toFixed(5)}, ${(+lng).toFixed(5)}`;
}
// Look up a place name with OpenStreetMap's free geocoder (Philippines only).
async function geocode(q) {
  if (!q || !q.trim()) return null;
  const url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=ph&q=' + encodeURIComponent(q);
  try { const r = await fetch(url, { headers: { 'Accept-Language': 'en' } }); const j = await r.json(); return j[0] || null; }
  catch { return null; }
}
async function findPlace(q) {
  const label = document.getElementById('ship-pick-label');
  label.textContent = 'Searching…';
  const hit = await geocode(q);
  if (!hit) { label.textContent = 'Place not found. Click the map to drop the pin yourself.'; return; }
  setPin(hit.lat, hit.lon, 'Found ' + hit.display_name.split(',').slice(0, 2).join(','));
  shipPickMap.setView([hit.lat, hit.lon], 16);
}
document.getElementById('ship-search')?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); findPlace(e.target.value); } });
function closeShipModal() { document.getElementById('modal-add-ship').classList.add('hidden'); }
document.getElementById('form-add-ship').addEventListener('submit', async function(e) {
  e.preventDefault();
  const body = Object.fromEntries(new FormData(this).entries());
  body.items = parseInt(body.items);
  // No pin dropped? Try to locate the destination text automatically.
  if (!body.dest_lat) {
    const hit = await geocode(body.destination);
    if (hit) { body.dest_lat = (+hit.lat).toFixed(6); body.dest_lng = (+hit.lon).toFixed(6); }
  }
  const res  = await fetch('api/shipments.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  const json = await res.json();
  if (json.ok) { location.reload(); }
  else { const el = document.getElementById('ship-form-error'); el.textContent = json.error; el.classList.remove('hidden'); }
});
</script>
<?php endif; ?>

<?php if ($canReview || $canDelete): ?>
<script>
async function reviewShipment(id, action) {
  let note = '';
  if (action === 'reject') {
    note = prompt('Reason for rejecting ' + id + ':');
    if (note === null) return;
    if (!note.trim()) { alert('Please give a reason.'); return; }
  } else if (!confirm('Approve ' + id + '? It will be sent to the driver app.')) return;
  const res  = await fetch('api/shipments.php?action=' + action + '&id=' + encodeURIComponent(id),
                           { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ note }) });
  const json = await res.json();
  if (!json.ok) alert(json.error);
  location.reload();
}
async function deleteShipment(id) {
  if (!confirm('Delete this shipment?')) return;
  const res  = await fetch('api/shipments.php?id=' + encodeURIComponent(id), { method: 'DELETE' });
  const json = await res.json();
  if (!json.ok) alert(json.error);
  location.reload();
}
</script>
<?php endif; ?>

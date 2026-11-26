<?php
if (!db_available()) { needs_database('Tracking Log'); return; }

$user     = $_SESSION['user'];
$canTrack = role_can($user['role'], 'track');
$pdo      = db();
$ref      = strtoupper(trim($_GET['ref'] ?? ''));

// Everything still moving, with its latest log line.
$active = $pdo->query(
    "SELECT x.ref_type, x.ref_id, x.title, x.status, l.status AS last_status, l.location AS last_location, l.logged_at AS last_at
     FROM (
       SELECT 'shipment' AS ref_type, id AS ref_id, destination AS title, status FROM shipments
         WHERE status IN ('For Approval', 'Approved', 'Picked Up', 'In Transit')
       UNION ALL
       SELECT 'shop_order', id, shop, status FROM shop_orders
         WHERE status IN ('Picking', 'Ready for Delivery', 'Out for Delivery')
       UNION ALL
       SELECT 'transfer', CONCAT('TRF-', LPAD(t.id, 5, '0')), CONCAT(f.name, ' → ', w.name), t.status FROM transfers t
         JOIN warehouses f ON f.id = t.from_warehouse JOIN warehouses w ON w.id = t.to_warehouse
         WHERE t.status IN ('Requested', 'Approved', 'In Transit')
     ) x
     LEFT JOIN tracking_logs l ON l.id = (SELECT l2.id FROM tracking_logs l2 WHERE l2.ref_type = x.ref_type AND l2.ref_id = x.ref_id
                                         ORDER BY l2.logged_at DESC, l2.id DESC LIMIT 1)
     ORDER BY COALESCE(l.logged_at, '1970-01-01') DESC")->fetchAll();

$recent = $pdo->query(
    "SELECT l.*, u.name AS user_name FROM tracking_logs l LEFT JOIN users u ON u.id = l.logged_by
     ORDER BY l.logged_at DESC, l.id DESC LIMIT 15")->fetchAll();

$typeLabel = ['shipment' => 'Shipment', 'shop_order' => 'Shop order', 'transfer' => 'Transfer'];
?>

<div class="flex flex-col gap-6">
  <?php page_header('Tracking Log', 'Follow any shipment, shop order or stock transfer by its tracking number. Each step is written as a dated text entry.'); ?>

  <form method="GET" class="flex flex-wrap gap-3" onsubmit="this.ref.value = this.ref.value.trim().toUpperCase()">
    <input type="hidden" name="module" value="tracking">
    <input name="ref" value="<?= h($ref) ?>" placeholder="Tracking number, e.g. SHP-1002, SO-1046, TRF-00001" required
      class="flex-1 min-w-[240px] bg-white border border-slate-200 rounded-xl px-4 py-3 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-300">
    <button class="<?= BTN_PRIMARY ?>">Track</button>
  </form>

  <?php if ($ref !== ''): ?>
  <section id="track-result" class="bg-white rounded-xl border border-slate-200 shadow-sm p-5" data-ref="<?= h($ref) ?>">
    <p class="text-sm text-slate-400">Loading…</p>
  </section>
  <?php endif; ?>

  <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-200"><h3 class="text-sm font-semibold text-slate-900">In progress (<?= count($active) ?>)</h3></div>
      <div class="divide-y divide-slate-100">
        <?php if (!$active): ?><p class="px-5 py-6 text-sm text-slate-400">Nothing is moving right now.</p><?php endif; ?>
        <?php foreach ($active as $a): ?>
        <a href="?module=tracking&ref=<?= urlencode($a['ref_id']) ?>" class="flex items-start gap-3 px-5 py-3 hover:bg-slate-50">
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2"><span class="font-mono text-xs font-bold text-indigo-600"><?= h($a['ref_id']) ?></span>
              <span class="text-[11px] text-slate-400"><?= h($typeLabel[$a['ref_type']]) ?></span><?= badge($a['status']) ?></div>
            <p class="text-sm text-slate-800 truncate"><?= h($a['title']) ?></p>
            <?php if ($a['last_status']): ?><p class="text-xs text-slate-500"><?= h($a['last_status']) ?><?= $a['last_location'] ? ' · ' . h($a['last_location']) : '' ?> · <?= h(fmt_dt($a['last_at'])) ?></p><?php endif; ?>
          </div>
          <span class="text-slate-300">›</span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-200"><h3 class="text-sm font-semibold text-slate-900">Latest log entries</h3></div>
      <div class="divide-y divide-slate-100">
        <?php if (!$recent): ?><p class="px-5 py-6 text-sm text-slate-400">No tracking entries yet.</p><?php endif; ?>
        <?php foreach ($recent as $l): ?>
        <a href="?module=tracking&ref=<?= urlencode($l['ref_id']) ?>" class="block px-5 py-3 hover:bg-slate-50">
          <div class="flex flex-wrap items-center gap-2 text-xs"><span class="font-mono font-bold text-indigo-600"><?= h($l['ref_id']) ?></span><?= badge($l['status']) ?><span class="text-slate-400"><?= h(fmt_dt($l['logged_at'])) ?></span></div>
          <p class="text-sm text-slate-700"><?= h($l['location']) ?><?= $l['location'] && $l['remarks'] ? ' — ' : '' ?><?= h($l['remarks']) ?></p>
          <?php if ($l['user_name']): ?><p class="text-[11px] text-slate-400"><?= h($l['user_name']) ?></p><?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($canTrack): ?>
<?php modal_open('modal-track', 'Add a tracking update', 'It is added to the log with your name and the current time.'); ?>
  <form id="form-track" class="px-6 py-5 flex flex-col gap-4">
    <input type="hidden" name="ref">
    <div><label class="<?= LABEL ?>">Update *</label>
      <select name="status" required class="<?= FIELD ?>"><option>In Transit</option><option>Arrived at checkpoint</option><option>Delayed</option><option>Packed</option><option>Note</option></select></div>
    <div><label class="<?= LABEL ?>">Location</label><input name="location" maxlength="150" placeholder="e.g. Pili, Camarines Sur" class="<?= FIELD ?>"></div>
    <div><label class="<?= LABEL ?>">Remarks</label><input name="remarks" maxlength="250" placeholder="e.g. Heavy traffic, expected 30 minutes late" class="<?= FIELD ?>"></div>
    <p class="text-xs text-slate-400">Status changes such as Approved, Dispatched, Delivered and Received are logged automatically by the system.</p>
    <p data-error class="hidden text-xs text-red-600"></p>
    <div class="flex gap-3 pt-1">
      <button type="button" onclick="closeModal('modal-track')" class="flex-1 <?= BTN_GHOST ?>">Cancel</button>
      <button type="submit" class="flex-1 <?= BTN_PRIMARY ?>">Add Update</button>
    </div>
  </form>
<?php modal_close(); ?>
<script>bindForm('form-track', 'api/tracking.php');</script>
<?php endif; ?>

<script>
(async () => {
  const box = document.getElementById('track-result');
  if (!box) return;
  const canTrack = <?= $canTrack ? 'true' : 'false' ?>;
  const closed = ['Delivered', 'Completed', 'Received', 'Rejected', 'Cancelled'];
  try {
    const d = await api('api/tracking.php?ref=' + encodeURIComponent(box.dataset.ref));
    const s = d.summary;
    const steps = d.logs.map((l, i) => `
      <li class="relative pl-7 pb-5 last:pb-0">
        <span class="absolute left-0 top-1 w-3.5 h-3.5 rounded-full ${i === 0 ? 'bg-indigo-600 ring-4 ring-indigo-100' : 'bg-slate-300'}"></span>
        ${i < d.logs.length - 1 ? '<span class="absolute left-[6px] top-5 bottom-0 w-0.5 bg-slate-200"></span>' : ''}
        <div class="flex flex-wrap items-center gap-2"><b class="text-sm text-slate-900">${esc(l.status)}</b><span class="text-xs text-slate-400">${esc(l.logged_at)}</span></div>
        <p class="text-sm text-slate-600">${esc(l.location)}${l.location && l.remarks ? ' — ' : ''}${esc(l.remarks)}</p>
        ${l.logged_by ? `<p class="text-[11px] text-slate-400">${esc(l.logged_by)}</p>` : ''}
      </li>`).join('');
    box.innerHTML = `
      <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div>
          <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Tracking number</p>
          <h2 class="font-mono text-xl font-bold text-indigo-600">${esc(d.ref)}</h2>
          <p class="text-sm font-semibold text-slate-900">${esc(s.title)} · <span class="text-indigo-600">${esc(s.status)}</span></p>
          <p class="text-sm text-slate-600">${esc(s.from)} → ${esc(s.to)}</p>
          <p class="text-xs text-slate-400">${esc(s.detail)}</p>
        </div>
        ${canTrack && !closed.includes(s.status) ? `<button type="button" onclick="addUpdate('${esc(d.ref)}')" class="<?= BTN_PRIMARY ?>">+ Add update</button>` : ''}
      </div>
      ${d.logs.length ? `<ol>${steps}</ol>` : '<p class="text-sm text-slate-400">No log entries yet.</p>'}`;
  } catch (e) {
    box.innerHTML = `<p class="text-sm text-red-600">${esc(e.message)}</p>`;
  }
})();
function addUpdate(ref) {
  const f = document.getElementById('form-track'); f.reset(); f.ref.value = ref; openModal('modal-track');
}
</script>

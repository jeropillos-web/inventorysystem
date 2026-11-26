<?php
require_once __DIR__ . '/../config/fallback.php';

$user = $_SESSION['user'];
// Everyone with access can see shop orders and who is delivering them;
// only warehouse personnel move an order to Picking / Ready for Delivery.
$canProcess = role_can($user['role'], 'process_orders');
$canCreate  = role_can($user['role'], 'write');

$offline = !db_available();
$notice = '';
$error = '';
// Warehouse staff move orders up to Ready for Delivery; drivers take it from there in the delivery app.
$allowedStatuses = ['Picking', 'Ready for Delivery'];

if ($canProcess && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['status'])) {
    $orderId = trim($_POST['order_id']);
    $status = trim($_POST['status']);
    if (!in_array($status, $allowedStatuses, true)) {
        $error = 'Invalid order status.';
    } elseif ($offline) {
        $error = 'Connect the database before processing orders.';
    } else {
        $from = $status === 'Picking' ? 'Pending' : 'Picking';
        $pdo  = db();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE shop_orders SET status = ? WHERE id = ? AND status = ?');
        $stmt->execute([$status, $orderId, $from]);
        if ($stmt->rowCount()) {
            $main = main_warehouse_id($pdo);
            track_log($pdo, 'shop_order', $orderId, $status, $main ? warehouse_name($pdo, $main) : 'Warehouse',
                      $status === 'Picking' ? 'Items are being picked' : 'Packed and waiting for a driver', (int)$user['id']);
            $notice = "$orderId updated to $status.";
        } else {
            $notice = 'Order not found or already updated.';
        }
        $pdo->commit();
    }
}

$orders = $offline
    ? $FB['shop_orders']
    : db()->query("SELECT o.*, u.name AS driver_name, d.vehicle_id, d.status AS delivery_status, lc.cancelled_by, lc.cancel_reason
                   FROM shop_orders o
                   LEFT JOIN deliveries d ON d.shop_order_id = o.id
                   LEFT JOIN users u ON u.id = d.driver_user_id
                   LEFT JOIN (SELECT c.job_id, c.reason AS cancel_reason, c.cancelled_at, cu.name AS cancelled_by
                              FROM delivery_cancellations c JOIN users cu ON cu.id = c.driver_user_id
                              WHERE c.kind = 'order' AND c.id = (SELECT MAX(c2.id) FROM delivery_cancellations c2
                                                                  WHERE c2.kind = c.kind AND c2.job_id = c.job_id)) lc ON lc.job_id = o.id
                   ORDER BY FIELD(o.status,'Pending','Picking','Ready for Delivery','Out for Delivery','Completed'), o.requested_at DESC")->fetchAll();

// Shipments added by admin/staff that the warehouse must approve before drivers see them.
$shipments = [];
if (!$offline) {
    $shipments = db()->query(
        "SELECT s.*, c.name AS created_by_name, u.name AS driver_name, d.vehicle_id, lc.cancelled_by, lc.cancel_reason
         FROM shipments s
         LEFT JOIN users c ON c.id = s.created_by
         LEFT JOIN deliveries d ON d.shipment_id = s.id
         LEFT JOIN users u ON u.id = d.driver_user_id
         LEFT JOIN (SELECT c.job_id, c.reason AS cancel_reason, c.cancelled_at, cu.name AS cancelled_by
                    FROM delivery_cancellations c JOIN users cu ON cu.id = c.driver_user_id
                    WHERE c.kind = 'shipment' AND c.id = (SELECT MAX(c2.id) FROM delivery_cancellations c2
                                                        WHERE c2.kind = c.kind AND c2.job_id = c.job_id)) lc ON lc.job_id = s.id
         WHERE s.status IN ('For Approval','Approved','Picked Up','In Transit')
         ORDER BY FIELD(s.status,'For Approval','Approved','Picked Up','In Transit'), s.eta"
    )->fetchAll();
}
$toApprove = array_values(array_filter($shipments, fn($s) => $s['status'] === 'For Approval'));
$inFlow    = array_values(array_filter($shipments, fn($s) => $s['status'] !== 'For Approval'));

$nextStatus = [
    'Pending' => ['Picking', 'Start Picking'],
    'Picking' => ['Ready for Delivery', 'Mark Ready'],
];
$statusColors = [
    'Pending' => 'bg-slate-100 text-slate-700',
    'Picking' => 'bg-amber-100 text-amber-700',
    'Ready for Delivery' => 'bg-blue-100 text-blue-700',
    'Out for Delivery' => 'bg-violet-100 text-violet-700',
    'Completed' => 'bg-emerald-100 text-emerald-700',
];
?>

<div class="flex flex-col gap-6">
  <div>
    <h1 class="text-2xl font-bold text-slate-900">Shop Orders &amp; Shipments</h1>
    <p class="text-sm text-slate-500 mt-0.5">Approve shipments, then pick, prepare, and release orders for drivers</p>
  </div>
  <?php if ($offline) offline_notice(); ?>
  <?php if ($canCreate && !$offline): ?>
  <div><button type="button" onclick="openModal('modal-order')" class="<?= BTN_PRIMARY ?>">+ Record Shop Order</button></div>
  <?php endif; ?>

  <?php if (!$offline): ?>
  <section class="rounded-xl border-2 <?= $toApprove && $canProcess ? 'border-orange-300' : 'border-slate-200' ?> bg-white shadow-sm overflow-hidden">
    <div class="flex items-center gap-2 border-b border-slate-200 px-5 py-4">
      <span class="inline-flex min-w-6 justify-center rounded-full <?= $toApprove ? 'bg-orange-500' : 'bg-slate-300' ?> px-2 py-0.5 text-xs font-bold text-white"><?= count($toApprove) ?></span>
      <h2 class="text-sm font-semibold text-slate-900"><?= $canProcess ? 'Shipments waiting for your approval' : 'Shipments waiting for warehouse approval' ?></h2>
    </div>
    <?php if (!$toApprove): ?>
    <p class="px-5 py-5 text-sm text-slate-400">No shipments to review. New shipments added by the admin appear here.</p>
    <?php else: ?>
    <div class="divide-y divide-slate-100">
      <?php foreach ($toApprove as $s): ?>
      <div class="flex flex-col gap-3 px-5 py-4 md:flex-row md:items-center">
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-mono text-sm font-bold text-indigo-600"><?= htmlspecialchars($s['id']) ?></span>
            <span class="rounded-full bg-orange-100 px-2.5 py-1 text-xs font-semibold text-orange-700">For Approval</span>
          </div>
          <p class="mt-1 font-semibold text-slate-900"><?= htmlspecialchars($s['origin']) ?> → <?= htmlspecialchars($s['destination']) ?></p>
          <p class="mt-1 font-mono text-xs text-slate-400">
            <?= number_format((int)$s['items']) ?> items<?= $s['weight'] !== '' ? ' · ' . htmlspecialchars($s['weight']) : '' ?>
            · Deliver by <?= htmlspecialchars($s['eta']) ?><?= !empty($s['created_by_name']) ? ' · Added by ' . htmlspecialchars($s['created_by_name']) : '' ?>
          </p>
        </div>
        <?php if ($canProcess): ?>
        <div class="flex gap-2">
          <button type="button" onclick="reviewShipment('<?= htmlspecialchars($s['id']) ?>','reject')"
            class="rounded-xl border border-red-200 px-4 py-2.5 text-sm font-bold text-red-600 transition hover:bg-red-50">Reject</button>
          <button type="button" onclick="reviewShipment('<?= htmlspecialchars($s['id']) ?>','approve')"
            class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white shadow-lg shadow-emerald-200 transition hover:bg-emerald-500">Approve</button>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($inFlow): ?>
    <div class="border-t border-slate-200 bg-slate-50 px-5 py-3">
      <p class="mb-2 text-xs font-bold uppercase tracking-wider text-slate-400">Approved shipments</p>
      <?php foreach ($inFlow as $s): ?>
      <div class="flex flex-wrap items-center gap-x-3 gap-y-1 py-1.5 text-sm">
        <span class="font-mono text-xs font-bold text-indigo-600"><?= htmlspecialchars($s['id']) ?></span>
        <span class="text-slate-700"><?= htmlspecialchars($s['destination']) ?></span>
        <span class="ml-auto text-xs font-semibold <?= $s['status'] === 'Approved' ? 'text-blue-600' : 'text-violet-600' ?>">
          <?= $s['status'] === 'Approved' ? 'Waiting for a driver' : htmlspecialchars(($s['driver_name'] ?? 'Driver') . ' · ' . ($s['vehicle_id'] ?? '') . ' · ' . $s['status']) ?>
          <?php if ($s['status'] === 'Approved'): ?><?php if (!empty($s['cancelled_by'])): ?><span class="block text-xs font-normal text-red-600">Cancelled by <?= htmlspecialchars($s['cancelled_by']) ?>: <?= htmlspecialchars($s['cancel_reason']) ?></span><?php endif; ?><?php endif; ?>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

  <h2 class="-mb-2 text-sm font-bold uppercase tracking-wider text-slate-400">Shop orders</h2>
  <?php endif; ?>

  <?php if ($notice): ?>
  <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700"><?= htmlspecialchars($notice) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <?php
    $stats = [
      ['Open Shop Orders', count(array_filter($orders, fn($o) => $o['status'] !== 'Completed')), 'bg-blue-100 text-blue-700'],
      ['Waiting / Out for Delivery', count(array_filter($orders, fn($o) => in_array($o['status'], ['Ready for Delivery', 'Out for Delivery'], true))), 'bg-amber-100 text-amber-700'],
      ['Completed', count(array_filter($orders, fn($o) => $o['status'] === 'Completed')), 'bg-emerald-100 text-emerald-700'],
    ];
    foreach ($stats as [$label, $value, $classes]):
    ?>
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <div class="mb-2 inline-flex rounded-lg px-2.5 py-1 font-mono text-sm font-bold <?= $classes ?>"><?= $value ?></div>
      <div class="text-sm font-semibold text-slate-700"><?= $label ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="flex flex-col gap-4">
    <?php foreach ($orders as $order): ?>
    <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <div class="flex flex-col gap-4 md:flex-row md:items-center">
        <div class="min-w-0 flex-1">
          <div class="mb-2 flex flex-wrap items-center gap-3">
            <span class="font-mono text-sm font-bold text-indigo-600"><?= htmlspecialchars($order['id']) ?></span>
            <span class="rounded-full px-2.5 py-1 text-xs font-semibold <?= $statusColors[$order['status']] ?? 'bg-slate-100 text-slate-700' ?>"><?= htmlspecialchars($order['status']) ?></span>
            <?php if (!$offline): ?><a href="?module=tracking&ref=<?= urlencode($order['id']) ?>" class="text-xs font-semibold text-slate-500 underline hover:text-indigo-600">Tracking log</a><?php endif; ?>
          </div>
          <h2 class="font-semibold text-slate-900"><?= htmlspecialchars($order['shop']) ?><?php if (!empty($order['address'])): ?> <span class="text-sm font-normal text-slate-500">· <?= htmlspecialchars($order['address']) ?></span><?php endif; ?></h2>
          <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars($order['items']) ?></p>
          <p class="mt-2 font-mono text-xs text-slate-400"><?= number_format((int)$order['units']) ?> units · Requested <?= htmlspecialchars(date('M d, Y g:i A', strtotime($order['requested_at']))) ?></p>
        </div>
        <?php if ($canProcess && isset($nextStatus[$order['status']])): [$next, $label] = $nextStatus[$order['status']]; ?>
        <form method="POST">
          <input type="hidden" name="order_id" value="<?= htmlspecialchars($order['id']) ?>" />
          <input type="hidden" name="status" value="<?= htmlspecialchars($next) ?>" />
          <button type="submit" <?= $offline ? 'disabled' : '' ?> class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white shadow-lg shadow-indigo-200 transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
            <?= htmlspecialchars($label) ?>
          </button>
        </form>
        <?php elseif (in_array($order['status'], ['Pending', 'Picking'], true)): ?>
        <span class="text-sm font-semibold text-slate-500">Being prepared in the warehouse</span>
        <?php elseif ($order['status'] === 'Ready for Delivery'): ?>
        <span class="text-right text-sm font-semibold text-blue-600">Waiting for a driver<?php if (!empty($order['cancelled_by'])): ?><span class="block text-xs font-normal text-red-600">Cancelled by <?= htmlspecialchars($order['cancelled_by']) ?>: <?= htmlspecialchars($order['cancel_reason']) ?></span><?php endif; ?></span>
        <?php elseif ($order['status'] === 'Out for Delivery'): ?>
        <span class="text-sm font-semibold text-violet-600"><?= htmlspecialchars(($order['driver_name'] ?? 'Driver') . ' · ' . ($order['vehicle_id'] ?? '')) ?><?= ($order['delivery_status'] ?? '') === 'In Transit' ? ' · on the road' : ' · accepted' ?></span>
        <?php else: ?>
        <span class="text-sm font-semibold text-emerald-600"><?= !empty($order['driver_name']) ? 'Delivered by ' . htmlspecialchars($order['driver_name']) : 'Processed' ?></span>
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($canProcess && !$offline): ?>
<script>
function reviewShipment(id, action) {
  if (action === 'reject') return actWithReason('api/shipments.php?action=reject&id=' + encodeURIComponent(id), 'Reason for rejecting ' + id + ':');
  return act('api/shipments.php?action=approve&id=' + encodeURIComponent(id), 'POST', {}, 'Approve ' + id + '? It will appear in the delivery app.');
}
</script>
<?php endif; ?>

<?php if ($canCreate && !$offline): ?>
<?php modal_open('modal-order', 'Record a shop order', 'A shop\'s request for products. The warehouse picks and prepares it.'); ?>
  <form id="form-order" class="px-6 py-5 flex flex-col gap-4">
    <div><label class="<?= LABEL ?>">Shop *</label><input name="shop" required maxlength="120" placeholder="e.g. PCS Computers Main Store" class="<?= FIELD ?>"></div>
    <div><label class="<?= LABEL ?>">Delivery address *</label><input name="address" required maxlength="200" placeholder="Street, barangay, city" class="<?= FIELD ?>"></div>
    <div><label class="<?= LABEL ?>">Requested items *</label><textarea name="items" required rows="2" maxlength="500" placeholder="e.g. MacBook Air M3 x2, MX Master 3S x4" class="<?= FIELD ?>"></textarea></div>
    <div><label class="<?= LABEL ?>">Total units *</label><input name="units" type="number" min="1" value="1" required class="<?= FIELD ?>"></div>
    <p data-error class="hidden text-xs text-red-600"></p>
    <div class="flex gap-3 pt-1">
      <button type="button" onclick="closeModal('modal-order')" class="flex-1 <?= BTN_GHOST ?>">Cancel</button>
      <button type="submit" class="flex-1 <?= BTN_PRIMARY ?>">Save Order</button>
    </div>
  </form>
<?php modal_close(); ?>
<script>bindForm('form-order', 'api/shop_orders.php');</script>
<?php endif; ?>

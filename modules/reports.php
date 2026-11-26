<?php
require_once __DIR__ . '/../config/fallback.php';

$offline = !db_available();

if ($offline) {
    $inv       = $FB['inventory'];
    $suppliers = $FB['suppliers'];
    $pos       = $FB['purchase_orders'];
} else {
    $pdo       = db();
    $inv       = $pdo->query('SELECT * FROM inventory')->fetchAll();
    $suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();
    $pos       = $pdo->query('SELECT * FROM purchase_orders ORDER BY order_date DESC')->fetchAll();
}

$totalSKUs  = count($inv);
$lowStock   = count(array_filter($inv, fn($i) => $i['status'] === 'Low Stock'));
$outStock   = count(array_filter($inv, fn($i) => $i['status'] === 'Out of Stock'));
$totalValue = array_sum(array_map(fn($i) => $i['qty'] * $i['cost'], $inv));
$openPOs    = count(array_filter($pos, fn($p) => in_array($p['status'], ['Pending', 'In Review', 'Confirmed'], true)));
$poValue    = array_sum(array_map(fn($p) => in_array($p['status'], ['Pending', 'In Review', 'Confirmed'], true) ? $p['value'] : 0, $pos));
$avgOnTime  = $suppliers ? round(array_sum(array_column($suppliers, 'on_time')) / count($suppliers)) : 0;

// Stock movement per month for the last 6 months (units in vs units out),
// leaving out transfers because they only move stock between warehouses.
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("first day of -$i month"));
    $months[$key] = ['label' => date('M', strtotime($key . '-01')), 'in' => 0, 'out' => 0];
}
$byWarehouse = $returnsBy = $disposalBy = $shipBy = $transferBy = [];
$writeOff = 0.0;
if (!$offline) {
    $since = array_key_first($months) . '-01 00:00:00';
    $mv = $pdo->prepare("SELECT created_at, qty_change FROM stock_movements WHERE created_at >= ? AND type NOT IN ('Transfer In', 'Transfer Out')");
    $mv->execute([$since]);
    foreach ($mv as $m) {
        $k = substr($m['created_at'], 0, 7);
        if (!isset($months[$k])) continue;
        if ($m['qty_change'] > 0) $months[$k]['in'] += (int)$m['qty_change']; else $months[$k]['out'] -= (int)$m['qty_change'];
    }
    $byWarehouse = $pdo->query('SELECT w.name, COALESCE(SUM(s.qty), 0) AS units, COALESCE(SUM(s.qty * i.cost), 0) AS value
                                FROM warehouses w LEFT JOIN stock_levels s ON s.warehouse_id = w.id LEFT JOIN inventory i ON i.id = s.item_id
                                WHERE w.active = 1 GROUP BY w.id, w.name, w.is_main ORDER BY w.is_main DESC, w.name')->fetchAll();
    $returnsBy  = $pdo->query('SELECT status, COUNT(*) AS n, SUM(qty) AS units FROM item_returns GROUP BY status ORDER BY n DESC')->fetchAll();
    $disposalBy = $pdo->query("SELECT reason, COUNT(*) AS n, SUM(qty) AS units, SUM(qty * unit_cost) AS value FROM disposals
                               WHERE status IN ('Approved', 'Disposed') GROUP BY reason ORDER BY value DESC")->fetchAll();
    $writeOff   = (float)array_sum(array_column($disposalBy, 'value'));
    $shipBy     = $pdo->query('SELECT status, COUNT(*) AS n FROM shipments GROUP BY status ORDER BY n DESC')->fetchAll(PDO::FETCH_KEY_PAIR);
    $transferBy = $pdo->query('SELECT status, COUNT(*) AS n FROM transfers GROUP BY status ORDER BY n DESC')->fetchAll(PDO::FETCH_KEY_PAIR);
}
$maxBar = max(1, max(array_map(fn($m) => max($m['in'], $m['out']), $months)));
?>

<?php if ($offline) offline_notice('Showing sample analytics. Start MySQL and import sql/invtrack.sql for live reports.'); ?>

<div class="flex flex-col gap-6">
  <div class="flex flex-wrap items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-slate-900">Reports &amp; Analytics</h1>
      <p class="text-sm text-slate-500 mt-0.5">Stock, movements, returns, write-offs, logistics and supplier performance · as of <?= date('M d, Y g:i A') ?></p>
    </div>
    <button type="button" onclick="window.print()" class="<?= BTN_GHOST ?> print:hidden">Print report</button>
  </div>

  <?php stat_cards([
      ['Total SKUs',              number_format($totalSKUs),            'text-slate-900'],
      ['Low / out of stock',      $lowStock . ' / ' . $outStock,        'text-amber-600'],
      ['Inventory value (₱)',     number_format($totalValue, 2),        'text-indigo-600'],
      ['Written off (₱)',         number_format($writeOff, 2),          'text-red-600'],
  ]); ?>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <h3 class="text-sm font-semibold text-slate-900 mb-1">Stock movement (units)</h3>
      <p class="text-xs text-slate-400 mb-4">Units added vs units removed per month, last 6 months. Transfers between warehouses are not counted.</p>
      <?php if ($offline): ?><p class="text-sm text-slate-400">Available when the database is connected.</p><?php else: ?>
      <svg viewBox="0 0 380 190" class="w-full h-auto" role="img" aria-label="Units in and out per month">
        <line x1="24" y1="150" x2="376" y2="150" stroke="#e2e8f0"/>
        <?php $i = 0; foreach ($months as $m):
          $x = 34 + $i * 57; $inH = 120 * $m['in'] / $maxBar; $outH = 120 * $m['out'] / $maxBar; ?>
        <rect x="<?= $x ?>" y="<?= 150 - $inH ?>" width="20" height="<?= $inH ?>" rx="3" fill="#10B981"><title><?= $m['label'] ?>: <?= $m['in'] ?> in</title></rect>
        <rect x="<?= $x + 22 ?>" y="<?= 150 - $outH ?>" width="20" height="<?= $outH ?>" rx="3" fill="#EF4444"><title><?= $m['label'] ?>: <?= $m['out'] ?> out</title></rect>
        <?php if ($m['in']): ?><text x="<?= $x + 10 ?>" y="<?= 146 - $inH ?>" text-anchor="middle" font-size="8" fill="#047857"><?= $m['in'] ?></text><?php endif; ?>
        <?php if ($m['out']): ?><text x="<?= $x + 32 ?>" y="<?= 146 - $outH ?>" text-anchor="middle" font-size="8" fill="#b91c1c"><?= $m['out'] ?></text><?php endif; ?>
        <text x="<?= $x + 21 ?>" y="166" text-anchor="middle" font-size="9" fill="#64748b"><?= $m['label'] ?></text>
        <?php $i++; endforeach; ?>
        <rect x="24" y="178" width="9" height="9" rx="2" fill="#10B981"/><text x="37" y="186" font-size="9" fill="#64748b">In (opening stock, deliveries, restocked returns)</text>
        <rect x="250" y="178" width="9" height="9" rx="2" fill="#EF4444"/><text x="263" y="186" font-size="9" fill="#64748b">Out (disposals)</text>
      </svg>
      <?php endif; ?>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <h3 class="text-sm font-semibold text-slate-900 mb-4">Stock by warehouse</h3>
      <?php if (!$byWarehouse): ?><p class="text-sm text-slate-400">Available when the database is connected.</p><?php endif; ?>
      <?php $maxU = max(1, max(array_map(fn($w) => (int)$w['units'], $byWarehouse ?: [['units' => 1]]))); foreach ($byWarehouse as $w): ?>
      <div class="mb-3">
        <div class="flex justify-between text-sm"><span class="text-slate-700"><?= h($w['name']) ?></span><span class="font-mono text-slate-900"><?= number_format((int)$w['units']) ?> units · ₱<?= number_format((float)$w['value'], 2) ?></span></div>
        <div class="mt-1 h-2 bg-slate-100 rounded-full overflow-hidden"><div class="h-full bg-indigo-500 rounded-full" style="width:<?= round($w['units'] / $maxU * 100) ?>%"></div></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <h3 class="text-sm font-semibold text-slate-900 mb-4">Returns by status</h3>
      <?php if (!$returnsBy): ?><p class="text-sm text-slate-400">No returns recorded.</p><?php endif; ?>
      <?php foreach ($returnsBy as $r): ?>
      <div class="flex items-center justify-between py-2 border-b border-slate-100 last:border-0">
        <?= badge($r['status']) ?><span class="font-mono text-sm text-slate-900"><?= (int)$r['n'] ?> return(s) · <?= (int)$r['units'] ?> unit(s)</span>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <h3 class="text-sm font-semibold text-slate-900 mb-4">Write-offs by reason (approved and disposed)</h3>
      <?php if (!$disposalBy): ?><p class="text-sm text-slate-400">No approved disposals.</p><?php endif; ?>
      <?php foreach ($disposalBy as $d): ?>
      <div class="flex items-center justify-between py-2 border-b border-slate-100 last:border-0">
        <span class="text-sm text-slate-700"><?= h($d['reason']) ?></span><span class="font-mono text-sm text-slate-900"><?= (int)$d['units'] ?> unit(s) · ₱<?= number_format((float)$d['value'], 2) ?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <h3 class="text-sm font-semibold text-slate-900 mb-4">Logistics</h3>
      <p class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-1">Shipments</p>
      <?php if (!$shipBy): ?><p class="text-sm text-slate-400 mb-3">No shipments.</p><?php endif; ?>
      <div class="flex flex-wrap gap-2 mb-4"><?php foreach ($shipBy as $s => $n): ?><span class="text-sm"><?= badge($s) ?> <b class="font-mono"><?= (int)$n ?></b></span><?php endforeach; ?></div>
      <p class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-1">Stock transfers</p>
      <?php if (!$transferBy): ?><p class="text-sm text-slate-400">No transfers.</p><?php endif; ?>
      <div class="flex flex-wrap gap-2"><?php foreach ($transferBy as $s => $n): ?><span class="text-sm"><?= badge($s) ?> <b class="font-mono"><?= (int)$n ?></b></span><?php endforeach; ?></div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <h3 class="text-sm font-semibold text-slate-900 mb-4">Supplier on-time delivery</h3>
      <div class="flex flex-col gap-3">
        <?php foreach ($suppliers as $s): ?>
        <div class="flex items-center gap-3">
          <div class="text-xs font-medium text-slate-700 w-40 truncate"><?= h($s['name']) ?></div>
          <div class="flex-1 h-2 bg-slate-100 rounded-full overflow-hidden"><div class="h-full rounded-full <?= $s['on_time'] >= 90 ? 'bg-emerald-500' : ($s['on_time'] >= 80 ? 'bg-amber-500' : 'bg-red-500') ?>" style="width:<?= (int)$s['on_time'] ?>%"></div></div>
          <div class="text-xs font-mono text-slate-700 w-8 text-right"><?= (int)$s['on_time'] ?>%</div>
        </div>
        <?php endforeach; ?>
      </div>
      <p class="mt-4 text-xs text-slate-500">Average on-time rate: <b><?= $avgOnTime ?>%</b> · Open POs: <b><?= $openPOs ?></b> worth <b>₱<?= number_format($poValue, 2) ?></b></p>
    </div>
  </div>

  <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-200"><h3 class="text-sm font-semibold text-slate-900">Products at or below the reorder level</h3></div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="bg-slate-50 border-b border-slate-200">
          <?php foreach (['SKU', 'Product', 'On hand', 'Reorder level', 'Status'] as $hd): ?>
          <th class="text-left text-xs font-bold text-slate-400 px-4 py-3 uppercase tracking-wider"><?= $hd ?></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
          <?php $low = array_values(array_filter($inv, fn($i) => $i['status'] !== 'In Stock')); usort($low, fn($a, $b) => $a['qty'] <=> $b['qty']); ?>
          <?php if (!$low): ?><tr><td colspan="5" class="py-6 text-center text-slate-400">All products are above their reorder level.</td></tr><?php endif; ?>
          <?php foreach ($low as $i): ?>
          <tr class="border-b border-slate-100 last:border-0">
            <td class="px-4 py-3 font-mono text-xs text-slate-500"><?= h($i['id']) ?></td>
            <td class="px-4 py-3 text-slate-900"><?= h($i['name']) ?></td>
            <td class="px-4 py-3 font-mono font-bold"><?= (int)$i['qty'] ?></td>
            <td class="px-4 py-3 font-mono"><?= (int)$i['reorder'] ?></td>
            <td class="px-4 py-3"><?= badge($i['status']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-200"><h3 class="text-sm font-semibold text-slate-900">Recent Purchase Orders</h3></div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="bg-slate-50 border-b border-slate-200">
          <?php foreach (['PO Number', 'Supplier', 'Order Date', 'Value (₱)', 'Status'] as $hd): ?>
          <th class="text-left text-xs font-bold text-slate-400 px-4 py-3 uppercase tracking-wider whitespace-nowrap"><?= $hd ?></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
          <?php foreach (array_slice($pos, 0, 5) as $po): ?>
          <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50 transition">
            <td class="px-4 py-3 font-mono text-xs text-indigo-600 font-medium"><?= h($po['id']) ?></td>
            <td class="px-4 py-3 text-slate-700"><?= h($po['supplier']) ?></td>
            <td class="px-4 py-3 font-mono text-xs text-slate-500"><?= h($po['order_date']) ?></td>
            <td class="px-4 py-3 font-mono font-semibold text-slate-900"><?= number_format((float)$po['value'], 2) ?></td>
            <td class="px-4 py-3"><?= badge($po['status']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

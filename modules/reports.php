<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/fallback.php';

$offline = !db_available();

if ($offline) {
    $inv       = $FB['inventory'];
    $suppliers = $FB['suppliers'];
    $pos       = $FB['purchase_orders'];
    $ships     = $FB['shipments'];

    $totalSKUs  = count($inv);
    $inStock    = count(array_filter($inv, fn($i)=>$i['status']==='In Stock'));
    $lowStock   = count(array_filter($inv, fn($i)=>$i['status']==='Low Stock'));
    $totalValue = array_sum(array_map(fn($i)=>$i['qty']*$i['cost'], $inv));
    $ytdSpend   = array_sum(array_column($suppliers,'spend'));
    $openPOs    = count(array_filter($pos, fn($p)=>!in_array($p['status'],['Confirmed','Delivered'])));
    $avgOnTime  = $suppliers ? round(array_sum(array_column($suppliers,'on_time'))/count($suppliers)) : 0;

    $shipments = [];
    foreach ($ships as $s) {
        $shipments[$s['status']] = ($shipments[$s['status']] ?? 0) + 1;
    }
} else {
    $totalSKUs  = db()->query('SELECT COUNT(*) FROM inventory')->fetchColumn();
    $inStock    = db()->query("SELECT COUNT(*) FROM inventory WHERE status='In Stock'")->fetchColumn();
    $lowStock   = db()->query("SELECT COUNT(*) FROM inventory WHERE status='Low Stock'")->fetchColumn();
    $totalValue = db()->query('SELECT SUM(qty * cost) FROM inventory')->fetchColumn();
    $suppliers  = db()->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();
    $pos        = db()->query('SELECT * FROM purchase_orders ORDER BY order_date DESC LIMIT 5')->fetchAll();
    $shipments  = db()->query("SELECT status, COUNT(*) AS cnt FROM shipments GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $ytdSpend   = array_sum(array_column($suppliers, 'spend'));
    $openPOs    = db()->query("SELECT COUNT(*) FROM purchase_orders WHERE status NOT IN ('Confirmed','Delivered')")->fetchColumn();
    $avgOnTime  = $suppliers ? round(array_sum(array_column($suppliers, 'on_time')) / count($suppliers)) : 0;
}

$months  = ['Jan','Feb','Mar','Apr','May','Jun','Jul'];
$revenue = [420000,380000,510000,490000,560000,620000,590000];
$maxRev  = max($revenue);
$cost    = [95000,88000,112000,108000,124000,134000,128000];
?>

<?php if ($offline): ?>
<div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-4 text-sm text-amber-700 flex items-center gap-2">
  <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
  <span><strong>Demo mode</strong> — database not connected. Showing sample analytics data.</span>
</div>
<?php endif; ?>

<div class="flex flex-col gap-6">
  <div>
    <h1 class="text-2xl font-bold text-slate-900">Reports &amp; Analytics</h1>
    <p class="text-sm text-slate-500 mt-0.5">KPIs, inventory value, supplier performance, and trends</p>
  </div>

  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
    <?php foreach ([
      ['Total SKUs',      number_format($totalSKUs), 'bg-blue-100 text-blue-600'],
      ['In Stock',        number_format($inStock),   'bg-emerald-100 text-emerald-600'],
      ['Low Stock',       number_format($lowStock),  'bg-amber-100 text-amber-600'],
      ['Inventory Value', '₱' . number_format($totalValue ?? 0), 'bg-violet-100 text-violet-600'],
    ] as [$label, $val, $cls]): ?>
    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <div class="w-9 h-9 rounded-lg <?= $cls ?> flex items-center justify-center mb-3">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
      </div>
      <div class="text-2xl font-bold text-slate-900"><?= $val ?></div>
      <div class="text-sm text-slate-500 mt-0.5"><?= $label ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <h3 class="text-sm font-semibold text-slate-900 mb-1">Monthly Flow (₱)</h3>
      <p class="text-xs text-slate-400 mb-4">Inbound vs outbound value — Jan to Jul 2026</p>
      <svg viewBox="0 0 380 180" class="w-full h-auto">
        <?php
        $barW = 22; $gap = 32; $chartH = 140; $startX = 30;
        foreach ($months as $mi => $month):
          $x  = $startX + $mi * ($barW * 2 + $gap);
          $rH = $chartH * ($revenue[$mi] / $maxRev);
          $cH = $chartH * ($cost[$mi]    / $maxRev);
        ?>
        <rect x="<?= $x ?>" y="<?= 150 - $rH ?>" width="<?= $barW ?>" height="<?= $rH ?>" rx="3" fill="#6366F1" opacity="0.85"/>
        <rect x="<?= $x + $barW + 2 ?>" y="<?= 150 - $cH ?>" width="<?= $barW ?>" height="<?= $cH ?>" rx="3" fill="#06B6D4" opacity="0.85"/>
        <text x="<?= $x + $barW ?>" y="168" text-anchor="middle" font-size="9" fill="#94a3b8"><?= $month ?></text>
        <?php endforeach; ?>
        <rect x="30" y="6" width="10" height="10" rx="2" fill="#6366F1"/>
        <text x="44" y="15" font-size="9" fill="#64748b">Revenue</text>
        <rect x="100" y="6" width="10" height="10" rx="2" fill="#06B6D4"/>
        <text x="114" y="15" font-size="9" fill="#64748b">Cost</text>
      </svg>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <h3 class="text-sm font-semibold text-slate-900 mb-4">Supplier Performance</h3>
      <div class="flex flex-col gap-3">
        <?php foreach ($suppliers as $s): ?>
        <div class="flex items-center gap-3">
          <div class="text-xs font-medium text-slate-700 w-36 truncate"><?= htmlspecialchars($s['name']) ?></div>
          <div class="flex-1 h-2 bg-slate-100 rounded-full overflow-hidden">
            <div class="h-full rounded-full <?= $s['on_time'] >= 90 ? 'bg-emerald-500' : ($s['on_time'] >= 80 ? 'bg-amber-500' : 'bg-red-500') ?>"
              style="width:<?= $s['on_time'] ?>%"></div>
          </div>
          <div class="text-xs font-mono text-slate-700 w-8 text-right"><?= $s['on_time'] ?>%</div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <h3 class="text-sm font-semibold text-slate-900 mb-4">Shipment Status</h3>
      <?php
      $colors = ['In Transit'=>'#06B6D4','Delivered'=>'#10B981','Customs'=>'#F59E0B','Picked Up'=>'#6366F1','Pending'=>'#94a3b8'];
      foreach ($shipments as $status => $cnt): ?>
      <div class="flex items-center justify-between py-2 border-b border-slate-100 last:border-0">
        <div class="flex items-center gap-2">
          <span class="w-2.5 h-2.5 rounded-full" style="background:<?= $colors[$status] ?? '#94a3b8' ?>"></span>
          <span class="text-sm text-slate-700"><?= htmlspecialchars($status) ?></span>
        </div>
        <span class="font-mono font-bold text-slate-900"><?= $cnt ?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <h3 class="text-sm font-semibold text-slate-900 mb-4">Procurement Summary</h3>
      <?php foreach ([
        ['YTD Spend',       '₱' . number_format($ytdSpend)],
        ['Open POs',        $openPOs],
        ['Avg On-Time',     "$avgOnTime%"],
        ['Active Vendors',  count(array_filter($suppliers, fn($s) => $s['status'] === 'Active'))],
      ] as [$label, $val]): ?>
      <div class="flex items-center justify-between py-2 border-b border-slate-100 last:border-0">
        <span class="text-sm text-slate-600"><?= $label ?></span>
        <span class="font-mono font-semibold text-slate-900"><?= $val ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-200"><h3 class="text-sm font-semibold text-slate-900">Recent Purchase Orders</h3></div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="bg-slate-50 border-b border-slate-200">
          <?php foreach (['PO Number','Supplier','Value (₱)','Status'] as $h): ?>
          <th class="text-left text-xs font-bold text-slate-400 px-4 py-3 uppercase tracking-wider whitespace-nowrap"><?= $h ?></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
          <?php foreach (array_slice($pos, 0, 5) as $po): ?>
          <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50 transition">
            <td class="px-4 py-3 font-mono text-xs text-indigo-600 font-medium"><?= htmlspecialchars($po['id']) ?></td>
            <td class="px-4 py-3 text-slate-700"><?= htmlspecialchars($po['supplier']) ?></td>
            <td class="px-4 py-3 font-mono font-semibold text-slate-900">₱<?= number_format($po['value'], 2) ?></td>
            <td class="px-4 py-3">
              <?php
              $statusMap = ['Confirmed'=>'bg-blue-100 text-blue-700','In Review'=>'bg-purple-100 text-purple-700','Pending'=>'bg-amber-100 text-amber-700'];
              $cls = $statusMap[$po['status']] ?? 'bg-slate-100 text-slate-700';
              echo "<span class=\"text-xs font-semibold px-2 py-0.5 rounded-full $cls\">".htmlspecialchars($po['status'])."</span>";
              ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/stock.php';
require_once __DIR__ . '/config/ui.php';
$user = require_auth('index.php');

$module = $_GET['module'] ?? null;

$I = [
  'inventory'   => 'M4 6h16M4 10h16M4 14h16M4 18h16',
  'categories'  => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z',
  'warehouse'   => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
  'transfers'   => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4',
  'returns'     => 'M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6',
  'disposals'   => 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16',
  'tracking'    => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
  'orders'      => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17',
  'procurement' => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z',
  'reports'     => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
];

$navByRole = [
  'admin' => [
    ['inventory',   'Inventory Control',       'Stock & SKUs',             $I['inventory']],
    ['categories',  'Product Categories',      'Manage Categories',        $I['categories']],
    ['warehouse',   'Warehouse & Logistics',   'Warehouses & Shipments',   $I['warehouse']],
    ['transfers',   'Stock Transfers',         'Warehouse to warehouse',   $I['transfers']],
    ['returns',     'Returns',                 'Returned items',           $I['returns']],
    ['disposals',   'Disposal',                'Stock write-offs',         $I['disposals']],
    ['tracking',    'Tracking Log',            'Parcel & item tracking',   $I['tracking']],
    ['orders',      'Shop Orders',             'Shop requests',            $I['orders']],
    ['procurement', 'Procurement & Suppliers', 'POs & Vendors',            $I['procurement']],
    ['reports',     'Reports & Analytics',     'KPIs & Trends',            $I['reports']],
  ],
  'staff' => [
    ['inventory',   'Inventory Control',       'Stock & SKUs',             $I['inventory']],
    ['categories',  'Product Categories',      'Manage Categories',        $I['categories']],
    ['transfers',   'Stock Transfers',         'Request transfers',        $I['transfers']],
    ['returns',     'Returns',                 'Record returned items',    $I['returns']],
    ['disposals',   'Disposal',                'Request write-offs',       $I['disposals']],
    ['tracking',    'Tracking Log',            'Parcel & item tracking',   $I['tracking']],
    ['procurement', 'Procurement & Suppliers', 'POs & Vendors',            $I['procurement']],
    ['reports',     'Reports & Analytics',     'KPIs & Trends',            $I['reports']],
  ],
  'warehouse' => [
    ['inventory',   'Available Products',      'Warehouse stock',          $I['inventory']],
    ['orders',      'Shop Orders',             'Process shop requests',    $I['orders']],
    ['transfers',   'Stock Transfers',         'Dispatch & receive',       $I['transfers']],
    ['returns',     'Returns',                 'Record returned items',    $I['returns']],
    ['disposals',   'Disposal',                'Request write-offs',       $I['disposals']],
    ['tracking',    'Tracking Log',            'Post tracking updates',    $I['tracking']],
  ],
];

$nav    = $navByRole[$user['role']] ?? $navByRole['staff'];
$module = $module && in_array($module, array_column($nav, 0), true) ? $module : $nav[0][0];

$roleMeta = [
  'admin'     => [ROLE_LABELS['admin'],     'bg-indigo-100 text-indigo-800'],
  'staff'     => [ROLE_LABELS['staff'],     'bg-blue-100 text-blue-700'],
  'warehouse' => [ROLE_LABELS['warehouse'], 'bg-amber-100 text-amber-700'],
];
[$roleLabel, $roleCls] = $roleMeta[$user['role']] ?? ['User', 'bg-slate-100 text-slate-700'];

$moduleTitles = [
  'inventory'   => 'Inventory Control',
  'categories'  => 'Product Categories',
  'warehouse'   => 'Warehouse & Logistics',
  'transfers'   => 'Stock Transfers',
  'returns'     => 'Returns',
  'disposals'   => 'Disposal',
  'tracking'    => 'Tracking Log',
  'orders'      => 'Shop Orders',
  'procurement' => 'Procurement & Suppliers',
  'reports'     => 'Reports & Analytics',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>INVTrack — <?= htmlspecialchars($moduleTitles[$module] ?? 'Dashboard') ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="assets/theme.js"></script>
  <link rel="icon" type="image/png" href="assets/pcs-icon.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet" />
  <script src="assets/app.js"></script>
  <style>
    body { font-family: 'Inter', sans-serif; }
    .font-mono, code { font-family: 'DM Mono', monospace; }
  </style>
</head>
<body class="bg-pcs-50 min-h-screen">

<div id="sidebar-overlay" onclick="closeSidebar()"
  class="fixed inset-0 z-30 bg-black/50 hidden lg:hidden"></div>

<aside id="sidebar"
  class="fixed inset-y-0 left-0 z-40 w-64 bg-pcs-900 flex flex-col h-screen transition-transform duration-300 -translate-x-full lg:translate-x-0">

  <div class="px-5 pt-4 pb-3 border-b border-pcs-800 shrink-0">
    <img src="assets/pcs-logo.png" alt="PCS Computers" class="w-full max-w-[150px] mx-auto rounded-md" />
    <div class="mt-2 text-center">
      <div class="text-white font-extrabold text-lg tracking-tight leading-none">INV<span class="text-indigo-400">Track</span></div>
      <div class="text-pcs-300 text-[11px] mt-1">Inventory Management &amp; Logistics System</div>
    </div>
  </div>

  <nav class="flex-1 overflow-y-auto px-3 py-4 flex flex-col gap-0.5">
    <span class="text-pcs-400 text-[10px] font-bold uppercase tracking-widest px-3 mb-1">PCS Computers &middot; Naga City</span>
    <?php foreach ($nav as [$id, $label, $desc, $iconPath]): ?>
    <a href="?module=<?= $id ?>" onclick="closeSidebar()"
      class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all group <?= $id === $module ? 'bg-indigo-600 text-white shadow-md' : 'text-pcs-200 hover:bg-pcs-800 hover:text-white' ?>">
      <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="<?= htmlspecialchars($iconPath) ?>"/>
      </svg>
      <div class="flex flex-col flex-1 min-w-0">
        <span class="text-sm font-semibold leading-tight"><?= htmlspecialchars($label) ?></span>
        <span class="text-[11px] leading-tight <?= $id === $module ? 'text-indigo-100' : 'text-pcs-400' ?>"><?= htmlspecialchars($desc) ?></span>
      </div>
      <?php if ($id === $module): ?>
      <svg class="w-4 h-4 opacity-70 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
      </svg>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
    <?php if (role_can($user['role'], 'deliver')): ?>
    <a href="driver/" class="mt-2 flex items-center gap-3 px-3 py-3 rounded-lg text-pcs-200 hover:bg-pcs-800 hover:text-white transition">
      <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
      <div class="flex flex-col"><span class="text-sm font-semibold leading-tight">Delivery App</span><span class="text-[11px] leading-tight text-pcs-400">Open on your phone</span></div>
    </a>
    <?php endif; ?>
  </nav>

  <div class="px-4 py-3 border-t border-pcs-800 shrink-0">
    <div class="px-2 mb-2 text-[11px] leading-snug">
      <span class="text-pcs-400 font-bold uppercase tracking-widest text-[10px]">Store</span>
      <span class="text-pcs-200">Yashano Mall, Diversion Rd., Naga City</span>
    </div>
    <div class="flex items-center gap-3 mb-2 p-2 rounded-lg bg-pcs-800">
      <div class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-400 to-indigo-700 flex items-center justify-center text-white text-sm font-bold shrink-0">
        <?= htmlspecialchars($user['avatar']) ?>
      </div>
      <div class="flex flex-col min-w-0">
        <span class="text-sm font-semibold text-white truncate"><?= htmlspecialchars($user['name']) ?></span>
        <span class="text-[11px] px-2 py-0.5 rounded-md font-semibold <?= $roleCls ?> w-fit mt-0.5"><?= $roleLabel ?></span>
      </div>
    </div>
    <a href="logout.php"
      class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-lg text-pcs-200 hover:bg-red-500/20 hover:text-red-400 text-sm font-medium transition-all duration-200">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
      </svg>
      Sign Out
    </a>
  </div>
</aside>

<div class="min-h-screen flex flex-col lg:ml-64">

  <header class="sticky top-0 z-20 h-16 bg-white border-b border-slate-200 flex items-center px-4 gap-3 shadow-sm shrink-0">
    <button onclick="toggleSidebar()" class="lg:hidden p-2 hover:bg-slate-100 rounded-lg transition text-slate-500">
      <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
      </svg>
    </button>

    <div class="hidden sm:flex items-center gap-2 text-sm text-slate-400">
      <span>Dashboard</span>
      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 5l7 7-7 7"/></svg>
      <span class="text-slate-800 font-medium"><?= htmlspecialchars($moduleTitles[$module] ?? $module) ?></span>
    </div>
    <span class="sm:hidden text-sm font-semibold text-slate-800"><?= htmlspecialchars($moduleTitles[$module] ?? $module) ?></span>

    <div class="ml-auto flex items-center gap-2">
      <span class="hidden sm:block text-xs font-mono text-slate-400 bg-slate-100 px-3 py-1.5 rounded-lg"><?= date('M d, Y') ?></span>
      <?php $tasks = pending_tasks($user); $taskCount = array_sum(array_column($tasks, 1)); ?>
      <div class="relative">
        <button type="button" onclick="document.getElementById('task-menu').classList.toggle('hidden')" title="Things waiting for you"
          class="relative p-2 hover:bg-slate-100 rounded-lg transition">
          <svg class="w-4 h-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/>
          </svg>
          <?php if ($taskCount): ?>
          <span class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center"><?= $taskCount > 99 ? '99+' : $taskCount ?></span>
          <?php endif; ?>
        </button>
        <div id="task-menu" class="hidden absolute right-0 mt-2 w-72 bg-white border border-slate-200 rounded-xl shadow-xl z-50 overflow-hidden">
          <div class="px-4 py-3 border-b border-slate-100 text-xs font-bold uppercase tracking-wider text-slate-400">Waiting for you</div>
          <?php if (!$taskCount): ?>
          <p class="px-4 py-4 text-sm text-slate-500">Nothing needs your action right now.</p>
          <?php else: foreach ($tasks as [$label, $n, $link]): if (!$n) continue; ?>
          <a href="<?= h($link) ?>" class="flex items-center justify-between px-4 py-3 text-sm text-slate-700 hover:bg-slate-50 border-b border-slate-50 last:border-0">
            <span><?= h($label) ?></span><span class="font-mono font-bold text-indigo-600"><?= $n ?></span>
          </a>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </header>

  <main class="flex-1 p-4 lg:p-6">
    <?php
    $allowed = array_keys($moduleTitles);
    $file    = __DIR__ . "/modules/$module.php";
    if (in_array($module, $allowed, true) && file_exists($file)) {
        try {
            include $file;
        } catch (Throwable $e) {
            echo '<div class="bg-red-50 border border-red-300 rounded-xl p-6 text-sm">';
            echo '<p class="font-bold text-red-700 mb-2">Error loading module</p>';
            echo '<p class="text-red-600 font-mono text-xs">' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<p class="text-red-400 mt-2 text-xs">' . htmlspecialchars($e->getFile() . ':' . $e->getLine()) . '</p>';
            echo '</div>';
        }
    } else {
        echo '<div class="text-center py-20 text-slate-400">Module not found: ' . htmlspecialchars($module) . '</div>';
    }
    ?>
  </main>
</div>

<script>
function toggleSidebar() {
  const sb = document.getElementById('sidebar');
  const ov = document.getElementById('sidebar-overlay');
  const isOpen = !sb.classList.contains('-translate-x-full');
  sb.classList.toggle('-translate-x-full', isOpen);
  ov.classList.toggle('hidden', isOpen);
}
function closeSidebar() {
  document.getElementById('sidebar').classList.add('-translate-x-full');
  document.getElementById('sidebar-overlay').classList.add('hidden');
}
</script>
</body>
</html>

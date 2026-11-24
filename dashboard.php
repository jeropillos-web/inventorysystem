<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/config/auth.php';
$user = require_auth('index.php');

$module = $_GET['module'] ?? null;

$navByRole = [
  'admin' => [
    ['inventory',    'Inventory Control',        'Stock & SKUs',        'M3 3 7h18M3 11h18M3 15h18M3 19h18'],
    ['categories',   'Product Categories',       'Manage Categories',   'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z'],
    ['warehouse',    'Warehouse & Logistics',    'Zones & Shipments',   'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
    ['procurement',  'Procurement & Suppliers',  'POs & Vendors',       'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z'],
    ['reports',      'Reports & Analytics',      'KPIs & Trends',       'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
    ['gps',          'GPS Tracker',              'Live Fleet Map',      'M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z M15 11a3 3 0 11-6 0 3 3 0 016 0z'],
    ['scanner',      'Barcode Scanner',          'Scan Items',          'M4 7V4h3M17 4h3v3M4 17v3h3M17 20h3v-3'],
  ],
  'staff' => [
    ['inventory',   'Inventory Control',        'Stock & SKUs',      'M3 3 7h18M3 11h18M3 15h18M3 19h18'],
    ['categories',  'Product Categories',       'Manage Categories', 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z'],
    ['procurement', 'Procurement & Suppliers',  'POs & Vendors',     'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z'],
    ['reports',     'Reports & Analytics',      'KPIs & Trends',     'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
  ],
  'warehouse' => [
    ['inventory', 'Available Products', 'Warehouse stock only', 'M3 3 7h18M3 11h18M3 15h18M3 19h18'],
    ['orders', 'Shop Orders', 'Process shop requests', 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17'],
  ],
];

$nav    = $navByRole[$user['role']] ?? $navByRole['staff'];
$module = $module && in_array($module, array_column($nav, 0)) ? $module : $nav[0][0];

$roleMeta = [
  'admin'     => ['Administrator',   'bg-violet-100 text-violet-700'],
  'staff'     => ['Staff',           'bg-blue-100 text-blue-700'],
  'warehouse' => ['Warehouse / Delivery Staff', 'bg-amber-100 text-amber-700'],
];
[$roleLabel, $roleCls] = $roleMeta[$user['role']] ?? ['User', 'bg-slate-100 text-slate-700'];

$moduleTitles = [
  'inventory'   => 'Inventory Control',
  'categories'  => 'Product Categories',
  'warehouse'   => 'Warehouse & Logistics',
  'procurement' => 'Procurement & Suppliers',
  'reports'     => 'Reports & Analytics',
  'gps'         => 'GPS Delivery Tracker',
  'scanner'     => 'Barcode Scanner',
  'orders'      => 'Shop Orders',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>INVTrack — <?= htmlspecialchars($moduleTitles[$module] ?? 'Dashboard') ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet" />
  <style>
    body { font-family: 'Inter', sans-serif; }
    .font-mono, code { font-family: 'DM Mono', monospace; }
  </style>
</head>
<body class="bg-slate-100 min-h-screen">

<div id="sidebar-overlay" onclick="closeSidebar()"
  class="fixed inset-0 z-30 bg-black/50 hidden lg:hidden"></div>

<aside id="sidebar"
  class="fixed inset-y-0 left-0 z-40 w-64 bg-slate-900 flex flex-col h-screen transition-transform duration-300 -translate-x-full lg:translate-x-0">

  <div class="px-6 py-6 border-b border-slate-800 shrink-0">
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 bg-indigo-600 rounded-lg flex items-center justify-center shadow-lg shadow-indigo-500/40 shrink-0">
        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0z"/>
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10l2.293-2.293A1 1 0 016 13h4a1 1 0 01.707.293L13 16z"/>
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 6h4l3 5-3 2-2-2H13"/>
        </svg>
      </div>
      <div>
        <div class="text-white font-bold tracking-tight">INVTrack</div>
        <div class="text-slate-500 text-[10px] font-mono uppercase tracking-widest">Management Suite</div>
      </div>
    </div>
  </div>

  <nav class="flex-1 overflow-y-auto px-3 py-5 flex flex-col gap-1">
    <span class="text-slate-600 text-[10px] font-bold uppercase tracking-widest px-3 mb-1">Navigation</span>
    <?php foreach ($nav as [$id, $label, $desc, $iconPath]): ?>
    <a href="?module=<?= $id ?>" onclick="closeSidebar()"
      class="flex items-center gap-3 px-3 py-3 rounded-lg transition-all group <?= $id === $module ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-400 hover:bg-slate-800 hover:text-white' ?>">
      <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="<?= htmlspecialchars($iconPath) ?>"/>
      </svg>
      <div class="flex flex-col flex-1 min-w-0">
        <span class="text-sm font-semibold leading-tight"><?= htmlspecialchars($label) ?></span>
        <span class="text-[11px] leading-tight <?= $id === $module ? 'text-indigo-200' : 'text-slate-600' ?>"><?= htmlspecialchars($desc) ?></span>
      </div>
      <?php if ($id === $module): ?>
      <svg class="w-4 h-4 opacity-70 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
      </svg>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <div class="px-4 py-5 border-t border-slate-800 shrink-0">
    <div class="flex items-center gap-3 mb-4 p-2 rounded-lg bg-slate-800">
      <div class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 flex items-center justify-center text-white text-sm font-bold shrink-0">
        <?= htmlspecialchars($user['avatar']) ?>
      </div>
      <div class="flex flex-col min-w-0">
        <span class="text-sm font-semibold text-white truncate"><?= htmlspecialchars($user['name']) ?></span>
        <span class="text-[11px] px-2 py-0.5 rounded-md font-semibold <?= $roleCls ?> w-fit mt-0.5"><?= $roleLabel ?></span>
      </div>
    </div>
    <a href="logout.php"
      class="w-full flex items-center justify-center gap-2 px-3 py-2.5 rounded-lg text-slate-400 hover:bg-red-500/20 hover:text-red-400 text-sm font-medium transition-all duration-200">
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
      <button class="relative p-2 hover:bg-slate-100 rounded-lg transition">
        <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/>
        </svg>
        <span class="absolute top-1.5 right-1.5 w-1.5 h-1.5 bg-red-500 rounded-full"></span>
      </button>
    </div>
  </header>

  <main class="flex-1 p-4 lg:p-6">
    <?php
    $allowed = ['inventory','categories','warehouse','procurement','reports','gps','scanner','orders'];
    $file    = __DIR__ . "/modules/$module.php";
    if (in_array($module, $allowed) && file_exists($file)) {
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

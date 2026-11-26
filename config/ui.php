<?php
// Small helpers shared by the dashboard modules.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

const FIELD = 'w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-300';
const LABEL = 'block text-xs font-semibold text-slate-600 mb-1';
const BTN_PRIMARY = 'inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-bold hover:bg-indigo-500 transition disabled:opacity-50';
const BTN_GHOST = 'inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-slate-200 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition';
const BTN_DANGER = 'inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-red-200 text-sm font-bold text-red-600 hover:bg-red-50 transition';
const BTN_GO = 'inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-emerald-600 text-white text-sm font-bold hover:bg-emerald-500 transition';

function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function fmt_dt($dt, string $fmt = 'M d, Y g:i A'): string {
    if (!$dt) return '';
    $t = strtotime((string)$dt);
    return $t ? date($fmt, $t) : (string)$dt;
}

// Coloured status pill. Colours follow the meaning of the status.
function badge(string $status): string {
    $green  = ['In Stock', 'Delivered', 'Completed', 'Received', 'Restocked', 'Disposed', 'Active', 'Confirmed', 'Approved'];
    $amber  = ['Low Stock', 'Pending', 'Pending Inspection', 'Pending Approval', 'For Approval', 'Requested', 'Picking', 'In Review', 'Under Review', 'Delayed', 'Packed'];
    $blue   = ['Ready for Delivery', 'For Disposal', 'Returned to Supplier', 'Arrived at checkpoint', 'Created', 'Note'];
    $violet = ['In Transit', 'Out for Delivery', 'Dispatched', 'Picked Up'];
    $red    = ['Out of Stock', 'Rejected', 'Cancelled', 'Inactive', 'Delivery cancelled'];
    $cls = 'bg-slate-100 text-slate-600';
    if (in_array($status, $green, true))  $cls = 'bg-emerald-100 text-emerald-700';
    if (in_array($status, $amber, true))  $cls = 'bg-amber-100 text-amber-700';
    if (in_array($status, $blue, true))   $cls = 'bg-blue-100 text-blue-700';
    if (in_array($status, $violet, true)) $cls = 'bg-violet-100 text-violet-700';
    if (in_array($status, $red, true))    $cls = 'bg-red-100 text-red-700';
    return '<span class="inline-block text-xs font-semibold px-2 py-0.5 rounded-full whitespace-nowrap ' . $cls . '">' . h($status) . '</span>';
}

function offline_notice(string $text = 'Database not connected. Start MySQL in XAMPP and import sql/invtrack.sql.'): void {
    echo '<div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-4 text-sm text-amber-700 flex items-center gap-2">'
       . '<svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>'
       . '<span><strong>Demo mode.</strong> ' . h($text) . '</span></div>';
}

// Shown by modules that only work with the database.
function needs_database(string $title): void {
    echo '<div class="flex flex-col gap-4"><h1 class="text-2xl font-bold text-slate-900">' . h($title) . '</h1>';
    offline_notice('This module needs the database. Start MySQL in the XAMPP Control Panel and import sql/invtrack.sql.');
    echo '</div>';
}

function page_header(string $title, string $subtitle, string $actions = ''): void {
    echo '<div class="flex flex-wrap items-center justify-between gap-3"><div>'
       . '<h1 class="text-2xl font-bold text-slate-900">' . h($title) . '</h1>'
       . '<p class="text-sm text-slate-500 mt-0.5">' . h($subtitle) . '</p></div>'
       . ($actions !== '' ? '<div class="flex flex-wrap gap-2">' . $actions . '</div>' : '') . '</div>';
}

function stat_cards(array $cards): void {
    echo '<div class="grid grid-cols-2 lg:grid-cols-' . max(2, min(4, count($cards))) . ' gap-4">';
    foreach ($cards as [$label, $value, $tone]) {
        echo '<div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">'
           . '<div class="text-2xl font-bold ' . $tone . '">' . h($value) . '</div>'
           . '<div class="text-sm text-slate-500 mt-0.5">' . h($label) . '</div></div>';
    }
    echo '</div>';
}

function modal_open(string $id, string $title, string $subtitle = '', string $width = 'max-w-lg'): void {
    echo '<div id="' . h($id) . '" data-modal class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">'
       . '<div class="bg-white rounded-2xl shadow-2xl w-full ' . $width . ' max-h-[95vh] overflow-y-auto">'
       . '<div class="flex items-start justify-between px-6 py-5 border-b border-slate-200"><div>'
       . '<h2 class="font-bold text-slate-900">' . h($title) . '</h2>'
       . ($subtitle !== '' ? '<p class="text-xs text-slate-500 mt-0.5">' . h($subtitle) . '</p>' : '')
       . '</div><button type="button" onclick="closeModal(\'' . h($id) . '\')" class="p-1.5 hover:bg-slate-100 rounded-lg text-slate-500" aria-label="Close">✕</button></div>';
}

function modal_close(): void {
    echo '</div></div>';
}

// Things waiting for the signed-in user, shown under the bell icon: [label, count, link].
function pending_tasks(array $user): array {
    if (!db_available()) return [];
    $pdo = db();
    $n = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();
    try {
        if ($user['role'] === 'admin') {
            return [
                ['Returns to inspect',           $n("SELECT COUNT(*) FROM item_returns WHERE status = 'Pending Inspection'"), 'dashboard.php?module=returns'],
                ['Disposal requests to approve', $n("SELECT COUNT(*) FROM disposals WHERE status = 'Pending Approval'"),       'dashboard.php?module=disposals'],
                ['Transfers to approve',         $n("SELECT COUNT(*) FROM transfers WHERE status = 'Requested'"),             'dashboard.php?module=transfers'],
                ['Low or out-of-stock products', $n("SELECT COUNT(*) FROM inventory WHERE status <> 'In Stock'"),             'dashboard.php?module=inventory&inv_status=Low+Stock'],
            ];
        }
        if ($user['role'] === 'warehouse') {
            return [
                ['Shipments to approve',  $n("SELECT COUNT(*) FROM shipments WHERE status = 'For Approval'"),          'dashboard.php?module=orders'],
                ['Shop orders to pick',   $n("SELECT COUNT(*) FROM shop_orders WHERE status IN ('Pending','Picking')"), 'dashboard.php?module=orders'],
                ['Transfers to dispatch', $n("SELECT COUNT(*) FROM transfers WHERE status = 'Approved'"),               'dashboard.php?module=transfers'],
                ['Transfers to receive',  $n("SELECT COUNT(*) FROM transfers WHERE status = 'In Transit'"),             'dashboard.php?module=transfers'],
                ['Disposals to complete', $n("SELECT COUNT(*) FROM disposals WHERE status = 'Approved'"),               'dashboard.php?module=disposals'],
            ];
        }
        return [
            ['Low or out-of-stock products', $n("SELECT COUNT(*) FROM inventory WHERE status <> 'In Stock'"), 'dashboard.php?module=inventory&inv_status=Low+Stock'],
            ['Purchase orders pending',      $n("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Pending','In Review')"), 'dashboard.php?module=procurement'],
        ];
    } catch (Throwable $e) {
        return [];   // tables missing on an old database: the modules show what to do
    }
}

function warehouses_list(bool $activeOnly = true): array {
    if (!db_available()) return [];
    return db()->query('SELECT id, name, address, is_main, active FROM warehouses' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY is_main DESC, name')->fetchAll();
}

function products_list(): array {
    if (!db_available()) return [];
    return db()->query('SELECT id, name, qty, cost FROM inventory ORDER BY name')->fetchAll();
}

<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/fallback.php';

$user     = $_SESSION['user'];
$readonly = $user['role'] === 'warehouse';
$offline  = !db_available();

$cats = $offline ? $FB['categories'] : db()->query('SELECT * FROM categories ORDER BY name')->fetchAll();
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
      <h1 class="text-2xl font-bold text-slate-900">Product Categories</h1>
      <p class="text-sm text-slate-500 mt-0.5">Organize your catalog into logical groups</p>
    </div>
    <?php if (!$readonly && !$offline): ?>
    <button onclick="document.getElementById('modal-add-cat').classList.remove('hidden')"
      class="flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-bold hover:bg-indigo-500 transition shadow-lg shadow-indigo-500/30">
      + Add Category
    </button>
    <?php endif; ?>
  </div>

  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
    <?php foreach ($cats as $cat): ?>
    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm hover:shadow-md transition relative">
      <?php if (!$readonly && !$offline): ?>
      <button onclick="deleteCategory('<?= htmlspecialchars($cat['id']) ?>')"
        class="absolute top-3 right-3 p-1.5 rounded-lg text-slate-300 hover:text-red-500 hover:bg-red-50 transition">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
      </button>
      <?php endif; ?>
      <div class="w-10 h-10 rounded-xl flex items-center justify-center mb-3" style="background:<?= htmlspecialchars($cat['color']) ?>22">
        <div class="w-5 h-5 rounded-full" style="background:<?= htmlspecialchars($cat['color']) ?>"></div>
      </div>
      <div class="font-bold text-slate-900 mb-1"><?= htmlspecialchars($cat['name']) ?></div>
      <div class="text-xs text-slate-500 leading-relaxed"><?= htmlspecialchars($cat['description'] ?? '') ?></div>
      <div class="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between">
        <span class="text-xs font-mono text-slate-400"><?= htmlspecialchars($cat['id']) ?></span>
        <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 font-medium">Active</span>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($cats)): ?>
    <div class="col-span-full text-center py-16 text-slate-400">No categories yet.</div>
    <?php endif; ?>
  </div>
</div>

<?php if (!$readonly && !$offline): ?>
<div id="modal-add-cat" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md" onclick="event.stopPropagation()">
    <div class="flex items-center justify-between px-6 py-5 border-b border-slate-200">
      <h2 class="font-bold text-slate-900">Add Category</h2>
      <button onclick="document.getElementById('modal-add-cat').classList.add('hidden')" class="p-1.5 hover:bg-slate-100 rounded-lg text-slate-500">✕</button>
    </div>
    <form id="form-add-cat" class="px-6 py-5 flex flex-col gap-4">
      <div><label class="block text-xs font-semibold text-slate-600 mb-1">Category Name *</label>
        <input name="name" required class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" /></div>
      <div><label class="block text-xs font-semibold text-slate-600 mb-1">Description</label>
        <input name="description" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" /></div>
      <div><label class="block text-xs font-semibold text-slate-600 mb-1">Accent Color</label>
        <input name="color" type="color" value="#6366F1" class="h-10 w-full rounded-lg border border-slate-200 cursor-pointer" /></div>
      <p id="cat-form-error" class="text-xs text-red-600 hidden"></p>
      <div class="flex gap-3 pt-1">
        <button type="button" onclick="document.getElementById('modal-add-cat').classList.add('hidden')"
          class="flex-1 py-2.5 rounded-xl border border-slate-200 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
        <button type="submit" class="flex-1 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-bold hover:bg-indigo-500 transition">Add Category</button>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('form-add-cat').addEventListener('submit', async function(e) {
  e.preventDefault();
  const fd = new FormData(this); const body = Object.fromEntries(fd.entries());
  const res = await fetch('api/categories.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
  const json = await res.json();
  if (json.ok) { location.reload(); }
  else { const el=document.getElementById('cat-form-error'); el.textContent=json.error; el.classList.remove('hidden'); }
});
function deleteCategory(id) {
  if (!confirm('Delete this category?')) return;
  fetch('api/categories.php?id='+encodeURIComponent(id),{method:'DELETE'}).then(()=>location.reload());
}
</script>
<?php endif; ?>

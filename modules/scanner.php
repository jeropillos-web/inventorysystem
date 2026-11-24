<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/fallback.php';

$offline = !db_available();
$items   = $offline ? $FB['inventory'] : db()->query('SELECT * FROM inventory ORDER BY name')->fetchAll();
?>

<div class="flex flex-col gap-6">
  <div class="flex flex-wrap items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-slate-900">Barcode Scanner</h1>
      <p class="text-sm text-slate-500 mt-0.5">Scan product barcodes to check items in or out of inventory</p>
    </div>
    <div class="flex items-center gap-2 shrink-0">
      <button id="btn-checkin" onclick="setAction('check-in')"
        class="px-4 py-2 rounded-lg text-sm font-semibold transition bg-emerald-600 text-white">Check In</button>
      <button id="btn-checkout" onclick="setAction('check-out')"
        class="px-4 py-2 rounded-lg text-sm font-semibold transition bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">Check Out</button>
    </div>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

    <div class="flex flex-col gap-4">

      <div id="camera-error" class="hidden bg-red-50 border border-red-200 rounded-lg px-4 py-3 flex items-center gap-2">
        <svg class="w-4 h-4 text-red-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
        <span id="camera-error-msg" class="text-sm text-red-700"></span>
      </div>

      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
        <div id="scanner-viewport" class="relative flex flex-col items-center justify-center select-none bg-white" style="height:300px">

          <div id="scanner-idle" class="flex flex-col items-center gap-4 text-slate-400 p-6">
            <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center">
              <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M4 7V4h3M17 4h3v3M4 17v3h3M17 20h3v-3"/><rect x="7" y="7" width="4" height="4" rx="0.5"/><rect x="13" y="7" width="4" height="4" rx="0.5"/><rect x="7" y="13" width="4" height="4" rx="0.5"/><path d="M13 13h1v1M15 15h2v2M13 17h2"/></svg>
            </div>
            <span class="text-sm font-medium text-slate-700">Ready to Scan</span>
            <div class="flex flex-col sm:flex-row gap-2 w-full max-w-xs">
              <button onclick="startUSB()"
                class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-semibold hover:bg-indigo-500 transition">
                USB Scanner
              </button>
              <button onclick="startCamera()"
                class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 bg-white border border-slate-200 text-slate-700 rounded-xl text-sm font-semibold hover:bg-slate-50 transition shadow-md">
                📷 Camera
              </button>
            </div>
            <span class="text-xs text-center text-slate-400">Use Camera on mobile for real-time barcode detection</span>
          </div>

          <div id="scanner-camera" class="hidden absolute inset-0 bg-slate-950">
            <video id="scan-video" class="absolute inset-0 w-full h-full object-cover" playsinline muted></video>
            <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
              <div class="absolute top-8 left-8 w-10 h-10 border-t-2 border-l-2 border-indigo-400 rounded-sm"></div>
              <div class="absolute top-8 right-8 w-10 h-10 border-t-2 border-r-2 border-indigo-400 rounded-sm"></div>
              <div class="absolute bottom-8 left-8 w-10 h-10 border-b-2 border-l-2 border-indigo-400 rounded-sm"></div>
              <div class="absolute bottom-8 right-8 w-10 h-10 border-b-2 border-r-2 border-indigo-400 rounded-sm"></div>
              <div id="scan-laser" class="absolute left-12 right-12 h-0.5 bg-red-500 shadow-[0_0_8px_2px_rgba(239,68,68,0.7)]" style="top:50%"></div>
            </div>
            <div class="absolute bottom-4 left-0 right-0 flex flex-col items-center gap-2">
              <span class="text-white/60 text-xs font-mono">● Point camera at barcode</span>
              <button onclick="stopCamera()" class="px-4 py-1.5 bg-white/20 hover:bg-white/30 text-white rounded-full text-xs font-medium backdrop-blur-sm transition">Stop Camera</button>
            </div>
          </div>

          <div id="scanner-usb" class="hidden absolute inset-0 bg-slate-950 flex flex-col items-center justify-center">
            <div class="flex gap-0.5 opacity-20 mb-4">
              <?php for ($b = 0; $b < 28; $b++): ?>
              <div class="bg-white" style="width:<?= $b % 3 === 0 ? 3 : 1.5 ?>px;height:60px"></div>
              <?php endfor; ?>
            </div>
            <span class="text-white/60 text-xs font-mono mt-2">● USB scanner ready — or type below</span>
          </div>
        </div>

        <div class="px-4 py-3 border-t border-slate-200 flex gap-2 bg-white">
          <input id="barcode-input" type="text" placeholder="Type barcode and press Enter…"
            class="flex-1 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-300"
            onkeydown="if(event.key==='Enter'){doScan(this.value.trim());this.value=''}" />
          <button onclick="const v=document.getElementById('barcode-input');doScan(v.value.trim());v.value=''"
            class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 transition">Scan</button>
        </div>
      </div>

      <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
        <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3">Quick Scan (Demo)</p>
        <div class="flex flex-col gap-1.5">
          <?php foreach (array_slice($items, 0, 5) as $item): ?>
          <button onclick="doScan('<?= htmlspecialchars($item['barcode']) ?>')"
            class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-slate-50 text-left transition group">
            <div class="flex gap-px opacity-40 group-hover:opacity-70">
              <?php for ($b = 0; $b < 10; $b++): ?>
              <div class="bg-slate-800" style="width:<?= $b % 3 === 0 ? 2 : 1 ?>px;height:14px"></div>
              <?php endfor; ?>
            </div>
            <div class="flex flex-col min-w-0">
              <span class="text-xs font-mono text-indigo-600 font-medium"><?= htmlspecialchars($item['barcode']) ?></span>
              <span class="text-xs text-slate-500 truncate"><?= htmlspecialchars($item['name']) ?></span>
            </div>
          </button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="flex flex-col gap-4">
      <div id="scan-result" class="hidden bg-white rounded-xl border border-slate-200 p-5 shadow-sm"></div>
      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm flex-1">
        <div class="px-4 py-3 border-b border-slate-200">
          <span class="text-sm font-semibold text-slate-900">Scan History</span>
        </div>
        <div id="scan-history" class="flex flex-col divide-y divide-slate-100">
          <div class="flex flex-col items-center justify-center py-12 text-slate-400 gap-2">
            <svg class="w-6 h-6 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M4 7V4h3M17 4h3v3M4 17v3h3M17 20h3v-3"/></svg>
            <span class="text-sm">No scans yet</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const INV = <?= json_encode(array_values($items)) ?>;
let scanAction = 'check-in';
let streamRef  = null;
let rafRef     = null;
let laserTimer = null;
let laserDir   = 1;
let laserPos   = 20;

function setAction(a) {
  scanAction = a;
  document.getElementById('btn-checkin').className  = 'px-4 py-2 rounded-lg text-sm font-semibold transition ' + (a==='check-in' ? 'bg-emerald-600 text-white' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50');
  document.getElementById('btn-checkout').className = 'px-4 py-2 rounded-lg text-sm font-semibold transition ' + (a==='check-out' ? 'bg-red-600 text-white' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50');
}
function animateLaser() {
  laserPos += laserDir * 1.5;
  if (laserPos > 80) laserDir = -1;
  if (laserPos < 20) laserDir = 1;
  const el = document.getElementById('scan-laser');
  if (el) el.style.top = laserPos + '%';
  laserTimer = requestAnimationFrame(animateLaser);
}
function startLaser()  { if (!laserTimer) laserTimer = requestAnimationFrame(animateLaser); }
function stopLaserAni(){ if (laserTimer) { cancelAnimationFrame(laserTimer); laserTimer = null; } }
function startUSB() {
  document.getElementById('scanner-idle').classList.add('hidden');
  document.getElementById('scanner-usb').classList.remove('hidden');
  document.getElementById('barcode-input').focus();
  startLaser();
}
async function startCamera() {
  document.getElementById('camera-error').classList.add('hidden');
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
    streamRef = stream;
    const video = document.getElementById('scan-video');
    video.srcObject = stream;
    await video.play();
    document.getElementById('scanner-idle').classList.add('hidden');
    document.getElementById('scanner-camera').classList.remove('hidden');
    startLaser();
    const BD = window.BarcodeDetector;
    if (BD) {
      const detector = new BD({ formats: ['qr_code','ean_13','ean_8','code_128','code_39','upc_a','upc_e'] });
      const tick = async () => {
        try { const results = await detector.detect(video); if (results.length > 0) { doScan(results[0].rawValue); stopCamera(); return; } } catch {}
        rafRef = requestAnimationFrame(tick);
      };
      rafRef = requestAnimationFrame(tick);
    }
  } catch(e) {
    document.getElementById('camera-error-msg').textContent = 'Camera access denied — please allow camera permission.';
    document.getElementById('camera-error').classList.remove('hidden');
  }
}
function stopCamera() {
  if (streamRef) { streamRef.getTracks().forEach(t => t.stop()); streamRef = null; }
  if (rafRef)    { cancelAnimationFrame(rafRef); rafRef = null; }
  stopLaserAni();
  document.getElementById('scanner-camera').classList.add('hidden');
  document.getElementById('scanner-usb').classList.add('hidden');
  document.getElementById('scanner-idle').classList.remove('hidden');
}
let currentItem = null, qty = 1;
function doScan(code) {
  if (!code) return;
  const item = INV.find(i => i.barcode === code || i.id === code);
  const resultEl = document.getElementById('scan-result');
  resultEl.classList.remove('hidden');
  if (!item) {
    resultEl.innerHTML = `<div class="flex items-center gap-2 text-red-600"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg><span class="text-sm">No item found for: <strong>${escHtml(code)}</strong></span></div>`;
    currentItem = null; return;
  }
  currentItem = item; qty = 1; renderResult();
}
function renderResult() {
  if (!currentItem) return;
  const item = currentItem;
  const isIn = scanAction === 'check-in';
  document.getElementById('scan-result').innerHTML = `
    <div class="flex items-center gap-2 mb-4"><svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><span class="text-sm font-semibold text-emerald-700">Item Found</span></div>
    <div class="flex flex-col gap-2 text-xs mb-4">${[['SKU',item.id],['Name',item.name],['Category',item.category],['Location',item.location],['Stock',item.qty+' units'],['Status',item.status]].map(([l,v])=>`<div class="flex justify-between border-b border-slate-100 pb-1.5 last:border-0"><span class="text-slate-500">${l}</span><span class="font-mono font-medium text-slate-900">${escHtml(String(v))}</span></div>`).join('')}</div>
    <div class="flex items-center gap-3 mb-3"><span class="text-sm text-slate-600">Quantity:</span><div class="flex items-center gap-2"><button onclick="adjQty(-1)" class="w-7 h-7 rounded border border-slate-200 bg-slate-100 flex items-center justify-center text-slate-700 hover:bg-slate-200">−</button><span id="qty-display" class="w-10 text-center font-mono font-bold text-slate-900">${qty}</span><button onclick="adjQty(1)" class="w-7 h-7 rounded border border-slate-200 bg-slate-100 flex items-center justify-center text-slate-700 hover:bg-slate-200">+</button></div></div>
    <button onclick="confirmScan()" class="w-full py-2.5 rounded-lg text-sm font-semibold text-white transition ${isIn ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-red-600 hover:bg-red-700'}">Confirm ${isIn ? 'Check In' : 'Check Out'} — ${qty} unit${qty!==1?'s':''}</button>`;
}
function adjQty(d) { qty = Math.max(1, qty + d); document.getElementById('qty-display').textContent = qty; renderResult(); }
function confirmScan() {
  if (!currentItem) return;
  const time = new Date().toLocaleTimeString('en-US', { hour:'2-digit', minute:'2-digit' });
  addHistory(currentItem, scanAction === 'check-in' ? `+${qty} Check In` : `-${qty} Check Out`, time);
  document.getElementById('scan-result').classList.add('hidden');
  currentItem = null; qty = 1;
}
function addHistory(item, action, time) {
  const hist = document.getElementById('scan-history');
  const isIn = action.startsWith('+');
  hist.innerHTML = hist.innerHTML.includes('No scans yet') ? '' : hist.innerHTML;
  const div = document.createElement('div');
  div.className = 'flex items-center gap-3 px-4 py-3';
  div.innerHTML = `<span class="font-mono text-xs text-slate-400 w-14 shrink-0">${escHtml(time)}</span><div class="px-2 py-0.5 rounded text-xs font-mono font-medium ${isIn ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}">${escHtml(action)}</div><div class="flex flex-col min-w-0"><span class="text-sm font-medium text-slate-900 truncate">${escHtml(item.name)}</span><span class="text-xs font-mono text-slate-400">${escHtml(item.barcode)}</span></div>`;
  hist.insertBefore(div, hist.firstChild);
}
function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
if ('ontouchstart' in window || navigator.maxTouchPoints > 0) { startCamera(); }
</script>

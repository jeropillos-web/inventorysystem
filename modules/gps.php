<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$user    = $_SESSION['user'];
$offline = !db_available();

if (!role_can($user['role'], 'gps')) {
    echo '<div class="rounded-xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">GPS tracking is limited to administrators.</div>';
    return;
}
?>

<div class="flex flex-col gap-6">
  <div class="flex flex-wrap items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-slate-900">GPS Delivery Tracker</h1>
      <p class="text-sm text-slate-500 mt-0.5">Live positions reported by drivers’ phones</p>
    </div>
    <span id="gps-live" class="flex items-center gap-1.5 text-xs font-mono text-slate-500 bg-slate-50 border border-slate-200 px-3 py-1.5 rounded-lg">
      <span class="w-2 h-2 rounded-full bg-slate-400 inline-block"></span> Connecting…
    </span>
  </div>

  <?php if ($offline): ?>
  <div class="rounded-xl border border-amber-300 bg-amber-50 px-5 py-4 text-sm text-amber-800">
    The database isn’t connected, so there are no positions to show. Start MySQL and import <code class="rounded bg-amber-100 px-1.5 py-0.5 font-mono">sql/invtrack.sql</code>.
  </div>
  <?php else: ?>

  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
    <?php foreach ([
      ['online',  'Reporting',  'bg-blue-100 text-blue-600'],
      ['moving',  'Moving',     'bg-indigo-100 text-indigo-600'],
      ['idle',    'Idle',       'bg-amber-100 text-amber-600'],
      ['deliveries', 'Active deliveries', 'bg-emerald-100 text-emerald-700'],
    ] as [$key, $label, $classes]): ?>
    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <div class="inline-flex rounded-lg px-2.5 py-1 font-mono text-sm font-bold <?= $classes ?>" data-stat="<?= $key ?>">–</div>
      <div class="text-sm text-slate-500 mt-2"><?= $label ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
      <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-slate-200">
        <span class="text-sm font-semibold text-slate-900">Live map</span>
        <div class="flex items-center gap-3 text-xs text-slate-500">
          <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full inline-block" style="background:#4f46e5"></span> Moving</span>
          <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full inline-block" style="background:#d97706"></span> Idle</span>
          <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full inline-block" style="background:#94a3b8"></span> Offline</span>
          <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full inline-block" style="background:#0891b2"></span> Assigned</span>
          <button type="button" id="gps-fit" class="rounded-lg border border-slate-200 px-2.5 py-1.5 font-medium text-slate-600 transition hover:bg-slate-50">Fit all</button>
        </div>
      </div>
      <div id="gps-map" style="height:460px"></div>
    </div>

    <div class="flex flex-col gap-3">
      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
        <div class="px-4 py-3 border-b border-slate-200">
          <span class="text-sm font-semibold text-slate-900">Active Deliveries</span>
          <p class="mt-0.5 text-xs text-slate-400">Shop orders and shipments drivers have accepted</p>
        </div>
        <div id="gps-deliveries"><p class="px-4 py-6 text-sm text-slate-400">Loading…</p></div>
      </div>

      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
        <div class="px-4 py-3 border-b border-slate-200">
          <span class="text-sm font-semibold text-slate-900">Fleet</span>
          <p class="mt-0.5 text-xs text-slate-400">Select a vehicle to follow it and see the last hour’s trail</p>
        </div>
        <div id="gps-fleet"><p class="px-4 py-6 text-sm text-slate-400">Loading vehicles…</p></div>
      </div>

      <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-xs leading-5 text-blue-800">
        Drivers use the <a href="driver/" class="font-semibold underline">driver app</a> on their phone. Location is shared automatically while a delivery trip is in progress. A vehicle shows as offline when it hasn’t reported for 2 minutes. ETAs are estimates from straight-line distance and current speed.
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if (!$offline): ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css" />
<style>
  /* Keep Leaflet's panes (z-index 400+) below the app's modals and header */
  #gps-map { position: relative; z-index: 0; isolation: isolate; }
  .gps-pin { position: relative; width: 36px; height: 36px; border-radius: 50%;
             background: var(--c); border: 2px solid #fff; box-shadow: 0 2px 6px rgba(15,23,42,.35);
             color: #fff; font: 700 10px/32px Inter, system-ui, sans-serif; text-align: center; }
  .gps-pin.is-selected { box-shadow: 0 0 0 4px rgba(79,70,229,.3), 0 2px 6px rgba(15,23,42,.35); }
  .gps-arrow { position: absolute; inset: -10px; pointer-events: none; }
  .gps-dest { font-size: 22px; line-height: 28px; filter: drop-shadow(0 1px 2px rgba(15,23,42,.45)); }
  .gps-arrow::before { content: ''; position: absolute; left: 50%; top: 0; transform: translateX(-50%);
                       border: 6px solid transparent; border-bottom: 8px solid var(--c); border-top: 0; }
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>
<script>
(() => {
  const POLL_MS = 5000;
  const COLORS  = { Moving: '#4f46e5', Idle: '#d97706', Offline: '#94a3b8', Assigned: '#0891b2' };

  const map = L.map('gps-map').setView([12.8797, 121.7740], 6);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
  }).addTo(map);

  const markers   = {};   
  const destDots  = {};   
  const destLines = {};   
  const trail     = L.polyline([], { color: '#4f46e5', weight: 4, opacity: 0.7 }).addTo(map);
  let vehicles = [];
  let selected = null;
  let fitted   = false;

  const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  const fmtAge = s => s == null ? 'never' : s < 60 ? `${s}s ago` : s < 3600 ? `${Math.floor(s/60)} min ago` : `${Math.floor(s/3600)} h ago`;
  const fmtEta = v => v.eta_min != null ? `~${v.eta_min} min` : (v.remaining_km != null ? `${v.remaining_km} km left` : '');

  function pinIcon(v) {
    const c = COLORS[v.status];
    const arrow = v.heading != null && v.status === 'Moving'
      ? `<span class="gps-arrow" style="transform:rotate(${v.heading}deg)"></span>` : '';
    return L.divIcon({
      className: '',
      html: `<div class="gps-pin${v.id === selected ? ' is-selected' : ''}" style="--c:${c}">${arrow}${esc(v.id)}</div>`,
      iconSize: [36, 36], iconAnchor: [18, 18], popupAnchor: [0, -18],
    });
  }

  function popupHtml(v) {
    return `<div style="font-family:Inter,system-ui,sans-serif;min-width:190px">
      <strong>${esc(v.id)}</strong> <span style="color:${COLORS[v.status]}">${esc(v.status)}</span>
      <div style="margin-top:4px">${esc(v.driver)}</div>
      <div style="color:#64748b;margin-top:3px;font-size:12px">${esc(v.route)}</div>
      <div style="margin-top:6px;font-size:12px">${v.speed_kmh ?? 0} km/h${fmtEta(v) ? ' · ' + esc(fmtEta(v)) : ''}</div>
      <div style="color:#94a3b8;font-size:11px;margin-top:2px">Updated ${fmtAge(v.age_s)}${v.accuracy_m ? ` · ±${Math.round(v.accuracy_m)} m` : ''}</div>
    </div>`;
  }

  function destLabel(v) {
    return v.delivery ? `${v.delivery.order_id} → ${v.delivery.shop}` : `${v.id} destination`;
  }

  function renderMap() {
    const seenVehicle = new Set(), seenDest = new Set();
    vehicles.forEach(v => {
      const pos = v.lat != null ? [v.lat, v.lng] : null;

      // Vehicle marker: only once the driver's phone has sent a location.
      if (pos) {
        seenVehicle.add(v.id);
        if (!markers[v.id]) {
          markers[v.id] = L.marker(pos, { icon: pinIcon(v) }).addTo(map).on('click', () => select(v.id, false));
          markers[v.id].bindPopup('');
        } else {
          markers[v.id].setLatLng(pos).setIcon(pinIcon(v));
        }
        markers[v.id].setZIndexOffset(v.status === 'Moving' ? 1000 : 0);
        markers[v.id].getPopup().setContent(popupHtml(v));
      }

      // Delivery destination: shown as soon as a driver accepts, even before any GPS arrives.
      if (v.dest) {
        seenDest.add(v.id);
        if (!destDots[v.id]) {
          destDots[v.id] = L.marker(v.dest, { icon: L.divIcon({ className: '', html: '<div class="gps-dest">🏁</div>', iconSize: [28, 28], iconAnchor: [6, 26] }) })
            .addTo(map).on('click', () => select(v.id, false));
        } else {
          destDots[v.id].setLatLng(v.dest);
        }
        destDots[v.id].unbindTooltip().bindTooltip(esc(destLabel(v)), { direction: 'top', offset: [8, -24] });
        if (pos) {
          if (!destLines[v.id]) destLines[v.id] = L.polyline([pos, v.dest], { color: '#64748b', weight: 2, dashArray: '6 6', opacity: 0.7 }).addTo(map);
          else destLines[v.id].setLatLngs([pos, v.dest]);
        } else if (destLines[v.id]) { destLines[v.id].remove(); delete destLines[v.id]; }
      }
    });

    Object.keys(markers).forEach(id => { if (!seenVehicle.has(id)) { markers[id].remove(); delete markers[id]; } });
    Object.keys(destDots).forEach(id => {
      if (seenDest.has(id)) return;
      destDots[id].remove(); delete destDots[id];
      destLines[id]?.remove(); delete destLines[id];
    });

    if (!fitted && (seenVehicle.size || seenDest.size)) { fitAll(); fitted = true; }
  }

  function renderFleet() {
    const box = document.getElementById('gps-fleet');
    if (!vehicles.length) {
      box.innerHTML = '<p class="px-4 py-6 text-sm text-slate-400">No active vehicles. Add rows to the <code>vehicles</code> table.</p>';
      return;
    }
    box.innerHTML = vehicles.map(v => `
      <button type="button" data-id="${esc(v.id)}"
        class="flex w-full items-start gap-3 border-b border-slate-100 px-4 py-3 text-left transition last:border-0 hover:bg-slate-50 ${v.id === selected ? 'bg-indigo-50' : ''}">
        <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-xs font-bold text-white" style="background:${COLORS[v.status]}">${esc(v.id)}</span>
        <span class="min-w-0 flex-1">
          <span class="flex items-center gap-2">
            <span class="text-xs font-semibold text-slate-700">${esc(v.status)}</span>
            <span class="ml-auto font-mono text-[10px] text-slate-400">${v.speed_kmh != null ? v.speed_kmh + ' km/h' : ''}</span>
          </span>
          <span class="mt-1 block truncate text-xs text-slate-500">${v.delivery ? esc(v.delivery.order_id + ' → ' + v.delivery.shop) : esc(v.route)}</span>
          <span class="mt-1 block text-xs text-slate-400">${esc(v.delivery ? v.delivery.driver : v.driver)} · ${fmtAge(v.age_s)}${fmtEta(v) ? ' · ' + esc(fmtEta(v)) : ''}</span>
        </span>
      </button>`).join('');
    box.querySelectorAll('button[data-id]').forEach(b => b.addEventListener('click', () => select(b.dataset.id, true)));
  }

  function renderDeliveries() {
    const box  = document.getElementById('gps-deliveries');
    const list = vehicles.filter(v => v.delivery);
    if (!list.length) {
      box.innerHTML = '<p class="px-4 py-6 text-sm text-slate-400">No active deliveries. They appear here when a driver accepts an order.</p>';
      return;
    }
    const fmt = s => s ? new Date(s.replace(' ', 'T')).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '';
    box.innerHTML = list.map(v => {
      const d = v.delivery, onRoad = d.status === 'In Transit';
      const gpsNote = !onRoad ? 'Waiting for driver to start the trip'
                    : v.lat == null ? 'Trip started · waiting for first GPS fix'
                    : v.status === 'Assigned' ? `GPS lost · last seen ${fmtAge(v.age_s)}`
                    : `${v.speed_kmh ?? 0} km/h${fmtEta(v) ? ' · ' + fmtEta(v) : ''}`;
      return `<button type="button" data-id="${esc(v.id)}"
        class="flex w-full flex-col gap-1 border-b border-slate-100 px-4 py-3 text-left transition last:border-0 hover:bg-slate-50 ${v.id === selected ? 'bg-indigo-50' : ''}">
        <span class="flex items-center gap-2">
          <span class="font-mono text-xs font-bold text-indigo-600">${esc(d.order_id)}</span>
          <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold ${d.kind === 'shipment' ? 'bg-orange-100 text-orange-700' : 'bg-violet-100 text-violet-700'}">${d.kind === 'shipment' ? 'Shipment' : 'Shop order'}</span>
          <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold ${onRoad ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700'}">${onRoad ? 'In transit' : 'Accepted'}</span>
          <span class="ml-auto text-[10px] text-slate-400">${esc(v.id)}</span>
        </span>
        <span class="text-sm font-semibold text-slate-900">${esc(d.shop)}</span>
        <span class="text-xs text-slate-500">${esc(d.driver)} · ${d.units} units · accepted ${esc(fmt(d.accepted_at))}</span>
        <span class="text-xs text-slate-400">${esc(gpsNote)}</span>
        ${v.dest ? '' : '<span class="text-xs text-amber-600">No map pin for this destination, so it can’t be shown on the map</span>'}
      </button>`;
    }).join('');
    box.querySelectorAll('button[data-id]').forEach(b => b.addEventListener('click', () => select(b.dataset.id, true)));
  }

  function renderStats() {
    const count = s => vehicles.filter(v => v.status === s).length;
    const stats = { moving: count('Moving'), idle: count('Idle'), deliveries: vehicles.filter(v => v.delivery).length };
    stats.online = stats.moving + stats.idle;
    Object.entries(stats).forEach(([k, n]) => {
      const el = document.querySelector(`[data-stat="${k}"]`);
      if (el) el.textContent = n;
    });
  }

  function setLive(ok) {
    const el = document.getElementById('gps-live');
    const t  = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    el.className = 'flex items-center gap-1.5 text-xs font-mono px-3 py-1.5 rounded-lg border ' +
      (ok ? 'text-emerald-600 bg-emerald-50 border-emerald-200' : 'text-red-600 bg-red-50 border-red-200');
    el.innerHTML = ok
      ? `<span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse inline-block"></span> Live · ${t}`
      : `<span class="w-2 h-2 rounded-full bg-red-500 inline-block"></span> Connection lost — retrying`;
  }

  async function loadTrail() {
    if (!selected) { trail.setLatLngs([]); return; }
    try {
      const res  = await fetch(`api/gps.php?trail=${encodeURIComponent(selected)}&minutes=60`, { cache: 'no-store' });
      const json = await res.json();
      if (json.ok) trail.setLatLngs(json.data);
    } catch {}
  }

  function select(id, pan) {
    selected = id;
    const v = vehicles.find(x => x.id === id);
    renderFleet(); renderDeliveries(); renderMap(); loadTrail();
    if (v && v.lat != null && markers[id]) {
      if (pan) map.setView([v.lat, v.lng], Math.max(map.getZoom(), 14));
      markers[id].openPopup();
    } else if (v && v.dest && destDots[id]) {
      if (pan) map.setView(v.dest, Math.max(map.getZoom(), 14));
      destDots[id].openTooltip();
    }
  }

  function fitAll() {
    const pts = [];
    vehicles.forEach(v => { if (v.lat != null) pts.push([v.lat, v.lng]); if (v.dest) pts.push(v.dest); });
    if (pts.length) map.fitBounds(pts, { padding: [48, 48], maxZoom: 15 });
  }
  document.getElementById('gps-fit').addEventListener('click', fitAll);

  async function poll() {
    try {
      const res  = await fetch('api/gps.php', { cache: 'no-store' });
      const json = await res.json();
      if (!json.ok) throw new Error(json.error);
      vehicles = json.data;
      renderStats(); renderFleet(); renderDeliveries(); renderMap();
      if (selected) loadTrail();
      setLive(true);
    } catch (e) {
      setLive(false);
    } finally {
      setTimeout(poll, document.hidden ? POLL_MS * 6 : POLL_MS);
    }
  }
  poll();
})();
</script>
<?php endif; ?>

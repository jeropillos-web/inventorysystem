<?php
require_once __DIR__ . '/_boot.php';
$user = driver_user();
if (!$user) { header('Location: index.php'); exit; }
$mapboxToken = driver_mapbox_token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f172a">
<link rel="manifest" href="manifest.webmanifest">
<title>INVTrack Driver</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css">
<link rel="stylesheet" href="style.css">
</head>
<body>
<header class="top">
  <span class="logo">IT</span>
  <div class="who"><strong><?= htmlspecialchars($user['name']) ?></strong><span>INVTrack Driver</span></div>
  <span class="gps-pill" id="gps-pill"><i></i><span>GPS off</span></span>
  <a href="logout.php">Log out</a>
</header>

<main class="app">
  <section id="view-current" hidden></section>
  <section id="view-jobs" hidden></section>
  <section id="view-history" hidden></section>
</main>

<nav class="tabs" role="tablist">
  <button class="tab" role="tab" data-view="current"><b>🚚</b>Current</button>
  <button class="tab" role="tab" data-view="jobs"><b>📦</b>Available<span class="count" id="jobs-count" hidden></span></button>
  <button class="tab" role="tab" data-view="history"><b>✓</b>History</button>
</nav>

<dialog id="dlg-accept">
  <form method="dialog" class="stack" id="form-accept">
    <div><h3>Accept delivery</h3><p class="muted small" id="accept-summary"></p></div>
    <label>Vehicle you are driving<select id="accept-vehicle" required></select></label>
    <div class="row"><button class="btn ghost" value="cancel" formnovalidate>Cancel</button><button class="btn primary" value="ok">Accept</button></div>
  </form>
</dialog>

<dialog id="dlg-deliver">
  <form method="dialog" class="stack" id="form-deliver">
    <div><h3>Confirm delivery</h3><p class="muted small">Hand over the items and ask for the recipient’s name.</p></div>
    <label>Received by<input id="deliver-recipient" maxlength="100" required placeholder="Full name"></label>
    <label>Notes (optional)<textarea id="deliver-notes" rows="2" maxlength="255" placeholder="e.g. left with the store supervisor"></textarea></label>
    <div class="row"><button class="btn ghost" value="cancel" formnovalidate>Cancel</button><button class="btn go" value="ok">Mark delivered</button></div>
  </form>
</dialog>

<dialog id="dlg-cancel">
  <form method="dialog" class="stack" id="form-cancel">
    <div><h3>Cancel delivery</h3><p class="muted small">It goes back to the queue so another driver can take it. The warehouse will see your reason.</p></div>
    <label>Reason<select id="cancel-reason" required>
      <option value="">Choose a reason…</option>
      <option>Vehicle problem</option>
      <option>Shop is closed / no one to receive</option>
      <option>Wrong or damaged items</option>
      <option>Address can’t be found</option>
      <option>Emergency</option>
      <option value="other">Other</option>
    </select></label>
    <label id="cancel-other-wrap" hidden>Details<textarea id="cancel-other" rows="2" maxlength="200" placeholder="What happened?"></textarea></label>
    <div class="row"><button class="btn ghost" value="back" formnovalidate>Keep delivery</button><button class="btn danger" value="ok">Cancel delivery</button></div>
  </form>
</dialog>

<div class="toast" id="toast" role="status"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>
<script>
(() => {
  const MAPBOX_TOKEN = <?= json_encode($mapboxToken) ?>;
  const GPS_EVERY_MS = 5000;
  const $ = s => document.querySelector(s);
  const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  let state = null, view = null, pendingOrder = null;
  const kindBadge = k => k === 'shipment' ? '<span class="badge kind-ship">Shipment</span>' : '<span class="badge kind-order">Shop order</span>';
  const jobKey = j => j.kind + ':' + j.job_id;

  function toast(msg, isErr) {
    const t = $('#toast'); t.textContent = msg; t.className = 'toast show' + (isErr ? ' err' : '');
    clearTimeout(toast.t); toast.t = setTimeout(() => t.className = 'toast', 3500);
  }
  async function api(action, body) {
    const res = await fetch('api.php?action=' + action, body
      ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
      : { cache: 'no-store' });
    if (res.status === 401) { location.href = 'index.php'; throw new Error('Signed out'); }
    const json = await res.json();
    if (!json.ok) throw new Error(json.error);
    return json.data;
  }
  function km(a, b) {
    const r = Math.PI / 180, dLat = (b[0]-a[0])*r, dLng = (b[1]-a[1])*r;
    const h = Math.sin(dLat/2)**2 + Math.cos(a[0]*r)*Math.cos(b[0]*r)*Math.sin(dLng/2)**2;
    return 12742 * Math.asin(Math.min(1, Math.sqrt(h)));
  }
  const fmtTime = s => s ? new Date(s.replace(' ', 'T')).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : '';

  async function refresh() {
    try {
      state = await api('state');
      const n = state.available.length;
      $('#jobs-count').hidden = !n; $('#jobs-count').textContent = n;
      if (!view) show(state.active ? 'current' : 'jobs'); else render();
      syncGps();
    } catch (e) { if (e.message !== 'Signed out') toast(e.message, true); }
  }

  function show(v) {
    view = v;
    document.querySelectorAll('.tab').forEach(t => t.setAttribute('aria-selected', t.dataset.view === v));
    ['current','jobs','history'].forEach(k => $('#view-' + k).hidden = k !== v);
    render();
  }
  document.querySelectorAll('.tab').forEach(t => t.addEventListener('click', () => show(t.dataset.view)));

  function render() {
    if (!state) return;
    if (view === 'jobs') renderJobs();
    if (view === 'history') renderHistory();
    if (view === 'current') renderCurrent();
  }

  function renderJobs() {
    const el = $('#view-jobs');
    const blocked = state.active ? `<p class="note">You have an unfinished delivery (${esc(state.active.job_id)}). Finish it before accepting another.</p>` : '';
    if (!state.available.length) { el.innerHTML = `<h2>Available deliveries</h2>${blocked}<div class="empty">No shop orders or approved shipments are waiting right now.<br>This list refreshes automatically.</div>`; return; }
    el.innerHTML = `<h2>Available deliveries</h2>${blocked}` + state.available.map(o => `
      <article class="card">
        <div class="order-head"><span class="order-id">${esc(o.job_id)} ${kindBadge(o.kind)}</span><span class="muted small">${o.due ? 'Due ' + esc(o.due) : esc(fmtTime(o.ready_at))}</span></div>
        <div class="order-shop">${esc(o.title)}</div>
        <div class="meta"><span>📍 ${esc(o.address || 'No address on file')}</span></div>
        <div class="order-items">${esc(o.items)} · <b>${Number(o.units)}</b> units</div>
        <button class="btn primary big" data-accept="${esc(jobKey(o))}" ${state.active ? 'disabled' : ''}>Accept delivery</button>
      </article>`).join('');
    el.querySelectorAll('[data-accept]').forEach(b => b.addEventListener('click', () => openAccept(b.dataset.accept)));
  }

  function renderHistory() {
    const el = $('#view-history');
    if (!state.history.length) { el.innerHTML = '<h2>History</h2><div class="empty">Deliveries you complete or cancel will appear here.</div>'; return; }
    el.innerHTML = '<h2>History</h2>' + state.history.map(h => {
      const done = h.outcome === 'Delivered';
      return `<article class="card">
        <div class="order-head"><span class="order-id">${esc(h.job_id)} ${kindBadge(h.kind)}</span><span class="badge ${done ? 'done' : 'cancelled'}">${done ? 'Delivered' : 'Cancelled'}</span></div>
        <div class="order-shop">${esc(h.title)}</div>
        <div class="meta"><span>${esc(fmtTime(h.at))}</span>${done ? `<span>Received by ${esc(h.recipient_name)}</span>` : `<span>Reason: ${esc(h.reason)}</span>`}<span>${esc(h.vehicle_id)}</span><span>${Number(h.units)} units</span></div>
      </article>`;
    }).join('');
  }

  let map = null, meMarker = null, destMarker = null;

  function renderCurrent() {
    const el = $('#view-current'), d = state.active;
    if (!d) { destroyMap(); el.innerHTML = '<h2>Current delivery</h2><div class="empty">You have no active delivery.<br><button class="btn primary" id="go-jobs" style="margin-top:12px">See available orders</button></div>'; $('#go-jobs').onclick = () => show('jobs'); return; }

    const transit = d.status === 'In Transit';
    const hasDest = d.dest_lat != null;
    const navUrl = hasDest
      ? `https://www.google.com/maps/dir/?api=1&destination=${d.dest_lat},${d.dest_lng}&travelmode=driving`
      : `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(d.address || d.title)}`;

    if (!el.querySelector('.active') || el.dataset.key !== d.id + d.status) {
      destroyMap();
      el.dataset.key = d.id + d.status;
      el.innerHTML = `<h2>Current delivery</h2>
        <article class="card active">
          <div class="order-head"><span class="order-id">${esc(d.job_id)} ${kindBadge(d.kind)}</span>
            <span class="badge transit">In transit</span></div>
          <div class="order-shop">${esc(d.title)}</div>
          <div class="meta"><span>📍 ${esc(d.address || 'No address on file')}</span><span>🚚 ${esc(d.vehicle_id)}</span></div>
          <div class="order-items">${esc(d.items)} · <b>${Number(d.units)}</b> units</div>
          <div id="mini-map"></div>
          <div class="stats">
            <div><b id="st-dist">–</b><span>km to go</span></div>
            <div><b id="st-speed">–</b><span>km/h</span></div>
            <div><b id="st-sent">–</b><span>last GPS</span></div>
          </div>
          <div class="stack">
            <button class="btn go big" id="btn-deliver">Mark as delivered</button>
            <div class="row">
              <a class="btn" href="${navUrl}" target="_blank" rel="noopener">Open navigation</a>
              <button class="btn danger-outline" id="btn-cancel">Cancel delivery</button>
            </div>
            <p class="muted small">Keep this screen open while driving so the warehouse can see your location.</p>
          </div>
        </article>`;
      $('#btn-deliver').addEventListener('click', () => { $('#deliver-recipient').value = ''; $('#deliver-notes').value = ''; $('#dlg-deliver').showModal(); });
      $('#btn-cancel').addEventListener('click', () => { $('#cancel-reason').value = ''; $('#cancel-other').value = ''; $('#cancel-other-wrap').hidden = true; $('#dlg-cancel').showModal(); });
      buildMap(d);
    } else if (map) {
      map.invalidateSize();
    }
    updateStats();
  }

  function buildMap(d) {
    map = L.map('mini-map', { zoomControl: false, attributionControl: true }).setView([12.8797, 121.774], 6);
    if (MAPBOX_TOKEN) {
      L.tileLayer(`https://api.mapbox.com/styles/v1/mapbox/streets-v12/tiles/512/{z}/{x}/{y}@2x?access_token=${MAPBOX_TOKEN}`,
        { tileSize: 512, zoomOffset: -1, maxZoom: 20, attribution: '&copy; Mapbox &copy; OpenStreetMap' }).addTo(map);
    } else {
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
    }
    if (d.dest_lat != null) {
      destMarker = L.circleMarker([+d.dest_lat, +d.dest_lng], { radius: 9, color: '#fff', weight: 3, fillColor: '#dc2626', fillOpacity: 1 })
        .addTo(map).bindTooltip(d.title);
      map.setView(destMarker.getLatLng(), 14);
    }
    if (gps.last) placeMe();
  }
  function destroyMap() { if (map) { map.remove(); map = meMarker = destMarker = null; } }
  function placeMe() {
    if (!map || !gps.last) return;
    const p = [gps.last.coords.latitude, gps.last.coords.longitude];
    if (!meMarker) {
      meMarker = L.circleMarker(p, { radius: 9, color: '#fff', weight: 3, fillColor: '#2563eb', fillOpacity: 1 }).addTo(map).bindTooltip('You');
      map.fitBounds(destMarker ? [p, destMarker.getLatLng()] : [p, p], { padding: [30, 30], maxZoom: 15 });
    } else meMarker.setLatLng(p);
  }
  function updateStats() {
    const d = state?.active; if (!d || !$('#st-dist')) return;
    if (gps.last && d.dest_lat != null) $('#st-dist').textContent = km([gps.last.coords.latitude, gps.last.coords.longitude], [+d.dest_lat, +d.dest_lng]).toFixed(1);
    if (gps.speedKmh != null) $('#st-speed').textContent = Math.round(gps.speedKmh);
    if (gps.sentAt) $('#st-sent').textContent = new Date(gps.sentAt).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }

  function openAccept(orderId) {
    const o = state.available.find(x => jobKey(x) === orderId); if (!o) return;
    if (!state.vehicles.length) { toast('No free vehicles right now. Ask the warehouse.', true); return; }
    pendingOrder = o;
    $('#accept-summary').textContent = `${o.kind === 'shipment' ? 'Shipment' : 'Shop order'} ${o.job_id} · ${o.title} · ${o.units} units`;
    let saved = null; try { saved = localStorage.getItem('invtrack.vehicle'); } catch {}
    $('#accept-vehicle').innerHTML = state.vehicles.map(v =>
      `<option value="${esc(v.id)}" ${v.id === saved ? 'selected' : ''}>${esc(v.id)}${v.plate ? ' · ' + esc(v.plate) : ''}</option>`).join('');
    $('#dlg-accept').showModal();
  }
  $('#dlg-accept').addEventListener('close', async () => {
    if ($('#dlg-accept').returnValue !== 'ok' || !pendingOrder) return;
    const vehicle = $('#accept-vehicle').value;
    try { localStorage.setItem('invtrack.vehicle', vehicle); } catch {}
    try {
      await api('accept', { kind: pendingOrder.kind, job_id: pendingOrder.job_id, vehicle_id: vehicle });
      toast(`${pendingOrder.job_id} accepted. Trip started — sharing your location.`);
      view = 'current'; await refresh(); show('current');
    } catch (e) { toast(e.message, true); refresh(); }
  });

  $('#cancel-reason').addEventListener('change', e => {
    const other = e.target.value === 'other';
    $('#cancel-other-wrap').hidden = !other; $('#cancel-other').required = other;
  });
  $('#dlg-cancel').addEventListener('close', async () => {
    if ($('#dlg-cancel').returnValue !== 'ok') return;
    const choice = $('#cancel-reason').value;
    const reason = choice === 'other' ? $('#cancel-other').value.trim() : choice;
    try {
      await api('cancel', { reason });
      toast('Delivery cancelled and returned to the queue.');
      await refresh(); show('jobs');
    } catch (e) { toast(e.message, true); }
  });
  $('#dlg-deliver').addEventListener('close', async () => {
    if ($('#dlg-deliver').returnValue !== 'ok') return;
    try {
      await api('deliver', { recipient: $('#deliver-recipient').value, notes: $('#deliver-notes').value });
      toast('Delivery completed. Nice work!');
      await refresh(); show('history');
    } catch (e) { toast(e.message, true); }
  });

  const gps = { watch: null, timer: null, last: null, sentAt: 0, speedKmh: null, busy: false, wake: null };

  function setPill(on, text) { $('#gps-pill').className = 'gps-pill' + (on ? ' on' : ''); $('#gps-pill span').textContent = text; }

  function syncGps() {
    const shouldShare = state?.active?.status === 'In Transit';
    if (shouldShare && gps.watch === null) startGps();
    if (!shouldShare && gps.watch !== null) stopGps();
  }
  function startGps() {
    if (!window.isSecureContext || !navigator.geolocation) { setPill(false, 'Needs HTTPS'); toast('Location needs an https:// address.', true); return; }
    setPill(true, 'Finding GPS…');
    gps.watch = navigator.geolocation.watchPosition(p => { gps.last = p; placeMe(); updateStats(); if (!gps.sentAt) sendGps(); },
      err => { setPill(false, err.code === 1 ? 'GPS blocked' : 'No GPS'); if (err.code === 1) toast('Allow location access in your browser settings.', true); },
      { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 });
    gps.timer = setInterval(sendGps, GPS_EVERY_MS);
    keepAwake();
  }
  function stopGps() {
    navigator.geolocation.clearWatch(gps.watch); clearInterval(gps.timer);
    gps.watch = null; gps.sentAt = 0; gps.speedKmh = null;
    gps.wake?.release().catch(() => {}); gps.wake = null;
    setPill(false, 'GPS off');
  }
  async function sendGps() {
    const d = state?.active; if (!gps.last || gps.busy || !d) return;
    if (Date.now() - gps.last.timestamp > 60000) { setPill(true, 'Weak signal'); return; }
    const c = gps.last.coords; gps.busy = true;
    try {
      const res = await fetch('../api/gps.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ vehicle_id: d.vehicle_id, lat: c.latitude, lng: c.longitude, accuracy: c.accuracy,
          speed: Number.isFinite(c.speed) ? c.speed : null, heading: Number.isFinite(c.heading) ? c.heading : null }) });
      const json = await res.json();
      if (!json.ok) throw new Error(json.error);
      if (json.data.stored) { gps.sentAt = Date.now(); gps.speedKmh = json.data.speed_kmh; setPill(true, 'Sharing'); }
      else if (json.data.reason === 'low_accuracy') setPill(true, 'Weak signal');
      updateStats();
    } catch { setPill(true, 'Retrying…'); }
    finally { gps.busy = false; }
  }
  async function keepAwake() {
    try { if ('wakeLock' in navigator && !gps.wake) { gps.wake = await navigator.wakeLock.request('screen'); gps.wake.addEventListener('release', () => gps.wake = null); } } catch {}
  }
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') { refresh(); if (gps.watch !== null) { keepAwake(); sendGps(); } }
  });

  refresh();
  setInterval(() => { if (document.visibilityState === 'visible') refresh(); }, 15000);
})();
</script>
</body>
</html>

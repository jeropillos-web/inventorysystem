<?php
require_once __DIR__ . '/_boot.php';
$user = driver_user();
if (!$user) { header('Location: index.php'); exit; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1b3429">
<link rel="icon" type="image/png" href="../assets/pcs-icon.png">
<link rel="apple-touch-icon" href="../assets/pcs-icon.png">
<link rel="manifest" href="manifest.webmanifest">
<title>INVTrack Delivery</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<header class="top">
  <img class="logo" src="../assets/pcs-icon.png" alt="PCS Computers">
  <div class="who"><strong><?= htmlspecialchars($user['name']) ?></strong><span>INVTrack Delivery</span></div>
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

<dialog id="dlg-update">
  <form method="dialog" class="stack" id="form-update">
    <div><h3>Post a tracking update</h3><p class="muted small">It is added to the tracking log that the office sees.</p></div>
    <label>Update<select id="update-status" required>
      <option>In Transit</option>
      <option>Arrived at checkpoint</option>
      <option>Delayed</option>
      <option>Note</option>
    </select></label>
    <label>Where are you?<input id="update-location" maxlength="150" placeholder="e.g. Pili, Camarines Sur"></label>
    <label>Remarks<textarea id="update-remarks" rows="2" maxlength="250" placeholder="e.g. Heavy traffic on Maharlika Highway"></textarea></label>
    <div class="row"><button class="btn ghost" value="cancel" formnovalidate>Cancel</button><button class="btn primary" value="ok">Post update</button></div>
  </form>
</dialog>

<div class="toast" id="toast" role="status"></div>

<script>
(() => {
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
  const fmtTime = s => s ? new Date(s.replace(' ', 'T')).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : '';

  async function refresh() {
    try {
      state = await api('state');
      const n = state.available.length;
      $('#jobs-count').hidden = !n; $('#jobs-count').textContent = n;
      if (!view) show(state.active ? 'current' : 'jobs'); else render();
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

  function renderCurrent() {
    const el = $('#view-current'), d = state.active;
    if (!d) { el.innerHTML = '<h2>Current delivery</h2><div class="empty">You have no active delivery.<br><button class="btn primary" id="go-jobs" style="margin-top:12px">See available deliveries</button></div>'; $('#go-jobs').onclick = () => show('jobs'); return; }

    const dirUrl = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(d.address || d.title)}`;
    const logs = (d.logs || []).map((l, i) => `<li class="${i === 0 ? 'now' : ''}"><b>${esc(l.status)}</b> <span class="muted small">${esc(fmtTime(l.logged_at))}</span>
        <div class="small">${esc(l.location)}${l.location && l.remarks ? ' — ' : ''}${esc(l.remarks)}</div></li>`).join('');
    el.innerHTML = `<h2>Current delivery</h2>
      <article class="card active">
        <div class="order-head"><span class="order-id">${esc(d.job_id)} ${kindBadge(d.kind)}</span><span class="badge transit">Out for delivery</span></div>
        <div class="order-shop">${esc(d.title)}</div>
        <div class="meta"><span>📍 ${esc(d.address || 'No address on file')}</span><span>🚚 ${esc(d.vehicle_id)}</span></div>
        <div class="order-items">${esc(d.items)} · <b>${Number(d.units)}</b> units</div>
        <div class="stack">
          <button class="btn primary big" id="btn-update">Post tracking update</button>
          <button class="btn go big" id="btn-deliver">Mark as delivered</button>
          <div class="row">
            <a class="btn" href="${dirUrl}" target="_blank" rel="noopener">Directions</a>
            <button class="btn danger-outline" id="btn-cancel">Cancel delivery</button>
          </div>
        </div>
        <h3 class="log-title">Tracking log · ${esc(d.job_id)}</h3>
        <ol class="log">${logs || '<li class="muted small">No updates yet.</li>'}</ol>
      </article>`;
    $('#btn-update').addEventListener('click', () => { $('#update-status').value = 'In Transit'; $('#update-location').value = ''; $('#update-remarks').value = ''; $('#dlg-update').showModal(); });
    $('#btn-deliver').addEventListener('click', () => { $('#deliver-recipient').value = ''; $('#deliver-notes').value = ''; $('#dlg-deliver').showModal(); });
    $('#btn-cancel').addEventListener('click', () => { $('#cancel-reason').value = ''; $('#cancel-other').value = ''; $('#cancel-other-wrap').hidden = true; $('#dlg-cancel').showModal(); });
  }

  function openAccept(orderId) {
    const o = state.available.find(x => jobKey(x) === orderId); if (!o) return;
    if (!state.vehicles.length) { toast('No free vehicles right now. Ask the warehouse.', true); return; }
    pendingOrder = o;
    $('#accept-summary').textContent = `${o.kind === 'shipment' ? 'Shipment' : 'Shop order'} ${o.job_id} · ${o.title} · ${o.units} units`;
    let saved = null; try { saved = localStorage.getItem('invtrack.vehicle'); } catch {}
    $('#accept-vehicle').innerHTML = state.vehicles.map(v =>
      `<option value="${esc(v.id)}" ${v.id === saved ? 'selected' : ''}>${esc(v.id)}${v.type ? ' · ' + esc(v.type) : ''}${v.plate ? ' · ' + esc(v.plate) : ''}</option>`).join('');
    $('#dlg-accept').showModal();
  }
  $('#dlg-accept').addEventListener('close', async () => {
    if ($('#dlg-accept').returnValue !== 'ok' || !pendingOrder) return;
    const vehicle = $('#accept-vehicle').value;
    try { localStorage.setItem('invtrack.vehicle', vehicle); } catch {}
    try {
      await api('accept', { kind: pendingOrder.kind, job_id: pendingOrder.job_id, vehicle_id: vehicle });
      toast(`${pendingOrder.job_id} accepted. Post updates as you go.`);
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
  $('#dlg-update').addEventListener('close', async () => {
    if ($('#dlg-update').returnValue !== 'ok') return;
    try {
      await api('update', { status: $('#update-status').value, location: $('#update-location').value, remarks: $('#update-remarks').value });
      toast('Update posted to the tracking log.');
      await refresh();
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

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') refresh();
  });

  refresh();
  setInterval(() => { if (document.visibilityState === 'visible') refresh(); }, 15000);
})();
</script>
</body>
</html>

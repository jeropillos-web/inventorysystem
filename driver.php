<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

$user = require_auth('index.php');
if (!role_can($user['role'], 'gps_ping')) {
    http_response_code(403);
    exit('Your account is not allowed to share vehicle locations.');
}
$vehicles = db_available()
    ? db()->query('SELECT id, driver_name, route_label FROM vehicles WHERE active = 1 ORDER BY id')->fetchAll()
    : [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#4f46e5">
<title>INVTrack Driver</title>
<style>
  :root { --ink:#0f172a; --muted:#64748b; --line:#e2e8f0; --brand:#4f46e5; --ok:#059669; --bad:#dc2626; }
  * { box-sizing: border-box; }
  body { margin:0; font:16px/1.45 Inter, system-ui, -apple-system, sans-serif; color:var(--ink); background:#f8fafc;
         padding: env(safe-area-inset-top) 16px env(safe-area-inset-bottom); }
  main { max-width: 440px; margin: 0 auto; padding: 20px 0 32px; display:flex; flex-direction:column; gap:16px; }
  h1 { font-size: 20px; margin: 0; }
  .sub { color: var(--muted); font-size: 14px; margin: 2px 0 0; }
  .card { background:#fff; border:1px solid var(--line); border-radius:14px; padding:16px; }
  label { display:block; font-size:13px; font-weight:600; color:var(--muted); margin-bottom:6px; }
  select { width:100%; font:inherit; padding:12px; border:1px solid var(--line); border-radius:10px; background:#fff; }
  #toggle { width:100%; font:inherit; font-size:18px; font-weight:700; padding:20px; border:0; border-radius:14px; color:#fff; background:var(--brand); cursor:pointer; }
  #toggle.on { background: var(--bad); }
  #toggle:disabled { opacity:.5; }
  #toggle:focus-visible, select:focus-visible { outline: 3px solid #a5b4fc; outline-offset: 2px; }
  dl { display:grid; grid-template-columns: auto 1fr; gap:8px 12px; margin:0; font-size:14px; }
  dt { color: var(--muted); }
  dd { margin:0; text-align:right; font-variant-numeric: tabular-nums; }
  #state { display:flex; align-items:center; gap:8px; font-weight:600; }
  #dot { width:10px; height:10px; border-radius:50%; background:#94a3b8; }
  #dot.on { background: var(--ok); }
  #msg { font-size:14px; margin:0; }
  #msg.err { color: var(--bad); }
  .note { font-size:13px; color: var(--muted); margin:0; }
</style>
</head>
<body>
<main>
  <div>
    <h1>Share location</h1>
    <p class="sub">Signed in as <?= htmlspecialchars($user['name'] ?? $user['email']) ?></p>
  </div>

  <?php if (!$vehicles): ?>
  <div class="card"><p id="msg" class="err">No vehicles are set up, or the database is offline. Ask an admin to add your vehicle.</p></div>
  <?php else: ?>
  <div class="card">
    <label for="vehicle">Vehicle</label>
    <select id="vehicle">
      <?php foreach ($vehicles as $v): ?>
      <option value="<?= htmlspecialchars($v['id']) ?>"><?= htmlspecialchars("{$v['id']} — {$v['driver_name']}") ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <button id="toggle" type="button">Start sharing</button>

  <div class="card">
    <div id="state"><span id="dot"></span><span id="state-text">Not sharing</span></div>
    <dl style="margin-top:12px">
      <dt>Accuracy</dt><dd id="acc">–</dd>
      <dt>Speed</dt><dd id="spd">–</dd>
      <dt>Last sent</dt><dd id="sent">–</dd>
      <dt>Updates sent</dt><dd id="count">0</dd>
    </dl>
    <p id="msg" style="margin-top:12px"></p>
  </div>

  <p class="note">Keep this page open with the screen on while driving. Phones pause location in web pages when the screen locks or you switch apps.</p>
  <?php endif; ?>
</main>

<?php if ($vehicles): ?>
<script>
(() => {
  const SEND_MS = 5000;           
  const MAX_FIX_AGE_MS = 60000;   

  const $ = id => document.getElementById(id);
  const sel = $('vehicle'), btn = $('toggle');
  let watchId = null, latest = null, lastSent = 0, inflight = false, count = 0, heartbeat = null, wakeLock = null;

  try { const saved = localStorage.getItem('invtrack.vehicle'); if (saved) sel.value = saved; } catch {}
  sel.addEventListener('change', () => { try { localStorage.setItem('invtrack.vehicle', sel.value); } catch {} });

  function message(text, isErr) { $('msg').textContent = text || ''; $('msg').className = isErr ? 'err' : ''; }
  function setState(on, text) { $('dot').className = on ? 'on' : ''; $('state-text').textContent = text; }

  if (!window.isSecureContext || !navigator.geolocation) {
    btn.disabled = true;
    message('Location needs a secure (https://) connection. Open this page over HTTPS.', true);
    return;
  }

  async function send(force) {
    if (!latest || inflight) return;
    const now = Date.now();
    if (!force && now - lastSent < SEND_MS) return;
    if (now - latest.timestamp > MAX_FIX_AGE_MS) { setState(true, 'Waiting for GPS…'); return; }

    const c = latest.coords;
    inflight = true;
    try {
      const res = await fetch('api/gps.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          vehicle_id: sel.value,
          lat: c.latitude, lng: c.longitude, accuracy: c.accuracy,
          speed: Number.isFinite(c.speed) ? c.speed : null,
          heading: Number.isFinite(c.heading) ? c.heading : null,
        }),
      });
      if (res.status === 401) { stop(); message('You were signed out. Sign in again and restart sharing.', true); return; }
      const json = await res.json();
      if (!json.ok) throw new Error(json.error);
      lastSent = now;
      if (json.data.stored) {
        count++;
        $('count').textContent = count;
        $('sent').textContent = new Date().toLocaleTimeString();
        $('spd').textContent = Math.round(json.data.speed_kmh) + ' km/h';
        setState(true, 'Sharing location');
        message('');
      } else if (json.data.reason === 'low_accuracy') {
        setState(true, 'Weak GPS signal');
        message('Waiting for a more accurate fix. Move away from buildings if you can.');
      }
    } catch (e) {
      setState(true, 'Retrying…');
      message('Couldn’t reach the server: ' + e.message + '. Will retry.', true);
    } finally {
      inflight = false;
    }
  }

  async function keepAwake() {
    try { if ('wakeLock' in navigator && !wakeLock) { wakeLock = await navigator.wakeLock.request('screen'); wakeLock.addEventListener('release', () => wakeLock = null); } } catch {}
  }

  function start() {
    setState(true, 'Waiting for GPS…');
    message('');
    watchId = navigator.geolocation.watchPosition(pos => {
      latest = pos;
      $('acc').textContent = '±' + Math.round(pos.coords.accuracy) + ' m';
      send(lastSent === 0);
    }, err => {
      const why = { 1: 'Location permission was denied. Allow it in your browser settings.', 2: 'Location is unavailable. Turn on GPS.', 3: 'GPS is taking too long. Still trying…' }[err.code];
      message(why || err.message, true);
      if (err.code === 1) stop();
    }, { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 });

    heartbeat = setInterval(() => send(false), SEND_MS);
    keepAwake();
    sel.disabled = true;
    btn.textContent = 'Stop sharing';
    btn.classList.add('on');
  }

  function stop() {
    if (watchId !== null) navigator.geolocation.clearWatch(watchId);
    clearInterval(heartbeat);
    watchId = null; latest = null; lastSent = 0;
    wakeLock?.release().catch(() => {});
    sel.disabled = false;
    btn.textContent = 'Start sharing';
    btn.classList.remove('on');
    setState(false, 'Not sharing');
  }

  btn.addEventListener('click', () => watchId === null ? start() : stop());
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && watchId !== null) { keepAwake(); send(true); }
  });
})();
</script>
<?php endif; ?>
</body>
</html>

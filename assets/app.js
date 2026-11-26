// Shared browser helpers for INVTrack modules.

// Calls an INVTrack API and returns its data, or throws an Error with the server's message.
async function api(url, method = 'GET', body = null) {
  const opts = { method, headers: { 'Accept': 'application/json' } };
  if (body !== null) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
  let res;
  try { res = await fetch(url, opts); }
  catch (e) { throw new Error('Cannot reach the server. Check that Apache is running.'); }
  if (res.status === 401) { location.href = 'index.php'; throw new Error('Signed out'); }
  let json;
  try { json = await res.json(); }
  catch (e) { throw new Error('The server returned an unexpected response (HTTP ' + res.status + ').'); }
  if (!json.ok) throw new Error(json.error || 'The request failed.');
  return json.data;
}

// Runs an API call, then reloads the page; shows the error otherwise.
async function act(url, method = 'POST', body = {}, confirmText = '') {
  if (confirmText && !confirm(confirmText)) return false;
  try { await api(url, method, body); location.reload(); return true; }
  catch (e) { if (e.message !== 'Signed out') alert(e.message); return false; }
}

// Asks for a required reason, then runs the call. Returns false when cancelled.
async function actWithReason(url, promptText, field = 'note', extra = {}) {
  const reason = prompt(promptText);
  if (reason === null) return false;
  if (!reason.trim()) { alert('Please enter a reason.'); return false; }
  return act(url, 'POST', Object.assign({ [field]: reason.trim() }, extra));
}

function openModal(id) { const m = document.getElementById(id); if (m) m.classList.remove('hidden'); }
function closeModal(id) { const m = document.getElementById(id); if (m) m.classList.add('hidden'); }

// Form fields to a plain object. Number inputs become numbers.
function formJSON(form) {
  const out = {};
  for (const el of form.elements) {
    if (!el.name || el.disabled) continue;
    if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) continue;
    out[el.name] = el.type === 'number' ? (el.value === '' ? null : Number(el.value)) : el.value;
  }
  return out;
}

// Submits a form to an API and reloads on success; shows the error under the form otherwise.
function bindForm(formId, url, method = 'POST', transform = null) {
  const form = document.getElementById(formId);
  if (!form) return;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = form.querySelector('[data-error]');
    const btn = form.querySelector('[type=submit]');
    if (err) { err.textContent = ''; err.classList.add('hidden'); }
    if (btn) btn.disabled = true;
    try {
      let body = formJSON(form);
      if (transform) body = transform(body, form);
      await api(typeof url === 'function' ? url(form) : url, typeof method === 'function' ? method(form) : method, body);
      location.reload();
    } catch (ex) {
      if (ex.message === 'Signed out') return;
      if (err) { err.textContent = ex.message; err.classList.remove('hidden'); } else alert(ex.message);
      if (btn) btn.disabled = false;
    }
  });
}

document.addEventListener('click', (e) => {
  // Close a modal when its dark background is clicked.
  if (e.target.matches && e.target.matches('[data-modal]')) e.target.classList.add('hidden');
  // Close the task menu when clicking elsewhere.
  const menu = document.getElementById('task-menu');
  if (menu && !menu.classList.contains('hidden') && !e.target.closest('#task-menu') && !e.target.closest('[onclick*="task-menu"]')) menu.classList.add('hidden');
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') document.querySelectorAll('[data-modal]').forEach(m => m.classList.add('hidden'));
});

function esc(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

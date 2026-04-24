/* =========================================================
 * UniBite – Κοινές utilities για όλα τα pages
 * ========================================================= */
(function () {
  'use strict';

  // Υπολόγισε path προς τα API ανάλογα με το που βρισκόμαστε (index ή /pages/*)
  // Ο φάκελος backend/ είναι αδερφός του frontend/, οπότε ανεβαίνουμε ένα
  // επίπεδο από το index.html και δύο από το frontend/pages/*.html.
  const isInPagesDir = location.pathname.includes('/pages/');
  const API_BASE = (isInPagesDir ? '../../' : '../') + 'backend/api';

  const CAMPUS = { lat: 38.288, lng: 21.790 }; // Πανεπιστημιούπολη Πάτρας (Ρίο)

  // -------- Fetch wrapper ----------------------------------------------------
  async function api(endpoint, { method = 'GET', body = null, qs = null } = {}) {
    let url = `${API_BASE}/${endpoint}`;
    if (qs) {
      const p = new URLSearchParams(qs);
      url += (url.includes('?') ? '&' : '?') + p.toString();
    }
    const opts = {
      method,
      credentials: 'same-origin',
      headers: {},
    };
    if (body !== null) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    let res;
    try {
      res = await fetch(url, opts);
    } catch (e) {
      throw new Error('Αποτυχία σύνδεσης με τον server');
    }
    let data = null;
    const ct = res.headers.get('content-type') || '';
    if (ct.includes('application/json')) {
      try { data = await res.json(); } catch (_) { data = null; }
    }
    if (!res.ok || (data && data.ok === false)) {
      const msg = (data && data.error) || `Σφάλμα ${res.status}`;
      const err = new Error(msg);
      err.status = res.status;
      err.data   = data;
      throw err;
    }
    return data || { ok: true };
  }

  // -------- Auth helpers -----------------------------------------------------
  let cachedUser = null;

  async function getMe() {
    if (cachedUser) return cachedUser;
    try {
      const r = await api('auth.php', { qs: { action: 'me' } });
      cachedUser = r.user;
      return cachedUser;
    } catch (_) {
      return null;
    }
  }

  async function requireAuthOptional() { return getMe(); }

  async function requireAuth(redirect = 'login.html') {
    const u = await getMe();
    if (!u) { window.location.href = (isInPagesDir ? '' : 'pages/') + redirect; return null; }
    return u;
  }

  async function requireAdmin() {
    const u = await requireAuth();
    if (u && u.role !== 'admin') {
      toast('Απαιτούνται δικαιώματα διαχειριστή', 'error');
      window.location.href = isInPagesDir ? 'feed.html' : 'pages/feed.html';
      return null;
    }
    return u;
  }

  async function logout() {
    try { await api('auth.php', { method: 'POST', qs: { action: 'logout' } }); } catch (_) {}
    cachedUser = null;
    window.location.href = isInPagesDir ? '../index.html' : 'index.html';
  }

  // -------- UI helpers -------------------------------------------------------
  function toast(msg, type = 'info', ms = 3500) {
    const box = document.getElementById('toast-container') || (() => {
      const d = document.createElement('div'); d.id = 'toast-container'; document.body.appendChild(d); return d;
    })();
    const t = document.createElement('div');
    t.className = 'toast ' + (type === 'error' ? 'error' : type === 'success' ? 'success' : '');
    t.textContent = msg;
    box.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity .25s'; }, ms - 250);
    setTimeout(() => { t.remove(); }, ms);
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function formatDT(iso) {
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleString('el-GR', {
      weekday: 'short', day: '2-digit', month: '2-digit',
      hour: '2-digit', minute: '2-digit',
    });
  }

  function formatTimeShort(iso) {
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleTimeString('el-GR', { hour: '2-digit', minute: '2-digit' });
  }

  function stateBadge(state) {
    if (state === 'active')   return `<span class="chip chip-success">Ενεργή</span>`;
    if (state === 'inactive') return `<span class="chip chip-warn">Εξαντλημένη</span>`;
    return `<span class="chip chip-muted">Ληγμένη</span>`;
  }

  function requestStatusBadge(status) {
    const map = {
      pending:   ['chip-info',    'Εκκρεμές'],
      approved:  ['chip-success', 'Εγκεκριμένο'],
      rejected:  ['chip-danger',  'Απορρίφθηκε'],
      picked_up: ['chip-success', 'Παραλήφθηκε'],
      no_show:   ['chip-danger',  'Μη παραλαβή'],
      cancelled: ['chip-muted',   'Ακυρώθηκε'],
    };
    const [cls, label] = map[status] || ['chip-muted', status];
    return `<span class="chip ${cls}">${label}</span>`;
  }

  function ratingStars(stars, dim = false) {
    const full = Math.round(stars || 0);
    let out = '';
    for (let i = 1; i <= 5; i++) out += (i <= full ? '★' : '☆');
    return `<span class="rating ${dim ? 'dim' : ''}">${out}</span>`;
  }

  function openModal(id) {
    const m = document.getElementById(id);
    if (!m) return;
    m.classList.add('open');
    m.querySelectorAll('[data-close]').forEach(b => {
      b.onclick = () => m.classList.remove('open');
    });
    // κλείσιμο με click στο backdrop
    m.addEventListener('click', (e) => { if (e.target === m) m.classList.remove('open'); }, { once: true });
    // κλείσιμο με Escape
    document.addEventListener('keydown', function onKey(e) {
      if (e.key === 'Escape') { m.classList.remove('open'); document.removeEventListener('keydown', onKey); }
    });
  }
  function closeModal(id) { document.getElementById(id)?.classList.remove('open'); }

  // -------- Navigation rendering --------------------------------------------
  async function renderNav() {
    const nav = document.getElementById('nav-links');
    if (!nav) return;
    const u = await getMe();
    if (!u) {
      nav.innerHTML = `
        <a href="${isInPagesDir ? 'feed.html' : 'pages/feed.html'}">Αγγελίες</a>
        <a href="${isInPagesDir ? 'login.html' : 'pages/login.html'}">Σύνδεση</a>
        <a href="${isInPagesDir ? 'register.html' : 'pages/register.html'}" class="btn btn-primary btn-sm" style="margin-left:.5rem">Εγγραφή</a>
      `;
      return;
    }
    const p = (x) => (isInPagesDir ? x : 'pages/' + x);
    const cur = location.pathname.split('/').pop();
    const mk = (href, label) =>
      `<a href="${p(href)}" class="${cur === href ? 'active' : ''}">${label}</a>`;
    nav.innerHTML =
      mk('feed.html', 'Αγγελίες') +
      mk('my-listings.html', 'Οι αγγελίες μου') +
      mk('inbox.html', 'Αιτήματα') +
      mk('my-requests.html', 'Τα αιτήματά μου') +
      (u.role === 'admin' ? mk('admin.html', 'Admin') : '') +
      mk('profile.html', 'Προφίλ') +
      `<span class="points-badge" title="Πόντοι">★ ${u.points}</span>
       <button class="btn btn-outline btn-sm" id="logout-btn">Αποσύνδεση</button>`;
    document.getElementById('logout-btn')?.addEventListener('click', logout);
  }

  function isoLocal(d = new Date()) {
    // Μετατροπή Date -> string συμβατό με <input type="datetime-local">
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }

  // DOM ready + auto-render nav
  document.addEventListener('DOMContentLoaded', renderNav);

  // -------- Expose ----------------------------------------------------------
  window.UniBite = {
    api, getMe, requireAuth, requireAdmin, requireAuthOptional, logout,
    toast, esc, formatDT, formatTimeShort, stateBadge, requestStatusBadge,
    ratingStars, openModal, closeModal, renderNav, isoLocal,
    CAMPUS, API_BASE,
  };
})();

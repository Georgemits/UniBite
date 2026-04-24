/* UniBite – Inbox / Request management (Β3) */
(function () {
  'use strict';
  const { api, toast, esc, formatDT, requestStatusBadge, requireAuth } = window.UniBite;

  let requests = [];
  let currentFilter = 'pending';

  const wrap     = document.getElementById('requests-table-wrap');
  const emptyMsg = document.getElementById('empty-msg');

  document.querySelectorAll('.tab').forEach(t => {
    t.addEventListener('click', () => {
      document.querySelectorAll('.tab').forEach(x => x.classList.remove('active'));
      t.classList.add('active');
      currentFilter = t.dataset.filter;
      render();
    });
  });

  async function loadInbox() {
    try {
      const { requests: rs } = await api('requests.php', { qs: { action: 'inbox' } });
      requests = rs;
      render();
    } catch (err) { toast('Σφάλμα φόρτωσης αιτημάτων', 'error'); }
  }

  function filtered() {
    if (currentFilter === 'all') return requests;
    return requests.filter(r => r.status === currentFilter);
  }

  function render() {
    const rows = filtered();
    if (!rows.length) {
      wrap.innerHTML = ''; emptyMsg.classList.remove('hidden'); return;
    }
    emptyMsg.classList.add('hidden');
    wrap.innerHTML = `
      <table class="table">
        <thead>
          <tr>
            <th>Φαγητό</th>
            <th>Καταναλωτής</th>
            <th>Μήνυμα</th>
            <th>Κατάσταση</th>
            <th>Ημερομηνία</th>
            <th>Ενέργειες</th>
          </tr>
        </thead>
        <tbody>
          ${rows.map(r => `
            <tr data-id="${r.id}">
              <td>
                <strong>${esc(r.listing_title)}</strong><br>
                <small class="text-muted">📍 ${esc(r.pickup_location)}</small><br>
                <small class="text-muted">⏰ ${formatDT(r.pickup_time_start)} – ${formatDT(r.pickup_time_end)}</small><br>
                <small class="text-muted">Διαθέσιμες: ${r.available_portions}/${r.total_portions}</small>
              </td>
              <td>
                ${esc(r.consumer_name)}<br>
                <small class="text-muted">@${esc(r.consumer_username)}</small>
              </td>
              <td>${esc(r.message || '—')}</td>
              <td>${requestStatusBadge(r.status)}</td>
              <td>${formatDT(r.created_at)}</td>
              <td>${actionButtons(r)}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
    wrap.querySelectorAll('button[data-do]').forEach(b => {
      b.addEventListener('click', () => doAction(b.dataset.do, +b.dataset.id));
    });
  }

  function actionButtons(r) {
    const btns = [];
    if (r.status === 'pending') {
      btns.push(`<button class="btn btn-success btn-sm" data-do="approve" data-id="${r.id}">Αποδοχή</button>`);
      btns.push(`<button class="btn btn-danger  btn-sm" data-do="reject"  data-id="${r.id}">Απόρριψη</button>`);
    } else if (r.status === 'approved') {
      btns.push(`<button class="btn btn-success btn-sm" data-do="mark_pickup"   data-id="${r.id}">Επιβεβαίωση παραλαβής</button>`);
      btns.push(`<button class="btn btn-danger  btn-sm" data-do="mark_no_show" data-id="${r.id}">Δήλωση μη-παραλαβής</button>`);
    }
    return btns.join(' ');
  }

  async function doAction(action, id) {
    const labels = {
      approve: 'Έγκριση αιτήματος;',
      reject:  'Απόρριψη αιτήματος;',
      mark_pickup: 'Επιβεβαίωση ότι παρελήφθη η μερίδα;',
      mark_no_show: 'Ο χρήστης δεν παρέλαβε – θα του αφαιρεθεί 1 πόντος. Συνέχεια;',
    };
    if (!confirm(labels[action] || 'Επιβεβαίωση;')) return;
    try {
      await api('requests.php', { method: 'POST', qs: { action }, body: { request_id: id } });
      toast('Ενημερώθηκε', 'success');
      loadInbox();
    } catch (err) {
      toast(err.message || 'Σφάλμα', 'error');
    }
  }

  (async () => {
    const u = await requireAuth();
    if (!u) return;
    loadInbox();
  })();
})();

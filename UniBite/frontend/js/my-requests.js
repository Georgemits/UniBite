/* UniBite – My Requests (outbox) + Rating (Γ3) */
(function () {
  'use strict';
  const { api, toast, esc, formatDT, requestStatusBadge, ratingStars,
          openModal, closeModal, requireAuth } = window.UniBite;

  let requests = [];
  let currentFilter = 'pending';
  let stars = 0;

  const wrap = document.getElementById('outbox-wrap');
  const emptyMsg = document.getElementById('empty-msg');

  document.querySelectorAll('.tab').forEach(t => {
    t.addEventListener('click', () => {
      document.querySelectorAll('.tab').forEach(x => x.classList.remove('active'));
      t.classList.add('active');
      currentFilter = t.dataset.filter;
      render();
    });
  });

  // -------- Rating picker UI ---------------------------------------------
  const picker = document.getElementById('rating-picker');
  picker.querySelectorAll('label').forEach(lab => {
    const val = +lab.dataset.val;
    lab.addEventListener('click', (e) => {
      e.preventDefault();
      stars = val;
      lab.querySelector('input').checked = true;
      picker.querySelectorAll('label').forEach(l =>
        l.classList.toggle('active', +l.dataset.val <= stars));
    });
    lab.addEventListener('mouseenter', () => {
      picker.querySelectorAll('label').forEach(l =>
        l.classList.toggle('hovered', +l.dataset.val <= val));
    });
    lab.addEventListener('mouseleave', () => {
      picker.querySelectorAll('label').forEach(l => l.classList.remove('hovered'));
    });
  });

  async function load() {
    try {
      const { requests: rs } = await api('requests.php', { qs: { action: 'outbox' } });
      requests = rs;
      render();
    } catch (err) { toast('Σφάλμα φόρτωσης', 'error'); }
  }

  function filtered() {
    if (currentFilter === 'all') return requests;
    return requests.filter(r => r.status === currentFilter);
  }

  function render() {
    const rows = filtered();
    if (!rows.length) { wrap.innerHTML = ''; emptyMsg.classList.remove('hidden'); return; }
    emptyMsg.classList.add('hidden');
    wrap.innerHTML = `
      <table class="table">
        <thead><tr>
          <th>Φαγητό</th><th>Μάγειρας</th><th>Κατάσταση</th>
          <th>Υποβλήθηκε</th><th>Αξιολόγηση</th><th></th>
        </tr></thead>
        <tbody>
          ${rows.map(r => `
            <tr>
              <td><strong>${esc(r.listing_title)}</strong><br>
                <small class="text-muted">📍 ${esc(r.pickup_location)}</small><br>
                <small class="text-muted">⏰ ${formatDT(r.pickup_time_start)} – ${formatDT(r.pickup_time_end)}</small>
              </td>
              <td>${esc(r.cook_name)}</td>
              <td>${requestStatusBadge(r.status)}</td>
              <td>${formatDT(r.created_at)}</td>
              <td>${r.rating_stars ? ratingStars(+r.rating_stars) : (r.status === 'picked_up' ? '—' : '')}</td>
              <td>${actionCell(r)}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
    wrap.querySelectorAll('button[data-do]').forEach(b => {
      b.addEventListener('click', () => doAction(b.dataset.do, b.dataset.id, b.dataset.title));
    });
  }

  function actionCell(r) {
    if (r.status === 'pending' || r.status === 'approved') {
      return `<button class="btn btn-outline btn-sm" data-do="cancel" data-id="${r.id}">Ακύρωση</button>`;
    }
    if (r.status === 'picked_up' && !r.rating_id) {
      return `<button class="btn btn-primary btn-sm" data-do="rate" data-id="${r.id}" data-title="${esc(r.listing_title)}">Αξιολόγηση</button>`;
    }
    return '';
  }

  async function doAction(action, id, title) {
    if (action === 'cancel') {
      if (!confirm('Ακύρωση του αιτήματος;')) return;
      try {
        await api('requests.php', { method: 'POST', qs: { action: 'cancel' }, body: { request_id: +id } });
        toast('Ακυρώθηκε', 'success');
        load();
      } catch (err) { toast(err.message || 'Σφάλμα', 'error'); }
      return;
    }
    if (action === 'rate') {
      document.getElementById('rate-rid').value = id;
      document.getElementById('rate-comment').value = '';
      document.getElementById('rate-listing-title').textContent = title || '';
      stars = 0;
      picker.querySelectorAll('label').forEach(l => l.classList.remove('active','hovered'));
      picker.querySelectorAll('input').forEach(i => i.checked = false);
      document.getElementById('rate-err').classList.add('hidden');
      openModal('rate-modal');
    }
  }

  document.getElementById('rate-submit').addEventListener('click', async () => {
    const errBox = document.getElementById('rate-err');
    errBox.classList.add('hidden');
    if (stars < 1) {
      errBox.textContent = 'Επίλεξε τουλάχιστον 1 αστέρι'; errBox.classList.remove('hidden'); return;
    }
    try {
      const r = await api('ratings.php', {
        method: 'POST', qs: { action: 'create' },
        body: {
          request_id: +document.getElementById('rate-rid').value,
          stars,
          comment: document.getElementById('rate-comment').value,
        },
      });
      toast(`Ευχαριστούμε! Ο μάγειρας έλαβε +${r.cook_points_added} πόντο(υς).`, 'success');
      closeModal('rate-modal');
      load();
    } catch (err) {
      errBox.textContent = err.message || 'Σφάλμα αποστολής';
      errBox.classList.remove('hidden');
    }
  });

  (async () => {
    const u = await requireAuth();
    if (!u) return;
    load();
  })();
})();

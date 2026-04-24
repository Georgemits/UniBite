/* UniBite – My Listings (Β1, Β2) */
(function () {
  'use strict';
  const { api, toast, esc, formatDT, stateBadge, ratingStars,
          openModal, closeModal, requireAuth, isoLocal, CAMPUS } = window.UniBite;

  let user = null;
  let allergens = [];
  let editingId = null;
  let editMap = null;
  let editMarker = null;

  const grid = document.getElementById('mine-grid');
  const emptyMsg = document.getElementById('mine-empty');
  const form = document.getElementById('listing-form');
  const errBox = document.getElementById('lf-err');

  // -------- Allergen checkbox list ----------------------------------------
  async function loadAllergens() {
    const { allergens: list } = await api('allergens.php');
    allergens = list;
    const box = document.getElementById('lf-allergens');
    box.innerHTML = allergens.map(a => `
      <label><input type="checkbox" value="${a.id}"> ${esc(a.name_el)}</label>
    `).join('');
  }

  // -------- Load my listings ---------------------------------------------
  async function loadMine() {
    grid.innerHTML = '';
    try {
      const { listings } = await api('listings.php', { qs: { action: 'mine' } });
      if (!listings.length) { emptyMsg.classList.remove('hidden'); return; }
      emptyMsg.classList.add('hidden');
      const frag = document.createDocumentFragment();
      listings.forEach(L_ => frag.appendChild(renderCard(L_)));
      grid.appendChild(frag);
    } catch (err) {
      toast('Αποτυχία φόρτωσης', 'error');
    }
  }

  function renderCard(L_) {
    const el = document.createElement('article');
    el.className = 'card' + (L_.state === 'inactive' ? ' listing-inactive' : '')
                          + (L_.state === 'deleted'  ? ' listing-inactive' : '');
    const reqStats = L_.request_stats || {};
    const photo = L_.photo_url
      ? `<div class="card-photo" style="background-image:url('${esc(L_.photo_url)}')"></div>`
      : `<div class="card-photo">🍲</div>`;
    el.innerHTML = `
      ${photo}
      <div class="card-body">
        <h3 class="card-title">${esc(L_.title)}</h3>
        <div class="card-meta">${formatDT(L_.created_at)}</div>
        <div class="mb-2">${stateBadge(L_.state)}
          <span class="chip chip-info">🍽️ ${L_.available_portions}/${L_.total_portions} μερίδες</span>
        </div>
        <p class="text-sm text-muted">${esc((L_.notes||'').slice(0,120))}</p>
        <div class="mb-2 text-sm">
          ${reqStats.pending   ? `<span class="chip chip-info">${reqStats.pending} εκκρεμή</span>` : ''}
          ${reqStats.approved  ? `<span class="chip chip-success">${reqStats.approved} εγκεκριμένα</span>` : ''}
          ${reqStats.picked_up ? `<span class="chip chip-muted">${reqStats.picked_up} παραδόθηκαν</span>` : ''}
        </div>
        <div class="flex gap-2 mt-2">
          <button class="btn btn-outline btn-sm" data-action="edit" ${L_.state === 'deleted' ? 'disabled' : ''}>Επεξεργασία</button>
          <button class="btn btn-danger btn-sm" data-action="delete">Διαγραφή</button>
        </div>
      </div>
    `;
    el.querySelector('[data-action="edit"]').addEventListener('click', () => openForm(L_));
    el.querySelector('[data-action="delete"]').addEventListener('click', () => deleteListing(L_));
    return el;
  }

  // -------- Open form (create / edit) ------------------------------------
  document.getElementById('new-listing-btn').addEventListener('click', () => openForm(null));

  function openForm(L_) {
    editingId = L_ ? L_.id : null;
    document.getElementById('lm-title').textContent = L_ ? 'Επεξεργασία αγγελίας' : 'Νέα αγγελία';
    errBox.classList.add('hidden');
    form.reset();
    document.getElementById('lf-id').value = L_ ? L_.id : '';
    // prefill
    if (L_) {
      document.getElementById('lf-title').value = L_.title || '';
      document.getElementById('lf-notes').value = L_.notes || '';
      document.getElementById('lf-photo').value = L_.photo_url || '';
      document.getElementById('lf-portions').value = L_.total_portions;
      document.getElementById('lf-location').value = L_.pickup_location || '';
      document.getElementById('lf-lat').value = L_.pickup_lat;
      document.getElementById('lf-lng').value = L_.pickup_lng;
      document.getElementById('lf-t1').value = (L_.pickup_time_start||'').replace(' ', 'T').slice(0,16);
      document.getElementById('lf-t2').value = (L_.pickup_time_end||'').replace(' ', 'T').slice(0,16);
      // Load allergens selection
      (async () => {
        const { listing } = await api('listings.php', { qs: { action: 'get', id: L_.id } });
        const selected = new Set((listing.allergens || []).map(a => +a.id));
        document.querySelectorAll('#lf-allergens input').forEach(i => i.checked = selected.has(+i.value));
      })();
    } else {
      // defaults
      const now = new Date();
      const later = new Date(now.getTime() + 2 * 3600 * 1000);
      const ends = new Date(now.getTime() + 4 * 3600 * 1000);
      document.getElementById('lf-t1').value = isoLocal(later);
      document.getElementById('lf-t2').value = isoLocal(ends);
      document.getElementById('lf-lat').value = (user && user.default_lat) || CAMPUS.lat;
      document.getElementById('lf-lng').value = (user && user.default_lng) || CAMPUS.lng;
    }
    openModal('listing-modal');
    // init map with slight delay so it sees the correct size
    setTimeout(initEditMap, 150);
  }

  function initEditMap() {
    const lat = +document.getElementById('lf-lat').value || CAMPUS.lat;
    const lng = +document.getElementById('lf-lng').value || CAMPUS.lng;
    if (!editMap) {
      editMap = L.map('lf-map').setView([lat, lng], 15);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; OpenStreetMap',
      }).addTo(editMap);
      editMarker = L.marker([lat, lng], { draggable: true }).addTo(editMap);
      editMarker.on('dragend', () => {
        const { lat, lng } = editMarker.getLatLng();
        document.getElementById('lf-lat').value = lat.toFixed(7);
        document.getElementById('lf-lng').value = lng.toFixed(7);
      });
      editMap.on('click', (e) => {
        editMarker.setLatLng(e.latlng);
        document.getElementById('lf-lat').value = e.latlng.lat.toFixed(7);
        document.getElementById('lf-lng').value = e.latlng.lng.toFixed(7);
      });
    } else {
      editMap.setView([lat, lng], 15);
      editMarker.setLatLng([lat, lng]);
    }
    setTimeout(() => editMap.invalidateSize(), 100);
  }

  // -------- Submit -------------------------------------------------------
  document.getElementById('lf-submit').addEventListener('click', async (e) => {
    e.preventDefault();
    errBox.classList.add('hidden');
    const fd = new FormData(form);
    const checkedAllergens = [...document.querySelectorAll('#lf-allergens input:checked')].map(i => +i.value);
    const payload = {
      id:               editingId,
      title:            (fd.get('title')||'').trim(),
      notes:            (fd.get('notes')||'').trim(),
      photo_url:        (fd.get('photo_url')||'').trim(),
      portions:         +fd.get('portions'),
      pickup_location:  (fd.get('pickup_location')||'').trim(),
      pickup_lat:       +fd.get('pickup_lat'),
      pickup_lng:       +fd.get('pickup_lng'),
      pickup_time_start: fd.get('pickup_time_start').replace('T',' ') + ':00',
      pickup_time_end:   fd.get('pickup_time_end').replace('T',' ') + ':00',
      allergens:        checkedAllergens,
    };
    if (!payload.title || !payload.portions || !payload.pickup_location
        || !payload.pickup_time_start || !payload.pickup_time_end) {
      errBox.textContent = 'Συμπληρώστε όλα τα υποχρεωτικά πεδία'; errBox.classList.remove('hidden'); return;
    }
    try {
      const action = editingId ? 'update' : 'create';
      await api('listings.php', { method: 'POST', qs: { action }, body: payload });
      toast(editingId ? 'Η αγγελία ενημερώθηκε' : 'Η αγγελία δημιουργήθηκε!', 'success');
      closeModal('listing-modal');
      loadMine();
    } catch (err) {
      errBox.textContent = err.message || 'Σφάλμα αποθήκευσης';
      errBox.classList.remove('hidden');
    }
  });

  async function deleteListing(L_) {
    if (!confirm(`Διαγραφή της αγγελίας "${L_.title}";`)) return;
    try {
      await api('listings.php', { method: 'POST', qs: { action: 'delete', id: L_.id } });
      toast('Διεγράφη', 'success');
      loadMine();
    } catch (err) {
      toast(err.message || 'Σφάλμα διαγραφής', 'error');
    }
  }

  // -------- Init --------------------------------------------------------
  (async () => {
    user = await requireAuth();
    if (!user) return;
    await loadAllergens();
    await loadMine();
  })();
})();

/* UniBite – Feed (Γ1, Γ2) */
(function () {
  'use strict';
  const { api, toast, esc, formatDT, stateBadge, ratingStars,
          openModal, closeModal, requireAuthOptional, CAMPUS } = window.UniBite;

  let currentUser = null;
  let listings = [];
  let userLocation = null;
  let map = null;
  let markerLayer = null;

  const $ = (sel) => document.querySelector(sel);
  const listView = $('#list-view');
  const mapView  = $('#map-view');
  const grid     = $('#feed-grid');
  const emptyMsg = $('#feed-empty');
  const locNote  = $('#loc-note');

  // -------- Tabs ----------------------------------------------------------
  const tabList = document.getElementById('tab-list');
  const tabMap  = document.getElementById('tab-map');
  tabList.addEventListener('click', () => setTab('list'));
  tabMap.addEventListener('click',  () => setTab('map'));

  function setTab(which) {
    tabList.classList.toggle('active', which === 'list');
    tabMap.classList.toggle('active', which === 'map');
    listView.classList.toggle('hidden', which !== 'list');
    mapView.classList.toggle('hidden', which !== 'map');
    if (which === 'map') initMap();
  }

  // -------- Map -----------------------------------------------------------
  function initMap() {
    if (map) { setTimeout(() => map.invalidateSize(), 200); return; }
    const center = userLocation || CAMPUS;
    map = L.map('map').setView([center.lat, center.lng], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);
    markerLayer = L.layerGroup().addTo(map);
    refreshMapMarkers();
  }

  function refreshMapMarkers() {
    if (!map || !markerLayer) return;
    markerLayer.clearLayers();
    const bounds = [];
    listings.forEach((L_) => {
      const isActive = L_.state === 'active';
      const color = isActive ? '#f97316' : '#94a3b8';
      const icon = L.divIcon({
        className: 'listing-marker',
        html: `<div style="
          width:34px;height:34px;border-radius:50%;
          background:${color};color:#fff;
          display:flex;align-items:center;justify-content:center;
          font-size:18px;border:2px solid #fff;
          box-shadow:0 2px 6px rgba(0,0,0,.25);
          ${!isActive ? 'opacity:.75' : ''}">
          ${isActive ? '🍽️' : '∅'}</div>`,
        iconSize: [34, 34],
        iconAnchor: [17, 17],
      });
      const m = L.marker([+L_.pickup_lat, +L_.pickup_lng], { icon }).addTo(markerLayer);
      m.bindPopup(`
        <strong>${esc(L_.title)}</strong><br>
        ${esc(L_.cook_name)}<br>
        <small>${esc(L_.pickup_location)}</small><br>
        ${stateBadge(L_.state)}
        <br><br>
        <button class="btn btn-sm btn-primary" onclick="window._unibiteOpen(${L_.id})">Λεπτομέρειες</button>
      `);
      bounds.push([+L_.pickup_lat, +L_.pickup_lng]);
    });
    if (userLocation) {
      L.circleMarker([userLocation.lat, userLocation.lng], {
        radius: 8, color: '#3b82f6', fillColor: '#60a5fa', fillOpacity: .8, weight: 2,
      }).addTo(markerLayer).bindPopup('Η τοποθεσία μου');
      bounds.push([userLocation.lat, userLocation.lng]);
    }
    if (bounds.length) map.fitBounds(bounds, { padding: [30, 30] });
  }

  // -------- Render cards --------------------------------------------------
  function render() {
    grid.innerHTML = '';
    if (listings.length === 0) {
      emptyMsg.classList.remove('hidden');
      return;
    }
    emptyMsg.classList.add('hidden');
    const frag = document.createDocumentFragment();
    listings.forEach((L_) => {
      const el = document.createElement('article');
      el.className = 'card' + (L_.state === 'inactive' ? ' listing-inactive' : '');
      el.dataset.id = L_.id;
      const photo = L_.photo_url
        ? `<div class="card-photo" style="background-image:url('${esc(L_.photo_url)}')"></div>`
        : `<div class="card-photo">🍲</div>`;
      const allergChips = (L_.allergens || []).map(a =>
        `<span class="chip chip-warn" title="Αλλεργιογόνο">⚠ ${esc(a.name_el)}</span>`
      ).join('');
      const distTxt = L_.distance_km != null ? `<span class="chip chip-muted">📍 ${L_.distance_km} km</span>` : '';
      const ratingTxt = L_.rating_avg
        ? `${ratingStars(L_.rating_avg)} <small class="muted">(${L_.rating_count})</small>`
        : `<small class="muted">Χωρίς αξιολογήσεις</small>`;
      el.innerHTML = `
        ${photo}
        <div class="card-body">
          <h3 class="card-title">${esc(L_.title)}</h3>
          <div class="card-meta">
            👨‍🍳 ${esc(L_.cook_name)} · ${formatDT(L_.created_at)}
          </div>
          <div class="mb-2">${stateBadge(L_.state)} ${distTxt}
            <span class="chip chip-info">🍽️ ${L_.available_portions}/${L_.total_portions} μερίδες</span>
          </div>
          <p class="text-sm text-muted" style="min-height:1em">${esc((L_.notes || '').slice(0, 130))}${(L_.notes||'').length > 130 ? '…' : ''}</p>
          <div class="mb-2">${allergChips || '<span class="chip chip-muted">Χωρίς δηλωμένα αλλεργιογόνα</span>'}</div>
          <div class="mb-2 text-sm">${ratingTxt}</div>
          <div class="flex gap-2 mt-2">
            <button class="btn btn-outline btn-sm" data-action="details">Λεπτομέρειες</button>
            <button class="btn btn-primary btn-sm" data-action="request" ${L_.state !== 'active' ? 'disabled' : ''}>Ζήτα μερίδα</button>
          </div>
        </div>
      `;
      el.querySelector('[data-action="details"]').addEventListener('click', () => openDetails(L_));
      el.querySelector('[data-action="request"]').addEventListener('click', () => requestPortion(L_));
      frag.appendChild(el);
    });
    grid.appendChild(frag);
  }

  // -------- Modal details -------------------------------------------------
  window._unibiteOpen = (id) => {
    const L_ = listings.find(x => +x.id === +id);
    if (L_) openDetails(L_);
  };

  function openDetails(L_) {
    document.getElementById('lm-title').textContent = L_.title;
    const photo = L_.photo_url
      ? `<img src="${esc(L_.photo_url)}" alt="" style="width:100%;border-radius:10px;margin-bottom:1rem;max-height:300px;object-fit:cover">`
      : '';
    const allergChips = (L_.allergens || []).map(a =>
      `<span class="chip chip-warn">⚠ ${esc(a.name_el)}</span>`
    ).join(' ') || '<span class="chip chip-muted">Χωρίς δηλωμένα αλλεργιογόνα</span>';
    document.getElementById('lm-body').innerHTML = `
      ${photo}
      <p>${esc(L_.notes || 'Χωρίς σημειώσεις')}</p>
      <table class="table">
        <tr><th>Μάγειρας</th><td>${esc(L_.cook_name)} (@${esc(L_.cook_username)})</td></tr>
        <tr><th>Τοποθεσία</th><td>${esc(L_.pickup_location)}</td></tr>
        <tr><th>Ώρα παραλαβής</th><td>${formatDT(L_.pickup_time_start)} – ${formatDT(L_.pickup_time_end)}</td></tr>
        <tr><th>Διαθέσιμες μερίδες</th><td>${L_.available_portions} από ${L_.total_portions}</td></tr>
        <tr><th>Αλλεργιογόνα</th><td>${allergChips}</td></tr>
        <tr><th>Κατάσταση</th><td>${stateBadge(L_.state)}</td></tr>
        <tr><th>Αξιολόγηση</th><td>${L_.rating_avg ? ratingStars(L_.rating_avg) + ' ('+L_.rating_count+' κριτικές)' : 'Χωρίς κριτικές'}</td></tr>
      </table>
    `;
    const btn = document.getElementById('lm-request-btn');
    btn.disabled = (L_.state !== 'active');
    btn.onclick = () => { closeModal('listing-modal'); requestPortion(L_); };
    openModal('listing-modal');
  }

  // -------- Request a portion --------------------------------------------
  async function requestPortion(L_) {
    if (!currentUser) {
      toast('Πρέπει να συνδεθείς πρώτα', 'error');
      setTimeout(() => window.location.href = 'login.html', 800);
      return;
    }
    if (currentUser.id === L_.cook_id) {
      toast('Δεν μπορείς να ζητήσεις από τη δική σου αγγελία', 'error');
      return;
    }
    if (currentUser.points < 1) {
      toast('Χρειάζεσαι τουλάχιστον 1 πόντο για να δεσμεύσεις μερίδα', 'error');
      return;
    }
    const msg = prompt('Μήνυμα προς τον μάγειρα (προαιρετικό):', '') || '';
    try {
      await api('requests.php', {
        method: 'POST',
        qs: { action: 'create' },
        body: { listing_id: L_.id, message: msg },
      });
      toast('Το αίτημά σου στάλθηκε!', 'success');
      loadFeed();
    } catch (err) {
      toast(err.message || 'Αποτυχία αιτήματος', 'error');
    }
  }

  // -------- Toolbar -------------------------------------------------------
  $('#sort').addEventListener('change', loadFeed);
  $('#maxKm').addEventListener('change', loadFeed);
  $('#limit').addEventListener('change', loadFeed);
  $('#locate-btn').addEventListener('click', () => {
    if (!navigator.geolocation) { toast('Ο browser δεν υποστηρίζει geolocation', 'error'); return; }
    locNote.textContent = 'Εντοπισμός τοποθεσίας…';
    locNote.classList.remove('hidden');
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        userLocation = { lat: pos.coords.latitude, lng: pos.coords.longitude };
        locNote.textContent = `Η τοποθεσία σου εντοπίστηκε (± ${Math.round(pos.coords.accuracy || 0)}m).`;
        loadFeed();
      },
      (err) => {
        locNote.classList.add('alert-warn');
        locNote.textContent = 'Δεν δόθηκε άδεια geolocation – χρήση της Πανεπιστημιούπολης ως τοποθεσία αναφοράς.';
        userLocation = { ...CAMPUS };
        loadFeed();
      },
      { enableHighAccuracy: true, timeout: 8000, maximumAge: 30000 }
    );
  });

  // -------- Load data ----------------------------------------------------
  async function loadFeed() {
    const sort  = $('#sort').value;
    const maxKm = $('#maxKm').value;
    const limit = $('#limit').value || 50;
    const qs = { action: 'feed', sort, limit, include_inactive: '1' };
    const loc = userLocation || CAMPUS;
    qs.lat = loc.lat; qs.lng = loc.lng;
    if (maxKm) qs.max_km = maxKm;
    try {
      const { listings: L } = await api('listings.php', { qs });
      listings = L;
      render();
      refreshMapMarkers();
    } catch (err) {
      toast('Αποτυχία φόρτωσης αγγελιών', 'error');
    }
  }

  // -------- Init ----------------------------------------------------------
  requireAuthOptional().then((u) => {
    currentUser = u;
    userLocation = u && u.default_lat && u.default_lng
      ? { lat: +u.default_lat, lng: +u.default_lng } : null;
    loadFeed();
  });
})();

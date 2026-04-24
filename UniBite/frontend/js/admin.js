/* UniBite – Admin Dashboard (Δ1, Δ2) */
(function () {
  'use strict';
  const { api, esc, formatDT, ratingStars, requireAdmin, toast } = window.UniBite;

  async function load() {
    try {
      const r = await api('admin.php', { qs: { action: 'overview' } });
      const s = r.stats;
      const l = r.leaderboard;

      document.getElementById('k-shared').textContent  = s.shared_last_month;
      document.getElementById('k-active').textContent  = s.active_listings;
      document.getElementById('k-users').textContent   = s.total_users;
      document.getElementById('k-ratings').textContent = s.total_ratings;
      document.getElementById('k-avg').textContent     = s.avg_rating != null ? s.avg_rating : '—';

      drawDailyChart(s.daily_shared || []);

      // Top Donors (Δ2)
      const donorsBox = document.getElementById('donors-wrap');
      if (!l.top_donors.length) {
        donorsBox.innerHTML = '<div class="alert alert-info">Δεν υπάρχουν ακόμα δεδομένα donors.</div>';
      } else {
        donorsBox.innerHTML = `
          <table class="table">
            <thead><tr><th>#</th><th>Χρήστης</th><th>Μερίδες που έδωσε</th><th>Πόντοι</th></tr></thead>
            <tbody>
              ${l.top_donors.map((d, i) => `
                <tr>
                  <td>${i === 0 ? '🥇' : (i === 1 ? '🥈' : (i === 2 ? '🥉' : (i+1)))}</td>
                  <td><strong>${esc(d.full_name)}</strong><br><small class="text-muted">@${esc(d.username)}</small></td>
                  <td>${d.portions_given}</td>
                  <td>${d.points}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>`;
      }

      // Top-rated listings
      const tlBox = document.getElementById('top-listings-wrap');
      if (!l.top_listings.length) {
        tlBox.innerHTML = '<div class="alert alert-info">Δεν υπάρχουν ακόμα αξιολογήσεις.</div>';
      } else {
        tlBox.innerHTML = `
          <table class="table">
            <thead><tr><th>Γεύμα</th><th>Μάγειρας</th><th>Μ.Ο.</th><th>Κριτικές</th><th>Ημερομηνία</th></tr></thead>
            <tbody>
              ${l.top_listings.map(L_ => `
                <tr>
                  <td><strong>${esc(L_.title)}</strong></td>
                  <td>${esc(L_.cook_name)}<br><small class="text-muted">@${esc(L_.cook_username)}</small></td>
                  <td>${ratingStars(+L_.avg_rating)} <small>(${L_.avg_rating}/5)</small></td>
                  <td>${L_.rating_count}</td>
                  <td>${formatDT(L_.created_at)}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>`;
      }

      // Top receivers
      const rcBox = document.getElementById('receivers-wrap');
      rcBox.innerHTML = !l.top_receivers.length
        ? '<div class="alert alert-info">Δεν υπάρχουν ακόμα δεδομένα.</div>'
        : `<table class="table">
             <thead><tr><th>Χρήστης</th><th>Μερίδες που έλαβε</th></tr></thead>
             <tbody>${l.top_receivers.map(r => `
               <tr><td>${esc(r.full_name)} <small class="text-muted">(@${esc(r.username)})</small></td>
                   <td>${r.portions_received}</td></tr>
             `).join('')}</tbody>
           </table>`;
    } catch (err) {
      toast('Σφάλμα φόρτωσης dashboard', 'error');
    }
  }

  // Minimal hand-rolled bar chart on canvas (no external lib needed)
  function drawDailyChart(points) {
    const canvas = document.getElementById('chart-daily');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const w = canvas.width  = canvas.offsetWidth;
    const h = canvas.height = 180;
    ctx.clearRect(0, 0, w, h);

    if (!points.length) {
      ctx.fillStyle = '#94a3b8';
      ctx.font = '14px system-ui';
      ctx.textAlign = 'center';
      ctx.fillText('Δεν υπάρχουν δεδομένα για τις τελευταίες 30 ημέρες.', w/2, h/2);
      return;
    }
    // Κανονικοποίηση σε 30 ημέρες
    const byDay = Object.fromEntries(points.map(p => [p.d, +p.c]));
    const today = new Date(); today.setHours(0,0,0,0);
    const days = [];
    for (let i = 29; i >= 0; i--) {
      const d = new Date(today); d.setDate(today.getDate() - i);
      const key = d.toISOString().slice(0,10);
      days.push({ d: key, c: byDay[key] || 0 });
    }
    const max = Math.max(1, ...days.map(x => x.c));
    const pad = 30;
    const barW = (w - pad * 2) / days.length;

    // Axes
    ctx.strokeStyle = '#e2e8f0';
    ctx.beginPath(); ctx.moveTo(pad, h - pad); ctx.lineTo(w - pad, h - pad); ctx.stroke();

    days.forEach((x, i) => {
      const barH = ((h - pad * 2) * x.c) / max;
      const x0 = pad + i * barW + 2;
      const y0 = (h - pad) - barH;
      ctx.fillStyle = x.c > 0 ? '#f97316' : '#e2e8f0';
      ctx.fillRect(x0, y0, barW - 4, barH || 2);
    });
    ctx.fillStyle = '#475569';
    ctx.font = '11px system-ui';
    ctx.textAlign = 'left';
    ctx.fillText(days[0].d.slice(5), pad, h - 10);
    ctx.textAlign = 'right';
    ctx.fillText(days[days.length-1].d.slice(5), w - pad, h - 10);
    ctx.textAlign = 'left';
    ctx.fillText(`max = ${max}`, pad, 14);
  }

  (async () => {
    const u = await requireAdmin();
    if (!u) return;
    load();
  })();
})();

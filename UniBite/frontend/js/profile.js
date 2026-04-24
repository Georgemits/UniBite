/* UniBite – Profile & points history (Β4) */
(function () {
  'use strict';
  const { api, esc, formatDT, requireAuth, toast } = window.UniBite;

  async function load() {
    try {
      const r = await api('users.php', { qs: { action: 'me_details' } });
      const u = r.user;
      const s = r.summary;
      document.getElementById('kpi-points').textContent   = u.points;
      document.getElementById('kpi-given').textContent    = s.portions_given;
      document.getElementById('kpi-received').textContent = s.portions_received;
      document.getElementById('kpi-avg').textContent      = s.avg_rating ? s.avg_rating + '/5' : '—';

      document.getElementById('user-meta').innerHTML = `
        <p><strong>Ονοματεπώνυμο:</strong> ${esc(u.full_name)}</p>
        <p><strong>Όνομα χρήστη:</strong> ${esc(u.username)}</p>
        <p><strong>Email:</strong> ${esc(u.email)}</p>
        <p><strong>Ρόλος:</strong> ${u.role === 'admin' ? 'Διαχειριστής' : 'Φοιτητής'}</p>
        <p><strong>Εγγραφή:</strong> ${formatDT(u.created_at)}</p>
      `;

      const txs = r.transactions || [];
      const wrap = document.getElementById('tx-wrap');
      if (!txs.length) {
        wrap.innerHTML = '<div class="alert alert-info">Δεν υπάρχει καταγεγραμμένη κίνηση πόντων.</div>';
        return;
      }
      wrap.innerHTML = `
        <table class="table">
          <thead><tr><th>Ημερομηνία</th><th>Μεταβολή</th><th>Λόγος</th></tr></thead>
          <tbody>
            ${txs.map(t => `
              <tr>
                <td>${formatDT(t.created_at)}</td>
                <td><strong style="color:${+t.delta >= 0 ? '#047857' : '#b91c1c'}">${t.delta > 0 ? '+' : ''}${t.delta}</strong></td>
                <td>${esc(t.reason)}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `;
    } catch (err) { toast('Σφάλμα φόρτωσης προφίλ', 'error'); }
  }

  (async () => {
    const u = await requireAuth();
    if (!u) return;
    load();
  })();
})();

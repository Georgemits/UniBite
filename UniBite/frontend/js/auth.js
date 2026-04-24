/* UniBite – Login & Register */
(function () {
  'use strict';
  const { api, toast } = window.UniBite;

  function showErr(id, msg) {
    const el = document.getElementById(id);
    if (!el) { toast(msg, 'error'); return; }
    el.textContent = msg;
    el.classList.remove('hidden');
  }
  function clearErr(id) { document.getElementById(id)?.classList.add('hidden'); }

  // ----- Login form --------------------------------------------------------
  const loginForm = document.getElementById('login-form');
  if (loginForm) {
    loginForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErr('login-err');
      const fd = new FormData(loginForm);
      const payload = {
        identifier: fd.get('identifier'),
        password:   fd.get('password'),
      };
      try {
        const btn = loginForm.querySelector('button[type="submit"]');
        btn.disabled = true;
        await api('auth.php', { method: 'POST', qs: { action: 'login' }, body: payload });
        toast('Καλωσόρισες!', 'success');
        window.location.href = 'feed.html';
      } catch (err) {
        showErr('login-err', err.message || 'Αποτυχία σύνδεσης');
      } finally {
        loginForm.querySelector('button[type="submit"]').disabled = false;
      }
    });
  }

  // ----- Register form -----------------------------------------------------
  const regForm = document.getElementById('register-form');
  if (regForm) {
    regForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErr('reg-err');
      const fd = new FormData(regForm);
      const payload = {
        full_name: fd.get('full_name'),
        username:  fd.get('username'),
        email:     fd.get('email'),
        password:  fd.get('password'),
      };
      try {
        const btn = regForm.querySelector('button[type="submit"]');
        btn.disabled = true;
        await api('auth.php', { method: 'POST', qs: { action: 'register' }, body: payload });
        toast('Καλωσόρισες στο UniBite!', 'success');
        window.location.href = 'feed.html';
      } catch (err) {
        showErr('reg-err', err.message || 'Αποτυχία εγγραφής');
      } finally {
        regForm.querySelector('button[type="submit"]').disabled = false;
      }
    });
  }
})();

import { api, url } from '../lib/api.js';

function status(form, message, error = false) {
  const el = form.querySelector('[data-status]');
  if (!el) return;
  el.textContent = message;
  el.classList.toggle('error', error);
}

export function init() {
  document.querySelector('[data-login-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    try {
      await api('/api/admin/login', {
        method: 'POST',
        body: JSON.stringify(Object.fromEntries(new FormData(form))),
      });
      location.href = url('/admin/dashboard');
    } catch (error) {
      status(form, error.message, true);
    }
  });

  document.querySelector('[data-password-reset-request]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    try {
      const result = await api('/api/admin/password-reset/request', {
        method: 'POST',
        body: JSON.stringify(Object.fromEntries(new FormData(form))),
      });
      status(form, result.message);
      form.querySelector('button').disabled = true;
    } catch (error) {
      status(form, error.message, true);
    }
  });

  const resetForm = document.querySelector('[data-password-reset-confirm]');
  if (resetForm) {
    resetForm.elements.token.value = new URLSearchParams(location.search).get('token') || '';
    resetForm.addEventListener('submit', async event => {
      event.preventDefault();
      const form = event.currentTarget;
      try {
        await api('/api/admin/password-reset/confirm', {
          method: 'POST',
          body: JSON.stringify(Object.fromEntries(new FormData(form))),
        });
        status(form, 'Password updated. Redirecting to sign in…');
        setTimeout(() => { location.href = url('/admin/login'); }, 700);
      } catch (error) {
        status(form, error.message, true);
      }
    });
  }
}

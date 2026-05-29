/* pages/signup.js — public signup landing + invite acceptance. */

import { $, setStatus, formData } from '../lib/dom.js';
import { api } from '../lib/api.js';

export function init() {
  $('[data-signup-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const status = $('[data-signup-status]', event.target);
    status.textContent = 'Creating your venue…';
    try {
      const data = await api('/api/signup', { method: 'POST', body: JSON.stringify(formData(event.target)) });
      let msg = `Venue created. ${data.next || ''}`;
      if (data.invite_url) msg += `\n\nDev mode — activate here: ${data.invite_url}`;
      status.textContent = msg;
      status.classList.add('success');
    } catch (e) {
      status.textContent = e.message || 'Signup failed.';
      status.classList.add('error');
    }
  });

  $('[data-signup-accept]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const status = $('[data-signup-status]', event.target);
    status.textContent = 'Activating…';
    try {
      const data = await api('/api/signup/accept', { method: 'POST', body: JSON.stringify(formData(event.target)) });
      status.textContent = `Activated. Redirecting to ${data.tenant.slug}…`;
      setTimeout(() => { location.href = `/admin/login?email=${encodeURIComponent(data.tenant.email)}`; }, 600);
    } catch (e) {
      status.textContent = e.message || 'Activation failed.';
      status.classList.add('error');
    }
  });

  // Carry ?token=… from the invite link into the hidden field.
  const acceptToken = $('[data-token-from-query]');
  if (acceptToken) {
    acceptToken.value = new URLSearchParams(location.search).get('token') || '';
  }
}

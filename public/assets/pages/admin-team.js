import { api } from '../lib/api.js';

const list = document.querySelector('[data-team-list]');

function field(label, control) {
  const wrapper = document.createElement('label');
  wrapper.append(label, control);
  return wrapper;
}

function renderMember(member) {
  const form = document.createElement('form');
  form.className = 'team-member';
  form.dataset.memberId = member.id;

  const name = document.createElement('input');
  name.name = 'display_name';
  name.value = member.display_name;
  name.maxLength = 160;
  name.required = true;

  const email = document.createElement('div');
  email.className = 'team-member-email';
  email.textContent = member.email;

  const role = document.createElement('select');
  role.name = 'role';
  for (const [value, label] of [['kj', 'KJ'], ['tenant_admin', 'Administrator']]) {
    const option = new Option(label, value, value === member.role, value === member.role);
    role.add(option);
  }

  const active = document.createElement('input');
  active.type = 'checkbox';
  active.name = 'is_active';
  active.checked = Boolean(Number(member.is_active));

  const actions = document.createElement('div');
  actions.className = 'team-member-actions';
  const save = document.createElement('button');
  save.type = 'submit';
  save.className = 'primary';
  save.textContent = 'Save';
  const resend = document.createElement('button');
  resend.type = 'button';
  resend.textContent = 'Send password link';
  resend.dataset.resend = '';
  const status = document.createElement('span');
  status.dataset.status = '';
  actions.append(save, resend, status);

  form.append(field('Name', name), email, field('Role', role), field('Active', active), actions);
  return form;
}

async function loadTeam() {
  if (!list) return;
  try {
    const { members } = await api('/api/admin/team');
    list.replaceChildren(...members.map(renderMember));
  } catch (error) {
    list.textContent = error.message;
  }
}

function showStatus(target, message, error = false) {
  const el = target.querySelector('[data-status]');
  if (!el) return;
  el.textContent = message;
  el.classList.toggle('error', error);
}

export function init() {
  document.querySelector('[data-team-invite]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    try {
      await api('/api/admin/team', {
        method: 'POST',
        body: JSON.stringify(Object.fromEntries(new FormData(form))),
      });
      showStatus(form, 'Invitation sent.');
      form.reset();
      await loadTeam();
    } catch (error) {
      showStatus(form, error.message, true);
    }
  });

  list?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.target.closest('[data-member-id]');
    if (!form) return;
    const payload = Object.fromEntries(new FormData(form));
    payload.is_active = form.elements.is_active.checked;
    try {
      await api(`/api/admin/team/${form.dataset.memberId}`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      });
      showStatus(form, 'Saved.');
      await loadTeam();
    } catch (error) {
      showStatus(form, error.message, true);
    }
  });

  list?.addEventListener('click', async event => {
    const button = event.target.closest('[data-resend]');
    const form = button?.closest('[data-member-id]');
    if (!button || !form) return;
    button.disabled = true;
    try {
      await api(`/api/admin/team/${form.dataset.memberId}/invite`, { method: 'POST' });
      showStatus(form, 'Password link sent.');
    } catch (error) {
      showStatus(form, error.message, true);
    } finally {
      button.disabled = false;
    }
  });

  loadTeam();
}

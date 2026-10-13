import { api, setCsrf, ApiError } from '../api.js';
import { h, logo, field, setBusy, errorNotice, showFieldErrors } from '../dom.js';

/** Change-password form. forced=true renders it full-screen after a first sign-in or a reset. */
export function renderChangePassword({ forced = false, onDone }) {
  const current = h('input', { class: 'input', name: 'currentPassword', type: 'password', autocomplete: 'current-password', maxlength: '128' });
  const next = h('input', { class: 'input', name: 'newPassword', type: 'password', autocomplete: 'new-password', maxlength: '128' });
  const repeat = h('input', { class: 'input', name: 'repeatPassword', type: 'password', autocomplete: 'new-password', maxlength: '128' });
  const submit = h('button', { class: 'btn primary', type: 'submit' }, 'Change password');
  const msg = h('div');

  const form = h('form', { class: 'form', novalidate: true },
    field(forced ? 'Temporary or current password' : 'Current password', current),
    field('New password', next, 'At least 10 characters. A short phrase of several words works well.'),
    field('Repeat new password', repeat),
    msg,
    h('div', { class: 'toolbar' }, submit));

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    msg.replaceChildren();
    if (next.value !== repeat.value) {
      showFieldErrors(form, { repeatPassword: 'The two new passwords are different.' });
      return;
    }
    showFieldErrors(form, {});
    setBusy(submit, true, 'Saving…');
    try {
      const res = await api('/auth/change-password', { method: 'POST', body: { currentPassword: current.value, newPassword: next.value } });
      setCsrf(res.csrfToken);
      onDone();
    } catch (err) {
      if (err instanceof ApiError && err.fields) showFieldErrors(form, err.fields);
      else msg.append(errorNotice(err));
      setBusy(submit, false);
    }
  });

  if (!forced) return form;
  return h('div', { class: 'auth' },
    h('div', { class: 'auth-card' },
      h('div', { class: 'brand' }, logo(36), h('span', {}, h('b', { text: 'Transformer X' }), h('small', { text: 'Product upload converter' }))),
      h('div', {}, h('h1', { text: 'Choose your own password' }), h('p', { class: 'muted small', text: 'You signed in with a temporary password. Pick a new one that only you know.' })),
      form));
}

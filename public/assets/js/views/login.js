import { api, setCsrf } from '../api.js';
import { h, logo, field, setBusy, errorNotice } from '../dom.js';

export function renderLogin(onSignedIn) {
  const username = h('input', { class: 'input', name: 'username', autocomplete: 'username', required: true, maxlength: '50', autofocus: true });
  const password = h('input', { class: 'input', name: 'password', type: 'password', autocomplete: 'current-password', required: true, maxlength: '128' });
  const submit = h('button', { class: 'btn primary big', type: 'submit' }, 'Sign in');
  const msg = h('div');

  const form = h('form', { class: 'form', novalidate: true },
    field('Username', username),
    field('Password', password),
    msg,
    submit);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    msg.replaceChildren();
    if (!username.value.trim() || !password.value) {
      msg.append(errorNotice({ message: 'Enter your username and password.' }));
      return;
    }
    setBusy(submit, true, 'Signing in…');
    try {
      const res = await api('/auth/login', { method: 'POST', body: { username: username.value.trim(), password: password.value } });
      setCsrf(res.csrfToken);
      onSignedIn(res.user);
    } catch (err) {
      password.value = '';
      msg.append(errorNotice(err));
      setBusy(submit, false);
      password.focus();
    }
  });

  return h('div', { class: 'auth' },
    h('div', { class: 'auth-card' },
      h('div', { class: 'brand' }, logo(36), h('span', {}, h('b', { text: 'Transformer X' }), h('small', { text: 'Product upload converter' }))),
      h('div', {}, h('h1', { text: 'Sign in' }), h('p', { class: 'muted small', text: 'Accounts are created by an administrator. Ask them if you need access or forgot your password.' })),
      form));
}

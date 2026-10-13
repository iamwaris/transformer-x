import { api } from '../api.js';
import { h, fmt, toast } from '../dom.js';
import { renderChangePassword } from './password.js';
import { applyTheme } from '../theme.js';

export function renderProfile(main, _params, { state }) {
  const u = state.user;
  const themes = [['system', 'Follow my computer'], ['light', 'Light'], ['dark', 'Dark']];
  const seg = h('div', { class: 'seg', role: 'group', 'aria-label': 'Appearance' }, themes.map(([key, label]) =>
    h('button', { type: 'button', 'aria-pressed': String(u.theme === key), onclick: async (e) => {
      const prev = u.theme;
      u.theme = key;
      applyTheme(key);
      seg.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b === e.currentTarget)));
      try { await api('/me', { method: 'PATCH', body: { theme: key } }); } catch (err) { u.theme = prev; applyTheme(prev); toast(err.message, 'bad'); }
    } }, label)));

  const pwHost = h('div');
  pwHost.append(renderChangePassword({ forced: false, onDone: () => {
    toast('Password changed.');
    pwHost.replaceChildren(renderChangePassword({ forced: false, onDone: () => toast('Password changed.') }));
  } }));

  main.append(
    h('div', { class: 'page-head' }, h('div', {}, h('h1', { text: 'My account' }))),
    h('div', { class: 'grid-2' },
      h('section', { class: 'panel' }, h('h2', { text: 'Profile' }), h('div', { class: 'kv' },
        h('div', { class: 'k', text: 'Name' }), h('div', { text: u.displayName }),
        h('div', { class: 'k', text: 'Username' }), h('div', { class: 'mono', text: u.username }),
        h('div', { class: 'k', text: 'Role' }), h('div', { text: u.role === 'admin' ? 'Administrator' : 'User' }),
        h('div', { class: 'k', text: 'Last sign-in' }), h('div', { text: fmt.date(u.lastLoginAt) })),
      h('h3', { text: 'Appearance' }), seg,
      h('p', { class: 'muted small', text: 'Your name and role can only be changed by an administrator.' })),
      h('section', { class: 'panel' }, h('h2', { text: 'Change password' }), pwHost)));
}

import { api } from '../api.js';
import { h, fmt, pager, errorNotice } from '../dom.js';

const ACTION_LABELS = {
  login: 'Signed in', logout: 'Signed out', login_rate_limited: 'Sign-in blocked (too many attempts)', account_locked: 'Account locked',
  password_changed: 'Changed password', import_uploaded: 'Uploaded a file', import_converted: 'Converted a file',
  import_cancelled: 'Discarded a preview', user_created: 'Created a user', user_updated: 'Changed a user',
  user_password_reset: 'Reset a password', user_unlocked: 'Unlocked a user', settings_changed: 'Changed settings',
};

export async function renderAudit(main) {
  const users = (await api('/admin/users')).items;
  const st = { page: 1, action: '', user: '' };
  const action = h('select', { class: 'input', 'aria-label': 'Action' }, h('option', { value: '', text: 'All actions' }));
  const user = h('select', { class: 'input', 'aria-label': 'User' }, h('option', { value: '', text: 'All users' }),
    users.map((u) => h('option', { value: u.id, text: `${u.displayName} (${u.username})` })));
  const host = h('div');
  action.addEventListener('change', () => { st.action = action.value; st.page = 1; load(); });
  user.addEventListener('change', () => { st.user = user.value; st.page = 1; load(); });

  main.append(
    h('div', { class: 'page-head' }, h('div', {}, h('h1', { text: 'Audit log' }),
      h('p', { text: 'Sign-ins, uploads, conversions and administrative changes, newest first.' }))),
    h('section', { class: 'panel' }, h('div', { class: 'toolbar' }, action, user), host));

  let actionsLoaded = false;
  async function load() {
    const params = new URLSearchParams({ page: st.page, size: 50 });
    if (st.action) params.set('action', st.action);
    if (st.user) params.set('user', st.user);
    try {
      const d = await api(`/admin/audit?${params}`);
      if (!actionsLoaded) {
        d.actions.forEach((a) => action.append(h('option', { value: a, text: ACTION_LABELS[a] || a })));
        actionsLoaded = true;
      }
      host.replaceChildren(
        h('div', { class: 'table-wrap' }, h('table', { class: 'data' },
          h('thead', {}, h('tr', {}, h('th', { text: 'When' }), h('th', { text: 'User' }), h('th', { text: 'Action' }), h('th', { text: 'Details' }), h('th', { text: 'IP address' }))),
          h('tbody', {}, d.items.map((a) => h('tr', {},
            h('td', { class: 'nowrap', text: fmt.date(a.at) }),
            h('td', { class: 'mono', text: a.username || '—' }),
            h('td', { text: ACTION_LABELS[a.action] || a.action }),
            h('td', { class: 'wrap small', text: details(a) }),
            h('td', { class: 'mono', title: a.userAgent, text: a.ip })))))),
        pager(d.page, d.size, d.total, (p) => { st.page = p; load(); }));
    } catch (err) {
      host.replaceChildren(errorNotice(err));
    }
  }
  load();
}

function details(a) {
  const parts = [];
  if (a.targetType) parts.push(`${a.targetType} #${a.targetId}`);
  if (a.details) parts.push(Object.entries(a.details).map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : v}`).join(', '));
  return parts.join(' — ');
}

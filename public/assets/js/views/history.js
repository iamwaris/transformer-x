import { api } from '../api.js';
import { h, fmt, statusPill, pager, errorNotice, STATUS } from '../dom.js';

export async function renderHistory(main, _params, { state }) {
  const isAdmin = state.user.role === 'admin';
  const users = isAdmin ? (await api('/admin/users')).items : [];
  const st = { page: 1, q: '', status: '', from: '', to: '', user: '' };
  const ctrl = new AbortController();

  const q = h('input', { class: 'input grow', type: 'search', placeholder: 'Search file name', 'aria-label': 'Search file name', maxlength: '100' });
  const status = h('select', { class: 'input', 'aria-label': 'Status' }, h('option', { value: '', text: 'Any status' }),
    Object.entries(STATUS).map(([k, [label]]) => h('option', { value: k, text: label })));
  const from = h('input', { class: 'input', type: 'date', 'aria-label': 'From date' });
  const to = h('input', { class: 'input', type: 'date', 'aria-label': 'To date' });
  const user = isAdmin ? h('select', { class: 'input', 'aria-label': 'User' }, h('option', { value: '', text: 'All users' }),
    users.map((u) => h('option', { value: u.id, text: `${u.displayName} (${u.username})` }))) : null;
  const host = h('div');

  let timer;
  q.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { st.q = q.value.trim(); st.page = 1; load(); }, 300); });
  for (const [el, key] of [[status, 'status'], [from, 'from'], [to, 'to'], [user, 'user']]) {
    el?.addEventListener('change', () => { st[key] = el.value; st.page = 1; load(); });
  }

  main.append(
    h('div', { class: 'page-head' }, h('div', {}, h('h1', { text: 'History' }),
      h('p', { text: isAdmin ? 'Every upload and conversion by every user. Files are never stored, so a converted file cannot be downloaded again.' : 'Your uploads and conversions. Files are never stored, so a converted file cannot be downloaded again.' }))),
    h('section', { class: 'panel' }, h('div', { class: 'toolbar' }, q, status, from, to, user), host));

  async function load() {
    const params = new URLSearchParams({ page: st.page, size: 25 });
    for (const k of ['q', 'status', 'from', 'to', 'user']) if (st[k]) params.set(k, st[k]);
    host.replaceChildren(h('p', { class: 'muted', text: 'Loading…' }));
    try {
      const data = await api(`/history?${params}`, { signal: ctrl.signal });
      if (data.total === 0) {
        host.replaceChildren(h('div', { class: 'empty' }, h('b', { text: 'Nothing found' }), h('span', { text: 'Uploads appear here as soon as a file is checked.' })));
        return;
      }
      host.replaceChildren(
        h('div', { class: 'table-wrap' }, h('table', { class: 'data' },
          h('thead', {}, h('tr', {},
            h('th', { text: 'Uploaded' }), isAdmin ? h('th', { text: 'User' }) : null, h('th', { text: 'File' }), h('th', { text: 'Status' }),
            h('th', { class: 'num', text: 'Rows' }), h('th', { class: 'num', text: 'Errors' }), h('th', { class: 'num', text: 'Warnings' }),
            h('th', { text: 'Output' }), h('th', { class: 'num', text: 'Value' }))),
          h('tbody', {}, data.items.map((i) => {
            const tr = h('tr', { class: 'clickable', tabindex: '0' },
              h('td', { class: 'nowrap', text: fmt.date(i.createdAt) }),
              isAdmin ? h('td', { class: 'nowrap', text: i.user.displayName }) : null,
              h('td', { class: 'wrap', text: i.filename }),
              h('td', {}, statusPill(i.status)),
              h('td', { class: 'num', text: fmt.int(i.rows) }),
              h('td', { class: 'num', text: i.errors ? fmt.int(i.errors) : '—' }),
              h('td', { class: 'num', text: i.warnings ? fmt.int(i.warnings) : '—' }),
              h('td', { class: 'mono', text: i.conversion ? i.conversion.outputFilename : '' }),
              h('td', { class: 'num', text: i.conversion ? fmt.money(i.conversion.totalCents) : '—' }));
            const open = () => { location.hash = `#/imports/${i.id}`; };
            tr.addEventListener('click', open);
            tr.addEventListener('keydown', (e) => { if (e.key === 'Enter') open(); });
            return tr;
          })))),
        pager(data.page, data.size, data.total, (p) => { st.page = p; load(); }));
    } catch (err) {
      if (err.name !== 'AbortError') host.replaceChildren(errorNotice(err));
    }
  }
  load();
  return () => ctrl.abort();
}

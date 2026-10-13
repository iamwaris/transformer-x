import { api } from '../api.js';
import { h, fmt, errorNotice } from '../dom.js';
import { columnChart, hbars } from '../charts.js';
import { issueLabel } from '../labels.js';

/** Local calendar date as YYYY-MM-DD (toISOString alone would shift to UTC). */
function iso(d) {
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

export async function renderStats(main, _params, { state }) {
  const isAdmin = state.user.role === 'admin';
  const users = isAdmin ? (await api('/admin/users')).items : [];
  const today = new Date();
  const st = { from: iso(new Date(today.getTime() - 29 * 86400000)), to: iso(today), user: '' };

  const from = h('input', { class: 'input', type: 'date', value: st.from, 'aria-label': 'From date' });
  const to = h('input', { class: 'input', type: 'date', value: st.to, 'aria-label': 'To date' });
  const user = isAdmin ? h('select', { class: 'input', 'aria-label': 'User' }, h('option', { value: '', text: 'All users' }),
    users.map((u) => h('option', { value: u.id, text: `${u.displayName} (${u.username})` }))) : null;
  const presets = h('div', { class: 'seg', role: 'group', 'aria-label': 'Period' }, [[7, '7 days'], [30, '30 days'], [90, '90 days'], [365, '12 months']].map(([days, label]) =>
    h('button', { type: 'button', 'aria-pressed': String(days === 30), onclick: (e) => {
      st.from = iso(new Date(today.getTime() - (days - 1) * 86400000)); st.to = iso(today);
      from.value = st.from; to.value = st.to;
      presets.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b === e.currentTarget)));
      load();
    } }, label)));
  const host = h('div', { class: 'view', 'aria-live': 'polite' });
  for (const [el, key] of [[from, 'from'], [to, 'to'], [user, 'user']]) {
    el?.addEventListener('change', () => { st[key] = el.value; presets.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', 'false')); load(); });
  }

  main.append(
    h('div', { class: 'page-head' }, h('div', {}, h('h1', { text: 'Statistics' }),
      h('p', { text: isAdmin ? 'Totals for every user, from the permanent record of uploads and conversions.' : 'Your uploads and conversions, from the permanent record.' })),
    h('div', { class: 'toolbar' }, presets, from, to, user)),
    host);

  async function load() {
    const params = new URLSearchParams({ from: st.from, to: st.to });
    if (st.user) params.set('user', st.user);
    host.replaceChildren(h('p', { class: 'muted', text: 'Loading…' }));
    try {
      render(await api(`/stats?${params}`));
    } catch (err) {
      host.replaceChildren(errorNotice(err));
    }
  }

  function render(d) {
    const c = d.conversions;
    const i = d.imports;
    const points = d.daily.map((x) => ({ label: x.date.slice(5).replace('-', '/'), a: x.imports, b: x.conversions }));
    const rowsPoints = d.daily.map((x) => ({ label: x.date.slice(5).replace('-', '/'), a: x.rows }));
    host.replaceChildren(
      h('div', { class: 'tiles' },
        tile('Uploads', fmt.int(i.total), `${fmt.int(i.rows_read)} rows checked`),
        tile('Conversions', fmt.int(c.total), i.total ? `${Math.round((100 * i.converted) / i.total)}% of uploads` : ''),
        tile('Products converted', fmt.int(c.rowsOut)),
        tile('Catalogue value', fmt.money(c.totalCents), 'sum of Regular price'),
        tile('Priced $0.00', fmt.int(c.zeroPriceRows), c.rowsOut ? `${((100 * c.zeroPriceRows) / c.rowsOut).toFixed(1)}% of products` : '', c.zeroPriceRows ? 'warn' : ''),
        tile('Blocked or rejected', fmt.int(i.blocked + i.rejected), 'files with errors', i.blocked + i.rejected ? 'bad' : ''),
        tile('Abandoned previews', fmt.int(i.abandoned), 'discarded or expired'),
        tile('Average conversion', fmt.ms(c.avgDurationMs))),
      h('section', { class: 'panel' }, h('header', {}, h('h2', { text: 'Uploads and conversions per day' })),
        columnChart(points, { aLabel: 'uploads', bLabel: 'conversions' })),
      h('section', { class: 'panel' }, h('header', {}, h('h2', { text: 'Products converted per day' })),
        columnChart(rowsPoints, { aLabel: 'products' })),
      h('div', { class: 'grid-2' },
        h('section', { class: 'panel' }, h('h2', { text: 'By product category' }), hbars(d.byCategory)),
        h('section', { class: 'panel' }, h('h2', { text: 'By product type' }), hbars(d.byProductType))),
      h('div', { class: 'grid-2' },
        h('section', { class: 'panel' }, h('h2', { text: 'Most common problems in uploads' }),
          hbars(d.issues, { label: (x) => `${x.severity === 'error' ? 'Error' : 'Warning'}: ${issueLabel(x.code)}`, value: (x) => x.count })),
        h('section', { class: 'panel' }, h('h2', { text: 'Catalogue value by category' }),
          hbars(d.byCategory, { value: (x) => x.totalCents, format: fmt.money }))),
      d.byUser.length ? h('section', { class: 'panel' }, h('h2', { text: 'By user' }),
        h('div', { class: 'table-wrap' }, h('table', { class: 'data' },
          h('thead', {}, h('tr', {}, h('th', { text: 'User' }), h('th', { class: 'num', text: 'Uploads' }), h('th', { class: 'num', text: 'Conversions' }), h('th', { class: 'num', text: 'Products' }))),
          h('tbody', {}, d.byUser.map((u) => h('tr', {},
            h('td', { text: `${u.displayName} (${u.username})` }), h('td', { class: 'num', text: fmt.int(u.imports) }),
            h('td', { class: 'num', text: fmt.int(u.conversions) }), h('td', { class: 'num', text: fmt.int(u.rows) }))))))) : null,
      h('p', { class: 'muted small', text: `All time: ${fmt.plural(d.allTime.conversions, 'conversion')}, ${fmt.plural(d.allTime.rowsOut, 'product')} converted${d.allTime.firstAt ? ` since ${fmt.date(d.allTime.firstAt, false)}` : ''}.` }));
  }
  load();
}

function tile(label, value, sub, kind = '') {
  return h('div', { class: `tile ${kind}` }, h('span', { class: 'label', text: label }), h('span', { class: 'value', title: value, text: value }), sub ? h('span', { class: 'sub', text: sub }) : null);
}

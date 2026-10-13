import { api } from '../api.js';
import { h, icon, fmt, statusPill } from '../dom.js';

export async function renderDashboard(main, _params, { state }) {
  const data = await api('/dashboard');
  const hour = new Date().getHours();
  const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';

  main.append(
    h('div', { class: 'page-head' },
      h('div', {}, h('h1', { text: `${greeting}, ${state.user.displayName}` }),
        h('p', { text: 'Turn a product upload sheet into a WooCommerce import file. You will see a full preview before anything is converted.' })),
      h('div', { class: 'actions' }, h('a', { class: 'btn primary big', href: '#/convert' }, icon('upload'), 'New conversion'))),

    h('div', { class: 'tiles' },
      tile('Conversions this month', fmt.int(data.month.conversions)),
      tile('Products converted this month', fmt.int(data.month.rowsOut)),
      tile('Catalogue value this month', fmt.money(data.month.totalCents), 'Sum of Regular price'),
    ),
  );

  if (data.open.length) {
    main.append(h('section', { class: 'panel' },
      h('header', {}, h('h2', { text: 'Waiting for you' }), h('p', { text: 'Previews you have not converted yet. They are discarded automatically if left open.' })),
      h('div', { class: 'table-wrap' }, h('table', { class: 'data' },
        h('thead', {}, h('tr', {}, h('th', { text: 'File' }), h('th', { text: 'Uploaded' }), h('th', { class: 'num', text: 'Rows' }), h('th', { text: 'Status' }), h('th', {}))),
        h('tbody', {}, data.open.map((i) => h('tr', {},
          h('td', { class: 'wrap', text: i.filename }),
          h('td', { class: 'nowrap', text: fmt.date(i.createdAt) }),
          h('td', { class: 'num', text: fmt.int(i.rows) }),
          h('td', {}, statusPill(i.status), i.errors ? h('span', { class: 'muted small', text: ` ${fmt.plural(i.errors, 'error')}` }) : null),
          h('td', { class: 'num' }, h('a', { class: 'btn small', href: `#/imports/${i.id}` }, i.status === 'ready' ? 'Review & convert' : 'See errors')))))))));
  }

  main.append(h('section', { class: 'panel' },
    h('header', {}, h('h2', { text: 'Your recent activity' }), h('a', { href: '#/history', text: 'All history' })),
    data.recent.length === 0
      ? h('div', { class: 'empty' }, h('b', { text: 'Nothing here yet' }), h('span', { text: 'Your uploads and conversions will be listed here.' }),
          h('a', { class: 'btn primary', href: '#/convert' }, 'Start your first conversion'))
      : h('div', { class: 'table-wrap' }, h('table', { class: 'data' },
          h('thead', {}, h('tr', {}, h('th', { text: 'File' }), h('th', { text: 'When' }), h('th', { text: 'Status' }), h('th', { class: 'num', text: 'Products' }), h('th', { class: 'num', text: 'Value' }))),
          h('tbody', {}, data.recent.map((i) => {
            const tr = h('tr', { class: 'clickable', tabindex: '0' },
              h('td', { class: 'wrap' }, i.filename, i.outputFilename ? h('div', { class: 'muted small mono', text: `→ ${i.outputFilename}` }) : null),
              h('td', { class: 'nowrap', text: fmt.date(i.createdAt) }),
              h('td', {}, statusPill(i.status)),
              h('td', { class: 'num', text: fmt.int(i.rowsOut ?? i.rows) }),
              h('td', { class: 'num', text: i.totalCents === null ? '—' : fmt.money(i.totalCents) }));
            const open = () => { location.hash = `#/imports/${i.id}`; };
            tr.addEventListener('click', open);
            tr.addEventListener('keydown', (e) => { if (e.key === 'Enter') open(); });
            return tr;
          }))))));

  main.append(h('section', { class: 'grid-2' },
    h('div', { class: 'panel' }, h('h2', { text: 'New here?' }),
      h('p', { text: 'The help guide explains the upload format, every calculation (prices, categories, attributes) and what each warning means.' }),
      h('div', {}, h('a', { class: 'btn', href: '#/help' }, icon('help'), 'Open the help guide'))),
    h('div', { class: 'panel' }, h('h2', { text: 'Statistics' }),
      h('p', { text: 'See how many products were converted, their value, and the most common problems in uploaded files.' }),
      h('div', {}, h('a', { class: 'btn', href: '#/stats' }, icon('chart'), 'View statistics')))));
}

function tile(label, value, sub) {
  return h('div', { class: 'tile' }, h('span', { class: 'label', text: label }), h('span', { class: 'value', text: value }), sub ? h('span', { class: 'sub', text: sub }) : null);
}

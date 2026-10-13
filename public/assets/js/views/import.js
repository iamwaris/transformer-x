// Steps 2-4: review the preview, convert, download. Also the read-only summary of a closed import.
import { api, download, ApiError } from '../api.js';
import { h, clear, icon, fmt, statusPill, errorNotice, toast, confirmDialog, setBusy, pager } from '../dom.js';
import { issueLabel, issueFix } from '../labels.js';
import { steps } from './convert.js';

const OUTPUT_DEFAULT = ['SKU', 'Name', 'Regular price', 'Categories', 'Stock', 'Product Type', 'Attribute 1 name', 'Attribute 1 value(s)'];
const LONG_COLUMNS = new Set(['Categories', 'Short description', 'Product Description (Copy)']);
const INPUT_DEFAULT =['Internal Reference', 'Name', 'Product Category', 'Product Type', 'Sales Price', 'Average Weight', 'Forecasted Quantity', 'Unit'];

export async function renderImport(main, { id }, ctx) {
  const imp = await api(`/imports/${id}`);
  // An open preview is still part of "New conversion"; a closed one is history.
  const navKey = imp.previewOpen || imp.status === 'rejected' ? 'convert' : 'history';
  document.querySelectorAll('.sidebar [data-nav]').forEach((a) => {
    if (a.dataset.nav === navKey) a.setAttribute('aria-current', 'page');
    else a.removeAttribute('aria-current');
  });
  if (imp.status === 'rejected') return renderRejected(main, imp);
  if (imp.previewOpen) return renderPreview(main, imp, ctx);
  return renderClosed(main, imp, false);
}

function head(imp, step, title) {
  return h('div', { class: 'page-head' },
    h('div', {},
      h('div', { class: 'eyebrow', text: title }),
      h('h1', { text: imp.filename }),
      h('p', {}, `${fmt.bytes(imp.fileSize)} · uploaded ${fmt.date(imp.createdAt)} by ${imp.user.displayName} · `, statusPill(imp.status))),
    step ? steps(step) : null);
}

function issueList(counts, severity) {
  const entries = Object.entries(counts || {}).sort((a, b) => b[1] - a[1]);
  return h('ul', {}, entries.map(([code, n]) => h('li', {},
    h('b', { text: `${issueLabel(code)}: ${fmt.int(n)}` }),
    issueFix(code) ? h('span', { class: 'muted', text: ` — ${issueFix(code)}` }) : null)));
}

// ------------------------------------------------------------------ rejected file
function renderRejected(main, imp) {
  main.append(
    head(imp, 1, 'New conversion'),
    h('div', { class: 'notice bad', role: 'alert' }, h('span', { class: 'ico', text: '!' }),
      h('div', {},
        h('b', { text: 'This file cannot be converted as it is.' }),
        h('ul', {}, imp.fileIssues.map((i) => h('li', {}, i.message))),
        h('span', { text: 'Fix the sheet in Excel and upload it again.' }))),
    h('div', { class: 'toolbar' },
      h('a', { class: 'btn primary', href: '#/convert' }, icon('upload'), 'Upload a corrected file'),
      h('a', { class: 'btn ghost', href: '#/help' }, 'See the required format')));
}

// ------------------------------------------------------------------ open preview
function renderPreview(main, imp, { navigate }) {
  const s = imp.summary;
  const blocked = imp.status === 'blocked';
  const ctrl = new AbortController();

  main.append(head(imp, 2, 'New conversion'));

  main.append(h('div', { class: 'tiles' },
    tile('Products', fmt.int(s.rows), s.blankRowsSkipped ? `${fmt.int(s.blankRowsSkipped)} empty rows skipped` : 'rows read'),
    tile('Ready', fmt.int(s.rowsByOk), 'no remarks', 'ok'),
    tile('With warnings', fmt.int(s.rowsByWarning), 'will still convert', s.rowsByWarning ? 'warn' : ''),
    tile('With errors', fmt.int(s.rowsByError), 'block conversion', s.rowsByError ? 'bad' : ''),
    tile('Catalogue value', fmt.money(s.totalCents), 'sum of Regular price'),
    tile('Priced $0.00', fmt.int(s.zeroPrice), s.blankPrice ? `${fmt.int(s.blankPrice)} left blank` : 'products', s.zeroPrice ? 'warn' : ''),
  ));

  if (blocked) {
    main.append(h('div', { class: 'notice bad', role: 'alert' }, h('span', { class: 'ico', text: '!' }),
      h('div', {},
        h('b', { text: `Conversion is blocked: ${fmt.plural(s.rowsByError, 'row has', 'rows have')} errors.` }),
        issueList(s.errorsByCode),
        h('span', { text: 'Fix these in the sheet and upload it again. Use the "Errors" filter below to see each row.' }))));
  }
  if (s.warningCount) {
    main.append(h('div', { class: 'notice warn' }, h('span', { class: 'ico', text: '!' }),
      h('div', {},
        h('b', { text: `${fmt.plural(s.rowsByWarning, 'row has', 'rows have')} warnings. ${blocked ? '' : 'They will still be converted exactly as the Access tool would.'}` }),
        issueList(s.warningsByCode))));
  }

  main.append(h('div', { class: 'grid-2' },
    breakdownPanel('By product category', s.byCategory),
    breakdownPanel('By product type', s.byProductType)));

  // ---- rows grid
  const st = { view: 'output', status: blocked ? 'error' : '', q: '', page: 1, all: false };
  const tabs = h('div', { class: 'tabs', role: 'tablist' });
  const filters = h('div', { class: 'seg', role: 'group', 'aria-label': 'Filter rows' });
  const search = h('input', { class: 'input grow', type: 'search', placeholder: 'Search reference, name, category…', 'aria-label': 'Search rows', maxlength: '100' });
  const allCols = h('label', { class: 'check' }, h('input', { type: 'checkbox', id: 'all-cols' }), 'Show all columns');
  const gridHost = h('div', { class: 'grid' });

  const tabDefs = [['output', 'Output preview'], ['input', 'Input as read']];
  for (const [key, label] of tabDefs) {
    tabs.append(h('button', { role: 'tab', type: 'button', 'aria-selected': String(st.view === key), onclick: (e) => {
      st.view = key; st.page = 1;
      tabs.querySelectorAll('button').forEach((b) => b.setAttribute('aria-selected', String(b === e.currentTarget)));
      load();
    } }, label));
  }
  const filterDefs = [['', 'All', s.rows], ['error', 'Errors', s.rowsByError], ['warning', 'Warnings', s.rowsByWarning], ['ok', 'OK', s.rowsByOk]];
  for (const [key, label, n] of filterDefs) {
    filters.append(h('button', { type: 'button', 'aria-pressed': String(st.status === key), onclick: (e) => {
      st.status = key; st.page = 1;
      filters.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b === e.currentTarget)));
      load();
    } }, `${label} (${fmt.int(n)})`));
  }
  let timer;
  search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { st.q = search.value.trim(); st.page = 1; load(); }, 300); });
  allCols.querySelector('input').addEventListener('change', (e) => { st.all = e.target.checked; load(); });

  main.append(h('section', { class: 'panel' },
    h('header', {}, h('h2', { text: 'Rows' }), h('p', { text: 'Click a row to see how each value was calculated.' })),
    tabs,
    h('div', { class: 'toolbar' }, filters, search, allCols),
    gridHost));

  async function load() {
    gridHost.replaceChildren(h('p', { class: 'muted', text: 'Loading rows…' }));
    const q = new URLSearchParams({ view: st.view, page: st.page, size: 50, status: st.status, q: st.q });
    try {
      const data = await api(`/imports/${imp.id}/rows?${q}`, { signal: ctrl.signal });
      gridHost.replaceChildren(grid(data, st, (row) => openRow(imp.id, row)), pager(data.page, data.size, data.total, (p) => { st.page = p; load(); }));
    } catch (err) {
      if (err.name !== 'AbortError') gridHost.replaceChildren(errorNotice(err));
    }
  }
  load();

  // ---- sticky actions
  const convertBtn = h('button', { class: 'btn primary big', type: 'button', disabled: !imp.canConvert }, icon('download'), `Convert ${fmt.plural(s.rows, 'product')}`);
  const cancelBtn = h('button', { class: 'btn', type: 'button', disabled: !imp.canCancel }, 'Discard');
  main.append(h('div', { class: 'sticky-actions' },
    h('span', { class: 'muted small', text: blocked
      ? 'Fix the errors and upload the file again.'
      : `Preview kept until ${fmt.date(imp.previewExpiresAt)}. Converting downloads the file once; nothing is stored.` }),
    h('div', { class: 'toolbar' }, cancelBtn, blocked ? h('a', { class: 'btn primary', href: '#/convert' }, icon('upload'), 'Upload corrected file') : convertBtn)));

  cancelBtn.addEventListener('click', async () => {
    if (!(await confirmDialog({ title: 'Discard this preview?', body: 'The uploaded rows are deleted. You can upload the file again later.', confirmLabel: 'Discard', danger: true }))) return;
    try { await api(`/imports/${imp.id}/cancel`, { method: 'POST' }); toast('Preview discarded.'); navigate('#/'); } catch (err) { toast(err.message, 'bad'); }
  });

  convertBtn.addEventListener('click', async () => {
    const extra = s.warningCount ? h('p', { class: 'muted small', text: `${fmt.plural(s.rowsByWarning, 'row has', 'rows have')} warnings (for example ${fmt.int(s.zeroPrice)} priced $0.00). They are converted as they are.` }) : null;
    const ok = await confirmDialog({
      title: 'Convert and download?',
      body: `This creates the WooCommerce import file with ${fmt.plural(s.rows, 'product')} and downloads it. The file is not stored on the server, so keep the download safe.`,
      extra, confirmLabel: 'Convert and download',
    });
    if (!ok) return;
    setBusy(convertBtn, true, 'Converting…');
    cancelBtn.disabled = true;
    try {
      const file = await download(`/imports/${imp.id}/convert`, 'POST');
      ctrl.abort();
      const fresh = await api(`/imports/${imp.id}`);
      clear(main);
      renderClosed(main, fresh, true, file);
    } catch (err) {
      setBusy(convertBtn, false);
      cancelBtn.disabled = false;
      toast(err.message, 'bad');
      if (err instanceof ApiError && err.status === 409) navigate(`#/imports/${imp.id}`);
    }
  });

  return () => ctrl.abort();
}

function tile(label, value, sub, kind = '') {
  return h('div', { class: `tile ${kind}` }, h('span', { class: 'label', text: label }), h('span', { class: 'value', title: value, text: value }), sub ? h('span', { class: 'sub', text: sub }) : null);
}

function breakdownPanel(title, groups) {
  const entries = Object.entries(groups || {}).sort((a, b) => b[1].rows - a[1].rows);
  return h('section', { class: 'panel' },
    h('h2', { text: title }),
    entries.length === 0 ? h('p', { class: 'muted', text: 'No convertible rows.' }) :
      h('div', { class: 'table-wrap' }, h('table', { class: 'data' },
        h('thead', {}, h('tr', {}, h('th', { text: 'Name' }), h('th', { class: 'num', text: 'Products' }), h('th', { class: 'num', text: 'Value' }), h('th', { class: 'num', text: '$0.00' }))),
        h('tbody', {}, entries.map(([name, g]) => h('tr', {},
          h('td', { text: name }), h('td', { class: 'num', text: fmt.int(g.rows) }), h('td', { class: 'num', text: fmt.money(g.cents) }),
          h('td', { class: 'num', text: g.zeroPrice ? fmt.int(g.zeroPrice) : '—' })))))));
}

function grid(data, st, onRow) {
  if (data.total === 0) return h('div', { class: 'empty' }, h('b', { text: 'No rows match.' }), h('span', { text: 'Change the filter or search.' }));
  const preferred = data.view === 'output' ? OUTPUT_DEFAULT : INPUT_DEFAULT;
  const idx = data.columns.map((c, i) => [c, i]).filter(([c]) => st.all || preferred.includes(c));
  const numeric = new Set(data.numericColumns);
  return h('div', { class: 'table-wrap' }, h('table', { class: 'data grid-table' },
    h('thead', {}, h('tr', {}, h('th', { class: 'num', text: 'Row' }), h('th', { text: 'Check' }), idx.map(([c]) => h('th', { class: numeric.has(c) ? 'num' : '', text: c })))),
    h('tbody', {}, data.rows.map((r) => {
      const tr = h('tr', { class: `clickable row-${r.status}`, tabindex: '0', title: r.issues.map((i) => i.message).join('\n') },
        h('td', { class: 'num', text: r.row }),
        h('td', {}, rowPill(r)),
        r.cells === null
          ? h('td', { colspan: idx.length, class: 'muted', text: 'Not converted — fix the error first.' })
          : idx.map(([c, i]) => {
            const v = r.cells[i];
            const cls = numeric.has(c) ? 'num' : c === 'SKU' || c === 'Internal Reference' ? 'ref' : LONG_COLUMNS.has(c) ? 'long' : '';
            return h('td', { class: cls, title: v === null ? null : String(v), text: v === null ? '' : String(v) });
          }));
      tr.addEventListener('click', () => onRow(r.row));
      tr.addEventListener('keydown', (e) => { if (e.key === 'Enter') onRow(r.row); });
      return tr;
    }))));
}

function rowPill(r) {
  if (r.status === 'ok') return h('span', { class: 'pill ok', text: 'OK' });
  const first = r.issues.find((i) => i.severity === (r.status === 'error' ? 'error' : 'warning')) || r.issues[0];
  const more = r.issues.length > 1 ? ` +${r.issues.length - 1}` : '';
  return h('span', { class: `pill ${r.status === 'error' ? 'bad' : 'warn'}`, text: `${issueLabel(first.code)}${more}` });
}

// ------------------------------------------------------------------ row explanation drawer
async function openRow(importId, rowNo) {
  const body = h('div', { class: 'body' }, h('p', { class: 'muted', text: 'Loading…' }));
  const closeBtn = h('button', { class: 'btn small', type: 'button', 'aria-label': 'Close' }, icon('x', 16), 'Close');
  const panel = h('aside', { class: 'drawer', role: 'dialog', 'aria-modal': 'true', 'aria-label': `Row ${rowNo}` },
    h('header', {}, h('h2', { text: `Row ${rowNo}` }), closeBtn), body);
  const backdrop = h('div', { class: 'drawer-backdrop' }, panel);
  const prevFocus = document.activeElement;
  const close = () => { backdrop.remove(); document.removeEventListener('keydown', onKey); prevFocus?.focus?.(); };
  const onKey = (e) => { if (e.key === 'Escape') close(); };
  backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
  closeBtn.addEventListener('click', close);
  document.addEventListener('keydown', onKey);
  document.body.append(backdrop);
  closeBtn.focus();

  try {
    const d = await api(`/imports/${importId}/rows/${rowNo}`);
    body.replaceChildren(...explain(d));
  } catch (err) {
    body.replaceChildren(errorNotice(err));
  }
}

function explain(d) {
  const out = [];
  if (d.issues.length) {
    out.push(h('div', { class: `notice ${d.status === 'error' ? 'bad' : 'warn'}` }, h('span', { class: 'ico', text: '!' }),
      h('div', {}, h('ul', {}, d.issues.map((i) => h('li', {}, h('b', { text: `${issueLabel(i.code)}: ` }), i.message))))));
  } else {
    out.push(h('div', { class: 'notice ok' }, h('span', { class: 'ico', text: '✓' }), h('div', {}, 'No problems found in this row.')));
  }

  const c = d.conversion;
  if (c) {
    const p = c.price;
    const lines = [];
    lines.push(h('div', { text: p.formula === 'parcel'
      ? 'Product Type is "Parcel", so: Sales Price × Average Weight'
      : 'Product Type is not "Parcel", so: Sales Price × Forecasted Quantity' }));
    if (p.raw === null) {
      lines.push(h('div', { text: `${p.a === null ? 'Sales Price' : p.bLabel} is empty → Regular price is left empty` }));
    } else {
      lines.push(h('div', { text: `${num(p.a)} × ${num(p.b)} = ${num(p.raw)}` }));
      lines.push(h('div', {}, 'Rounded to cents (half up) → ', h('span', { class: 'res', text: p.text })));
    }
    out.push(section('Regular price', h('div', { class: 'calc' }, lines)));
    out.push(section('Size attribute', h('div', { class: 'calc' },
      h('div', { text: 'Length & "x" & Width & "x" & Depth & " mm"' }),
      h('div', {}, '→ ', h('span', { class: 'res', text: c.size })))));
    out.push(section('Categories', c.categories.length
      ? h('div', { class: 'chips' }, c.categories.map((x) => h('span', { class: 'chip', text: x })))
      : h('p', { class: 'muted', text: 'None (Product Category is empty).' })));
    out.push(section(`Attributes (${c.attributes.length} of 18 slots)`, h('div', { class: 'kv' },
      c.attributes.map((a, i) => [h('div', { class: 'k', text: `${i + 1}. ${a.name}` }), h('div', { text: a.value })])),
    c.droppedAttributes.length ? h('div', { class: 'chips' }, h('span', { class: 'muted small', text: 'Left out (no free slot): ' }), c.droppedAttributes.map((x) => h('span', { class: 'chip dropped', text: x }))) : null));
  }

  out.push(section('Values read from the sheet', h('div', { class: 'kv' },
    Object.entries(d.input).map(([k, v]) => [h('div', { class: 'k', text: k }), h('div', { class: v === null ? 'muted' : '', text: v === null ? '(empty)' : JSON.stringify(v).replace(/^"|"$/g, '') })]))));

  if (c) {
    out.push(section('Output row (non-empty cells)', h('div', { class: 'kv' },
      Object.entries(c.output).filter(([, v]) => v !== null).map(([k, v]) => [h('div', { class: 'k', text: k }), h('div', { class: typeof v === 'number' ? 'num' : '', text: String(v) })]))));
  }
  return out;
}

function section(title, ...content) {
  return h('section', { class: 'panel' }, h('h3', { text: title }), ...content);
}

/** Numbers at the 15 significant digits Access works with (5.9750000000000005 → 5.975). */
function num(v) {
  return v === null || v === undefined ? '(empty)' : String(Number(v.toPrecision(15)));
}

// ------------------------------------------------------------------ closed import (converted / cancelled / expired)
function renderClosed(main, imp, justConverted, file) {
  const s = imp.summary;
  const c = imp.conversion;
  main.append(head(imp, justConverted ? 4 : null, justConverted ? 'New conversion' : 'History'));

  if (justConverted && c) {
    main.append(h('div', { class: 'notice ok', role: 'status' }, h('span', { class: 'ico', text: '✓' }),
      h('div', {},
        h('b', { text: `Converted ${fmt.plural(c.rowsOut, 'product')} and downloaded ${c.outputFilename}.` }),
        h('span', { text: 'The file was not kept on the server. If you need it again, upload the source sheet again.' }))));
  } else if (imp.status !== 'converted') {
    const why = imp.status === 'expired' ? 'This preview expired before it was converted.' : 'This preview was discarded before it was converted.';
    main.append(h('div', { class: 'notice' }, h('span', { class: 'ico', text: 'i' }), h('div', {}, why)));
  }

  if (c) {
    main.append(h('div', { class: 'tiles' },
      tile('Products converted', fmt.int(c.rowsOut)),
      tile('Catalogue value', fmt.money(c.totalCents), 'sum of Regular price'),
      tile('Priced $0.00', fmt.int(c.zeroPriceRows), c.blankPriceRows ? `${fmt.int(c.blankPriceRows)} blank` : '', c.zeroPriceRows ? 'warn' : ''),
      tile('Attributes written', fmt.int(c.attributeCount), c.attributesDroppedRows ? `${fmt.int(c.attributesDroppedRows)} rows hit the 18 limit` : ''),
      tile('Conversion time', fmt.ms(c.durationMs)),
      tile('Output file', fmt.bytes(c.outputSize), c.outputFilename)));
    const cats = c.breakdown.filter((b) => b.dimension === 'category');
    const types = c.breakdown.filter((b) => b.dimension === 'product_type');
    const toGroups = (list) => Object.fromEntries(list.map((b) => [b.name, { rows: b.rows, cents: b.totalCents, zeroPrice: b.zeroPriceRows }]));
    main.append(h('div', { class: 'grid-2' }, breakdownPanel('By product category', toGroups(cats)), breakdownPanel('By product type', toGroups(types))));
  } else {
    main.append(h('div', { class: 'tiles' },
      tile('Rows read', fmt.int(s.rows)),
      tile('With errors', fmt.int(s.rowsByError), '', s.rowsByError ? 'bad' : ''),
      tile('With warnings', fmt.int(s.rowsByWarning), '', s.rowsByWarning ? 'warn' : '')));
  }

  if (s.errorCount || s.warningCount) {
    main.append(h('section', { class: 'panel' }, h('h2', { text: 'What the check found' }),
      s.errorCount ? h('div', {}, h('h3', { text: 'Errors' }), issueList(s.errorsByCode)) : null,
      s.warningCount ? h('div', {}, h('h3', { text: 'Warnings' }), issueList(s.warningsByCode)) : null));
  }

  main.append(h('section', { class: 'panel' }, h('h2', { text: 'Record' }), h('div', { class: 'kv' },
    kv('Uploaded file', imp.filename), kv('File size', fmt.bytes(imp.fileSize)), kv('Uploaded', fmt.date(imp.createdAt)),
    kv('Uploaded by', `${imp.user.displayName} (${imp.user.username})`), kv('Status', imp.status), kv('Closed', fmt.date(imp.closedAt)),
    c ? kv('Output file', c.outputFilename) : null, c ? kv('Output SHA-256', c.outputSha256) : null,
    kv('Engine version', c ? c.engineVersion : imp.engineVersion))));

  main.append(h('div', { class: 'toolbar' },
    h('a', { class: 'btn primary', href: '#/convert' }, icon('upload'), 'Convert another file'),
    h('a', { class: 'btn', href: '#/history' }, 'Back to history')));
}

function kv(k, v) {
  return [h('div', { class: 'k', text: k }), h('div', { text: v ?? '—' })];
}

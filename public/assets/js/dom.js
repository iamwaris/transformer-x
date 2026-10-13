// DOM helpers. Text is always set with textContent — server data is never parsed as HTML.

/**
 * h('button', {class: 'btn', onclick: fn, disabled: true}, 'Label', childNode)
 * Attributes: class, text, on<event>, dataset {}, style is not supported (CSP); everything else via setAttribute.
 */
export function h(tag, attrs = {}, ...children) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === undefined || v === null || v === false) continue;
    if (k === 'class') el.className = v;
    else if (k === 'text') el.textContent = v;
    else if (k === 'dataset') Object.assign(el.dataset, v);
    else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
    else if (k === 'value' && 'value' in el) el.value = v;
    else if (k === 'checked' || k === 'selected') el[k] = !!v;
    else el.setAttribute(k, v === true ? '' : String(v));
  }
  append(el, children);
  return el;
}

export function append(el, children) {
  for (const c of children.flat(Infinity)) {
    if (c === null || c === undefined || c === false) continue;
    el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return el;
}

export function clear(el) {
  el.replaceChildren();
  return el;
}

// ------------------------------------------------------------------ icons (stroke paths, 24px grid)
const ICONS = {
  home: 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z',
  upload: 'M12 15V4m0 0-4 4m4-4 4 4M4 15v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4',
  history: 'M3 12a9 9 0 1 0 3-6.7L3 8m0-5v5h5m4-1v5l3 2',
  chart: 'M4 20V10m6 10V4m6 16v-7m4 7H2',
  help: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zm-2.5-13a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6V14m0 3.5v.5',
  user: 'M20 21a8 8 0 0 0-16 0m8-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
  users: 'M16 21a6 6 0 0 0-12 0m6-9a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm12 9a6 6 0 0 0-4-5.7M16 4.1a4 4 0 0 1 0 7.8',
  shield: 'M12 22s8-3.5 8-10V5l-8-3-8 3v7c0 6.5 8 10 8 10zm-3-10 2 2 4-4',
  gear: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm7.4-3a7.4 7.4 0 0 0-.1-1.2l2-1.6-2-3.4-2.4 1a7.5 7.5 0 0 0-2-1.2L14.5 3h-5l-.4 2.6a7.5 7.5 0 0 0-2 1.2l-2.4-1-2 3.4 2 1.6a7.4 7.4 0 0 0 0 2.4l-2 1.6 2 3.4 2.4-1a7.5 7.5 0 0 0 2 1.2l.4 2.6h5l.4-2.6a7.5 7.5 0 0 0 2-1.2l2.4 1 2-3.4-2-1.6c.1-.4.1-.8.1-1.2z',
  file: 'M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8zm0 0v5h5M9 13h6m-6 4h6',
  download: 'M12 4v11m0 0-4-4m4 4 4-4M4 19h16',
  x: 'M6 6l12 12M18 6 6 18',
  check: 'M5 12.5 10 17 19 7',
  alert: 'M12 9v4m0 4h.01M10.3 3.9 2.4 18a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
  search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zm10 2-4.3-4.3',
  menu: 'M4 7h16M4 12h16M4 17h16',
};

export function icon(name, size = 18) {
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('width', size);
  svg.setAttribute('height', size);
  svg.setAttribute('fill', 'none');
  svg.setAttribute('stroke', 'currentColor');
  svg.setAttribute('stroke-width', '1.8');
  svg.setAttribute('stroke-linecap', 'round');
  svg.setAttribute('stroke-linejoin', 'round');
  svg.setAttribute('aria-hidden', 'true');
  const p = document.createElementNS(ns, 'path');
  p.setAttribute('d', ICONS[name] || ICONS.file);
  svg.append(p);
  return svg;
}

/** The Transformer X mark: a faceted gem. */
export function logo(size = 30) {
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', '0 0 32 32');
  svg.setAttribute('width', size);
  svg.setAttribute('height', size);
  svg.setAttribute('aria-hidden', 'true');
  const shapes = [
    ['path', { d: 'M9 5h14l6 8-13 15L3 13z', fill: '#c99a3b' }],
    ['path', { d: 'M3 13h26L16 28z', fill: '#8a6516' }],
    ['path', { d: 'M9 5l3 8h8l3-8', fill: 'none', stroke: '#f6e2a8', 'stroke-width': '1.4', 'stroke-linejoin': 'round' }],
    ['path', { d: 'M12 13l4 15 4-15', fill: 'none', stroke: '#f6e2a8', 'stroke-width': '1.4', 'stroke-linejoin': 'round' }],
  ];
  for (const [tag, attrs] of shapes) {
    const el = document.createElementNS(ns, tag);
    for (const [k, v] of Object.entries(attrs)) el.setAttribute(k, v);
    svg.append(el);
  }
  return svg;
}

// ------------------------------------------------------------------ formatting
const intFmt = new Intl.NumberFormat('en-US');
const moneyFmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

export const fmt = {
  int: (n) => intFmt.format(n ?? 0),
  money: (cents) => moneyFmt.format((cents ?? 0) / 100),
  bytes(n) {
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
    return `${(n / 1024 / 1024).toFixed(1)} MB`;
  },
  ms: (ms) => (ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`),
  /** "2026-10-13 05:14:02" (server local time) -> "13 Oct 2026, 05:14" */
  date(s, withTime = true) {
    if (!s) return '—';
    const [d, t = ''] = s.split(' ');
    const [y, m, day] = d.split('-').map(Number);
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return `${day} ${months[m - 1]} ${y}${withTime && t ? `, ${t.slice(0, 5)}` : ''}`;
  },
  plural: (n, one, many = `${one}s`) => `${intFmt.format(n)} ${n === 1 ? one : many}`,
};

export const STATUS = {
  ready: ['Ready to convert', 'accent'],
  blocked: ['Has errors', 'bad'],
  rejected: ['Rejected', 'bad'],
  converted: ['Converted', 'ok'],
  cancelled: ['Cancelled', ''],
  expired: ['Expired', ''],
};

export function statusPill(status) {
  const [label, cls] = STATUS[status] || [status, ''];
  return h('span', { class: `pill ${cls}`, text: label });
}

// ------------------------------------------------------------------ feedback
let toastHost = null;
export function toast(message, kind = '') {
  toastHost ||= document.body.appendChild(h('div', { class: 'toasts', role: 'status', 'aria-live': 'polite' }));
  const el = h('div', { class: `toast ${kind}` }, h('span', { text: message }), h('button', { 'aria-label': 'Dismiss', onclick: () => el.remove() }, '×'));
  toastHost.append(el);
  setTimeout(() => el.remove(), kind === 'bad' ? 9000 : 5000);
}

/** Modal built on <dialog>. Resolves true when the confirm button is used. */
export function confirmDialog({ title, body, confirmLabel = 'Confirm', cancelLabel = 'Cancel', danger = false, extra = null }) {
  return new Promise((resolve) => {
    const dlg = h('dialog', { class: 'modal', 'aria-labelledby': 'dlg-title' },
      h('div', { class: 'm-body' }, h('h2', { id: 'dlg-title', text: title }), typeof body === 'string' ? h('p', { text: body }) : body, extra),
      h('div', { class: 'm-actions' },
        cancelLabel ? h('button', { class: 'btn', onclick: () => dlg.close('cancel') }, cancelLabel) : null,
        h('button', { class: `btn ${danger ? 'danger' : 'primary'}`, onclick: () => dlg.close('ok') }, confirmLabel)));
    dlg.addEventListener('close', () => { resolve(dlg.returnValue === 'ok'); dlg.remove(); });
    document.body.append(dlg);
    dlg.showModal();
  });
}

/** Shows a one-time secret (temporary password) with a copy button. */
export function secretDialog(title, intro, secret) {
  const box = h('div', { class: 'secret', text: secret });
  const copy = h('button', { class: 'btn small', type: 'button', onclick: async () => {
    try { await navigator.clipboard.writeText(secret); copy.textContent = 'Copied'; } catch { getSelection().selectAllChildren(box); }
  } }, 'Copy');
  return confirmDialog({ title, body: h('div', { class: 'form' }, h('p', { text: intro }), box, copy), confirmLabel: 'Done', cancelLabel: null });
}

export function errorNotice(err) {
  return h('div', { class: 'notice bad', role: 'alert' }, h('span', { class: 'ico', text: '!' }), h('div', {}, h('span', { text: err.message || String(err) })));
}

export function setBusy(btn, busy, label) {
  if (busy) {
    btn.dataset.label = btn.textContent;
    btn.disabled = true;
    btn.replaceChildren(h('span', { class: 'spinner', 'aria-hidden': 'true' }), label || btn.dataset.label);
  } else {
    btn.disabled = false;
    btn.replaceChildren(btn.dataset.label || label || '');
  }
}

/** Shows field errors from a 422 response next to inputs with matching name attributes. */
export function showFieldErrors(form, fields) {
  form.querySelectorAll('.err').forEach((e) => e.remove());
  form.querySelectorAll('[aria-invalid]').forEach((e) => e.removeAttribute('aria-invalid'));
  for (const [name, msg] of Object.entries(fields || {})) {
    const input = form.querySelector(`[name="${CSS.escape(name)}"]`);
    if (!input) continue;
    input.setAttribute('aria-invalid', 'true');
    input.closest('.field')?.append(h('span', { class: 'err', text: msg }));
  }
  form.querySelector('[aria-invalid="true"]')?.focus();
}

export function field(label, input, hint) {
  const id = input.id || `f-${input.name || Math.random().toString(36).slice(2)}`;
  input.id = id;
  return h('div', { class: 'field' }, h('label', { for: id, text: label }), input, hint ? h('span', { class: 'hint', text: hint }) : null);
}

export function pager(page, size, total, onPage) {
  const pages = Math.max(1, Math.ceil(total / size));
  const from = total === 0 ? 0 : (page - 1) * size + 1;
  const to = Math.min(total, page * size);
  return h('div', { class: 'pager' },
    h('span', { text: total === 0 ? 'No rows' : `${fmt.int(from)}–${fmt.int(to)} of ${fmt.int(total)}` }),
    h('div', { class: 'toolbar' },
      h('button', { class: 'btn small', disabled: page <= 1, onclick: () => onPage(page - 1) }, '‹ Previous'),
      h('span', { text: `Page ${fmt.int(page)} of ${fmt.int(pages)}` }),
      h('button', { class: 'btn small', disabled: page >= pages, onclick: () => onPage(page + 1) }, 'Next ›')));
}

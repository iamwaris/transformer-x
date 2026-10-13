import { api, upload, download } from '../api.js';
import { h, icon, fmt, errorNotice, toast } from '../dom.js';

export function steps(current) {
  const names = ['Upload', 'Review preview', 'Convert', 'Download'];
  return h('ol', { class: 'steps', 'aria-label': 'Progress' }, names.map((n, i) => {
    const cls = i + 1 < current ? 'done' : i + 1 === current ? 'current' : '';
    return h('li', { class: cls, 'aria-current': i + 1 === current ? 'step' : null }, h('span', { class: 'n', text: i + 1 < current ? '✓' : String(i + 1) }), n);
  }));
}

export async function renderConvert(main, _params, { navigate }) {
  const format = await api('/format');

  const input = h('input', { type: 'file', accept: '.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', id: 'file-input' });
  const status = h('div', { 'aria-live': 'polite' });
  const bar = h('span');
  const progress = h('div', { class: 'progress', hidden: true }, bar);
  const zone = h('label', { class: 'dropzone', for: 'file-input' },
    h('span', { class: 'big-ico' }, icon('upload', 26)),
    h('b', { text: 'Drop your .xlsx file here, or click to choose it' }),
    h('span', { class: 'muted small', text: `Excel workbook, up to ${format.maxUploadMb} MB and ${fmt.int(format.maxRows)} products. Only the first sheet is read.` }),
    input);

  let busy = false;
  async function send(file) {
    if (busy || !file) return;
    status.replaceChildren();
    if (!/\.xlsx$/i.test(file.name)) {
      status.append(errorNotice({ message: `"${file.name}" is not an .xlsx file. In Excel use File › Save As › "Excel Workbook (*.xlsx)".` }));
      return;
    }
    if (file.size > format.maxUploadMb * 1024 * 1024) {
      status.append(errorNotice({ message: `"${file.name}" is ${fmt.bytes(file.size)}. The limit is ${format.maxUploadMb} MB.` }));
      return;
    }
    busy = true;
    zone.setAttribute('aria-disabled', 'true');
    progress.hidden = false;
    bar.style.width = '0%';
    status.append(h('p', { class: 'muted', text: `Uploading ${file.name}…` }));
    try {
      const result = await upload('/imports', file, (p) => {
        bar.style.width = `${Math.round(p * 100)}%`;
        if (p >= 1) status.replaceChildren(h('p', { class: 'muted', text: 'Checking every row and working out the conversion…' }));
      });
      navigate(`#/imports/${result.id}`);
    } catch (err) {
      busy = false;
      zone.removeAttribute('aria-disabled');
      progress.hidden = true;
      status.replaceChildren(errorNotice(err));
    }
  }

  input.addEventListener('change', () => send(input.files[0]));
  zone.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('over'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('over'));
  zone.addEventListener('drop', (e) => {
    e.preventDefault();
    zone.classList.remove('over');
    if (e.dataTransfer.files.length > 1) {
      status.replaceChildren(errorNotice({ message: 'Drop one file at a time.' }));
      return;
    }
    send(e.dataTransfer.files[0]);
  });

  const templateBtn = h('button', { class: 'btn', type: 'button', onclick: async () => {
    try { await download('/template'); } catch (err) { toast(err.message, 'bad'); }
  } }, icon('download'), 'Download blank template');

  main.append(
    h('div', { class: 'page-head' },
      h('div', {}, h('div', { class: 'eyebrow', text: 'New conversion' }), h('h1', { text: 'Upload the product sheet' }),
        h('p', { text: 'Nothing is converted yet. After the upload you get a full preview with every calculated price, category and attribute, and any problems to fix.' })),
      steps(1)),
    h('div', { class: 'grid-2' },
      h('section', { class: 'panel' }, zone, progress, status),
      h('section', { class: 'panel' },
        h('h2', { text: 'Before you upload' }),
        h('ul', { class: 'checklist' },
          h('li', { text: `Row 1 holds the ${format.inputColumns.length} column headers, spelled exactly as in the template. Their order does not matter.` }),
          h('li', { text: 'Every product has its own Internal Reference (it becomes the SKU). Blank or repeated references are not allowed.' }),
          h('li', { text: `${format.numericColumns.join(', ')} contain numbers, not text.` }),
          h('li', { text: 'Text cells are at most 255 characters.' }),
          h('li', { text: 'Your file is deleted from the server as soon as it has been read. Only statistics are kept.' })),
        h('div', { class: 'toolbar' }, templateBtn, h('a', { class: 'btn ghost', href: '#/help' }, 'Format details')))),
  );
}

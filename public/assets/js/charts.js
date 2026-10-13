// Small SVG charts drawn to scale with theme-token colours (classes in app.css).
import { h, fmt } from './dom.js';

const NS = 'http://www.w3.org/2000/svg';

function svgEl(tag, attrs = {}, text) {
  const el = document.createElementNS(NS, tag);
  for (const [k, v] of Object.entries(attrs)) el.setAttribute(k, String(v));
  if (text !== undefined) el.textContent = text;
  return el;
}

function niceMax(v) {
  if (v <= 4) return 4;
  const p = 10 ** Math.floor(Math.log10(v));
  for (const m of [1, 2, 2.5, 5, 10]) if (m * p >= v) return m * p;
  return 10 * p;
}

/**
 * Daily columns with an optional second series drawn as narrower inner bars.
 * @param {{label: string, a: number, b?: number}[]} points
 */
export function columnChart(points, { aLabel, bLabel, height = 220 } = {}) {
  const width = 760;
  const pad = { l: 40, r: 8, t: 10, b: 28 };
  const max = niceMax(Math.max(1, ...points.map((p) => Math.max(p.a, p.b ?? 0))));
  const iw = width - pad.l - pad.r;
  const ih = height - pad.t - pad.b;
  const svg = svgEl('svg', { viewBox: `0 0 ${width} ${height}`, class: 'chart', role: 'img', 'aria-label': `${aLabel} per day` });
  const grid = svgEl('g', { class: 'grid' });
  for (let i = 0; i <= 4; i++) {
    const y = pad.t + ih - (ih * i) / 4;
    grid.append(svgEl('line', { x1: pad.l, x2: width - pad.r, y1: y, y2: y }));
    svg.append(svgEl('text', { x: pad.l - 6, y: y + 4, 'text-anchor': 'end' }, fmt.int((max * i) / 4)));
  }
  svg.prepend(grid);
  const step = iw / Math.max(1, points.length);
  const bw = Math.max(2, Math.min(28, step * 0.7));
  const labelEvery = Math.ceil(points.length / 10);
  points.forEach((p, i) => {
    const x = pad.l + step * i + (step - bw) / 2;
    const ha = (ih * p.a) / max;
    const bar = svgEl('rect', { class: 'bar alt', x, y: pad.t + ih - ha, width: bw, height: Math.max(0, ha), rx: 2 });
    bar.append(svgEl('title', {}, `${p.label}: ${fmt.int(p.a)} ${aLabel}${p.b !== undefined ? `, ${fmt.int(p.b)} ${bLabel}` : ''}`));
    svg.append(bar);
    if (p.b !== undefined) {
      const hb = (ih * p.b) / max;
      const inner = svgEl('rect', { class: 'bar', x: x + bw * 0.2, y: pad.t + ih - hb, width: bw * 0.6, height: Math.max(0, hb), rx: 1.5 });
      inner.append(svgEl('title', {}, `${p.label}: ${fmt.int(p.b)} ${bLabel}`));
      svg.append(inner);
    }
    if (i % labelEvery === 0) svg.append(svgEl('text', { x: x + bw / 2, y: height - 8, 'text-anchor': 'middle' }, p.label));
  });
  const legend = h('div', { class: 'legend' },
    h('span', {}, h('i', { class: 'swatch-a' }), aLabel),
    bLabel ? h('span', {}, h('i', { class: 'swatch-b' }), bLabel) : null);
  return h('div', {}, svg, legend);
}

/** Horizontal bars for a ranked list. */
export function hbars(items, { value = (x) => x.rows, label = (x) => x.name, format = fmt.int } = {}) {
  if (!items.length) return h('p', { class: 'muted', text: 'No data for this period.' });
  const max = Math.max(1, ...items.map(value));
  return h('div', { class: 'hbars' }, items.map((it) => {
    const fill = h('div', { class: 'fill' });
    fill.style.width = `${(100 * value(it)) / max}%`;
    return h('div', { class: 'hbar' },
      h('span', { class: 'name', title: label(it), text: label(it) }),
      h('div', { class: 'track' }, fill),
      h('span', { class: 'val', text: format(value(it)) }));
  }));
}

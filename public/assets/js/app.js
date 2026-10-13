// Bootstraps the single-page app: session check, hash router, layout and navigation.
import { api, setCsrf } from './api.js';
import { h, clear, icon, logo, toast, errorNotice } from './dom.js';
import { applyTheme } from './theme.js';
import { renderLogin } from './views/login.js';
import { renderChangePassword } from './views/password.js';
import { renderDashboard } from './views/dashboard.js';
import { renderConvert } from './views/convert.js';
import { renderImport } from './views/import.js';
import { renderHistory } from './views/history.js';
import { renderStats } from './views/stats.js';
import { renderHelp } from './views/help.js';
import { renderProfile } from './views/profile.js';
import { renderUsers } from './views/admin-users.js';
import { renderAudit } from './views/admin-audit.js';
import { renderSettings } from './views/admin-settings.js';

const root = document.getElementById('app');

export const state = { user: null, idleMinutes: 30 };

const ROUTES = [
  { path: /^\/$/, view: renderDashboard, nav: 'home', title: 'Dashboard' },
  { path: /^\/convert$/, view: renderConvert, nav: 'convert', title: 'New conversion' },
  { path: /^\/imports\/(\d+)$/, view: renderImport, nav: 'history', title: 'Import', keys: ['id'] },
  { path: /^\/history$/, view: renderHistory, nav: 'history', title: 'History' },
  { path: /^\/stats$/, view: renderStats, nav: 'stats', title: 'Statistics' },
  { path: /^\/help$/, view: renderHelp, nav: 'help', title: 'Help' },
  { path: /^\/profile$/, view: renderProfile, nav: 'profile', title: 'My account' },
  { path: /^\/admin\/users$/, view: renderUsers, nav: 'users', title: 'Users', admin: true },
  { path: /^\/admin\/audit$/, view: renderAudit, nav: 'audit', title: 'Audit log', admin: true },
  { path: /^\/admin\/settings$/, view: renderSettings, nav: 'settings', title: 'Settings', admin: true },
];

const NAV = [
  ['home', '#/', 'Dashboard', 'home'],
  ['convert', '#/convert', 'New conversion', 'upload'],
  ['history', '#/history', 'History', 'history'],
  ['stats', '#/stats', 'Statistics', 'chart'],
  ['help', '#/help', 'Help guide', 'help'],
];
const ADMIN_NAV = [
  ['users', '#/admin/users', 'Users', 'users'],
  ['audit', '#/admin/audit', 'Audit log', 'shield'],
  ['settings', '#/admin/settings', 'Settings', 'gear'],
];

let cleanup = null;
let shell = null;
let navToken = 0;

export function navigate(hash) {
  if (location.hash === hash) route();
  else location.hash = hash;
}

function currentPath() {
  const p = location.hash.replace(/^#/, '') || '/';
  return p.split('?')[0];
}

export function queryParams() {
  const q = location.hash.split('?')[1] || '';
  return new URLSearchParams(q);
}

function buildShell() {
  const main = h('main', { class: 'main', id: 'main', tabindex: '-1' });
  const navLinks = (items) => items.map(([key, href, label, ico]) =>
    h('a', { class: 'nav-link', href, dataset: { nav: key } }, icon(ico), h('span', { text: label })));
  const sidebar = h('nav', { class: 'sidebar', 'aria-label': 'Main' },
    h('a', { class: 'brand', href: '#/' }, logo(), h('span', {}, h('b', { text: 'Transformer X' }), h('small', { text: 'Product converter' }))),
    navLinks(NAV),
    state.user.role === 'admin' ? [h('div', { class: 'nav-sep', text: 'Administration' }), navLinks(ADMIN_NAV)] : null,
    h('div', { class: 'nav-foot' },
      h('a', { class: 'nav-link', href: '#/profile', dataset: { nav: 'profile' } }, icon('user'),
        h('span', { class: 'who' }, h('b', { text: state.user.displayName }), h('span', { text: state.user.role === 'admin' ? 'Administrator' : 'User' }))),
      h('button', { type: 'button', onclick: signOut }, 'Sign out')));
  const menuBtn = h('button', { type: 'button', 'aria-expanded': 'false', 'aria-controls': 'sidebar' }, 'Menu');
  const topbar = h('header', { class: 'topbar' },
    h('a', { class: 'brand', href: '#/' }, logo(26), h('b', { text: 'Transformer X' })), menuBtn);
  sidebar.id = 'sidebar';
  const el = h('div', { class: 'shell' }, topbar, sidebar, main);
  menuBtn.addEventListener('click', () => {
    const open = el.classList.toggle('menu-open');
    menuBtn.setAttribute('aria-expanded', String(open));
  });
  el.addEventListener('click', (e) => {
    if (el.classList.contains('menu-open') && (e.target === el || e.target.closest('.nav-link'))) {
      el.classList.remove('menu-open');
      menuBtn.setAttribute('aria-expanded', 'false');
    }
  });
  return { el, main, sidebar };
}

async function route() {
  if (cleanup) { try { cleanup(); } catch { /* ignore */ } cleanup = null; }
  const path = currentPath();

  if (!state.user) {
    shell = null;
    clear(root).append(renderLogin(onSignedIn));
    document.title = 'Sign in · Transformer X';
    return;
  }
  if (state.user.mustChangePassword) {
    shell = null;
    clear(root).append(renderChangePassword({ forced: true, onDone: onPasswordChanged }));
    document.title = 'Choose a new password · Transformer X';
    return;
  }

  const match = ROUTES.map((r) => ({ r, m: path.match(r.path) })).find((x) => x.m);
  if (!shell) {
    shell = buildShell();
    clear(root).append(shell.el);
  }
  const main = shell.main;   // the previous page stays visible until the next one is ready
  shell.sidebar.querySelectorAll('[data-nav]').forEach((a) => {
    if (match && a.dataset.nav === match.r.nav) a.setAttribute('aria-current', 'page');
    else a.removeAttribute('aria-current');
  });

  if (!match || (match.r.admin && state.user.role !== 'admin')) {
    document.title = 'Not found · Transformer X';
    main.replaceChildren(h('div', { class: 'empty' }, h('b', { text: 'This page does not exist.' }), h('a', { href: '#/', text: 'Go to the dashboard' })));
    return;
  }
  document.title = `${match.r.title} · Transformer X`;
  const params = {};
  (match.r.keys || []).forEach((k, i) => { params[k] = Number(match.m[i + 1]); });
  // Render off-screen; only the latest navigation is shown (a slower earlier one is dropped).
  const token = ++navToken;
  const view = h('div', { class: 'view' });
  let viewCleanup = null;
  try {
    viewCleanup = (await match.r.view(view, params, { state, navigate })) || null;
  } catch (err) {
    if (err?.name === 'AbortError') return;
    view.append(errorNotice(err));
  }
  if (token !== navToken) {
    if (viewCleanup) viewCleanup();
    return;
  }
  cleanup = viewCleanup;
  main.replaceChildren(view);
  main.focus({ preventScroll: true });
  window.scrollTo(0, 0);
}

function onSignedIn(user) {
  state.user = user;
  applyTheme(user.theme);
  shell = null;
  if (!user.mustChangePassword && location.hash !== '' && location.hash !== '#/') {
    route();   // stay on the page the user asked for
  } else if (location.hash === '#/') {
    route();
  } else {
    location.hash = '#/';   // hashchange triggers route()
  }
}

function onPasswordChanged() {
  state.user.mustChangePassword = false;
  toast('Password changed.');
  shell = null;
  location.hash = '#/';
  route();
}

async function signOut() {
  try { await api('/auth/logout', { method: 'POST' }); } catch { /* signed out either way */ }
  state.user = null;
  shell = null;
  location.hash = '#/';
  route();
  try { const me = await api('/auth/me'); setCsrf(me.csrfToken); } catch { /* ignore */ }
}

window.addEventListener('tx:signed-out', () => {
  if (!state.user) return;
  state.user = null;
  shell = null;
  toast('You were signed out (the session ended). Sign in again to continue.', 'bad');
  route();
});
window.addEventListener('tx:password-required', () => {
  if (state.user) { state.user.mustChangePassword = true; shell = null; route(); }
});
window.addEventListener('hashchange', route);
window.addEventListener('tx:new-version', () => {
  const bar = h('div', { class: 'toast', role: 'status' },
    h('span', { text: 'Transformer X has been updated.' }),
    h('button', { type: 'button', onclick: () => location.reload() }, 'Reload now'));
  let host = document.querySelector('.toasts');
  if (!host) { host = h('div', { class: 'toasts' }); document.body.append(host); }
  host.append(bar);
});

async function boot() {
  try { applyTheme(localStorage.getItem('tx-theme') || 'system'); } catch { /* ignore */ }
  try {
    const me = await api('/auth/me');
    setCsrf(me.csrfToken);
    state.user = me.user;
    state.idleMinutes = me.idleMinutes;
    if (me.user) applyTheme(me.user.theme);
    if (me.sessionExpired) toast('Your session ended after a period of inactivity. Sign in again.');
  } catch (err) {
    clear(root).append(h('div', { class: 'auth' }, h('div', { class: 'auth-card' }, errorNotice(err))));
    return;
  }
  route();
}

boot();

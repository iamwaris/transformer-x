import { api, ApiError } from '../api.js';
import { h, fmt, toast, confirmDialog, secretDialog, field, showFieldErrors, setBusy, errorNotice } from '../dom.js';

export async function renderUsers(main, _params, { state }) {
  const host = h('div');
  const addBtn = h('button', { class: 'btn primary', type: 'button', onclick: () => openForm() }, 'Add user');
  main.append(
    h('div', { class: 'page-head' }, h('div', {}, h('h1', { text: 'Users' }),
      h('p', { text: 'Create accounts and manage access. New users get a temporary password and must choose their own at first sign-in.' })),
    h('div', { class: 'actions' }, addBtn)),
    host);

  async function load() {
    try {
      const { items } = await api('/admin/users');
      host.replaceChildren(h('div', { class: 'table-wrap' }, h('table', { class: 'data' },
        h('thead', {}, h('tr', {}, h('th', { text: 'Name' }), h('th', { text: 'Username' }), h('th', { text: 'Role' }), h('th', { text: 'Status' }),
          h('th', { text: 'Last sign-in' }), h('th', { class: 'num', text: 'Conversions' }), h('th', { text: '' }))),
        h('tbody', {}, items.map((u) => row(u))))));
    } catch (err) {
      host.replaceChildren(errorNotice(err));
    }
  }

  function row(u) {
    const me = u.id === state.user.id;
    const statusPills = [
      u.isActive ? h('span', { class: 'pill ok', text: 'Active' }) : h('span', { class: 'pill', text: 'Deactivated' }),
      u.locked ? h('span', { class: 'pill bad', text: 'Locked' }) : null,
      u.mustChangePassword ? h('span', { class: 'pill info', text: 'Temporary password' }) : null,
    ];
    const actions = h('div', { class: 'toolbar' },
      h('button', { class: 'btn small', type: 'button', onclick: () => openForm(u) }, 'Edit'),
      h('button', { class: 'btn small', type: 'button', onclick: () => resetPassword(u) }, 'Reset password'),
      u.locked ? h('button', { class: 'btn small', type: 'button', onclick: () => unlock(u) }, 'Unlock') : null,
      me ? null : h('button', { class: `btn small ${u.isActive ? 'danger' : ''}`, type: 'button', onclick: () => toggleActive(u) }, u.isActive ? 'Deactivate' : 'Reactivate'));
    return h('tr', {},
      h('td', {}, u.displayName, me ? h('span', { class: 'muted small', text: ' (you)' }) : null),
      h('td', { class: 'mono', text: u.username }),
      h('td', { text: u.role === 'admin' ? 'Administrator' : 'User' }),
      h('td', {}, h('div', { class: 'chips' }, statusPills)),
      h('td', { class: 'nowrap', text: fmt.date(u.lastLoginAt) }),
      h('td', { class: 'num', text: fmt.int(u.conversions) }),
      h('td', {}, actions));
  }

  async function openForm(u = null) {
    const username = h('input', { class: 'input', name: 'username', maxlength: '50', value: u?.username || '', disabled: !!u, autocomplete: 'off' });
    const displayName = h('input', { class: 'input', name: 'displayName', maxlength: '100', value: u?.displayName || '' });
    const role = h('select', { class: 'input', name: 'role' },
      h('option', { value: 'user', text: 'User — converts files, sees own history', selected: u?.role !== 'admin' }),
      h('option', { value: 'admin', text: 'Administrator — also manages users and sees everything', selected: u?.role === 'admin' }));
    const msg = h('div');
    const save = h('button', { class: 'btn primary', type: 'submit' }, u ? 'Save changes' : 'Create user');
    const form = h('form', { class: 'form', novalidate: true },
      h('h2', { text: u ? `Edit ${u.displayName}` : 'Add user' }),
      field('Username', username, u ? 'Usernames cannot be changed.' : '3–50 letters, digits, dots, dashes or underscores.'),
      field('Full name', displayName),
      field('Role', role),
      msg);
    const dlg = h('dialog', { class: 'modal' },
      h('div', { class: 'm-body' }, form),
      h('div', { class: 'm-actions' }, h('button', { class: 'btn', type: 'button', onclick: () => dlg.close() }, 'Cancel'), save));
    save.addEventListener('click', (e) => { e.preventDefault(); form.requestSubmit(); });
    dlg.addEventListener('close', () => dlg.remove());
    document.body.append(dlg);
    dlg.showModal();

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      msg.replaceChildren();
      setBusy(save, true, 'Saving…');
      try {
        if (u) {
          await api(`/admin/users/${u.id}`, { method: 'PATCH', body: { displayName: displayName.value, role: role.value } });
          dlg.close();
          toast('Saved.');
        } else {
          const res = await api('/admin/users', { method: 'POST', body: { username: username.value.trim(), displayName: displayName.value, role: role.value } });
          dlg.close();
          await secretDialog('User created', `Give ${res.user.displayName} this temporary password. They will choose their own when they first sign in. It is shown only once.`, res.temporaryPassword);
        }
        load();
      } catch (err) {
        setBusy(save, false);
        if (err instanceof ApiError && err.fields) showFieldErrors(form, err.fields);
        else msg.append(errorNotice(err));
      }
    });
  }

  async function resetPassword(u) {
    const ok = await confirmDialog({ title: `Reset ${u.displayName}'s password?`, body: 'Their current password stops working. You get a temporary password to pass on; they must change it when they sign in.', confirmLabel: 'Reset password' });
    if (!ok) return;
    try {
      const res = await api(`/admin/users/${u.id}/reset-password`, { method: 'POST' });
      await secretDialog('Temporary password', `Give ${u.displayName} this temporary password. It is shown only once.`, res.temporaryPassword);
      load();
    } catch (err) { toast(err.message, 'bad'); }
  }

  async function unlock(u) {
    try { await api(`/admin/users/${u.id}/unlock`, { method: 'POST' }); toast(`${u.displayName} can sign in again.`); load(); } catch (err) { toast(err.message, 'bad'); }
  }

  async function toggleActive(u) {
    if (u.isActive) {
      const ok = await confirmDialog({ title: `Deactivate ${u.displayName}?`, body: 'They are signed out and cannot sign in. Their history and statistics are kept. You can reactivate them later.', confirmLabel: 'Deactivate', danger: true });
      if (!ok) return;
    }
    try { await api(`/admin/users/${u.id}`, { method: 'PATCH', body: { isActive: !u.isActive } }); toast(u.isActive ? 'Deactivated.' : 'Reactivated.'); load(); } catch (err) { toast(err.message, 'bad'); }
  }

  load();
}

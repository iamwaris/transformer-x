import { api, ApiError } from '../api.js';
import { h, field, toast, setBusy, showFieldErrors, errorNotice } from '../dom.js';

export async function renderSettings(main) {
  const { settings } = await api('/admin/settings');
  const inputs = {};
  const msg = h('div');
  const save = h('button', { class: 'btn primary', type: 'submit' }, 'Save settings');
  const form = h('form', { class: 'form', novalidate: true },
    h('div', { class: 'form-row' }, Object.entries(settings).map(([name, s]) => {
      inputs[name] = h('input', { class: 'input', type: 'number', name, min: s.min, max: s.max, step: '1', value: s.value, required: true });
      return field(s.label, inputs[name], `${s.min} to ${s.max}`);
    })),
    msg,
    h('div', { class: 'toolbar' }, save));

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    msg.replaceChildren();
    const body = {};
    for (const [name, input] of Object.entries(inputs)) body[name] = Number.parseInt(input.value, 10);
    setBusy(save, true, 'Saving…');
    try {
      await api('/admin/settings', { method: 'PUT', body });
      showFieldErrors(form, {});
      toast('Settings saved.');
    } catch (err) {
      if (err instanceof ApiError && err.fields) showFieldErrors(form, err.fields);
      else msg.append(errorNotice(err));
    } finally {
      setBusy(save, false);
    }
  });

  main.append(
    h('div', { class: 'page-head' }, h('div', {}, h('h1', { text: 'Settings' }),
      h('p', { text: 'Limits and security settings for everyone. Changes apply immediately.' }))),
    h('section', { class: 'panel' }, form),
    h('div', { class: 'notice' }, h('span', { class: 'ico', text: 'i' }),
      h('div', {}, 'The upload limit cannot exceed the server\'s PHP limit (upload_max_filesize = 40 MB in this XAMPP).')));
}

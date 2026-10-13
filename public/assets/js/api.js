// JSON API client: CSRF header on every write, one retry after a CSRF refresh, typed errors.

const base = document.getElementById('app').dataset.base || '';
let csrfToken = '';

export const BASE = base;

// The page was built for one version of the front-end files; if the server now has newer ones, offer a reload once.
const loadedVersion = document.getElementById('app').dataset.version || '';
let versionNoticeShown = false;
function checkVersion(serverVersion) {
  if (!serverVersion || !loadedVersion || serverVersion === loadedVersion || versionNoticeShown) return;
  versionNoticeShown = true;
  window.dispatchEvent(new CustomEvent('tx:new-version'));
}

export function setCsrf(token) {
  if (token) csrfToken = token;
}

export class ApiError extends Error {
  constructor(status, code, message, payload = {}) {
    super(message);
    this.status = status;
    this.code = code;
    this.fields = payload.fields || null;
  }
}

async function refreshCsrf() {
  const res = await fetch(`${base}/api/auth/me`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
  if (res.ok) setCsrf((await res.json()).csrfToken);
}

async function parseError(res) {
  let payload = null;
  if ((res.headers.get('content-type') || '').includes('application/json')) {
    try { payload = (await res.json()).error; } catch { /* not JSON after all */ }
  }
  payload ||= { code: `http_${res.status}`, message: `The server returned an error (${res.status}). Try again.` };
  const err = new ApiError(res.status, payload.code, payload.message, payload);
  if (res.status === 401) window.dispatchEvent(new CustomEvent('tx:signed-out'));
  if (payload.code === 'password_change_required') window.dispatchEvent(new CustomEvent('tx:password-required'));
  return err;
}

/**
 * @param {string} path  API path after /api, e.g. "/imports/4"
 * @param {{method?: string, body?: any, raw?: boolean, signal?: AbortSignal}} opts
 */
export async function api(path, { method = 'GET', body, raw = false, signal } = {}, retried = false) {
  const headers = { Accept: 'application/json' };
  if (method !== 'GET') headers['X-CSRF-Token'] = csrfToken;
  let payload;
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }
  let res;
  try {
    res = await fetch(`${base}/api${path}`, { method, headers, body: payload, credentials: 'same-origin', signal });
  } catch (e) {
    if (e.name === 'AbortError') throw e;
    throw new ApiError(0, 'network', 'Could not reach the server. Check your connection and try again.');
  }
  checkVersion(res.headers.get('X-App-Version'));
  if (!res.ok) {
    const err = await parseError(res);
    if (err.code === 'csrf' && !retried) {
      await refreshCsrf();
      return api(path, { method, body, raw, signal }, true);
    }
    throw err;
  }
  if (raw) return res;
  return res.status === 204 ? null : res.json();
}

/** Multipart upload with progress (fetch has no upload progress). */
export function upload(path, file, onProgress) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', `${base}/api${path}`);
    xhr.setRequestHeader('X-CSRF-Token', csrfToken);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.responseType = 'json';
    xhr.upload.onprogress = (e) => { if (e.lengthComputable && onProgress) onProgress(e.loaded / e.total); };
    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) return resolve(xhr.response);
      const p = (xhr.response && xhr.response.error) || { code: `http_${xhr.status}`, message: `Upload failed (${xhr.status}).` };
      if (xhr.status === 413) p.message = 'The file is too large for the server.';
      if (xhr.status === 401) window.dispatchEvent(new CustomEvent('tx:signed-out'));
      reject(new ApiError(xhr.status, p.code, p.message, p));
    };
    xhr.onerror = () => reject(new ApiError(0, 'network', 'The upload could not reach the server. Check your connection and try again.'));
    const fd = new FormData();
    fd.append('file', file);
    xhr.send(fd);
  });
}

/** POST/GET that returns a file; saves it with the server-given name. */
export async function download(path, method = 'GET') {
  const res = await api(path, { method, raw: true });
  const name = res.headers.get('X-Filename') || 'download.xlsx';
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 30000);
  return { name, size: blob.size };
}

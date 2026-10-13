// Light / dark / follow-system. Kept separate so views never import app.js (which would start a second app instance).

export function applyTheme(theme) {
  document.documentElement.dataset.theme = theme || 'system';
  try { localStorage.setItem('tx-theme', theme || 'system'); } catch { /* storage may be blocked */ }
}

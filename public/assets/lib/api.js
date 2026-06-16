/* api.js — JSON fetch wrapper + appConfig + URL builder.
 *
 * Page modules import { api, url, appConfig } from './lib/api.js'.
 */

export const appConfig = {
  csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',
  page: document.querySelector('meta[name="app-page"]')?.content || '',
  basePath: document.querySelector('meta[name="app-base-path"]')?.content || '',
  tenantSlug: document.querySelector('meta[name="app-tenant-slug"]')?.content || '',
  sessionId: document.querySelector('meta[name="app-session-id"]')?.content || '',
  screen: new URLSearchParams(location.search).get('screen') || 'main',
  wsEnabled: (document.querySelector('meta[name="app-ws-enabled"]')?.content || '1') === '1',
  wsPath: document.querySelector('meta[name="app-ws-path"]')?.content || '/ws',
};

const basePath = appConfig.basePath.replace(/\/$/, '');

export function url(path) {
  const normalized = `/${String(path || '').replace(/^\/+/, '')}`;
  return normalized === '/' ? (basePath || '/') : `${basePath}${normalized}`;
}

export async function api(path, options = {}) {
  const headers = { 'Accept': 'application/json', ...(options.headers || {}) };
  if (options.body && !(options.body instanceof FormData)) headers['Content-Type'] = 'application/json';
  if (!['GET', undefined].includes(options.method)) headers['X-CSRF-Token'] = appConfig.csrf;
  const response = await fetch(url(path), { ...options, headers });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data.error || 'Request failed');
  return data;
}

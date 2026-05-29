/* dom.js — query helpers and form/text utilities.
 *
 * Page modules import { $, $$, escapeHtml, setStatus, formData } from './lib/dom.js'.
 */

export const $ = (selector, root = document) => root.querySelector(selector);
export const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

export function formData(form) {
  return Object.fromEntries(new FormData(form).entries());
}

export function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
  }[char]));
}

export function setStatus(target, message) {
  const el = typeof target === 'string' ? $(target) : target;
  if (el) el.textContent = message;
}

/* albumArt.js — real album art with a deterministic generated fallback.
 *
 * Uses song.album_art_url (hotlinked Last.fm cover, or a KJ-supplied URL)
 * when present; otherwise generates a cover client-side from "artist -
 * title" via GeoPattern (the approach from the standalone coverart
 * prototype). Both are CSP-safe: img-src allows https: and data: URLs.
 *
 * A broken hotlinked cover auto-swaps to its generated fallback via one
 * capture-phase error listener (installed on import) — no inline onerror,
 * which CSP would block.
 */

import { escapeHtml } from './dom.js';

const generatedCache = new Map();

function seedFor(song) {
  const artist = (song && song.artist) || '';
  const title = (song && (song.title || song.name)) || '';
  return `${artist} - ${title}`.trim() || 'PanicMic';
}

// Minimal deterministic SVG used only if GeoPattern failed to load.
function plainSvg(seed) {
  let h = 0;
  for (let i = 0; i < seed.length; i++) h = (h * 31 + seed.charCodeAt(i)) & 0xffffff;
  const hue = h % 360;
  const svg = `<svg xmlns='http://www.w3.org/2000/svg' width='80' height='80'>`
    + `<rect width='80' height='80' fill='hsl(${hue},45%,30%)'/>`
    + `<circle cx='40' cy='40' r='15' fill='hsl(${(hue + 40) % 360},55%,60%)'/></svg>`;
  return 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
}

export function generatedCover(song) {
  const seed = seedFor(song);
  if (generatedCache.has(seed)) return generatedCache.get(seed);
  let url;
  try {
    const gp = window.GeoPattern;
    if (gp && typeof gp.generate === 'function') {
      const pattern = gp.generate(seed);
      // toDataUri() is a bare data: URL (good for <img src>); toDataUrl()
      // wraps it in url(...) for CSS — strip that if it's all we have.
      url = typeof pattern.toDataUri === 'function'
        ? pattern.toDataUri()
        : pattern.toDataUrl().replace(/^url\(["']?/, '').replace(/["']?\)$/, '');
    } else {
      url = plainSvg(seed);
    }
  } catch (_) {
    url = plainSvg(seed);
  }
  generatedCache.set(seed, url);
  return url;
}

/** The URL to display for a song: real cover if present, else generated. */
export function coverUrl(song) {
  const real = song && (song.album_art_url || song.albumArtUrl);
  return real ? String(real) : generatedCover(song);
}

/** An <img> tag string; a real cover carries a generated fallback. */
export function coverMarkup(song, cls = 'album-art') {
  const generated = generatedCover(song);
  const real = song && (song.album_art_url || song.albumArtUrl);
  const src = real ? String(real) : generated;
  const fallbackAttr = real ? ` data-cover-fallback="${escapeHtml(generated)}"` : '';
  const alt = escapeHtml((song && (song.album || song.title)) || '');
  return `<img class="${cls}" src="${escapeHtml(src)}"${fallbackAttr} alt="${alt}" loading="lazy">`;
}

let installed = false;
export function installCoverFallback() {
  if (installed) return;
  installed = true;
  document.addEventListener('error', event => {
    const img = event.target;
    if (img && img.tagName === 'IMG' && img.dataset && img.dataset.coverFallback) {
      const fallback = img.dataset.coverFallback;
      delete img.dataset.coverFallback;
      img.src = fallback;
    }
  }, true);
}

installCoverFallback();

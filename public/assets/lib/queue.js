/* queue.js — queue loading, queue rendering, and the display player.
 *
 * Imported by pages/public.js, pages/admin-dashboard.js, and
 * pages/display.js. Each page renders a different facet:
 *   - public: renderPublicQueue
 *   - admin:  renderAdminQueue + renderAdminStats
 *   - display: renderDisplay (which drives the player layer)
 *
 * loadQueue() fans out to every renderer present on the page; missing
 * containers are silently skipped, so this is safe to call from any
 * page.
 */

import { $, $$, escapeHtml } from './dom.js';
import { api, appConfig } from './api.js';

const displayPlayer = {
  currentVideoId: null,
  ytPlayer: null,
  ytApiLoaded: false,
  ytShouldUnmute: false,
};

export async function loadQueue() {
  // Display windows opened with ?screen=<id> need per-screen state.
  const screenParam = (appConfig.page === 'display' && appConfig.screen && appConfig.screen !== 'main')
    ? `?screen=${encodeURIComponent(appConfig.screen)}`
    : '';
  const data = await api(`/api/queue${screenParam}`);
  renderPublicQueue(data.queue);
  renderAdminQueue(data.queue);
  renderDisplay(data.queue, data.display);
  renderAdminStats(data.queue);
  return data;
}

export function renderPublicQueue(queue) {
  $$('[data-public-queue]').forEach(container => {
    container.innerHTML = queue.filter(item => !['completed', 'skipped', 'canceled'].includes(item.queue_status)).map((item, index) => `
      <div class="queue-item status-${escapeHtml(item.queue_status)}">
        <div><strong>${index + 1}. ${escapeHtml(item.singer_name)}</strong><br>${escapeHtml(item.title)} - ${escapeHtml(item.artist)}</div>
        <span>${escapeHtml(item.queue_status.replace('_', ' '))}</span>
      </div>
    `).join('') || '<p>No singers in queue yet.</p>';
  });
}

function renderQueueItemSource(item) {
  if (item.manual_video_url) {
    return `<a class="provider-link" href="${escapeHtml(item.manual_video_url)}" target="_blank" rel="noreferrer">↗ Linked video</a>`;
  }
  if (item.youtube_url) {
    return `<a class="youtube-link" href="${escapeHtml(item.youtube_url)}" target="_blank" rel="noreferrer">YouTube: ${escapeHtml(item.youtube_title || 'karaoke video')}</a>`;
  }
  if (item.video_url) {
    return `<small class="muted">Self-hosted video ready</small>`;
  }
  if (item.provider_url) {
    const label = item.video_provider ? `Open on ${escapeHtml(item.video_provider)}` : 'Open on provider';
    return `<a class="provider-link" href="${escapeHtml(item.provider_url)}" target="_blank" rel="noreferrer">↗ ${label}</a>`;
  }
  const q = encodeURIComponent(`${item.title || ''} ${item.artist || ''} karaoke`.trim());
  const search = `https://www.youtube.com/results?search_query=${q}`;
  return `<a class="provider-link muted" href="${escapeHtml(search)}" target="_blank" rel="noreferrer">↗ Find on YouTube</a>`;
}

export function renderAdminQueue(queue) {
  const container = $('[data-admin-queue]');
  if (!container) return;
  container.innerHTML = queue.map(item => `
    <article class="queue-item status-${escapeHtml(item.queue_status)}" draggable="true" data-request-id="${item.request_id}">
      <div>
        <strong>${escapeHtml(item.position)}. ${escapeHtml(item.singer_name)}</strong>
        <p>${escapeHtml(item.title)} - ${escapeHtml(item.artist)} ${item.song_source === 'shared' ? '<span class="badge shared">shared</span>' : ''} ${item.notes ? `<br><small>${escapeHtml(item.notes)}</small>` : ''}</p>
        ${renderQueueItemSource(item)}
      </div>
      <div class="queue-actions">
        ${['up_next', 'now_singing', 'completed', 'skipped', 'canceled'].map(status => `<button data-status="${status}" data-id="${item.request_id}">${status.replace('_', ' ')}</button>`).join('')}
        <button data-youtube="${item.request_id}">Find video</button>
        <button data-manual-video="${item.request_id}" data-manual-current="${escapeHtml(item.manual_video_url || '')}">${item.manual_video_url ? 'Edit link' : 'Link video'}</button>
      </div>
    </article>
  `).join('') || '<p class="muted">Queue is empty.</p>';
  enableDrag(container);
}

export function renderAdminStats(queue) {
  const root = $('[data-admin-stats]');
  if (!root) return;
  const counts = { queue: 0, up_next: 0, now_singing: 0, completed: 0 };
  for (const item of queue) {
    if (item.queue_status === 'up_next') counts.up_next++;
    else if (item.queue_status === 'now_singing') counts.now_singing++;
    else if (item.queue_status === 'completed') counts.completed++;
    if (['pending', 'up_next', 'now_singing'].includes(item.queue_status)) counts.queue++;
  }
  for (const [key, value] of Object.entries(counts)) {
    const el = $(`[data-stat="${key}"]`, root);
    if (el) el.textContent = value;
  }
}

export function renderDisplay(queue, display = {}) {
  const now = $('[data-display-now]');
  if (!now) return;
  const current = queue.find(item => item.request_id === display.now_request_id) || queue.find(item => item.queue_status === 'now_singing');
  const next = queue.find(item => item.queue_status === 'up_next') || queue.find(item => item.queue_status === 'pending');

  if (current) {
    now.innerHTML = `<strong>${escapeHtml(current.singer_name)}</strong><br>${escapeHtml(current.title)} - ${escapeHtml(current.artist)}`;
  } else {
    now.innerHTML = '<span>Ready for requests</span>';
  }
  const upNextEl = $('[data-up-next]');
  if (upNextEl) {
    upNextEl.innerHTML = next ? `<strong>${escapeHtml(next.singer_name)}</strong> — ${escapeHtml(next.title)}` : 'No singers queued yet.';
  }
  const dq = $('[data-display-queue]');
  if (dq) {
    dq.innerHTML = queue.filter(item => !['completed', 'skipped', 'canceled'].includes(item.queue_status)).slice(0, 8).map((item, index) => `<div><span>${index + 1}.</span> ${escapeHtml(item.singer_name)} — ${escapeHtml(item.title)}</div>`).join('') || '<p>Queue is empty.</p>';
  }
  const qr = $('[data-qr]');
  if (qr && !qr.innerHTML.trim()) qr.innerHTML = qrSvg('Scan for requests');
  syncDisplayPlayer(current, display);
}

function syncDisplayPlayer(current, display = {}) {
  const playerRoot = $('[data-display-player]');
  const grid = $('[data-display-grid]');
  if (!playerRoot || !grid) return;

  const playMode = display.mode === 'now_singing';
  playerRoot.hidden = !playMode;
  grid.hidden = playMode;

  if (!playMode) {
    stopDisplayPlayer();
    return;
  }

  const lt = $('[data-display-lower-third]', playerRoot);
  if (lt) {
    lt.hidden = !current;
    if (current) {
      $('[data-display-lt-singer]', lt).textContent = current.singer_name || '';
      $('[data-display-lt-song]', lt).textContent = `${current.title || ''}${current.artist ? ' — ' + current.artist : ''}`;
    }
  }

  // A KJ-supplied manual link wins when it is something the display can
  // actually embed (a YouTube URL or a direct video file). Non-embeddable
  // links stay a console-only convenience and fall through to the song's
  // own video below.
  const manualUrl = display.manual_video_url || current?.manual_video_url || '';
  const manualYtId = extractYouTubeId(manualUrl);
  const manualFileUrl = isPlayableVideoFile(manualUrl) ? resolveVideoUrl(manualUrl) : '';

  const ytId = display.youtube_video_id || extractYouTubeId(display.youtube_url || current?.youtube_url || '');
  // Prefer self-hosted MP4 (durable, no quota) over YouTube when both
  // are available on the song.
  const videoUrl = resolveVideoUrl(display.song_video_url || '');

  if (manualYtId) {
    showYouTube(manualYtId);
  } else if (manualFileUrl) {
    showSelfHostedVideo(manualFileUrl);
  } else if (ytId) {
    showYouTube(ytId);
  } else if (videoUrl) {
    showSelfHostedVideo(videoUrl);
  } else {
    showEmptyPlayer(current);
  }
}

function isPlayableVideoFile(url) {
  return /\.(mp4|webm|ogg|ogv|mov|m4v|m3u8)(\?|#|$)/i.test(url || '');
}

function stopDisplayPlayer() {
  if (displayPlayer.ytPlayer && typeof displayPlayer.ytPlayer.stopVideo === 'function') {
    try { displayPlayer.ytPlayer.stopVideo(); } catch (_) {}
  }
  displayPlayer.currentVideoId = null;
  const yt = $('[data-display-yt]');
  const v = $('[data-display-video]');
  const empty = $('[data-display-player-empty]');
  if (yt) yt.hidden = true;
  if (v) { v.pause(); v.removeAttribute('src'); v.hidden = true; }
  if (empty) empty.hidden = true;
}

function showEmptyPlayer(current) {
  const empty = $('[data-display-player-empty]');
  if (!empty) return;
  $('[data-display-yt]').hidden = true;
  $('[data-display-video]').hidden = true;
  empty.hidden = false;
  $('[data-display-player-title]', empty).textContent = current
    ? `${current.singer_name || ''} — ${current.title || ''}`
    : 'Ready';
}

function showSelfHostedVideo(src) {
  const v = $('[data-display-video]');
  const yt = $('[data-display-yt]');
  const empty = $('[data-display-player-empty]');
  if (yt) yt.hidden = true;
  if (empty) empty.hidden = true;
  if (!v) return;
  if (v.getAttribute('src') !== src) {
    v.setAttribute('src', src);
  }
  v.hidden = false;
  v.muted = true;
  v.play().catch(() => {});
}

function showYouTube(videoId) {
  const yt = $('[data-display-yt]');
  const v = $('[data-display-video]');
  const empty = $('[data-display-player-empty]');
  if (v) { v.pause(); v.hidden = true; }
  if (empty) empty.hidden = true;
  if (!yt) return;
  yt.hidden = false;
  if (displayPlayer.currentVideoId === videoId) return;
  displayPlayer.currentVideoId = videoId;

  loadYouTubeApi(() => {
    if (!displayPlayer.ytPlayer) {
      displayPlayer.ytPlayer = new YT.Player(yt, {
        height: '100%',
        width: '100%',
        videoId,
        playerVars: { autoplay: 1, controls: 0, modestbranding: 1, rel: 0, playsinline: 1, mute: 1 },
        events: {
          // Autoplay muted on load to satisfy Chromium's autoplay policy.
          onReady: e => { e.target.mute(); e.target.playVideo(); },
          // Unmute only once the player has actually transitioned into
          // PLAYING — calling unMute() before that triggers a browser
          // autoplay block. This is the path PLAN.md Phase 5.2 calls
          // out by name.
          onStateChange: e => {
            if (e.data === YT.PlayerState.PLAYING && displayPlayer.ytShouldUnmute) {
              try { e.target.unMute(); } catch (_) {}
              displayPlayer.ytShouldUnmute = false;
            }
          },
        },
      });
    } else {
      displayPlayer.ytPlayer.loadVideoById(videoId);
      displayPlayer.ytShouldUnmute = true;
    }
    displayPlayer.ytShouldUnmute = true;
  });
}

function loadYouTubeApi(callback) {
  if (window.YT && window.YT.Player) {
    callback();
    return;
  }
  if (displayPlayer.ytApiLoaded) {
    setTimeout(() => loadYouTubeApi(callback), 50);
    return;
  }
  displayPlayer.ytApiLoaded = true;
  const tag = document.createElement('script');
  tag.src = 'https://www.youtube.com/iframe_api';
  document.head.appendChild(tag);
  window.onYouTubeIframeAPIReady = callback;
}

function extractYouTubeId(url) {
  if (!url) return null;
  const m = url.match(/(?:v=|youtu\.be\/|\/embed\/|\/shorts\/)([A-Za-z0-9_-]{6,})/);
  return m ? m[1] : null;
}

/**
 * Self-hosted video URLs come back as relative `/files/...` paths
 * because the server doesn't know our base path. Rewrite into the
 * app's mount point so the <video> element can fetch them.
 */
function resolveVideoUrl(raw) {
  if (!raw) return '';
  const basePath = appConfig.basePath.replace(/\/$/, '');
  if (raw.startsWith('/') && !raw.startsWith(basePath + '/')) {
    return basePath + raw;
  }
  return raw;
}

function qrSvg(text) {
  return `<svg viewBox="0 0 120 120" role="img" aria-label="Request QR placeholder"><rect width="120" height="120" fill="#fff"/><path fill="#111" d="M8 8h32v32H8zM80 8h32v32H80zM8 80h32v32H8zM50 50h10v10H50zM70 50h10v10H70zM50 70h30v10H50zM90 60h10v40H90zM58 88h20v12H58z"/></svg><small>${escapeHtml(text)}</small>`;
}

export function enableDrag(container) {
  let dragged = null;
  container.addEventListener('dragstart', event => { dragged = event.target.closest('[data-request-id]'); });
  container.addEventListener('dragover', event => {
    event.preventDefault();
    const item = event.target.closest('[data-request-id]');
    if (item && dragged && item !== dragged) container.insertBefore(dragged, item);
  });
  container.addEventListener('drop', async () => {
    const ids = $$('[data-request-id]', container).map(item => Number(item.dataset.requestId));
    await api('/api/queue/reorder', { method: 'PATCH', body: JSON.stringify({ request_ids: ids }) });
  });
}

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
import { coverMarkup } from './albumArt.js';

const displayPlayer = {
  currentVideoId: null,
  ytPlayer: null,
  ytApiLoaded: false,
  ytShouldUnmute: false,
  // WebSocket-synchronized playback state (additive; the short-poll path
  // never touches these and keeps using showYouTube/showSelfHostedVideo).
  provider: null,            // 'youtube' | 'self_hosted' | 'none'
  requestId: null,
  cued: false,
  pendingPlayback: { startAtServerMs: null, offsetSeconds: 0 },
  cancelScheduled: null,     // cancel fn for the scheduled play
  driftTimer: null,          // self-hosted drift correction interval
  actualStartMs: null,       // wall-clock ms when local playback began
};

/**
 * Scheduler for synchronized playback. Defaults to a plain setTimeout
 * against local time; ws.js calls setScheduler() with its clock-offset
 * aware scheduler once it has connected. Kept as a setter to avoid a
 * circular import between queue.js and ws.js.
 */
let scheduleAt = (serverMs, cb) => {
  const id = setTimeout(cb, Math.max(0, serverMs - Date.now()));
  return () => clearTimeout(id);
};

export function setScheduler(fn) {
  if (typeof fn === 'function') scheduleAt = fn;
}

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
        <div class="queue-item-main">
          ${coverMarkup(item)}
          <div><strong>${index + 1}. ${escapeHtml(item.singer_name)}</strong><br>${escapeHtml(item.title)} - ${escapeHtml(item.artist)}</div>
        </div>
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
      <div class="queue-item-main">
        ${coverMarkup(item)}
        <div>
          <strong>${escapeHtml(item.position)}. ${escapeHtml(item.singer_name)}</strong>
          <p>${escapeHtml(item.title)} - ${escapeHtml(item.artist)} ${item.song_source === 'shared' ? '<span class="badge shared">shared</span>' : ''} ${item.notes ? `<br><small>${escapeHtml(item.notes)}</small>` : ''}</p>
          ${renderQueueItemSource(item)}
        </div>
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

/** Return up-to-two uppercase initials from a singer's display name. */
function singerInitials(name) {
  const parts = (name || '').trim().split(/\s+/);
  return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase() || '?';
}

/** Stable hue (0–359) derived from the singer's name for avatar colour. */
function singerHue(name) {
  let h = 0;
  for (const c of (name || '')) h = (h * 31 + c.charCodeAt(0)) & 0xffff;
  return h % 360;
}

export function renderDisplay(queue, display = {}) {
  // Only runs on the display page — bail if sidebar queue container is absent.
  const dq = $('[data-display-queue]');
  if (!dq) return;

  const current = queue.find(item => item.request_id === display.now_request_id) || queue.find(item => item.queue_status === 'now_singing');

  // Update the "Now Playing" bar at the top of the stage.
  const nowTitle = $('[data-display-now-title]');
  const nowSinger = $('[data-display-now-singer]');
  if (nowTitle) nowTitle.textContent = current ? `${current.title} — ${current.artist}` : 'Ready for requests';
  if (nowSinger) nowSinger.textContent = current ? current.singer_name : '';

  // Build rich singer-queue rows in the sidebar.
  const AVG_MIN = 5;
  const activeQueue = queue.filter(item => !['completed', 'skipped', 'canceled'].includes(item.queue_status));

  if (!activeQueue.length) {
    dq.innerHTML = '<p class="display-queue-empty">No singers in queue yet.</p>';
  } else {
    dq.innerHTML = activeQueue.slice(0, 10).map((item, idx) => {
      const isOnStage = item.queue_status === 'now_singing';
      const initials = singerInitials(item.singer_name);
      const hue = singerHue(item.singer_name);
      const posNum = idx + 1;
      const waitMin = isOnStage ? null : idx * AVG_MIN;

      return `<div class="display-singer-row">
        <div class="display-singer-avatar" style="background:hsl(${hue},50%,36%)">${escapeHtml(initials)}</div>
        <div class="display-singer-name">${escapeHtml(item.singer_name)}${isOnStage ? '<span class="display-badge-onstage">ON STAGE</span>' : ''}</div>
        <div class="display-singer-song">${escapeHtml(item.title)}</div>
        <div class="display-singer-meta">
          ${waitMin !== null ? `<span class="display-singer-wait">~${waitMin} min</span>` : ''}
          ${!isOnStage ? `<span class="display-singer-pos">#${posNum}</span>` : ''}
        </div>
      </div>`;
    }).join('');
  }

  // Total wait info box.
  const waitEl = $('[data-display-wait]');
  if (waitEl) {
    const totalWait = activeQueue.length * AVG_MIN;
    if (activeQueue.length >= 2) {
      waitEl.hidden = false;
      waitEl.innerHTML = `
        <div class="display-wait-total">
          <strong>Total wait to join now:</strong>
          <span class="display-wait-value">~${totalWait} min</span>
        </div>
        <div>${activeQueue.length} singer${activeQueue.length !== 1 ? 's' : ''} in queue &middot; ~${AVG_MIN} min avg song</div>
      `;
    } else {
      waitEl.hidden = true;
    }
  }

  syncDisplayPlayer(current, display);
}

function syncDisplayPlayer(current, display = {}) {
  const playerRoot = $('[data-display-player]');
  const idleEl = $('[data-display-idle]');
  if (!playerRoot) return;

  const playMode = display.mode === 'now_singing';
  playerRoot.hidden = !playMode;
  if (idleEl) idleEl.hidden = playMode;

  if (!playMode) {
    stopDisplayPlayer();
    return;
  }

  const lt = $('[data-display-lower-third]');
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
  clearSyncPlaybackState();
  displayPlayer.currentVideoId = null;
  const yt = $('[data-display-yt]');
  const v = $('[data-display-video]');
  const empty = $('[data-display-player-empty]');
  if (yt) yt.hidden = true;
  if (v) { v.pause(); v.removeAttribute('src'); v.hidden = true; }
  if (empty) empty.hidden = true;
}

function clearSyncPlaybackState() {
  if (displayPlayer.cancelScheduled) { try { displayPlayer.cancelScheduled(); } catch (_) {} displayPlayer.cancelScheduled = null; }
  if (displayPlayer.driftTimer) { clearInterval(displayPlayer.driftTimer); displayPlayer.driftTimer = null; }
  displayPlayer.provider = null;
  displayPlayer.requestId = null;
  displayPlayer.cued = false;
  displayPlayer.pendingPlayback = { startAtServerMs: null, offsetSeconds: 0 };
  displayPlayer.actualStartMs = null;
}

/* -------------------------------------------------------------- */
/* WebSocket-synchronized player control (additive).              */
/*                                                                */
/* These coexist with syncDisplayPlayer()'s immediate-play path.  */
/* The short-poll flow keeps calling showYouTube/showSelfHosted   */
/* directly; the WS flow cues first then plays at a server time.  */
/* -------------------------------------------------------------- */

/**
 * Cue a video (load but don't play). onReady(provider) fires once the
 * video is loaded and ready to start.
 * videoInfo: { provider, youtubeVideoId, videoUrl, requestId }
 */
export function cueDisplayPlayer(videoInfo, onReady) {
  const info = videoInfo || {};
  const provider = info.provider || 'none';
  displayPlayer.provider = provider;
  displayPlayer.requestId = info.requestId ?? null;
  displayPlayer.cued = false;
  displayPlayer.pendingPlayback = { startAtServerMs: null, offsetSeconds: 0 };

  const yt = $('[data-display-yt]');
  const v = $('[data-display-video]');
  const empty = $('[data-display-player-empty]');

  const ready = () => {
    displayPlayer.cued = true;
    try { onReady?.(provider); } catch (_) {}
  };

  if (provider === 'youtube' && info.youtubeVideoId) {
    if (v) { v.pause(); v.hidden = true; }
    if (empty) empty.hidden = true;
    if (!yt) { ready(); return; }
    yt.hidden = false;
    displayPlayer.currentVideoId = info.youtubeVideoId;
    loadYouTubeApi(() => {
      const onState = e => {
        if (e.data === YT.PlayerState.CUED) ready();
      };
      if (!displayPlayer.ytPlayer) {
        displayPlayer.ytPlayer = new YT.Player(yt, {
          height: '100%',
          width: '100%',
          videoId: info.youtubeVideoId,
          playerVars: { autoplay: 0, controls: 0, modestbranding: 1, rel: 0, playsinline: 1, mute: 1 },
          events: {
            onReady: e => { try { e.target.mute(); e.target.cueVideoById(info.youtubeVideoId); } catch (_) {} },
            onStateChange: onState,
          },
        });
      } else {
        try {
          displayPlayer.ytPlayer.mute();
          displayPlayer.ytPlayer.cueVideoById(info.youtubeVideoId);
          displayPlayer.ytPlayer.addEventListener('onStateChange', onState);
        } catch (_) { ready(); }
      }
    });
    return;
  }

  if (provider === 'self_hosted' && info.videoUrl) {
    const src = resolveVideoUrl(info.videoUrl);
    if (yt) yt.hidden = true;
    if (empty) empty.hidden = true;
    if (!v) { ready(); return; }
    v.muted = true;
    v.preload = 'auto';
    if (v.getAttribute('src') !== src) v.setAttribute('src', src);
    v.hidden = false;
    const onCanPlay = () => { v.removeEventListener('canplay', onCanPlay); ready(); };
    v.addEventListener('canplay', onCanPlay);
    try { v.load(); } catch (_) {}
    // If already buffered enough, canplay may not refire.
    if (v.readyState >= 3) { v.removeEventListener('canplay', onCanPlay); ready(); }
    return;
  }

  // provider 'none' or unknown: show the empty placeholder immediately.
  showEmptyPlayer(null);
  displayPlayer.provider = 'none';
  ready();
}

/**
 * Schedule synchronized playback at a server wall-clock time (ms).
 * offsetSeconds seeks to this position before playing.
 */
export function playDisplayPlayerAt(startAtServerMs, offsetSeconds = 0) {
  displayPlayer.pendingPlayback = { startAtServerMs, offsetSeconds };
  if (displayPlayer.cancelScheduled) { try { displayPlayer.cancelScheduled(); } catch (_) {} }
  displayPlayer.cancelScheduled = scheduleAt(startAtServerMs, () => {
    displayPlayer.actualStartMs = Date.now();
    startSyncedPlayback(offsetSeconds);
  });
}

function startSyncedPlayback(offsetSeconds) {
  if (displayPlayer.provider === 'youtube' && displayPlayer.ytPlayer) {
    try {
      displayPlayer.ytPlayer.mute(); // display pages stay muted
      if (offsetSeconds > 0) displayPlayer.ytPlayer.seekTo(offsetSeconds, true);
      displayPlayer.ytPlayer.playVideo();
    } catch (_) {}
    return;
  }
  if (displayPlayer.provider === 'self_hosted') {
    const v = $('[data-display-video]');
    if (!v) return;
    v.muted = true;
    try { if (offsetSeconds > 0) v.currentTime = offsetSeconds; } catch (_) {}
    v.play().catch(() => {});
    startDriftCorrection(v, offsetSeconds);
  }
}

function startDriftCorrection(video, offsetSeconds) {
  if (displayPlayer.driftTimer) clearInterval(displayPlayer.driftTimer);
  displayPlayer.driftTimer = setInterval(() => {
    if (!video || video.paused || displayPlayer.actualStartMs === null) return;
    const expected = (Date.now() - displayPlayer.actualStartMs) / 1000 + offsetSeconds;
    const drift = video.currentTime - expected;
    if (Math.abs(drift) > 0.5) {
      try { video.currentTime = expected; } catch (_) {}
      video.playbackRate = 1.0;
    } else if (drift > 0.1) {
      video.playbackRate = 0.95; // we're ahead, slow down
    } else if (drift < -0.1) {
      video.playbackRate = 1.05; // we're behind, speed up
    } else {
      video.playbackRate = 1.0;
    }
  }, 2000);
}

/** Current player status, or null when no player is active. */
export function getDisplayPlayerStatus() {
  if (displayPlayer.provider === 'youtube' && displayPlayer.ytPlayer) {
    try {
      const stateMap = { '-1': 'unstarted', 0: 'ended', 1: 'playing', 2: 'paused', 3: 'buffering', 5: 'cued' };
      const ps = displayPlayer.ytPlayer.getPlayerState();
      return {
        requestId: displayPlayer.requestId,
        videoId: displayPlayer.currentVideoId,
        provider: 'youtube',
        playerState: stateMap[ps] ?? String(ps),
        currentTime: displayPlayer.ytPlayer.getCurrentTime?.() ?? 0,
        muted: displayPlayer.ytPlayer.isMuted?.() ?? true,
      };
    } catch (_) { return null; }
  }
  if (displayPlayer.provider === 'self_hosted') {
    const v = $('[data-display-video]');
    if (!v) return null;
    return {
      requestId: displayPlayer.requestId,
      videoId: v.getAttribute('src') || '',
      provider: 'self_hosted',
      playerState: v.paused ? 'paused' : 'playing',
      currentTime: v.currentTime || 0,
      muted: !!v.muted,
    };
  }
  return null;
}

/** Seek the active player to a position in seconds. */
export function seekDisplayPlayer(seconds) {
  if (displayPlayer.provider === 'youtube' && displayPlayer.ytPlayer) {
    try { displayPlayer.ytPlayer.seekTo(seconds, true); } catch (_) {}
    return;
  }
  if (displayPlayer.provider === 'self_hosted') {
    const v = $('[data-display-video]');
    if (v) { try { v.currentTime = seconds; } catch (_) {} }
  }
}

/** Stop and clear the player. Public wrapper over the internal stop. */
export function stopDisplayPlayerPublic() {
  stopDisplayPlayer();
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

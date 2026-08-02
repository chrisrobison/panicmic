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
  // WebSocket-synchronized playback state (additive; the short-poll path
  // never touches these and keeps using showYouTube/showSelfHostedVideo).
  provider: null,            // 'youtube' | 'self_hosted' | 'none'
  requestId: null,
  cued: false,
  pendingPlayback: { startAtServerMs: null, offsetSeconds: 0 },
  cancelScheduled: null,     // cancel fn for the scheduled play
  driftTimer: null,          // self-hosted drift correction interval
  actualStartMs: null,       // wall-clock ms when local playback began
  pausedAtMs: null,          // used to keep drift clock stable across pause
  // True once the display page has had a real user gesture (a tap on the
  // "enable sound" overlay). Browsers block unmuted autoplay without one;
  // after it happens, the browser grants unmuted playback for the rest of
  // this page's life, so every subsequent song can play with sound too —
  // not just the one active when the tap happened.
  audioUnlocked: false,
  defaultVolume: 80,
  synchronizedCommands: false,
  recoveredCommandId: null,
};

/** Make cue/play-at commands the only path allowed to start display media. */
export function enableSynchronizedPlayback() {
  displayPlayer.synchronizedCommands = true;
}

export function applyDisplayConfiguration(configuration = {}) {
  const shell = $('[data-screen]');
  if (!shell) return;
  const layout = ['main', 'lyrics', 'lobby', 'stage', 'custom'].includes(configuration.layout)
    ? configuration.layout
    : 'main';
  const showQr = Number(configuration.show_qr) === 1 || configuration.show_qr === true;
  const showQueue = Number(configuration.show_queue) === 1 || configuration.show_queue === true;

  shell.dataset.layout = layout;
  shell.classList.toggle('display-no-qr', !showQr);
  shell.classList.toggle('display-no-queue', !showQueue);
  shell.classList.toggle('display-no-sidebar', !showQr && !showQueue);

  const between = $('[data-display-between]');
  const idle = $('[data-display-idle-message]');
  const playing = !$('[data-display-player]')?.hidden;
  if (between && idle) {
    between.hidden = playing || !showQr;
    idle.hidden = playing || showQr;
  }

  displayPlayer.defaultVolume = Math.max(0, Math.min(100, Number(configuration.default_volume) || 0));
  applyPlayerVolume();
}

function applyPlayerVolume() {
  if (displayPlayer.ytPlayer) {
    try { displayPlayer.ytPlayer.setVolume(displayPlayer.defaultVolume); } catch (_) {}
  }
  const video = $('[data-display-video]');
  if (video) video.volume = displayPlayer.defaultVolume / 100;
}

/**
 * Unlock audio for the rest of this page's life. Must be called
 * synchronously from within a real click/tap handler — that's what
 * satisfies the browser's autoplay-with-sound requirement.
 *
 * For the currently-playing video, this doesn't just call unMute() on the
 * existing player — that's unreliable for YouTube's iframe even from a
 * genuine click, because the iframe was originally created *muted*
 * (mute: 1 in playerVars) and some browsers don't honor a later
 * postMessage-based unMute() on a player that was born muted. Destroying
 * and recreating the iframe with mute: 0 from the start, synchronously
 * inside this same click, is the reliable pattern. Every subsequent song
 * already creates its player unmuted-from-birth once audioUnlocked is
 * true (see cueDisplayPlayer/showYouTube), so this recreation is only
 * needed for whatever's already on screen at unlock time.
 */
export function unlockDisplayAudio() {
  displayPlayer.audioUnlocked = true;
  if (displayPlayer.provider === 'youtube' && displayPlayer.ytPlayer) {
    recreateYouTubePlayerUnmuted();
  } else if (displayPlayer.provider === 'self_hosted') {
    const v = $('[data-display-video]');
    if (v) {
      v.muted = false;
      v.volume = displayPlayer.defaultVolume / 100;
    }
  }
}

function recreateYouTubePlayerUnmuted() {
  const yt = $('[data-display-yt]');
  const videoId = displayPlayer.currentVideoId;
  if (!yt || !videoId) return;

  let resumeAt = 0;
  let wasPlaying = true;
  try { resumeAt = displayPlayer.ytPlayer.getCurrentTime() || 0; } catch (_) {}
  try { wasPlaying = displayPlayer.ytPlayer.getPlayerState() !== YT.PlayerState.PAUSED; } catch (_) {}

  try { displayPlayer.ytPlayer.destroy(); } catch (_) {}
  displayPlayer.ytPlayer = null;

  displayPlayer.ytPlayer = new YT.Player(yt, {
    height: '100%',
    width: '100%',
    videoId,
    playerVars: { autoplay: 0, controls: 0, modestbranding: 1, rel: 0, playsinline: 1, mute: 0 },
    events: {
      onReady: e => {
        try {
          e.target.unMute();
          e.target.setVolume(displayPlayer.defaultVolume);
          e.target.seekTo(resumeAt, true);
          if (wasPlaying) e.target.playVideo();
        } catch (_) {}
      },
    },
  });
}

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
  renderIncomingRequests(data.queue);
  renderAdminQueue(data.queue);
  renderDisplay(data.queue, data.display);
  applyDisplayConfiguration(data.screen_config || {});
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

/** Badge chips: VIP (KJ-toggled priority), Duo (duet/group party), NEW (singer's first request tonight). */
function badgesMarkup(item) {
  const chips = [];
  if (item.is_priority) chips.push('<span class="chip chip-vip">VIP</span>');
  if (item.party_type === 'duet' || item.party_type === 'group') chips.push('<span class="chip chip-duo">Duo</span>');
  if (item.is_new) chips.push('<span class="chip chip-new">NEW</span>');
  return chips.join('');
}

function timeLabel(iso) {
  if (!iso) return '';
  const d = new Date(String(iso).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

/** mm:ss elapsed since an ISO/SQL timestamp; used for the now-singing row. */
export function elapsedLabel(iso) {
  if (!iso) return '';
  const started = new Date(String(iso).replace(' ', 'T')).getTime();
  if (Number.isNaN(started)) return '';
  const secs = Math.max(0, Math.floor((Date.now() - started) / 1000));
  const m = Math.floor(secs / 60);
  const s = secs % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}

/** Requests still awaiting KJ review: pending and never accepted into rotation. */
export function renderIncomingRequests(queue) {
  const container = $('[data-incoming-requests]');
  if (!container) return;
  const incoming = queue.filter(item => item.is_incoming).sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
  const countEl = $('[data-incoming-count]');
  if (countEl) countEl.textContent = incoming.length;
  container.innerHTML = incoming.map(item => `
    <article class="incoming-item" data-request-id="${item.request_id}">
      <div class="incoming-item-head">
        ${badgesMarkup(item)}
        <strong class="incoming-name">${escapeHtml(item.singer_name)}</strong>
        <time class="incoming-time">${escapeHtml(timeLabel(item.created_at))}</time>
      </div>
      <p class="incoming-song">${escapeHtml(item.title)}</p>
      <p class="incoming-artist muted">${escapeHtml(item.artist)}</p>
      <div class="incoming-actions">
        <button type="button" class="icon-btn ok" data-approve="${item.request_id}" title="Accept into rotation">✓</button>
        <button type="button" class="icon-btn danger" data-deny="${item.request_id}" title="Deny request">✕</button>
        <button type="button" class="icon-btn" data-fast-track="${item.request_id}" title="Accept &amp; move to front">➜</button>
      </div>
    </article>
  `).join('') || '<p class="muted">No new requests.</p>';
}

export function renderAdminQueue(queue) {
  const container = $('[data-admin-queue]');
  if (!container) return;
  const rotation = queue.filter(item => !item.is_incoming);
  container.innerHTML = rotation.map(item => `
    <article class="queue-item status-${escapeHtml(item.queue_status)}" draggable="true" data-request-id="${item.request_id}">
      <div class="queue-item-drag" title="Drag to reorder">⠿</div>
      <div class="queue-item-pos">${escapeHtml(item.position)}</div>
      <div class="queue-item-main">
        ${coverMarkup(item)}
        <div class="queue-item-body">
          <div class="queue-item-singer-row">
            <strong>${escapeHtml(item.singer_name)}</strong>
            ${badgesMarkup(item)}
            ${item.queue_status === 'now_singing' ? `<span class="chip chip-live">NOW SINGING &middot; ${escapeHtml(elapsedLabel(item.status_updated_at))}</span>` : ''}
            ${item.queue_status === 'up_next' ? '<span class="chip chip-next">UP NEXT</span>' : ''}
          </div>
          <p class="queue-item-song">${escapeHtml(item.title)} <span class="muted">— ${escapeHtml(item.artist)}</span> ${item.song_source === 'shared' ? '<span class="badge shared">shared</span>' : ''}</p>
          ${item.notes ? `<small class="muted">${escapeHtml(item.notes)}</small>` : ''}
          ${renderQueueItemSource(item)}
        </div>
      </div>
      <details class="queue-item-menu">
        <summary title="More actions">&#8942;</summary>
        <div class="queue-item-menu-panel">
          <button type="button" data-priority="${item.request_id}" data-priority-current="${item.is_priority ? 1 : 0}">${item.is_priority ? '★ Remove VIP' : '☆ Mark VIP'}</button>
          ${['up_next', 'now_singing', 'completed', 'skipped', 'canceled'].map(status => `<button data-status="${status}" data-id="${item.request_id}">${status.replace('_', ' ')}</button>`).join('')}
          <button data-youtube="${item.request_id}">Find video</button>
          <button data-manual-video="${item.request_id}" data-manual-current="${escapeHtml(item.manual_video_url || '')}">${item.manual_video_url ? 'Edit link' : 'Link video'}</button>
        </div>
      </details>
    </article>
  `).join('') || '<p class="muted">Queue is empty. Accept an incoming request to get started.</p>';
  enableDrag(container);

  const rotCount = $('[data-rotation-count]');
  if (rotCount) rotCount.textContent = rotation.filter(i => !['completed', 'skipped', 'canceled'].includes(i.queue_status)).length;

  const estEl = $('[data-rotation-estimate]');
  const footEl = $('[data-rotation-footer]');
  const active = rotation.filter(i => !['completed', 'skipped', 'canceled'].includes(i.queue_status));
  if (estEl || footEl) {
    const AVG_MIN = 5;
    const totalSecs = active.length * AVG_MIN * 60;
    const h = Math.floor(totalSecs / 3600);
    const m = Math.floor((totalSecs % 3600) / 60);
    const s = totalSecs % 60;
    const est = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    if (estEl) estEl.textContent = est;
    if (footEl) footEl.textContent = `${active.length} singer${active.length !== 1 ? 's' : ''} • ${est}`;
  }
}

export function renderAdminStats(queue) {
  const root = $('[data-admin-stats]');
  if (!root) return;
  const counts = { queue: 0, up_next: 0, now_singing: 0, completed: 0, incoming: 0 };
  for (const item of queue) {
    if (item.is_incoming) counts.incoming++;
    if (item.queue_status === 'up_next') counts.up_next++;
    else if (item.queue_status === 'now_singing') counts.now_singing++;
    else if (item.queue_status === 'completed') counts.completed++;
    if (!item.is_incoming && ['pending', 'up_next', 'now_singing'].includes(item.queue_status)) counts.queue++;
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
  const next = queue.find(item => item.queue_status === 'up_next') || queue.find(item => item.queue_status === 'pending' && item !== current);

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

  syncDisplayPlayer(current, display, next);
}

function syncDisplayPlayer(current, display = {}, next = null) {
  const playerRoot = $('[data-display-player]');
  const betweenEl = $('[data-display-between]');
  if (!playerRoot) return;

  const playMode = display.mode === 'now_singing';

  // --- Now-bar: label + title + singer ---
  // While playing  → "NOW PLAYING / Song — Artist / Singer name"
  // Between singers → "UP NEXT / Singer name / Song — Artist"
  // Truly idle      → "NOW PLAYING / Ready for requests / (blank)"
  const nowLabel  = $('[data-display-now-label]');
  const nowTitle  = $('[data-display-now-title]');
  const nowSinger = $('[data-display-now-singer]');
  if (playMode && current) {
    if (nowLabel)  nowLabel.textContent  = 'NOW PLAYING';
    if (nowTitle)  nowTitle.textContent  = `${current.title} — ${current.artist}`;
    if (nowSinger) nowSinger.textContent = current.singer_name;
  } else if (next) {
    if (nowLabel)  nowLabel.textContent  = 'UP NEXT';
    if (nowTitle)  nowTitle.textContent  = next.singer_name;
    if (nowSinger) nowSinger.textContent = `${next.title} — ${next.artist}`;
  } else {
    if (nowLabel)  nowLabel.textContent  = 'NOW PLAYING';
    if (nowTitle)  nowTitle.textContent  = 'Ready for requests';
    if (nowSinger) nowSinger.textContent = '';
  }

  // --- Viewport layers ---
  playerRoot.hidden = !playMode;
  if (betweenEl) betweenEl.hidden = playMode; // QR screen when not playing

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

  // Display pages use cue/play-at as the single playback authority. Queue
  // refreshes still update labels and overlays, but must never start media:
  // doing so would race the scheduled command and desynchronize screens.
  if (displayPlayer.synchronizedCommands) {
    return;
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
  displayPlayer.pausedAtMs = null;
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
    // Stashed here (rather than closed over) so the single onStateChange
    // listener registered below always calls the *latest* cue's ready(),
    // instead of accumulating a new listener per cue (each of which would
    // keep firing forever since the player instance is reused across songs).
    displayPlayer.onCued = ready;
    loadYouTubeApi(() => {
      if (!displayPlayer.ytPlayer) {
        displayPlayer.ytPlayer = new YT.Player(yt, {
          height: '100%',
          width: '100%',
          videoId: info.youtubeVideoId,
          playerVars: { autoplay: 0, controls: 0, modestbranding: 1, rel: 0, playsinline: 1, mute: displayPlayer.audioUnlocked ? 0 : 1 },
          events: {
            onReady: e => {
              try {
                if (displayPlayer.audioUnlocked) e.target.unMute(); else e.target.mute();
                e.target.setVolume(displayPlayer.defaultVolume);
                e.target.cueVideoById(info.youtubeVideoId);
              } catch (_) {}
            },
            onStateChange: e => {
              if (e.data === YT.PlayerState.CUED) { try { displayPlayer.onCued?.(); } catch (_) {} }
            },
          },
        });
      } else {
        try {
          if (displayPlayer.audioUnlocked) displayPlayer.ytPlayer.unMute(); else displayPlayer.ytPlayer.mute();
          displayPlayer.ytPlayer.setVolume(displayPlayer.defaultVolume);
          displayPlayer.ytPlayer.cueVideoById(info.youtubeVideoId);
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
    v.muted = !displayPlayer.audioUnlocked;
    v.volume = displayPlayer.defaultVolume / 100;
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
 * Rejoin an already-running command after a page refresh or display reconnect.
 * The persisted server timestamp lets the display seek to the point the other
 * screens should currently be showing.
 */
export function recoverDisplayPlayback(display = {}) {
  if (!display || display.mode !== 'now_singing') return;
  const commandId = String(display.play_command_id || '');
  if (!commandId || commandId === displayPlayer.recoveredCommandId) return;
  displayPlayer.recoveredCommandId = commandId;

  const manualUrl = display.manual_video_url || '';
  const manualYtId = extractYouTubeId(manualUrl);
  const manualFileUrl = isPlayableVideoFile(manualUrl) ? manualUrl : '';
  const ytId = display.youtube_video_id || extractYouTubeId(display.youtube_url || '');
  const videoUrl = display.song_video_url || '';
  const info = manualYtId
    ? { provider: 'youtube', youtubeVideoId: manualYtId, videoUrl: '' }
    : manualFileUrl
      ? { provider: 'self_hosted', youtubeVideoId: '', videoUrl: manualFileUrl }
      : ytId
        ? { provider: 'youtube', youtubeVideoId: ytId, videoUrl: '' }
        : videoUrl
          ? { provider: 'self_hosted', youtubeVideoId: '', videoUrl }
          : { provider: 'none', youtubeVideoId: '', videoUrl: '' };

  cueDisplayPlayer({ requestId: display.now_request_id, ...info }, () => {
    const baseOffset = Number(display.play_offset_seconds) || 0;
    if (display.play_state === 'paused' || display.play_state === 'cued') {
      if (baseOffset > 0) seekDisplayPlayer(baseOffset);
      return;
    }
    const startedAt = Number(display.play_started_at_ms) || Date.now();
    const elapsed = Math.max(0, (Date.now() - startedAt) / 1000);
    playDisplayPlayerAt(Date.now() + 50, baseOffset + elapsed);
  });
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
    displayPlayer.pausedAtMs = null;
    startSyncedPlayback(offsetSeconds);
  });
}

function startSyncedPlayback(offsetSeconds) {
  if (displayPlayer.provider === 'youtube' && displayPlayer.ytPlayer) {
    try {
      if (displayPlayer.audioUnlocked) displayPlayer.ytPlayer.unMute(); else displayPlayer.ytPlayer.mute();
      displayPlayer.ytPlayer.setVolume(displayPlayer.defaultVolume);
      if (offsetSeconds > 0) displayPlayer.ytPlayer.seekTo(offsetSeconds, true);
      displayPlayer.ytPlayer.playVideo();
    } catch (_) {}
    return;
  }
  if (displayPlayer.provider === 'self_hosted') {
    const v = $('[data-display-video]');
    if (!v) return;
    v.muted = !displayPlayer.audioUnlocked;
    v.volume = displayPlayer.defaultVolume / 100;
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

/** Pause the active player in place (does not clear cue/seek state). */
export function pauseDisplayPlayer() {
  if (displayPlayer.pausedAtMs === null) {
    displayPlayer.pausedAtMs = Date.now();
  }
  if (displayPlayer.provider === 'youtube' && displayPlayer.ytPlayer) {
    try { displayPlayer.ytPlayer.pauseVideo(); } catch (_) {}
    return;
  }
  if (displayPlayer.provider === 'self_hosted') {
    const v = $('[data-display-video]');
    if (v) v.pause();
  }
}

/** Resume a paused player. */
export function resumeDisplayPlayer() {
  if (displayPlayer.pausedAtMs !== null && displayPlayer.actualStartMs !== null) {
    displayPlayer.actualStartMs += Date.now() - displayPlayer.pausedAtMs;
  }
  displayPlayer.pausedAtMs = null;
  if (displayPlayer.provider === 'youtube' && displayPlayer.ytPlayer) {
    try {
      if (displayPlayer.audioUnlocked) displayPlayer.ytPlayer.unMute(); else displayPlayer.ytPlayer.mute();
      displayPlayer.ytPlayer.setVolume(displayPlayer.defaultVolume);
      displayPlayer.ytPlayer.playVideo();
    } catch (_) {}
    return;
  }
  if (displayPlayer.provider === 'self_hosted') {
    const v = $('[data-display-video]');
    if (v) {
      v.muted = !displayPlayer.audioUnlocked;
      v.volume = displayPlayer.defaultVolume / 100;
      v.play().catch(() => {});
    }
  }
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
  v.muted = !displayPlayer.audioUnlocked;
  v.volume = displayPlayer.defaultVolume / 100;
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

  const sameVideo = displayPlayer.currentVideoId === videoId;
  displayPlayer.currentVideoId = videoId;

  loadYouTubeApi(() => {
    if (!displayPlayer.ytPlayer) {
      displayPlayer.ytPlayer = new YT.Player(yt, {
        height: '100%',
        width: '100%',
        videoId,
        // Muted-by-default until a real tap unlocks audio (see
        // unlockDisplayAudio) — that's what keeps autoplay/loadVideoById
        // reliably allowed before the gesture happens.
        playerVars: { autoplay: 1, controls: 0, modestbranding: 1, rel: 0, playsinline: 1, mute: displayPlayer.audioUnlocked ? 0 : 1 },
        events: {
          onReady: e => {
            try {
              if (displayPlayer.audioUnlocked) e.target.unMute(); else e.target.mute();
              e.target.setVolume(displayPlayer.defaultVolume);
              e.target.playVideo();
            } catch (_) {}
          },
        },
      });
      return;
    }

    if (sameVideo) {
      // Same video the player already thinks it's showing. Normally a
      // no-op, but if a WS "cue" (cueDisplayPlayer) landed for this same
      // id and the follow-up "play" command was lost or delayed — e.g. on
      // a flaky link to a remote display — the player can be sitting
      // paused on the cued frame while this poll-driven path silently
      // no-ops forever because the id already matches. Self-heal: if the
      // player isn't actually playing/buffering, force it to play.
      try {
        const state = displayPlayer.ytPlayer.getPlayerState();
        if (state !== YT.PlayerState.PLAYING && state !== YT.PlayerState.BUFFERING) {
          if (displayPlayer.audioUnlocked) displayPlayer.ytPlayer.unMute(); else displayPlayer.ytPlayer.mute();
          displayPlayer.ytPlayer.setVolume(displayPlayer.defaultVolume);
          displayPlayer.ytPlayer.playVideo();
        }
      } catch (_) {}
      return;
    }

    try {
      if (displayPlayer.audioUnlocked) displayPlayer.ytPlayer.unMute(); else displayPlayer.ytPlayer.mute();
      displayPlayer.ytPlayer.setVolume(displayPlayer.defaultVolume);
    } catch (_) {}
    displayPlayer.ytPlayer.loadVideoById(videoId);
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

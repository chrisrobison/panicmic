/* pages/display.js — fullscreen projector / display popout.
 *
 * Realtime sync prefers the WebSocket daemon (synchronized playback via
 * display:cue / display:play_at) and transparently falls back to
 * short-polling when the daemon isn't running. The BroadcastChannel
 * listener stays as the same-browser fast path for operator cues.
 */

import { appConfig, api } from '../lib/api.js';
import {
  loadQueue,
  renderDisplay,
  renderAdminStats,
  cueDisplayPlayer,
  playDisplayPlayerAt,
  getDisplayPlayerStatus,
  setScheduler,
  pauseDisplayPlayer,
  resumeDisplayPlayer,
  unlockDisplayAudio,
} from '../lib/queue.js';
import { broadcast } from '../lib/broadcast.js';
import { startRealtime, sendDisplayReady, sendDisplayStatus, onMessage, scheduleAtServerTime } from '../lib/ws.js';

function startDisplayBroadcastListener() {
  broadcast.subscribe(async data => {
    if (!data || !data.action) return;
    if (data.screen && data.screen !== 'all' && data.screen !== appConfig.screen) return;
    try {
      const [stateResp, queueResp] = await Promise.all([
        api(`/api/display/state?screen=${encodeURIComponent(appConfig.screen)}`),
        api(`/api/queue?screen=${encodeURIComponent(appConfig.screen)}`),
      ]);
      renderDisplay(queueResp.queue || [], stateResp.display || queueResp.display || {});
      renderAdminStats(queueResp.queue || []);
    } catch (_) {}
  });
}

export function init() {
  // Let queue.js schedule synchronized playback against server time.
  setScheduler(scheduleAtServerTime);

  // Sound is muted until this real click/tap happens — required by every
  // browser's autoplay policy. Once unlocked it stays unlocked for the
  // rest of this page's life (until reloaded), so this only needs to fire
  // once per display session.
  const audioUnlockButton = document.querySelector('[data-display-audio-unlock]');
  audioUnlockButton?.addEventListener('click', () => {
    unlockDisplayAudio();
    audioUnlockButton.hidden = true;
  });

  // BroadcastChannel listener (same-browser fast path — unchanged).
  startDisplayBroadcastListener();

  // Realtime: prefers WS, falls back to short-poll. Every EventBus event
  // (WS daemon or short-poll fallback, same shape either way) also flows
  // through here, so display:pause/display:resume from the KJ console's
  // "Pause" button are picked up without any daemon-side special-casing.
  startRealtime(events => {
    for (const e of events || []) {
      if (e.event_name !== 'display:pause' && e.event_name !== 'display:resume') continue;
      const screen = e.payload?.screen || 'all';
      if (screen !== 'all' && screen !== appConfig.screen) continue;
      if (e.event_name === 'display:pause') pauseDisplayPlayer(); else resumeDisplayPlayer();
    }
    loadQueue().catch(() => {});
  });

  // Typed WS commands for synchronized playback.
  onMessage(msg => {
    if (!msg || !msg.type) return;
    if (msg.type === 'display:cue') {
      const { screen, video, requestId } = msg;
      if (screen && screen !== 'all' && screen !== appConfig.screen) return;
      cueDisplayPlayer({ requestId, ...video }, () => {
        sendDisplayReady({
          screen: appConfig.screen,
          requestId,
          videoId: (video && (video.youtubeVideoId || video.videoUrl)) || '',
          provider: video && video.provider,
        });
      });
    }
    if (msg.type === 'display:play_at') {
      const { screen, startAtServerMs, offsetSeconds } = msg;
      if (screen && screen !== 'all' && screen !== appConfig.screen) return;
      playDisplayPlayerAt(startAtServerMs, offsetSeconds || 0);
    }
  });

  // Periodic status reports while on the display page.
  setInterval(() => {
    const status = getDisplayPlayerStatus();
    if (status) sendDisplayStatus({ screen: appConfig.screen, ...status });
  }, 2000);

  // Initial load.
  loadQueue().catch(() => {});
}

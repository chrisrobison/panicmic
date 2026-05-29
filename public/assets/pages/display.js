/* pages/display.js — fullscreen projector / display popout.
 *
 * Subscribes to:
 *   - SSE display:state_changed + queue:updated (cross-device fallback)
 *   - BroadcastChannel cues from the operator (same-browser fast path)
 * Both paths converge on re-fetching per-screen authoritative state.
 */

import { appConfig, api } from '../lib/api.js';
import { loadQueue, renderDisplay, renderAdminStats } from '../lib/queue.js';
import { broadcast } from '../lib/broadcast.js';
import { startEvents } from '../lib/events.js';

function startDisplayListener() {
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
  loadQueue().catch(() => {});
  startEvents(() => loadQueue().catch(() => {}));
  startDisplayListener();
}

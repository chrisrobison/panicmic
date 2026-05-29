/* broadcast.js — same-browser BroadcastChannel for operator ↔ display.
 *
 * Channel name: `nextup:display:{tenantSlug}:{sessionId}` (per PLAN.md
 * Phase 5.3). Display windows subscribe and refresh authoritative
 * server state on any received command — the bus is a cache invalidation
 * cue, not a data path. Cross-device viewers still get
 * `display:state_changed` over SSE.
 */

import { appConfig } from './api.js';

const broadcast = {
  channel: null,
  open() {
    if (this.channel || !window.BroadcastChannel) return this.channel;
    const name = `nextup:display:${appConfig.tenantSlug || 'unknown'}:${appConfig.sessionId || '0'}`;
    this.channel = new BroadcastChannel(name);
    return this.channel;
  },
  post(payload) {
    const ch = this.open();
    if (!ch) return;
    ch.postMessage(payload);
  },
  subscribe(handler) {
    const ch = this.open();
    if (!ch) return;
    ch.onmessage = event => handler(event.data || {});
  },
};

export { broadcast };

export function broadcastDisplayCommand({ screen = 'all', action, payload = {} }) {
  broadcast.post({ screen, action, payload, sentAt: Date.now() });
}

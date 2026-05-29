/* events.js — realtime subscription (short-poll).
 *
 * Phase 8 replaced the SSE handler with a jittered short-poll on
 * /api/events?last_id=. The server returns {events, last_id} and exits
 * immediately so no PHP-FPM worker is held open per connected client.
 * The client self-schedules with setTimeout so polls never overlap and
 * many clients don't synchronize onto the same tick.
 *
 * Page modules call startEvents(onRefresh) to be notified when the
 * server log has a new event in the realtime channel set. onRefresh is
 * invoked once per poll cycle that returned non-empty events.
 *
 * Returns a stop() function for teardown.
 */

import { api, url } from './api.js';

const BASE_DELAY_MS = 4000;
const JITTER_MS = 1500;
const FAILURE_BACKOFF_AFTER = 2;

export function startEvents(onRefresh) {
  if (location.pathname.startsWith(url('/super'))) return () => {};

  let stopped = false;
  let timer = null;
  let lastId = 0;
  let failures = 0;

  const jitter = () => BASE_DELAY_MS + Math.random() * JITTER_MS;

  const tick = async () => {
    if (stopped) return;
    try {
      const data = await api(`/api/events?last_id=${lastId}`);
      failures = 0;
      const events = Array.isArray(data.events) ? data.events : [];
      const nextId = Number(data.last_id) || 0;
      if (nextId > lastId) lastId = nextId;
      if (events.length) {
        try { onRefresh?.(events); } catch (_) {}
      }
    } catch (_err) {
      failures++;
    }
    if (stopped) return;
    const delay = failures > FAILURE_BACKOFF_AFTER ? jitter() * 2 : jitter();
    timer = setTimeout(tick, delay);
  };

  timer = setTimeout(tick, jitter());

  return function stop() {
    stopped = true;
    if (timer) clearTimeout(timer);
  };
}

/* ws.js — optional WebSocket realtime client with short-poll fallback.
 *
 * The standalone daemon (scripts/ws-server.php) pushes EventBus events and
 * typed display commands (display:cue / display:play_at) over a WebSocket.
 * This module:
 *   - connects to <proto>://<host><basePath>/ws?session_id=&screen=&role=
 *   - dispatches typed messages to onMessage() subscribers
 *   - calls onRefresh(events) for generic `event` pushes (same shape as
 *     the short-poll path in events.js)
 *   - keeps a rolling clock offset so displays can schedule synchronized
 *     playback against server wall-clock time
 *   - falls back to startPollingEvents() when the daemon is unavailable
 *
 * Everything degrades gracefully: if the daemon isn't running, or
 * WEBSOCKET_ENABLED is false, or there's no live session, we transparently
 * use short-polling and the rest of the app behaves exactly as before.
 */

import { appConfig } from './api.js';
import { startPollingEvents } from './events.js';

const MAX_CLOCK_SAMPLES = 5;
const CLOCK_PING_INTERVAL_MS = 10000;
const RECONNECT_MIN_MS = 1000;
const RECONNECT_MAX_MS = 30000;
const FALLBACK_AFTER_FAILURES = 3;

const state = {
  ws: null,
  role: 'display',
  onRefresh: null,
  connected: false,
  failures: 0,
  fellBack: false,
  reconnectDelay: RECONNECT_MIN_MS,
  reconnectTimer: null,
  clockTimer: null,
  clockSamples: [],
  avgOffset: 0,
  stopPoll: null,
  handlers: new Set(),
};

function log(...args) {
  // Quiet by default; uncomment for debugging.
  // console.debug('[ws]', ...args);
}

function wsUrl() {
  const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
  const base = (appConfig.basePath || '').replace(/\/$/, '');
  const path = appConfig.wsPath || '/ws';
  const params = new URLSearchParams({
    session_id: appConfig.sessionId || '0',
    screen: appConfig.screen || 'main',
    role: state.role,
  });
  return `${proto}//${location.host}${base}${path}?${params.toString()}`;
}

function canConnect() {
  if (!appConfig.wsEnabled) return false;
  if (typeof WebSocket === 'undefined') return false;
  const sid = String(appConfig.sessionId || '');
  return sid !== '' && sid !== '0';
}

function send(obj) {
  if (state.ws && state.ws.readyState === WebSocket.OPEN) {
    try {
      state.ws.send(JSON.stringify(obj));
      return true;
    } catch (_) { /* fall through */ }
  }
  return false;
}

function dispatch(msg) {
  for (const cb of state.handlers) {
    try { cb(msg); } catch (_) { /* one bad handler shouldn't break others */ }
  }
}

function recordClockSample(serverTimeMs) {
  const offset = serverTimeMs - Date.now();
  state.clockSamples.push(offset);
  if (state.clockSamples.length > MAX_CLOCK_SAMPLES) state.clockSamples.shift();
  const sum = state.clockSamples.reduce((a, b) => a + b, 0);
  state.avgOffset = state.clockSamples.length ? sum / state.clockSamples.length : 0;
}

function startClockSync() {
  stopClockSync();
  const ping = () => send({ type: 'clock:ping', clientSentPerfMs: performance.now() });
  ping();
  state.clockTimer = setInterval(ping, CLOCK_PING_INTERVAL_MS);
}

function stopClockSync() {
  if (state.clockTimer) { clearInterval(state.clockTimer); state.clockTimer = null; }
}

function fallBackToPolling() {
  if (state.fellBack) return;
  state.fellBack = true;
  log('falling back to short-poll');
  state.stopPoll = startPollingEvents(events => {
    try { state.onRefresh?.(events); } catch (_) {}
  });
}

function scheduleReconnect() {
  if (state.reconnectTimer) return;
  const jitter = Math.random() * 1000;
  const delay = Math.min(state.reconnectDelay, RECONNECT_MAX_MS) + jitter;
  state.reconnectTimer = setTimeout(() => {
    state.reconnectTimer = null;
    connect();
  }, delay);
  state.reconnectDelay = Math.min(state.reconnectDelay * 2, RECONNECT_MAX_MS);
}

function connect() {
  if (!canConnect()) { fallBackToPolling(); return; }

  let ws;
  try {
    ws = new WebSocket(wsUrl());
  } catch (_) {
    state.failures++;
    if (state.failures >= FALLBACK_AFTER_FAILURES) fallBackToPolling();
    else scheduleReconnect();
    return;
  }
  state.ws = ws;

  ws.addEventListener('open', () => {
    state.connected = true;
    state.failures = 0;
    state.reconnectDelay = RECONNECT_MIN_MS;
    log('connected');
    send({
      type: 'hello',
      role: state.role,
      screen: appConfig.screen || 'main',
      sessionId: Number(appConfig.sessionId) || 0,
      clientId: clientId(),
      page: appConfig.page || '',
    });
    startClockSync();
  });

  ws.addEventListener('message', ev => {
    let msg;
    try { msg = JSON.parse(ev.data); } catch (_) { return; }
    if (!msg || typeof msg !== 'object') return;

    switch (msg.type) {
      case 'clock:pong':
        if (typeof msg.serverTimeMs === 'number') recordClockSample(msg.serverTimeMs);
        return;
      case 'hello:ack':
        if (typeof msg.serverTimeMs === 'number') recordClockSample(msg.serverTimeMs);
        dispatch(msg);
        return;
      case 'event':
        // Generic EventBus push: same array shape the short-poll path
        // delivers, so callers can reuse their onRefresh handler.
        try {
          state.onRefresh?.([{ event_name: msg.eventName, payload: msg.payload || {} }]);
        } catch (_) {}
        dispatch(msg);
        return;
      default:
        // Typed messages (display:cue, display:play_at, display:ready,
        // display:status, ...) go straight to subscribers.
        dispatch(msg);
        return;
    }
  });

  ws.addEventListener('close', () => {
    state.connected = false;
    stopClockSync();
    state.ws = null;
    state.failures++;
    log('closed', state.failures);
    if (state.failures >= FALLBACK_AFTER_FAILURES) fallBackToPolling();
    scheduleReconnect();
  });

  ws.addEventListener('error', () => {
    // The close handler does the bookkeeping; just close to be safe.
    try { ws.close(); } catch (_) {}
  });
}

let _clientId = null;
function clientId() {
  if (_clientId) return _clientId;
  try {
    _clientId = (crypto.randomUUID && crypto.randomUUID()) || `c-${Date.now()}-${Math.random().toString(36).slice(2)}`;
  } catch (_) {
    _clientId = `c-${Date.now()}-${Math.random().toString(36).slice(2)}`;
  }
  return _clientId;
}

/* -------------------------------------------------------------- */
/* Public API                                                     */
/* -------------------------------------------------------------- */

/**
 * Start the realtime connection. Prefers WebSocket, falls back to
 * short-polling. onRefresh(events) is invoked with an array of events
 * (same shape as events.js) whenever new events arrive.
 */
export function startRealtime(onRefresh) {
  state.onRefresh = onRefresh;
  state.role = appConfig.page === 'admin-dashboard' || appConfig.page === 'admin-settings' ? 'kj' : 'display';
  if (!canConnect()) {
    fallBackToPolling();
    return () => stop();
  }
  connect();
  return () => stop();
}

function stop() {
  if (state.reconnectTimer) { clearTimeout(state.reconnectTimer); state.reconnectTimer = null; }
  stopClockSync();
  if (state.stopPoll) { try { state.stopPoll(); } catch (_) {} state.stopPoll = null; }
  if (state.ws) { try { state.ws.close(); } catch (_) {} state.ws = null; }
  state.connected = false;
}

export function sendDisplayReady({ screen, requestId, videoId, provider }) {
  send({ type: 'display:ready', screen, requestId, videoId, provider });
}

export function sendDisplayStatus({ screen, requestId, videoId, provider, playerState, currentTime, muted }) {
  send({ type: 'display:status', screen, requestId, videoId, provider, playerState, currentTime, muted });
}

/**
 * Register a handler for typed WS messages (display:cue, display:play_at,
 * display:ready, display:status, hello:ack, event, ...). Returns an
 * unsubscribe function.
 */
export function onMessage(cb) {
  state.handlers.add(cb);
  return () => state.handlers.delete(cb);
}

/** Estimated server wall-clock time in ms (local clock + rolling offset). */
export function getServerNowMs() {
  return Date.now() + state.avgOffset;
}

/**
 * Schedule callback to fire at approximately serverMs (server wall-clock).
 * Returns a cancel function.
 */
export function scheduleAtServerTime(serverMs, callback) {
  const delay = Math.max(0, serverMs - getServerNowMs());
  const id = setTimeout(callback, delay);
  return () => clearTimeout(id);
}

/** True while a WebSocket is open. Useful for UI status indicators. */
export function isConnected() {
  return state.connected;
}

<?php

declare(strict_types=1);

use PanicMic\Database\Connection;
use PanicMic\Services\EventBus;
use PanicMic\Support\Env;
use PanicMic\Tenant\TenantContext;

require dirname(__DIR__) . '/src/autoload.php';
Env::load(dirname(__DIR__) . '/.env');

/*
 * Standalone WebSocket daemon for synchronized display playback.
 *
 *   php scripts/ws-server.php
 *
 * This is entirely optional: when it isn't running, displays fall back to
 * short-polling /api/events. It is a small, dependency-free daemon built on
 * stream_socket_server() — no extensions, no Composer, no framework.
 *
 * Responsibilities:
 *   - Accept WebSocket upgrades on a GET /ws?session_id=&screen=&role= URL.
 *   - Resolve the tenant from the HTTP Host header (never trust the client).
 *   - Validate the session id exists for that tenant.
 *   - Pump EventBus events to connected clients for their tenant+session.
 *   - Translate display:cue / display:play_at events into typed messages.
 *   - Heartbeat with ping/pong; drop dead connections.
 *
 * The daemon is read-only against the database (EventBus::after + a couple
 * of lookups). It never executes user-supplied code or includes paths.
 */

const WS_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
const PING_INTERVAL_S = 30;
const PONG_TIMEOUT_S = 60;
const PUMP_INTERVAL_S = 0.5;
const SELECT_TIMEOUT_US = 100000; // 0.1s

function ws_log(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n");
}

$bind = (string)(Env::get('WEBSOCKET_BIND', '127.0.0.1') ?? '127.0.0.1');
$port = (int)(Env::get('WEBSOCKET_PORT', '8090') ?? 8090);
$listenAddr = "tcp://{$bind}:{$port}";

$errno = 0;
$errstr = '';
$server = @stream_socket_server($listenAddr, $errno, $errstr);
if (!$server) {
    ws_log("FATAL: cannot bind {$listenAddr}: {$errstr} ({$errno})");
    exit(1);
}
stream_set_blocking($server, false);
ws_log("listening on {$listenAddr}");

/**
 * Per-connection state.
 *
 * @var array<int,array{
 *   socket: resource, handshaked: bool, buffer: string, frameBuffer: string,
 *   tenantSlug: ?string, dbName: ?string, sessionId: ?int, screen: string,
 *   role: string, clientId: string, lastId: int, lastPing: float, lastPong: float,
 *   peer: string
 * }> $clients
 */
$clients = [];

/** Cache of tenant PDO handles keyed by database name. @var array<string,PDO> */
$tenantDbs = [];

/** Last pump time. */
$lastPump = 0.0;

/* ----------------------- WebSocket framing ----------------------- */

function ws_encode(string $payload, int $opcode = 0x1): string
{
    $b1 = 0x80 | ($opcode & 0x0F);
    $len = strlen($payload);
    if ($len <= 125) {
        $header = chr($b1) . chr($len);
    } elseif ($len <= 65535) {
        $header = chr($b1) . chr(126) . pack('n', $len);
    } else {
        $header = chr($b1) . chr(127) . pack('J', $len);
    }
    return $header . $payload;
}

/**
 * Decode as many complete frames as are buffered. Returns a list of
 * ['opcode' => int, 'payload' => string]; leftover partial bytes remain in
 * $buffer (passed by reference).
 *
 * @return list<array{opcode:int,payload:string}>
 */
function ws_decode(string &$buffer): array
{
    $frames = [];
    while (true) {
        $len = strlen($buffer);
        if ($len < 2) {
            break;
        }
        $b1 = ord($buffer[0]);
        $b2 = ord($buffer[1]);
        $opcode = $b1 & 0x0F;
        $masked = ($b2 & 0x80) !== 0;
        $payloadLen = $b2 & 0x7F;
        $offset = 2;

        if ($payloadLen === 126) {
            if ($len < 4) {
                break;
            }
            $payloadLen = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($payloadLen === 127) {
            if ($len < 10) {
                break;
            }
            $payloadLen = unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }

        $maskLen = $masked ? 4 : 0;
        if ($len < $offset + $maskLen + $payloadLen) {
            break; // wait for the rest of this frame
        }

        $maskKey = $masked ? substr($buffer, $offset, 4) : '';
        $offset += $maskLen;
        $payload = substr($buffer, $offset, $payloadLen);
        if ($masked) {
            $unmasked = '';
            for ($i = 0; $i < $payloadLen; $i++) {
                $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
            }
            $payload = $unmasked;
        }
        $frames[] = ['opcode' => $opcode, 'payload' => $payload];
        $buffer = substr($buffer, $offset + $payloadLen);
    }
    return $frames;
}

/* ----------------------- Send helpers ----------------------- */

/** @param array<string,mixed> $client */
function ws_send_json(array &$client, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    @fwrite($client['socket'], ws_encode($json, 0x1));
}

/** @param array<string,mixed> $client */
function ws_send_close(array &$client): void
{
    @fwrite($client['socket'], ws_encode('', 0x8));
}

function server_now_ms(): int
{
    return (int)(microtime(true) * 1000);
}

/* ----------------------- Tenant / session resolution ----------------------- */

/**
 * Parse the HTTP upgrade request: status line + headers.
 *
 * @return array{method:string,uri:string,headers:array<string,string>}|null
 */
function parse_http_request(string $raw): ?array
{
    $headerEnd = strpos($raw, "\r\n\r\n");
    if ($headerEnd === false) {
        return null;
    }
    $head = substr($raw, 0, $headerEnd);
    $lines = explode("\r\n", $head);
    $requestLine = array_shift($lines);
    if (!preg_match('#^(\S+)\s+(\S+)\s+HTTP/\d\.\d$#', (string)$requestLine, $m)) {
        return null;
    }
    $headers = [];
    foreach ($lines as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $pos)));
        $value = trim(substr($line, $pos + 1));
        $headers[$name] = $value;
    }
    return ['method' => $m[1], 'uri' => $m[2], 'headers' => $headers];
}

/** Resolve host from the Host header exactly as TenantContext does. */
function host_from_header(string $hostHeader): string
{
    $host = strtolower(trim(explode(',', $hostHeader)[0]));
    if (str_starts_with($host, '[')) {
        return substr($host, 1, (int)strpos($host, ']') - 1);
    }
    return explode(':', $host)[0];
}

/**
 * Look up the tenant for a host. Returns [slug, database_name] or null.
 *
 * @return array{slug:string,database_name:string}|null
 */
function resolve_tenant(string $host): ?array
{
    if (!TenantContext::isAllowedHost($host)) {
        return null;
    }
    $stmt = Connection::super()->prepare(
        "SELECT t.slug, t.database_name
         FROM tenant_domains d
         JOIN tenants t ON t.id = d.tenant_id
         WHERE d.domain = ? AND t.status = 'active'
         LIMIT 1"
    );
    $stmt->execute([$host]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return ['slug' => (string)$row['slug'], 'database_name' => (string)$row['database_name']];
}

/** Open (and cache) a tenant DB handle. */
function tenant_db(string $database): ?PDO
{
    global $tenantDbs;
    if (isset($tenantDbs[$database])) {
        return $tenantDbs[$database];
    }
    try {
        return $tenantDbs[$database] = Connection::tenant($database);
    } catch (Throwable $e) {
        ws_log("tenant DB open failed for {$database}: " . $e->getMessage());
        return null;
    }
}

/** Validate the session id exists for this tenant. */
function session_exists(PDO $db, int $sessionId): bool
{
    if ($sessionId <= 0) {
        return false;
    }
    try {
        $stmt = $db->prepare('SELECT 1 FROM karaoke_sessions WHERE id = ? LIMIT 1');
        $stmt->execute([$sessionId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        ws_log('session lookup failed: ' . $e->getMessage());
        return false;
    }
}

/* ----------------------- Handshake ----------------------- */

/**
 * Attempt the WebSocket handshake once the full HTTP head is buffered.
 * On success, populates the client entry and returns true.
 *
 * @param array<string,mixed> $client
 */
function try_handshake(array &$client): bool
{
    $req = parse_http_request($client['buffer']);
    if ($req === null) {
        // Need more bytes, unless the buffer is implausibly large.
        if (strlen($client['buffer']) > 16384) {
            return false; // caller will drop on false + handshaked stays false
        }
        return false;
    }

    $headers = $req['headers'];
    $key = $headers['sec-websocket-key'] ?? '';
    $upgrade = strtolower($headers['upgrade'] ?? '');
    if ($key === '' || $upgrade !== 'websocket') {
        @fwrite($client['socket'], "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
        return false;
    }

    // Resolve tenant from Host header — never from client query params.
    $host = host_from_header($headers['host'] ?? '');
    $tenant = resolve_tenant($host);
    if ($tenant === null) {
        @fwrite($client['socket'], "HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n");
        ws_log("handshake rejected: unknown/forbidden host '{$host}'");
        return false;
    }

    // Parse query string from the request URI.
    $query = [];
    $qPos = strpos($req['uri'], '?');
    if ($qPos !== false) {
        parse_str(substr($req['uri'], $qPos + 1), $query);
    }
    $sessionId = isset($query['session_id']) ? (int)$query['session_id'] : 0;
    $screen = preg_replace('/[^a-z0-9_-]/i', '', (string)($query['screen'] ?? 'main')) ?: 'main';
    $role = ($query['role'] ?? '') === 'kj' ? 'kj' : 'display';

    $db = tenant_db($tenant['database_name']);
    if ($db === null || !session_exists($db, $sessionId)) {
        @fwrite($client['socket'], "HTTP/1.1 404 Not Found\r\nConnection: close\r\n\r\n");
        ws_log("handshake rejected: bad session {$sessionId} for tenant {$tenant['slug']}");
        return false;
    }

    // Complete the RFC 6455 handshake.
    $accept = base64_encode(sha1($key . WS_GUID, true));
    $response = "HTTP/1.1 101 Switching Protocols\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Accept: {$accept}\r\n\r\n";
    @fwrite($client['socket'], $response);

    // Initialize lastId to the current head so we only push *new* events.
    $lastId = 0;
    try {
        $row = $db->query('SELECT COALESCE(MAX(id), 0) FROM realtime_events')->fetchColumn();
        $lastId = (int)$row;
    } catch (Throwable $e) { /* default 0 */ }

    $now = microtime(true);
    $client['handshaked'] = true;
    $client['buffer'] = '';
    $client['tenantSlug'] = $tenant['slug'];
    $client['dbName'] = $tenant['database_name'];
    $client['sessionId'] = $sessionId;
    $client['screen'] = $screen;
    $client['role'] = $role;
    $client['clientId'] = bin2hex(random_bytes(8));
    $client['lastId'] = $lastId;
    $client['lastPing'] = $now;
    $client['lastPong'] = $now;

    ws_log("client {$client['clientId']} connected: tenant={$tenant['slug']} session={$sessionId} screen={$screen} role={$role}");

    // Greet immediately.
    ws_send_json($client, [
        'type' => 'hello:ack',
        'serverTimeMs' => server_now_ms(),
        'clientId' => $client['clientId'],
        'tenantSlug' => $tenant['slug'],
        'sessionId' => $sessionId,
    ]);
    return true;
}

/* ----------------------- Client message handling ----------------------- */

/** @param array<string,mixed> $client */
function handle_text_message(array &$client, string $payload): bool
{
    $msg = json_decode($payload, true);
    if (!is_array($msg) || !isset($msg['type'])) {
        return false; // protocol violation → caller closes
    }
    switch ($msg['type']) {
        case 'hello':
            // Already greeted at handshake; nothing required. Could refine
            // role/screen here, but Host-derived tenant is authoritative.
            return true;
        case 'clock:ping':
            ws_send_json($client, [
                'type' => 'clock:pong',
                'serverTimeMs' => server_now_ms(),
                'clientSentPerfMs' => $msg['clientSentPerfMs'] ?? null,
            ]);
            return true;
        case 'display:ready':
        case 'display:status':
        case 'display:error':
            // Status/telemetry from displays. The daemon doesn't persist
            // these (read-only design); they're available for future
            // KJ-presence relays. Accept and ignore for now.
            return true;
        default:
            return true; // tolerate unknown client messages
    }
}

/* ----------------------- Event pumping ----------------------- */

/**
 * For each distinct tenant+session among connected clients, fetch new
 * EventBus rows once and fan them out.
 */
function pump_events(): void
{
    global $clients;

    // Group handshaked clients by db+session, tracking the minimum lastId
    // so one query serves every client in the group.
    $groups = [];
    foreach ($clients as $id => $c) {
        if (!$c['handshaked'] || $c['dbName'] === null || $c['sessionId'] === null) {
            continue;
        }
        $key = $c['dbName'] . ':' . $c['sessionId'];
        if (!isset($groups[$key])) {
            $groups[$key] = ['dbName' => $c['dbName'], 'sessionId' => $c['sessionId'], 'minLastId' => $c['lastId'], 'ids' => []];
        }
        $groups[$key]['minLastId'] = min($groups[$key]['minLastId'], $c['lastId']);
        $groups[$key]['ids'][] = $id;
    }

    foreach ($groups as $group) {
        $db = tenant_db($group['dbName']);
        if ($db === null) {
            continue;
        }
        try {
            $events = EventBus::after($db, $group['minLastId']);
        } catch (Throwable $e) {
            ws_log('pump query failed: ' . $e->getMessage());
            continue;
        }
        if ($events === []) {
            continue;
        }
        $maxId = $group['minLastId'];
        foreach ($events as $ev) {
            $maxId = max($maxId, (int)$ev['id']);
        }
        foreach ($group['ids'] as $id) {
            if (!isset($clients[$id])) {
                continue;
            }
            $client = &$clients[$id];
            foreach ($events as $ev) {
                if ((int)$ev['id'] <= $client['lastId']) {
                    continue; // already delivered to this client
                }
                deliver_event($client, $ev);
            }
            $client['lastId'] = max($client['lastId'], $maxId);
            unset($client);
        }
    }
}

/**
 * Send a single EventBus row to one client. Pushes the generic `event`
 * message, and additionally a typed display:cue / display:play_at message
 * when the event is one of those (respecting per-screen targeting).
 *
 * @param array<string,mixed> $client
 * @param array<string,mixed> $ev
 */
function deliver_event(array &$client, array $ev): void
{
    $name = (string)($ev['event_name'] ?? '');
    $payload = is_array($ev['payload'] ?? null) ? $ev['payload'] : [];
    $nowMs = server_now_ms();

    ws_send_json($client, [
        'type' => 'event',
        'eventName' => $name,
        'payload' => $payload,
        'serverTimeMs' => $nowMs,
    ]);

    if ($name === 'display:cue' || $name === 'display:play_at') {
        $target = (string)($payload['screen'] ?? 'all');
        // Per-screen targeting: 'all' reaches every screen.
        if ($target !== 'all' && $target !== $client['screen']) {
            return;
        }
        if ($name === 'display:cue') {
            ws_send_json($client, [
                'type' => 'display:cue',
                'screen' => $target,
                'requestId' => $payload['requestId'] ?? null,
                'video' => $payload['video'] ?? ['provider' => 'none', 'youtubeVideoId' => '', 'videoUrl' => ''],
                'serverTimeMs' => $nowMs,
            ]);
        } else {
            ws_send_json($client, [
                'type' => 'display:play_at',
                'screen' => $target,
                'requestId' => $payload['requestId'] ?? null,
                'commandId' => $payload['commandId'] ?? '',
                'startAtServerMs' => $payload['startAtServerMs'] ?? $nowMs,
                'offsetSeconds' => $payload['offsetSeconds'] ?? 0,
                'serverTimeMs' => $nowMs,
            ]);
        }
    }
}

/* ----------------------- Connection teardown ----------------------- */

/** @param array<int,array<string,mixed>> $clients */
function drop_client(array &$clients, int $id, string $reason): void
{
    if (!isset($clients[$id])) {
        return;
    }
    $cid = $clients[$id]['clientId'] ?? '(pre-handshake)';
    if (is_resource($clients[$id]['socket'])) {
        @fclose($clients[$id]['socket']);
    }
    unset($clients[$id]);
    ws_log("client {$cid} dropped: {$reason}");
}

/* ----------------------- Main loop ----------------------- */

// Run until the listening socket goes away (or the process is signalled).
while (is_resource($server)) {
    try {
        $read = [$server];
        foreach ($clients as $c) {
            $read[] = $c['socket'];
        }
        $write = null;
        $except = null;

        // stream_select mutates $read; copy each loop.
        $ready = @stream_select($read, $write, $except, 0, SELECT_TIMEOUT_US);
        if ($ready === false) {
            // Interrupted by a signal — keep going.
            usleep(10000);
        } elseif ($ready > 0) {
            foreach ($read as $sock) {
                if ($sock === $server) {
                    $conn = @stream_socket_accept($server, 0);
                    if ($conn) {
                        stream_set_blocking($conn, false);
                        $id = (int)$conn;
                        $clients[$id] = [
                            'socket' => $conn,
                            'handshaked' => false,
                            'buffer' => '',
                            'frameBuffer' => '',
                            'tenantSlug' => null,
                            'dbName' => null,
                            'sessionId' => null,
                            'screen' => 'main',
                            'role' => 'display',
                            'clientId' => '',
                            'lastId' => 0,
                            'lastPing' => microtime(true),
                            'lastPong' => microtime(true),
                            'peer' => (string)@stream_socket_get_name($conn, true),
                        ];
                    }
                    continue;
                }

                $id = (int)$sock;
                if (!isset($clients[$id])) {
                    continue;
                }
                $data = @fread($sock, 65535);
                if ($data === '' || $data === false) {
                    // EOF / error.
                    if (feof($sock)) {
                        drop_client($clients, $id, 'eof');
                    }
                    continue;
                }

                if (!$clients[$id]['handshaked']) {
                    $clients[$id]['buffer'] .= $data;
                    if (str_contains($clients[$id]['buffer'], "\r\n\r\n")) {
                        if (!try_handshake($clients[$id])) {
                            drop_client($clients, $id, 'handshake failed');
                        }
                    } elseif (strlen($clients[$id]['buffer']) > 16384) {
                        drop_client($clients, $id, 'oversized handshake');
                    }
                    continue;
                }

                // Established connection: decode WebSocket frames.
                $clients[$id]['frameBuffer'] .= $data;
                $frames = ws_decode($clients[$id]['frameBuffer']);
                foreach ($frames as $frame) {
                    $opcode = $frame['opcode'];
                    if ($opcode === 0x8) { // close
                        ws_send_close($clients[$id]);
                        drop_client($clients, $id, 'client close');
                        break;
                    } elseif ($opcode === 0x9) { // ping → pong
                        @fwrite($clients[$id]['socket'], ws_encode($frame['payload'], 0xA));
                    } elseif ($opcode === 0xA) { // pong
                        $clients[$id]['lastPong'] = microtime(true);
                    } elseif ($opcode === 0x1) { // text
                        if (!handle_text_message($clients[$id], $frame['payload'])) {
                            ws_send_close($clients[$id]);
                            drop_client($clients, $id, 'protocol violation');
                            break;
                        }
                    }
                    // Binary (0x2) and continuation (0x0) frames are ignored.
                }
            }
        }

        // Periodic event pump.
        $now = microtime(true);
        if ($now - $lastPump >= PUMP_INTERVAL_S) {
            $lastPump = $now;
            pump_events();
        }

        // Heartbeat: send pings, reap dead connections.
        foreach ($clients as $id => $c) {
            if (!$c['handshaked']) {
                continue;
            }
            if ($now - $c['lastPong'] > PONG_TIMEOUT_S) {
                drop_client($clients, $id, 'pong timeout');
                continue;
            }
            if ($now - $c['lastPing'] > PING_INTERVAL_S) {
                @fwrite($c['socket'], ws_encode('', 0x9)); // ping
                $clients[$id]['lastPing'] = $now;
            }
        }
    } catch (Throwable $e) {
        // A single bad connection must never crash the daemon.
        ws_log('loop error: ' . $e->getMessage());
        usleep(50000);
    }
}

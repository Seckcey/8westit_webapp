# Milepost — Phase 1: Real-Time Foundation

**Authoritative SPECIFICATION & CONTRACT — implementation-ready.**

This document merges three architect proposals (pragmatic / scale / security) into one
buildable contract. It is precise enough that four engineers can independently build the
four components below and have them interoperate on the first try:

1. **Node backend** (`realtime/backend/`) — WebSocket server on the VPS.
2. **.NET agent module** (`agent/EightWestAgent/RealtimeClient.cs`) — additive real-time client.
3. **MySQL migration** (`portal/db/migrations/2026-07-01_realtime_foundation.sql`).
4. **Portal PHP integration** (`portal/public/api/svc/*` + `portal/lib/svc_auth.php`).

It is anchored to the **actual** current code (verified against the live repo):
- `portal/lib/bootstrap.php`: `db()`, `cfg($key,$default)`, `audit(?int $userId, ?int $agentId, string $action, string $detail='')`, `json_out($data,$code)`, `json_err($msg,$code)`, `read_json_body()`, `client_ip()`.
- `portal/lib/auth.php`: `authenticate_agent()` does `hash('sha256',$token)` → `agents.auth_token_hash WHERE is_archived=0`. `bearer_token()` reads `Authorization` / `REDIRECT_HTTP_AUTHORIZATION`.
- `portal/public/api/jobs.php`: GET claims one `status='queued'` job → `running`, sets `picked_at`; POST updates `WHERE id=? AND agent_id=? AND status='running'`. Job shape on the wire is `{id, job_type, payload}`. Result body is `{job_id,status,exit_code,output}`. Output is capped at 1,000,000 bytes.
- `portal/public/api/heartbeat.php`: returns `{ok, pending_jobs}`.
- `portal/public/api/enroll.php`: returns `{ok, token, heartbeat_secs, rustdesk:{relay_host,relay_key}}`.
- `agent/EightWestAgent/`: `Worker.cs` (`Version="1.0.3"`, `MainLoop()`), `Config.cs` (reads HKLM\SOFTWARE\8WestIT\Agent 64-bit view + `config.json`; keys `PortalUrl`/`EnrollKey`/`RustDeskUrl`), `JobRunner.Execute(type,payload) → JobResult{bool Success;int ExitCode;string Output}`, types `powershell|cmd|restart|message`, 5-minute cap, `ApiClient` (already enables TLS), `AgentState` (`AuthToken`,`AgentUid`,`RustDeskPassword`, `Save()`/`Load()`).
- `portal/config/config.sample.php`: a flat `return [ ... ]` array read via `cfg('topkey')`.

> **Naming note.** The new agent registry/config namespace stays `8WestIT\Agent` (existing),
> and the brand for new identifiers is **Milepost** (e.g. `X-Milepost-Service`, `service_secret`).
> Both are intentional; do not "fix" one to match the other.

---

## 0. Topology & trust boundaries

```
                       (source of truth — never reachable from VPS)
  ┌──────────────┐  (1) HTTPS poll (UNCHANGED)   ┌───────────────────────────┐
  │  .NET Agent  │──────────────────────────────►│  PHP Portal (HostGator)    │
  │  svc 4.8     │   enroll/heartbeat/jobs/inv    │  MySQL 8 = SOURCE OF TRUTH │
  │ v1.0.3→1.1.0 │                                │  /api/*.php (agent)        │
  │              │                                │  /api/svc/*.php (service)  │
  │              │  (2) WSS (NEW, real-time)      └───────────────────────────┘
  │              │═══════════════╗                         ▲
  └──────────────┘               ║                         ║ (3) HTTPS + shared service
        │ RustDesk P2P/relay     ▼                         ║     secret (X-Milepost-Service)
        │                ┌───────────────────────────┐     ║
        └───────────────►│  Milepost RT Backend (VPS) │═════╝
                         │  Node + ws (ws://127.0.0.1)│
        ┌───────────────►│  in-mem presence+metrics   │
        │ hbbs/hbbr      │  optional SQLite cache     │
   ┌────┴─────┐          │  behind Caddy TLS (443)    │
   │ RustDesk │          └───────────────────────────┘
   └──────────┘
```

Three trust zones:

1. **Agent ⇄ Portal (HTTPS poll)** — the *existing* channel. **Never removed.** It is the
   always-on floor: every real-time feature degrades to polling.
2. **Agent ⇄ Backend (WSS)** — the new real-time channel. Authenticated by the agent's
   **existing bearer token** (constraint 4 — no re-enrollment). The backend cannot verify the
   token itself (no DB), so it asks the portal.
3. **Backend ⇄ Portal (HTTPS, shared secret)** — the only path by which live data reaches
   MySQL. The backend has **no MySQL driver and no DB credentials** (constraint 2).

**Source-of-truth principle.** Backend state (presence, latest metrics, in-flight commands)
is ephemeral and rebuildable. If the backend is wiped, polling still works and the portal is
intact. The portal independently re-authorizes every state-changing service call (it does not
blindly trust "the backend says agent 5 ran this").

---

## 1. Real-time channel: transport + wire protocol

### 1.1 Transport (resolved decisions)

| Decision | Choice | Rationale |
|---|---|---|
| Protocol | WebSocket (RFC 6455), one persistent connection per agent | constraint 5/6 |
| Agent lib | `System.Net.WebSockets.ClientWebSocket` | built into .NET 4.8, no NuGet |
| Backend lib | Node.js + `ws` | constraint 6, minimal deps |
| Frames | UTF-8 **text** only, exactly one JSON object per frame | binary rejected (close 1003); simplest |
| Subprotocol | `Sec-WebSocket-Protocol: milepost.v1` | version pinned at handshake; server echoes it |
| URL | `wss://rt.8westit.com/agent` (proxy → `ws://127.0.0.1:8090/agent`) | token NOT in URL (no proxy-log leakage) |
| Frame size cap | 256 KB (`maxPayload`); oversize → close 1009 | matches per-frame output chunking |

**Envelope — every message, both directions:**

```json
{ "t": "<type>", "id": "<uuid-or-null>", "ts": 1719600000, "ref": "<id-or-null>", "d": {} }
```

- `t` — message type (string, required).
- `id` — uuidv4 message id, present when a reply/ack is expected; `null` for fire-and-forget (`metrics`).
- `ts` — Unix **seconds**, sender clock. **Advisory only; never used for authorization.**
- `ref` — the `id` this message responds to; `null`/omitted otherwise.
- `d` — type-specific payload object (may be `{}`).

> Conflict resolution: Design A used short keys `t/id/ts/d`; Design B added `v`/`ref`/ULID;
> Design C added `ref`. **Resolved:** keep A's compact envelope, adopt `ref` (needed for clean
> ack correlation), drop the separate `v` field (the subprotocol token already pins the version),
> use **uuidv4** not ULID (no dependency, agent already generates GUIDs). Unknown `t` and unknown
> fields **MUST be ignored** (forward-compatibility).

### 1.2 Connection lifecycle

```
Agent                                            Backend
  │── WSS connect (subprotocol milepost.v1) ──────►│
  │── hello {token,...} ──────────────────────────►│ POST /api/svc/verify_agent.php (cached)
  │◄───────────────── welcome {agent_id,...} ──────│ presence ONLINE; flush presence to portal
  │◄──────────────── ping (every 30s) ─────────────│
  │── pong ───────────────────────────────────────►│
  │── metrics (cadence) ──────────────────────────►│ store latest in memory
  │◄──────────────── command {job_id,...} ─────────│ (minted via /api/svc/job_create.php)
  │── cmd_ack {accepted} ─────────────────────────►│
  │── cmd_result {status,exit_code,output} ───────►│ POST /api/svc/job_result.php
  │◄──────────────── cmd_result_ack ───────────────│ (agent may drop from outbox)
  │── event {kind,...} ───────────────────────────►│
  │── bye (service stop) ─────────────────────────►│ presence OFFLINE
```

**`hello` deadline:** the agent MUST send `hello` within **10 s** of socket open, and no other
message type is accepted before `welcome`. Miss it → backend closes **4408**.

### 1.3 Messages: **Agent → Backend**

**`hello`** — authenticate the connection (first frame).
```json
{ "t":"hello","id":"6f1c…","ts":1719600000,"d":{
  "agent_uid":"3F2504E0-4F89-41D3-9A0C-0305E82C3301",
  "token":"<raw existing bearer token>",
  "agent_version":"1.1.0",
  "caps":["metrics","exec"],
  "boot_ts":1719590000,
  "rustdesk_id":"123456789"
}}
```

**`metrics`** — live telemetry, fire-and-forget (`id:null`). Lossy by design.
```json
{ "t":"metrics","id":null,"ts":1719600060,"d":{
  "cpu":13.5,
  "mem":42.0,
  "disk_c":68.3,
  "uptime_secs":864000,
  "logged_user":"DESK-7\\frank",
  "net_up":12000,
  "net_down":88000
}}
```
Cadence is dictated by the backend (`welcome.d.metrics_interval` / `set_metrics_interval`):
**15 s** while a tech is watching the device, **60 s** otherwise. Agent coalesces (keeps only the
newest queued `metrics` under send pressure).

**`cmd_ack`** — agent received a `command` and decided to run (or refuse) it.
```json
{ "t":"cmd_ack","id":"3a0b…","ts":1719600061,"ref":"<command id>","d":{
  "job_id":501,
  "accepted":true,
  "reason":""
}}
```
`accepted:false` with a `reason` (e.g. `"unsupported job_type"`, `"already_ran"`) means the agent
will not execute. The job stays `queued` (the poller picks it up if appropriate).

**`cmd_result`** — terminal result of a command.
```json
{ "t":"cmd_result","id":"7c4d…","ts":1719600070,"ref":"<command id>","d":{
  "job_id":501,
  "status":"done",
  "exit_code":0,
  "output":"…stdout+stderr, capped 256 KB on the wire…",
  "truncated":false
}}
```
`status ∈ {"done","error"}` (mirrors `JobRunner.JobResult.Success`). On-wire `output` is capped at
256 KB (one frame). The portal stores up to 1,000,000 bytes (existing cap); if the agent has more,
it sets `truncated:true`.

**`event`** — unsolicited state change.
```json
{ "t":"event","id":null,"ts":1719600080,"d":{ "kind":"rustdesk_ready","rustdesk_id":"123456789" }}
```
Defined `kind` values: `rustdesk_ready`, `user_login`, `user_logout`, `reboot_pending`.

**`pong`** — reply to backend `ping`.
```json
{ "t":"pong","id":null,"ts":1719600090,"ref":"<ping id>","d":{} }
```

**`bye`** — graceful shutdown (service stopping).
```json
{ "t":"bye","id":null,"ts":1719600099,"d":{ "reason":"service_stop" } }
```

### 1.4 Messages: **Backend → Agent**

**`welcome`** — auth succeeded.
```json
{ "t":"welcome","id":"a91e…","ts":1719600001,"ref":"<hello id>","d":{
  "agent_id":42,
  "session_id":"s-7d2a…",
  "metrics_interval":60,
  "ping_interval":30,
  "server_time":1719600001,
  "features":["exec","metrics"]
}}
```

**`command`** — push a job to run now. Mirrors a real `jobs` row already minted in MySQL.
```json
{ "t":"command","id":"d44b…","ts":1719600050,"d":{
  "job_id":501,
  "job_type":"powershell",
  "payload":"Get-Service Spooler",
  "timeout_secs":300,
  "not_after":1719600650,
  "tool_action_id":"restart-print-spooler"
}}
```
- `job_type` is the existing enum: `powershell | cmd | restart | message`.
- `job_id` is the portal `jobs.id` — the **end-to-end idempotency key**.
- `not_after` (Unix sec) — agent refuses (with `cmd_ack accepted:false`) if past it.
- `tool_action_id` — optional governance link (null for raw jobs).

**`set_metrics_interval`** — change metrics cadence.
```json
{ "t":"set_metrics_interval","id":null,"ts":1719600100,"d":{ "seconds":15 } }
```

**`ping`** — liveness + idle-proxy keepalive (every 30 s).
```json
{ "t":"ping","id":"e3f0…","ts":1719600030,"d":{} }
```

**`cmd_result_ack`** — backend confirms the result was durably persisted to the portal.
```json
{ "t":"cmd_result_ack","id":null,"ts":1719600071,"ref":"<cmd_result id>","d":{
  "job_id":501,
  "persisted":true
}}
```

**`error`** — non-fatal protocol problem (does not necessarily close).
```json
{ "t":"error","id":null,"ts":1719600105,"ref":"<offending id or null>","d":{
  "code":"bad_message",
  "msg":"unknown type"
}}
```
Codes: `bad_message`, `rate_limit`, `backpressure`, `not_authenticated`.

### 1.5 Close codes (backend → agent)

| Code | Meaning | Agent action |
|---|---|---|
| 1000 | normal close / `bye` acknowledged | none |
| 1002 | subprotocol/protocol violation | reconnect with backoff |
| 1003 | binary frame / unsupported data | reconnect with backoff |
| 1009 | frame too large | reconnect with backoff |
| 1011 | backend internal error / portal unreachable | reconnect with backoff; **polling carries load** |
| 1012 / 1001 | backend restarting / going away | reconnect with backoff |
| **4401** | auth failed / token revoked / archived | **stop WS for this token**; keep polling; restart WS after token rotates |
| **4408** | no `hello` within 10 s | reconnect with backoff |
| **4409** | duplicate connection (same agent reconnected) | the *older* socket closes silently |

### 1.6 Liveness & correctness mechanics (resolved)

- **Keepalive:** backend pings every **30 s**; agent must `pong`. Backend misses **2** pongs →
  close 1011, mark agent `stale` then `offline` after grace. Agent treats **45 s of silence**
  (no ping/command/anything) as a dead socket → close + reconnect.
- **WS never replaces the portal heartbeat.** The agent keeps POSTing `/api/heartbeat.php` so
  `agents.last_seen_at` (the truth) stays fresh even if the backend is down. (See §5.)
- **Idempotent commands.** `job_id` is the idempotency key end to end. The agent keeps a small
  on-disk LRU (in `AgentState`) of `job_id`s seen in the last ~10 min. If the same `job_id`
  arrives via **both** WS `command` and a poll claim, it runs **once**. If a redelivered command's
  result was already produced, the agent re-sends `cmd_result` (cheap) rather than re-executing.
- **No double execution.** A `command` is only ever sent for a row the portal already marked
  `running` (the portal mints it `queued`, the backend's dispatch path flips it to `running` via
  `/api/svc/job_create.php` returning the row already claimed for RT — see §3.3). The polling GET
  only claims `status='queued'`, so it cannot grab an RT-claimed job.
- **No lost results.** Two-phase ownership:
  - Backend keeps a `command` in its per-agent outbox until `cmd_result` arrives.
  - Agent keeps a `cmd_result` in its outbox until `cmd_result_ack` arrives **or** a fallback
    timeout (`RtResultFallbackSecs`, default 30 s) elapses — then it writes the result via the
    **existing** `POST /api/jobs.php` path. Idempotency by `job_id` makes the eventual double-write
    safe (the second write hits a non-`running` row and no-ops).
- **Backpressure.** Backend watches `ws.bufferedAmount`; above 1 MB it stops sending non-critical
  frames and emits `error{code:"backpressure"}`; if saturated past a timeout it closes 1011.
  Metrics are droppable; command/result frames are never silently dropped.

---

## 2. Backend ⇄ Portal: shared-secret auth & token verification

### 2.1 The shared service secret

A single 64-hex secret, generated with `openssl rand -hex 32`, stored in:
- portal `config.php` → `'service_secret'`
- backend `.env` → `MILEPOST_SERVICE_SECRET`

They MUST be byte-identical.

**Every backend→portal request carries a static header AND a signature** (defense in depth — a
stolen static header alone is not enough, and HostGator sometimes strips `Authorization`, so the
HMAC is the real check):

```
X-Milepost-Service:   <MILEPOST_SERVICE_SECRET>          # static shared secret (constant-time compared)
X-Milepost-Timestamp: <unix seconds>                     # replay window ±300 s
X-Milepost-Nonce:     <uuidv4>                            # single-use within the window
X-Milepost-Sign:      <hex HMAC-SHA256( secret,
                         METHOD + "\n" + PATH + "\n" + TS + "\n" + NONCE + "\n" + sha256(body) )>
```

> Conflict resolution: A used a bare static header; B/C added HMAC+timestamp+nonce. **Resolved:**
> keep the **static header** (simple, supports rotation/identification) AND require the
> **HMAC + timestamp + nonce** (replay-safe, survives header stripping). Both must validate.

`PATH` is the request path beginning with `/api/svc/…` (no query string, no scheme/host).
`body` is the raw request bytes (empty string for none). Timestamps outside ±300 s → 401.
Reused nonces within the window → 401 (tracked in `service_nonces`, pruned on a slow cadence).

### 2.2 Portal helper: `portal/lib/svc_auth.php`

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** Verify a backend-to-portal service request. 401s on any failure. */
function require_service(): void {
    $secret = (string) cfg('service_secret', '');
    if ($secret === '') json_err('Service auth not configured', 401);

    $sent  = $_SERVER['HTTP_X_MILEPOST_SERVICE']   ?? '';
    $ts    = $_SERVER['HTTP_X_MILEPOST_TIMESTAMP'] ?? '';
    $nonce = $_SERVER['HTTP_X_MILEPOST_NONCE']     ?? '';
    $sign  = $_SERVER['HTTP_X_MILEPOST_SIGN']      ?? '';

    if (!hash_equals($secret, (string)$sent)) json_err('Unauthorized', 401);

    $skew = (int) cfg('service_replay_window', 300);
    if (!ctype_digit((string)$ts) || abs(time() - (int)$ts) > $skew) json_err('Stale request', 401);
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', (string)$nonce)) json_err('Bad nonce', 401);

    $body = file_get_contents('php://input') ?: '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $base = $method . "\n" . $path . "\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $body);
    $want = hash_hmac('sha256', $base, $secret);
    if (!hash_equals($want, (string)$sign)) json_err('Bad signature', 401);

    // Replay protection: nonce must be unused within the window.
    try {
        db()->prepare('INSERT INTO service_nonces (nonce) VALUES (?)')->execute([$nonce]);
    } catch (Throwable $e) {
        json_err('Replay detected', 401);
    }
    // Opportunistic prune (cheap, runs ~1% of requests).
    if (random_int(1, 100) === 1) {
        db()->prepare('DELETE FROM service_nonces WHERE seen_at < (NOW() - INTERVAL 1 HOUR)')->execute();
    }
}
```

> `read_json_body()` re-reads `php://input`; that is fine because both calls use
> `file_get_contents('php://input')`, which is replayable in PHP for the same request.

### 2.3 Agent authentication flow on the WS

1. Agent opens WS, sends `hello.d.token` (raw bearer over TLS).
2. Backend → portal `POST /api/svc/verify_agent.php` with `{ "token": "<raw>" }` (service-signed).
3. Portal computes `sha256(token)`, looks up `agents WHERE auth_token_hash=? AND is_archived=0`,
   returns identity + tenancy (mirrors `authenticate_agent()` exactly).
4. Backend caches `sha256(token) → {agent_id, client_id, site_id, hostname}` **in memory for 300 s**
   (`VERIFY_CACHE_TTL_SECS`) so reconnect storms don't hammer the portal. The cache key is the
   **hash**, never the raw token; the raw token is used transiently and never logged or persisted.
5. `ok:false` → backend sends `error{code:"not_authenticated"}` then closes **4401**.

No new secret on the agent, no re-enrollment (constraint 4).

### 2.4 Durable persistence (the only writes that reach MySQL)

- **Command results** → `POST /api/svc/job_result.php` immediately on `cmd_result`; on success the
  backend sends `cmd_result_ack`. On portal failure the backend retries with backoff and does
  **not** ack (agent's REST fallback is the backstop). Buffered in the optional SQLite ring so a
  backend restart doesn't lose an unacked result.
- **Presence + latest metrics** → coalesced. Backend flushes `POST /api/svc/metrics_snapshot.php`
  at most **once per agent per 60 s** (`METRICS_SNAPSHOT_THROTTLE_SECS`), batched across agents.
  This updates `agents.last_seen_at` and upserts `agent_metrics_latest`. **WS-connected agents
  therefore write to MySQL *less* than 60 s polling, not more.**
- **Live metrics are never written per-push.** They live in backend memory (and optional SQLite
  cache); only the throttled snapshot reaches MySQL. No metrics history table on shared hosting.

---

## 3. Portal-side REST endpoints (called by the backend)

All under `portal/public/api/svc/`. All require `require_service()` (§2.2). All return the existing
`{ "ok":true, … }` / `{ "ok":false, "error":… }` convention via `json_out`/`json_err`. None are
reachable by agents or browsers. New config key: `service_secret`.

> Directory `portal/public/api/svc/` should also get a minimal `.htaccess` (`Require all granted`
> with normal PHP handling) — no special rules needed; the secret guards every file.

### 3.1 `POST /api/svc/verify_agent.php`
```jsonc
// Auth: service headers (§2.1)
// Request
{ "token": "<raw agent bearer token>" }
// Response 200 (ok flag carries the result; HTTP stays 200 on unknown token)
{ "ok":true, "agent_id":42, "client_id":7, "site_id":3, "site":"HQ",
  "hostname":"DESK-7", "display_name":"DESK-7", "is_archived":false,
  "policy_etag":"p-9f3a2c" }
// Response 200 (not found)
{ "ok":false, "error":"unknown token" }
```
Logic:
```sql
SELECT id, client_id, site_id, site, hostname, display_name, policy_etag
FROM agents WHERE auth_token_hash = SHA2(?,256) AND is_archived = 0;
```
(In PHP use `hash('sha256',$token)` and bind the hex string, matching `authenticate_agent()`.)

### 3.2 `POST /api/svc/job_result.php`
```jsonc
// Request
{ "agent_id":42, "job_id":501, "status":"done", "exit_code":0, "output":"…" }
// Response
{ "ok":true, "already":false }
```
Logic (idempotent by `job_id`; trusts `agent_id` from the verified backend):
```sql
UPDATE jobs
   SET status=?, exit_code=?, output=?, finished_at=NOW(), delivered_via='realtime'
 WHERE id=? AND agent_id=? AND status IN ('queued','running');
```
`status` normalized to `done|error` (anything not `error` → `done`). `output` capped at 1,000,000
bytes (existing rule). 0 rows affected (already terminal) → `{ "ok":true, "already":true }` (still
200, so the backend safely acks). Then `audit(null, $agentId, 'job_result_rt', "job=$jobId status=$status exit=$exit")`.

### 3.3 `POST /api/svc/job_create.php`
Mints a `jobs` row and (when `dispatch:"realtime"`) returns it already claimed for RT delivery, so
the poller cannot also grab it. Called by the **portal UI** ("Run now") or by the backend when it
needs the canonical `job_id` before pushing `command`.
```jsonc
// Request
{ "agent_id":42, "job_type":"powershell", "payload":"Get-Service Spooler",
  "created_by_user":3, "tool_action_id":"restart-print-spooler",
  "timeout_secs":300, "dispatch":"realtime" }     // dispatch: "realtime" | "poll"
// Response
{ "ok":true, "job_id":501, "status":"running" }   // status "running" when dispatch=realtime, else "queued"
```
Logic:
```sql
INSERT INTO jobs (agent_id, job_type, payload, status, delivered_via, tool_action_id, queued_at)
VALUES (?, ?, ?, /*queued or running*/ ?, ?, ?, NOW());
-- when dispatch='realtime', insert status='running' + picked_at=NOW() + delivered_via='realtime'
-- when dispatch='poll',     insert status='queued'  + delivered_via='poll'
```
If RT delivery later fails (agent offline), the backend calls `job_requeue` (§3.4) to drop it back
to `queued` so the next poll picks it up. Then `audit($createdByUser, $agentId, 'job_create', …)`.

> **Existing `jobs` columns used:** `agent_id, job_type, payload, status, exit_code, output,
> picked_at, finished_at`. **New columns added by the migration:** `queued_at, delivered_via,
> tool_action_id` (see §4). `created_by_user` is recorded in `audit_log`, not on `jobs`.

### 3.4 `POST /api/svc/job_requeue.php`
```jsonc
// Request
{ "agent_id":42, "job_id":501 }
// Response
{ "ok":true }
```
```sql
UPDATE jobs SET status='queued', picked_at=NULL, delivered_via='poll'
 WHERE id=? AND agent_id=? AND status='running';
```
Used when an RT-claimed job could not be delivered over WS, so the polling path reclaims it.

### 3.5 `POST /api/svc/metrics_snapshot.php`
```jsonc
// Request (batched)
{ "ts":1719600000, "items":[
  { "agent_id":42, "online":true, "local_ip":"10.0.0.7", "last_user":"DESK-7\\frank",
    "cpu":13.5, "mem":42.0, "disk_c":68.3, "uptime_secs":864000 }
]}
// Response
{ "ok":true, "updated":1 }
```
Per item, in one transaction:
```sql
UPDATE agents SET last_seen_at=NOW(), local_ip=?, last_user=?, presence='online'
 WHERE id=?;

INSERT INTO agent_metrics_latest (agent_id,cpu,mem,disk_c,uptime_secs,logged_user,sampled_at)
VALUES (?,?,?,?,?,?,NOW())
ON DUPLICATE KEY UPDATE cpu=VALUES(cpu),mem=VALUES(mem),disk_c=VALUES(disk_c),
  uptime_secs=VALUES(uptime_secs),logged_user=VALUES(logged_user),sampled_at=NOW();
```
`online:false` items set `agents.presence='offline'` and do **not** bump `last_seen_at`.

### 3.6 `GET /api/svc/agent_policy.php?agent_id=42`
Returns the resolved effective policy + tool grants for an agent (backend caches; refreshes on
`policy_etag` change).
```jsonc
// Response
{ "ok":true, "policy_etag":"p-9f3a2c",
  "effective":{ "metrics_interval_s":30, "allow_remote":true,
                "allowed_tools":["restart-print-spooler"], "deny":[],
                "auto_approve_tiers":["read"], "max_blast_radius":1 } }
```
Resolution is computed in PHP (`portal/lib/policy.php::effective_policy_for_agent($agentId)`) by
merging `policy_assignments`/`policies` in order `global → client → site → group → device` (§4.2).

### 3.7 Backend routes (called by the PORTAL — reverse direction)

These live on the **Node backend**, not PHP. Same shared secret, same header scheme (§2.1), but the
portal is the caller and the backend verifies.

**`POST https://rt.8westit.com/internal/dispatch`** — "Run now" reaches an online agent instantly.
```jsonc
// Request (portal → backend)
{ "agent_id":42, "job_id":501, "job_type":"powershell", "payload":"…",
  "timeout_secs":300, "not_after":1719600650, "tool_action_id":null }
// Response
{ "ok":true, "delivered":true }   // delivered=false → agent not connected; portal leaves job queued
```
When `delivered:false`, the portal should have created the job with `dispatch:"poll"` (or call
`job_requeue`) so the poller handles it.

**`GET https://rt.8westit.com/internal/presence?agent_ids=42,43`** — live tiles for the dashboard.
```jsonc
// Response
{ "ok":true, "agents":{
  "42":{ "online":true, "last_metrics_ts":1719600060, "cpu":13.5, "mem":42.0,
         "disk_c":68.3, "uptime_secs":864000 },
  "43":{ "online":false } } }
```
The portal renders live status server-side (no browser WS client in Phase 1). When the backend is
unreachable, the portal falls back to `agent_metrics_latest` from MySQL and shows "live metrics
unavailable, last seen X".

**`GET https://rt.8westit.com/healthz`** — localhost/Caddy health probe.
```jsonc
{ "ok":true, "connections":128, "portal_reachable":true, "uptime_s":3600 }
```

---

## 4. MySQL 8 DDL (run once via phpMyAdmin)

File: `portal/db/migrations/2026-07-01_realtime_foundation.sql`.
**Plain `ALTER`/`CREATE TABLE IF NOT EXISTS` only** — MySQL 8 has no `ADD COLUMN IF NOT EXISTS`
(constraint 1). If a column already exists you'll get "Duplicate column name"; delete that line and
re-run. All additive; existing rows untouched; existing polling code reads none of the new columns.

```sql
-- Milepost Phase 1 — real-time foundation + Client→Site→Group→Device policy + governed tools.
-- Run ONCE on the live DB via phpMyAdmin. Plain ALTERs (MySQL 8 has no ADD COLUMN IF NOT EXISTS).
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ───────────────────────────────────────────────────────────────────────────
-- 1) HIERARCHY: Sites + Groups (Site was free-text on agents/enrollment_keys;
--    we promote it to a first-class row while keeping the text column for compat).
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sites (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id  INT UNSIGNED NOT NULL,
  name       VARCHAR(128) NOT NULL,           -- mirrors the existing agents.site string
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sites_client_name (client_id, name),
  KEY idx_sites_client (client_id),
  CONSTRAINT fk_sites_client FOREIGN KEY (client_id)
    REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS device_groups (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id  INT UNSIGNED NOT NULL,
  site_id    INT UNSIGNED NULL,               -- NULL = client-wide group
  parent_id  INT UNSIGNED NULL,               -- nestable groups
  name       VARCHAR(128) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_group_client (client_id),
  KEY idx_group_site (site_id),
  KEY idx_group_parent (parent_id),
  CONSTRAINT fk_group_client FOREIGN KEY (client_id)
    REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_group_site FOREIGN KEY (site_id)
    REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────────────────────
-- 2) Hook agents into the hierarchy + real-time/presence state (additive, nullable).
-- ───────────────────────────────────────────────────────────────────────────
ALTER TABLE agents
  ADD COLUMN site_id            INT UNSIGNED NULL AFTER client_id,
  ADD COLUMN group_id           INT UNSIGNED NULL AFTER site_id,
  ADD COLUMN presence           ENUM('online','stale','offline') NOT NULL DEFAULT 'offline' AFTER last_seen_at,
  ADD COLUMN rt_supported       TINYINT(1) NOT NULL DEFAULT 0 AFTER agent_version,
  ADD COLUMN rt_last_connect_at DATETIME NULL AFTER rt_supported,
  ADD COLUMN policy_etag        CHAR(12) NULL AFTER tags;
ALTER TABLE agents
  ADD KEY idx_agents_site (site_id),
  ADD KEY idx_agents_group (group_id),
  ADD KEY idx_agents_presence (presence),
  ADD CONSTRAINT fk_agents_site  FOREIGN KEY (site_id)  REFERENCES sites(id)         ON DELETE SET NULL,
  ADD CONSTRAINT fk_agents_group FOREIGN KEY (group_id) REFERENCES device_groups(id) ON DELETE SET NULL;

-- ───────────────────────────────────────────────────────────────────────────
-- 3) POLICIES + ASSIGNMENTS (generic key/value docs, inheritance by scope).
--    Inheritance: global → client → site → group → device (device wins).
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS policies (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(128) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  doc_json    JSON NOT NULL,                  -- the settings blob this policy carries
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_policy_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS policy_assignments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  policy_id   INT UNSIGNED NOT NULL,
  scope_type  ENUM('global','client','site','group','device') NOT NULL,
  scope_id    INT UNSIGNED NULL,              -- NULL only when scope_type='global'
  priority    INT NOT NULL DEFAULT 100,       -- tie-break within a scope level (higher wins)
  is_enabled  TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assign (policy_id, scope_type, scope_id),
  KEY idx_assign_scope (scope_type, scope_id),
  CONSTRAINT fk_assign_policy FOREIGN KEY (policy_id) REFERENCES policies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────────────────────
-- 4) GOVERNED TOOLS (groundwork for AI/automation with approval).
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tool_actions (
  id                VARCHAR(64)  NOT NULL,     -- slug, e.g. 'restart-print-spooler'
  display_name      VARCHAR(128) NOT NULL,
  job_type          ENUM('powershell','cmd','restart','message') NOT NULL DEFAULT 'powershell',
  payload_tmpl      MEDIUMTEXT   NOT NULL,     -- template; {{params}} substituted at invoke
  params_schema     JSON         NOT NULL,     -- JSON Schema for allowed params
  tier              ENUM('read','standard','elevated','destructive') NOT NULL DEFAULT 'standard',
  max_blast_radius  INT          NOT NULL DEFAULT 1,
  requires_approval TINYINT(1)   NOT NULL DEFAULT 1,
  is_enabled        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tool_invocations (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  action_id     VARCHAR(64)  NOT NULL,
  agent_id      INT UNSIGNED NOT NULL,
  params_json   JSON         NOT NULL,
  tier          ENUM('read','standard','elevated','destructive') NOT NULL DEFAULT 'standard',
  blast_radius  INT          NOT NULL DEFAULT 1,
  requested_by  VARCHAR(64)  NOT NULL DEFAULT '',  -- 'user:3' | 'ai:assistant' | 'system'
  status        ENUM('pending','approved','denied','dispatched','done','error','expired')
                NOT NULL DEFAULT 'pending',
  approved_by   INT UNSIGNED NULL,
  job_id        INT UNSIGNED NULL,             -- the jobs row created on approval
  reason        VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at    DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_inv_agent (agent_id),
  KEY idx_inv_status (status),
  CONSTRAINT fk_inv_action FOREIGN KEY (action_id)   REFERENCES tool_actions(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_agent  FOREIGN KEY (agent_id)    REFERENCES agents(id)       ON DELETE CASCADE,
  CONSTRAINT fk_inv_user   FOREIGN KEY (approved_by) REFERENCES users(id)        ON DELETE SET NULL,
  CONSTRAINT fk_inv_job    FOREIGN KEY (job_id)      REFERENCES jobs(id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────────────────────
-- 5) JOBS: record delivery path, queue time, and the optional tool link (additive).
--    Existing columns (agent_id, job_type, payload, status, exit_code, output,
--    picked_at, finished_at) are unchanged.
-- ───────────────────────────────────────────────────────────────────────────
ALTER TABLE jobs
  ADD COLUMN queued_at      DATETIME NULL AFTER status,
  ADD COLUMN delivered_via  ENUM('poll','realtime') NOT NULL DEFAULT 'poll' AFTER queued_at,
  ADD COLUMN tool_action_id VARCHAR(64) NULL AFTER delivered_via;
ALTER TABLE jobs
  ADD KEY idx_jobs_agent_status (agent_id, status);

-- ───────────────────────────────────────────────────────────────────────────
-- 6) LATEST METRICS (one row per agent; durable snapshot for normal page loads).
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS agent_metrics_latest (
  agent_id     INT UNSIGNED NOT NULL,
  cpu          DECIMAL(5,2) NULL,
  mem          DECIMAL(5,2) NULL,
  disk_c       DECIMAL(5,2) NULL,
  uptime_secs  BIGINT UNSIGNED NULL,
  logged_user  VARCHAR(128) NOT NULL DEFAULT '',
  sampled_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (agent_id),
  CONSTRAINT fk_metrics_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────────────────────
-- 7) SERVICE NONCES (replay protection for backend→portal calls).
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS service_nonces (
  nonce    VARCHAR(36) NOT NULL,
  seen_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (nonce),
  KEY idx_sn_seen (seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 4.1 One-time backfill (optional, run after the DDL)
```sql
-- Create a site row per (client_id, site) pair seen on existing agents, then link.
INSERT IGNORE INTO sites (client_id, name)
  SELECT DISTINCT client_id, site FROM agents WHERE site <> '' AND client_id IS NOT NULL;
UPDATE agents a JOIN sites s ON s.client_id=a.client_id AND s.name=a.site
  SET a.site_id = s.id WHERE a.site_id IS NULL AND a.site <> '';
```

### 4.2 Inheritance resolution (PHP, not SQL)
For a device, gather **enabled** `policy_assignments` whose `(scope_type, scope_id)` match, in
order: `global`, its `client_id`, its `site_id`, its `group_id`, and `device`=its own `agent_id`.
Deep-merge each policy's `doc_json` in that order (later overrides earlier; within one level,
higher `priority` wins; explicit `deny` arrays always win). The merged result's short hash becomes
`agents.policy_etag`; bump it whenever any assignment in an agent's chain changes so the backend
knows to refetch via `/api/svc/agent_policy.php`. Pure PHP array merge — HostGator-safe, no
recursive SQL. Implement in `portal/lib/policy.php`.

### 4.3 Governed tool flow (AI-safe groundwork)
A tool is never raw shell from the UI/AI. Pipeline:
1. Caller (operator UI or future `requested_by='ai:…'`) inserts a `tool_invocations` row
   (`status='pending'`), with `tier` + `blast_radius` computed from `tool_actions` × effective
   policy. `read` tier within blast limit may auto-approve (`auto_approve_tiers`); `destructive`
   is **never** auto-approvable, regardless of issuer.
2. On approval, the portal renders `payload_tmpl` with validated `params_json`, calls
   `job_create.php` to mint the `jobs` row (carrying `tool_action_id`), sets
   `tool_invocations.job_id` + `status='dispatched'`.
3. Delivery runs over WS (`command.d.tool_action_id`) or, on fallback, the normal poll path.
4. Result flows back through `cmd_result` → `job_result.php`; the portal advances the invocation to
   `done|error` and writes `audit_log` with full provenance (issuer, approver, tier, blast radius).

---

## 5. .NET agent real-time module

New files (additive, **zero new NuGet**, .NET Framework 4.8):
- `agent/EightWestAgent/RealtimeClient.cs` — owns `ClientWebSocket`, send/receive loop, ping/pong, reconnect, outbox.
- `agent/EightWestAgent/MetricsCollector.cs` — CPU/mem/disk sampling (`PerformanceCounter`, `Win32_OperatingSystem`, `DriveInfo`).
Edits:
- `Worker.cs` — start the RT client after enrollment; relax (never stop) polling while WS is up.
- `Config.cs` — add `RealtimeUrl`, `RealtimeEnabled` (registry + `config.json`, same pattern as existing keys).
- `AgentState.cs` — add a small persisted `job_id` LRU + a result outbox.
- Bump `Worker.Version` to `"1.1.0"`.

JSON uses the agent's existing `System.Web.Script.Serialization.JavaScriptSerializer` (already used
by `Config.cs`) — no new dependency.

### 5.1 Wiring into `Worker.Run` (after `Enroll()`)
```
Run:
  Config.Load(); AgentState.Load(); ApiClient(...)
  if (!IsEnrolled) Enroll()
  // NEW:
  rtUrl = ResolveRtUrl();   // Config.RealtimeUrl, else value the portal advertises (§7)
  if (Config.RealtimeEnabled && rtUrl != "")
      _rt = new RealtimeClient(rtUrl, () => _state.AuthToken, JobRunner.Execute, new MetricsCollector(), _state);
      _rt.Start();          // own background thread + CancellationToken tied to service stop
  MainLoop();               // EXISTING heartbeat/jobs/inventory polling — runs concurrently
```
The two loops share `_state.AuthToken` and `JobRunner`. `MainLoop()` is otherwise unchanged.

### 5.2 RealtimeClient state machine (single thread + `CancellationToken`)
1. **Connect.** `new ClientWebSocket()`; `Options.AddSubProtocol("milepost.v1")`;
   `Options.KeepAliveInterval = TimeSpan.Zero` (app-level ping instead); TLS is already enabled by
   the agent. `ConnectAsync(wss://…/agent)`. Do **not** put the token in the URL.
2. **Hello.** Send `hello` with the raw token. Await `welcome` (10 s). On `welcome`: store
   `agent_id`/`session_id`, set metrics cadence, reset backoff. On close 4401: see fallback below.
3. **Run.** One `ReceiveAsync` loop; a timer drives `metrics` (on cadence) and `pong` (on `ping`).
   On `command`: dedupe by `job_id` (LRU); if new, send `cmd_ack{accepted:true}`, run
   `JobRunner.Execute(job_type, payload)` on a worker `Task` (concurrency gate = 1 for SYSTEM
   shells), then send `cmd_result` and hold it in the on-disk outbox until `cmd_result_ack`.
   All socket I/O is serialized through a `SemaphoreSlim` (ClientWebSocket allows one send + one
   receive at a time).
4. **Detect death.** No frame for 45 s, any `WebSocketException`, or a close frame → Reconnect.

### 5.3 Reconnect / backoff (exact)
- Full-jitter exponential: `delay = rand(0, min(cap, base * 2^attempt))`, **base = 5 s, cap = 60 s**.
- After **10** consecutive failures, widen `cap` to **5 min** (avoid hammering the VPS in an outage).
- Reset `attempt = 0` after a connection survives ≥ 60 s.
- Triggers: socket close, 2 missed pings (no traffic for 45 s), send failure.
- **Close 4401 (token bad):** stop WS attempts for this token; set a flag. The existing
  `MainLoop` 401 handler already re-enrolls and resets `_state.AuthToken`; on a fresh token,
  restart `RealtimeClient`. Never crash, never hot-loop.

### 5.4 Fallback to polling (the safety guarantee — constraint 3)
- **Polling never stops.** `MainLoop` keeps hitting `/api/heartbeat.php` and `/api/jobs.php`
  regardless of WS state.
- **WS up:** relax poll pressure but don't stop — heartbeat at the server's `heartbeat_secs`
  (≥ 60 s); `DrainJobs` still runs each cycle. A job claimed for RT is `status='running'`, so the
  poller's `status='queued'` GET won't grab it. A job that **failed to deliver** over WS is dropped
  back to `queued` by the backend (`job_requeue`) and the poller claims it next cycle — automatic
  fallback, no special agent logic.
- **WS down:** behavior is exactly today's product — 60 s polling, full command/inventory, no live
  metrics (dashboard shows "live metrics unavailable, last seen X").
- **Result fallback:** if no `cmd_result_ack` within `RtResultFallbackSecs` (default 30 s) or the
  socket is down, the agent writes the result via the existing `POST /api/jobs.php`. Idempotent by
  `job_id` (the second write hits a non-`running` row and no-ops).

### 5.5 Metrics sampling cost
CPU via a cached `PerformanceCounter("Processor","% Processor Time","_Total")`; mem via
`Win32_OperatingSystem` free/total; disk via `DriveInfo` on the system drive. All sub-millisecond;
sampled only on the backend-dictated cadence; coalesced under send pressure (keep newest only).

---

## 6. Telemetry without overloading shared MySQL (resolved policy)

The load-bearing guarantee: **WS-connected agents write to MySQL fewer times than 60 s polling.**

- Live metrics live in backend memory (+ optional SQLite cache); **never** written per-push.
- Only a throttled snapshot reaches MySQL: `metrics_snapshot.php` at most once/agent/60 s,
  **batched across all agents** in one request → a handful of upserts, not thousands of inserts.
- Presence updates ride the same batched snapshot (one write covering many agents) and become the
  WS path's `last_seen_at` writer, replacing per-agent 60 s heartbeat writes.
- The backend caps total portal write rate with a token bucket (`PORTAL_MAX_WRITE_RPS`, default 5);
  command results are prioritized (durable) over presence/metrics (lossy/refreshable).
- No metrics history table on HostGator. Short-term trend history, if ever wanted, lives in the
  backend's optional SQLite; long-term history is a Phase 2 decision (external store), never shared
  MySQL.

---

## 7. Config keys (all three components)

### 7.1 Portal — add to the `return [...]` array in `portal/config/config.php`
```php
// --- Milepost real-time backend integration (Phase 1) ---
'service_secret'        => 'CHANGE_ME_64_HEX',     // == backend MILEPOST_SERVICE_SECRET
'service_replay_window' => 300,                     // seconds; ±skew allowed on service calls
'realtime' => [
    'enabled'      => true,
    'backend_url'  => 'https://rt.8westit.com',     // portal → backend /internal/* base
    'agent_ws_url' => 'wss://rt.8westit.com/agent',  // advertised to agents via enroll/heartbeat
    'dispatch_timeout_ms' => 4000,                   // portal HTTP timeout to the backend
],
```
`enroll.php` and `heartbeat.php` append `"realtime_url"` to their JSON responses (from
`realtime.agent_ws_url` when `realtime.enabled`), so agents learn the WS endpoint **without an MSI
change**. Old agents ignore the unknown key.

> Add the same keys (with placeholders) to `portal/config/config.sample.php`.

### 7.2 Backend — `realtime/backend/.env` (sample shipped as `.env.sample`)
```ini
PORT=8090                                   # ws + /internal HTTP, behind Caddy
BIND=127.0.0.1                              # localhost only
WS_PATH=/agent
PORTAL_BASE_URL=https://support.8westit.com  # portal origin; svc endpoints under /api/svc/
MILEPOST_SERVICE_SECRET=CHANGE_ME_64_HEX    # MUST equal portal service_secret
SERVICE_REPLAY_WINDOW_SECS=300
PING_INTERVAL_SECS=30
HELLO_TIMEOUT_SECS=10
DEFAULT_METRICS_INTERVAL_SECS=60
WATCHING_METRICS_INTERVAL_SECS=15
VERIFY_CACHE_TTL_SECS=300
METRICS_SNAPSHOT_THROTTLE_SECS=60           # max 1 portal write per agent per minute
PORTAL_MAX_WRITE_RPS=5                       # token-bucket cap on portal writes
WS_BUFFER_HIGH_WATER=1048576                 # backpressure threshold (bytes)
MAX_WS_PAYLOAD_BYTES=262144                  # 256 KB frame cap
RESULT_FALLBACK_GRACE_SECS=30
SQLITE_PATH=./data/milepost-rt.sqlite        # blank = pure in-memory
LOG_LEVEL=info                               # never log tokens/secrets
```

### 7.3 Agent — `HKLM\SOFTWARE\8WestIT\Agent` (registry) and/or `config.json`
Read by `Config.Load()` exactly like the existing keys (64-bit registry view, then `config.json`).
```
PortalUrl        (existing)
EnrollKey        (existing)
RustDeskUrl      (existing)
RealtimeUrl      (NEW, optional) — overrides the wss URL the portal advertises; blank = use portal's
RealtimeEnabled  (NEW, optional, default "1") — "0" forces pure polling (kill switch)
```
Both new keys are **optional**: with neither set, the agent uses whatever the portal returns and
real-time is on. **No MSI rebuild is required** to enable real-time — only a portal config change.
The registry keys exist purely as override/kill-switch. (Internal tunables —
`RtResultFallbackSecs=30`, backoff base/cap — are constants in `RealtimeClient.cs`, not registry
keys, to keep the surface small.)

---

## 8. File / directory layout to create

```
web_app/
├─ realtime/
│  ├─ PHASE1-SPEC.md                      # this document
│  ├─ backend/
│  │  ├─ package.json                     # deps: ws (+ better-sqlite3 if SQLITE_PATH set); dev: none
│  │  ├─ Dockerfile                       # node:20-alpine, npm ci --omit=dev, CMD node src/server.js
│  │  ├─ .env.sample
│  │  ├─ README.md                        # secret gen, DNS, compose, TLS-at-Caddy note
│  │  ├─ src/
│  │  │  ├─ server.js                     # ws server + /internal + /healthz HTTP
│  │  │  ├─ protocol.js                   # envelope encode/decode, type guards
│  │  │  ├─ wsAuth.js                     # hello → verify_agent, verify cache
│  │  │  ├─ portalClient.js               # signed svc calls (HMAC+nonce+ts), token bucket
│  │  │  ├─ liveStore.js                  # in-mem presence/metrics, optional SQLite cache
│  │  │  └─ dispatch.js                   # per-agent outbox, command delivery, requeue
│  │  ├─ data/                            # SQLite cache (gitignored)
│  │  └─ test/                            # node --test
│  │     ├─ protocol.test.js              # envelope framing, unknown-type ignore
│  │     ├─ idempotency.test.js           # duplicate job_id result dedupe
│  │     ├─ reconnect.test.js             # outbox redeliver + requeue-on-undeliverable
│  │     ├─ backpressure.test.js          # metrics coalescing under buffer pressure
│  │     └─ svcsign.test.js               # HMAC sign/verify round-trip + replay reject
│  └─ deploy/
│     ├─ Caddyfile
│     └─ docker-compose.snippet.yml       # milepost-rt + caddy services (added beside hbbs/hbbr)
├─ portal/
│  ├─ db/migrations/2026-07-01_realtime_foundation.sql
│  ├─ lib/
│  │  ├─ svc_auth.php                      # require_service()
│  │  └─ policy.php                        # effective_policy_for_agent()
│  └─ public/api/svc/
│     ├─ verify_agent.php
│     ├─ job_result.php
│     ├─ job_create.php
│     ├─ job_requeue.php
│     ├─ metrics_snapshot.php
│     └─ agent_policy.php
└─ agent/EightWestAgent/
   ├─ RealtimeClient.cs                    # NEW
   ├─ MetricsCollector.cs                  # NEW
   ├─ Worker.cs                            # EDIT: start RT, relax polling, Version="1.1.0"
   ├─ Config.cs                            # EDIT: RealtimeUrl, RealtimeEnabled
   └─ AgentState.cs                        # EDIT: job_id LRU + result outbox
```
Also edit: `portal/public/api/enroll.php` + `heartbeat.php` (append `realtime_url`),
`portal/config/config.sample.php` (add `service_secret` + `realtime`).

---

## 9. VPS deployment with TLS

Backend listens **`ws://127.0.0.1:8090`** only (constraint 6). TLS terminates at **Caddy** (auto
Let's Encrypt) on the same VPS, beside the existing RustDesk containers.

**DNS:** `rt.8westit.com` A-record → VPS IP (same box as `relay.8westit.com`).

**`realtime/deploy/Caddyfile`:**
```
rt.8westit.com {
    encode zstd gzip
    reverse_proxy 127.0.0.1:8090       # Caddy forwards Upgrade/Connection headers automatically
    # /agent (WS), /internal/* and /healthz all proxy to the same backend; the shared
    # secret guards /internal/*. Optionally restrict /internal to HostGator egress IPs.
}
```
nginx equivalent: `proxy_set_header Upgrade $http_upgrade; proxy_set_header Connection "upgrade";
proxy_read_timeout 75s; proxy_pass http://127.0.0.1:8090;` — keep proxy idle/read timeout (75 s)
**above** the 30 s ping interval so pings keep the socket alive.

**`realtime/deploy/docker-compose.snippet.yml`** (merge into the relay compose, beside hbbs/hbbr):
```yaml
services:
  milepost-rt:
    build: ../backend
    restart: unless-stopped
    env_file: ../backend/.env
    ports: ["127.0.0.1:8090:8090"]      # localhost only; Caddy reaches it
    volumes: ["../backend/data:/app/data"]
    read_only: true
    tmpfs: ["/tmp"]
  caddy:
    image: caddy:2
    restart: unless-stopped
    ports: ["80:80","443:443"]
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy_data:/data
      - caddy_config:/config
volumes: { caddy_data: {}, caddy_config: {} }
```

**Hardening / firewall:** container runs non-root, read-only FS except `./data`; outbound to portal
over HTTPS only; ufw allows **80/443** (Caddy) + existing RustDesk ports; **8090 stays bound to
localhost.** No DB port is opened anywhere — the backend has no DB client (constraint 2).

**README must document:** `openssl rand -hex 32` for the secret (must match portal `service_secret`),
DNS record, `docker compose up -d`, the TLS-terminates-at-Caddy note, and `node --test` to run the suite.

---

## 10. Migration path (polling keeps working at every step)

Strictly additive, reversible, independently shippable.

1. **DB migration** (phpMyAdmin): run `2026-07-01_realtime_foundation.sql` + optional backfill
   (§4.1). All columns nullable/defaulted; polling reads none of them. **No behavior change.**
2. **Portal `/api/svc/*` + `svc_auth.php` + `policy.php`.** Add a real `service_secret` to
   `config.php`. Deploy. Agent-facing endpoints unchanged; new endpoints inert until the backend
   exists. **Polling unaffected.**
3. **Deploy the VPS backend** (Caddy + Node) with the matching secret. Verify
   `verify_agent.php`, `job_result.php`, `/internal/dispatch`, `/healthz` via signed curl. No agent
   touches it yet (portal hasn't advertised the WS URL).
4. **Flip `realtime.enabled = true`** so `enroll`/`heartbeat` return `realtime_url`. Only
   real-time-capable agents act on it; fielded agents ignore the extra field and keep polling.
5. **Canary agent.** Set `RealtimeUrl` on one machine (or ship `1.1.0` to one). It connects WS,
   relaxes polling, runs jobs in real time, streams metrics, sets `agents.rt_supported=1`. Pull the
   backend → it falls back to full polling within 45 s. Validate idempotency by forcing a duplicate
   result.
6. **Ship `1.1.0`** through the normal MSI/auto-update channel. New/updated agents open the WS while
   still polling; never-updated agents keep working exactly as before.
7. **Portal UI** lights up live presence/metrics via server-side `/internal/presence`, with graceful
   "metrics unavailable" when the backend or an agent is offline.

**Rollback at any step:** set `realtime.enabled=false` (agents drop WS within one heartbeat) or stop
the `milepost-rt` container (agents detect a dead socket in 45 s, back off, keep polling). Nothing
in the truth DB depends on the backend; the new tables/columns are simply unused.

---

## 11. Correctness summary (the load-bearing guarantees)

- **No double execution:** RT jobs are minted `running`; the poller only claims `queued`; the agent
  dedupes by `job_id` (on-disk LRU).
- **No lost results:** ack-gated agent outbox → REST fallback after 30 s; idempotent by `job_id`.
- **No lost commands:** per-agent backend outbox; undeliverable RT jobs are requeued to `poll`.
- **No DB overload:** live data stays in backend memory; only batched, rate-capped, throttled
  snapshots reach HostGator — fewer writes than today's polling.
- **No hard dependency on real-time:** every RT feature has a polling fallback; the PHP portal +
  MySQL remain the single source of truth at all times.
```

# Milepost Real-Time Protocol (`milepost.v1`)

The exact wire protocol for the Phase 1 real-time channel between the **.NET agent** and the
**Node real-time backend** on the VPS, plus the **backend ⇄ portal** service auth.

This document is a precise restatement of the wire contract in
[`PHASE1-SPEC.md`](PHASE1-SPEC.md) §1–§3. If anything here disagrees with the SPEC, the SPEC
wins — but they are intended to be identical. Use the **verbatim** message types, header
names, endpoint paths, and close codes below; components are built independently and must
interoperate on the first try.

> **Two brand namespaces are intentional.** The agent registry/config namespace stays
> `8WestIT\Agent` (existing); new service identifiers use **Milepost** (`X-Milepost-Service`,
> `service_secret`, `MILEPOST_SERVICE_SECRET`). Do not "fix" one to match the other.

---

## 1. Where this channel sits

There are three trust zones. Real-time is **purely additive** — the existing HTTPS polling
channel is never removed, and every real-time feature degrades to polling.

```
  ┌──────────────┐  (1) HTTPS poll (UNCHANGED)   ┌───────────────────────────┐
  │  .NET Agent  │──────────────────────────────►│  PHP Portal (HostGator)    │
  │  svc 4.8     │   enroll/heartbeat/jobs/inv    │  MySQL 8 = SOURCE OF TRUTH │
  │ v1.0.3→1.1.0 │                                │  /api/*.php   (agent)      │
  │              │  (2) WSS (NEW, real-time)      │  /api/svc/*.php (service)  │
  │              │═══════════════╗                └───────────────────────────┘
  └──────────────┘               ▼                          ▲
                          ┌───────────────────────────┐     ║ (3) HTTPS + shared service
                          │  Milepost RT Backend (VPS) │═════╝     secret (X-Milepost-*)
                          │  Node + ws (ws://127…:8090)│
                          │  in-mem presence + metrics │
                          └───────────────────────────┘
```

1. **Agent ⇄ Portal (HTTPS poll)** — the existing, always-on floor. Never removed.
2. **Agent ⇄ Backend (WSS)** — the new real-time channel (this document, §2–§5).
3. **Backend ⇄ Portal (HTTPS + shared secret)** — the only path by which live data reaches
   MySQL (§6). The backend has no MySQL driver and no DB credentials.

**Source-of-truth principle.** Backend state (presence, latest metrics, in-flight commands)
is ephemeral and rebuildable. If the backend is wiped, polling still works and the portal is
intact. The portal independently re-authorizes every state-changing service call.

---

## 2. Transport

| Property | Value |
|---|---|
| Protocol | WebSocket (RFC 6455), **one persistent connection per agent** |
| Agent lib | `System.Net.WebSockets.ClientWebSocket` (built into .NET 4.8, no NuGet) |
| Backend lib | Node.js + `ws` |
| Frames | UTF-8 **text** only — exactly **one JSON object per frame**. Binary → close `1003`. |
| Subprotocol | `Sec-WebSocket-Protocol: milepost.v1` — version pinned at the handshake; the server echoes it |
| Public URL | `wss://rt.8westit.com/agent` (Caddy/reverse proxy → `ws://127.0.0.1:8090/agent`) |
| Token in URL? | **No.** The bearer token travels in the first `hello` frame, never in the URL (no proxy-log leakage). |
| Frame size cap | **256 KB** (`maxPayload`). Oversize → close `1009`. |

The subprotocol token `milepost.v1` is the version. There is **no** `v` field in the
envelope. A future `milepost.v2` would be negotiated at the handshake.

---

## 3. Envelope

**Every** message, in both directions, is a single JSON object with this shape:

```json
{ "t": "<type>", "id": "<uuid-or-null>", "ts": 1719600000, "ref": "<id-or-null>", "d": {} }
```

| Field | Type | Meaning |
|---|---|---|
| `t`   | string (required) | Message type. |
| `id`  | uuidv4 \| null | Message id. Present when a reply/ack is expected; `null` for fire-and-forget (e.g. `metrics`). |
| `ts`  | integer | Unix **seconds**, sender clock. **Advisory only — never used for authorization.** |
| `ref` | uuid \| null | The `id` this message responds to. `null`/omitted otherwise. |
| `d`   | object | Type-specific payload. May be `{}`. |

**Forward-compatibility rules (mandatory):**

- Unknown `t` values **MUST be ignored** (optionally reply `error{code:"bad_message"}`; never crash).
- Unknown fields inside `d` **MUST be ignored**.
- IDs are **uuidv4** (the agent already generates GUIDs; the backend uses `crypto.randomUUID()`). No ULID, no extra dependency.

---

## 4. Message catalog

### 4.1 Agent → Backend

#### `hello` — authenticate the connection (MUST be the first frame)

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

The agent MUST send `hello` within **10 s** of the socket opening, and **no other message
type is accepted before `welcome`**. Miss the deadline → backend closes **4408**.

#### `metrics` — live telemetry, fire-and-forget (`id:null`, lossy by design)

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
**15 s** while a tech is watching the device, **60 s** otherwise. Under send pressure the
agent **coalesces** — it keeps only the newest queued `metrics` frame.

#### `cmd_ack` — agent received a `command` and decided to run (or refuse) it

```json
{ "t":"cmd_ack","id":"3a0b…","ts":1719600061,"ref":"<command id>","d":{
  "job_id":501,
  "accepted":true,
  "reason":""
}}
```

`accepted:false` with a `reason` (`"unsupported job_type"`, `"already_ran"`, `"not_after"` …)
means the agent will not execute. The job stays `queued`; the poller picks it up if appropriate.

#### `cmd_result` — terminal result of a command

```json
{ "t":"cmd_result","id":"7c4d…","ts":1719600070,"ref":"<command id>","d":{
  "job_id":501,
  "status":"done",
  "exit_code":0,
  "output":"…stdout+stderr, capped 256 KB on the wire…",
  "truncated":false
}}
```

- `status ∈ {"done","error"}` — mirrors `JobRunner.JobResult.Success`.
- On-wire `output` is capped at **256 KB** (one frame). The portal stores up to **1,000,000
  bytes** (existing cap). If the agent had more output than fit on the wire, it sets
  `truncated:true`.

#### `event` — unsolicited state change (`id:null`)

```json
{ "t":"event","id":null,"ts":1719600080,"d":{ "kind":"rustdesk_ready","rustdesk_id":"123456789" }}
```

Defined `kind` values: `rustdesk_ready`, `user_login`, `user_logout`, `reboot_pending`.

#### `pong` — reply to backend `ping`

```json
{ "t":"pong","id":null,"ts":1719600090,"ref":"<ping id>","d":{} }
```

#### `bye` — graceful shutdown (service stopping)

```json
{ "t":"bye","id":null,"ts":1719600099,"d":{ "reason":"service_stop" } }
```

---

### 4.2 Backend → Agent

#### `welcome` — auth succeeded

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

#### `command` — push a job to run now (mirrors a `jobs` row already minted in MySQL)

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
- `not_after` (Unix sec): the agent refuses (with `cmd_ack accepted:false`) if past it.
- `tool_action_id`: optional governance link; `null` for raw jobs.

#### `set_metrics_interval` — change metrics cadence (`id:null`)

```json
{ "t":"set_metrics_interval","id":null,"ts":1719600100,"d":{ "seconds":15 } }
```

#### `ping` — liveness + idle-proxy keepalive (every 30 s)

```json
{ "t":"ping","id":"e3f0…","ts":1719600030,"d":{} }
```

#### `cmd_result_ack` — backend confirms the result was durably persisted to the portal (`id:null`)

```json
{ "t":"cmd_result_ack","id":null,"ts":1719600071,"ref":"<cmd_result id>","d":{
  "job_id":501,
  "persisted":true
}}
```

Once received, the agent may drop the result from its outbox.

#### `error` — non-fatal protocol problem (does not necessarily close)

```json
{ "t":"error","id":null,"ts":1719600105,"ref":"<offending id or null>","d":{
  "code":"bad_message",
  "msg":"unknown type"
}}
```

Codes: `bad_message`, `rate_limit`, `backpressure`, `not_authenticated`.

---

## 5. Authentication handshake

The agent authenticates the WebSocket using its **existing bearer token** — no new secret,
no re-enrollment. The backend cannot verify the token itself (it has no DB), so it asks the
portal and caches the answer.

```
Agent                          Backend                         Portal (HostGator)
  │                               │                                   │
  │── WSS connect ───────────────►│  (subprotocol: milepost.v1)       │
  │   (no token in URL)           │                                   │
  │                               │                                   │
  │── hello { token, agent_uid,…}►│                                   │
  │                               │  cache hit on sha256(token)?      │
  │                               │   ├─ yes → use cached identity     │
  │                               │   └─ no  → POST /api/svc/verify_agent.php
  │                               │──────── { "token":"<raw>" } ──────►│  (service-signed, §6)
  │                               │                                   │  sha256(token) →
  │                               │                                   │  agents WHERE
  │                               │                                   │  auth_token_hash=?
  │                               │                                   │  AND is_archived=0
  │                               │◄──── { ok:true, agent_id, … } ────│
  │                               │  cache sha256(token)→identity      │
  │                               │  (VERIFY_CACHE_TTL_SECS = 300)     │
  │◄── welcome { agent_id, … } ───│  presence=ONLINE; flush presence   │
  │                               │                                   │
  │                          (auth FAILED path)                       │
  │◄── error{not_authenticated} ──│  ok:false / unknown token          │
  │◄── close 4401 ────────────────│                                   │
```

Key points:

- The cache key is the **sha256 hash**, never the raw token. The raw token is used
  transiently and **never logged or persisted**.
- On `ok:false` (unknown/archived/revoked token): backend sends `error{code:"not_authenticated"}`
  then closes **4401**. The agent stops WS for that token but **keeps polling**; it restarts
  the WS only after the token rotates (its existing 401 re-enrollment path).
- `verify_agent.php` mirrors `authenticate_agent()` exactly: `hash('sha256',$token)` →
  `agents.auth_token_hash WHERE is_archived=0`.

---

## 6. Backend ⇄ Portal service auth

Every backend→portal request carries a **static shared-secret header AND an HMAC signature**
(defense in depth — a stolen static header alone is not enough, and HostGator sometimes strips
`Authorization`, so the HMAC is the real check).

```
X-Milepost-Service:   <MILEPOST_SERVICE_SECRET>   # static 64-hex secret, constant-time compared
X-Milepost-Timestamp: <unix seconds>              # replay window ±300 s
X-Milepost-Nonce:     <uuidv4>                     # single-use within the window
X-Milepost-Sign:      <hex HMAC-SHA256( secret,
                        METHOD + "\n" + PATH + "\n" + TS + "\n" + NONCE + "\n" + sha256(body) )>
```

- `PATH` is the request path beginning with `/api/svc/…` (no query string, no scheme/host).
- `body` is the raw request bytes (empty string when there is no body).
- The secret is generated with `openssl rand -hex 32` and stored **byte-identically** in the
  portal `config.php` (`service_secret`) and the backend `.env` (`MILEPOST_SERVICE_SECRET`).
- Timestamps outside ±300 s → `401`. Reused nonces within the window → `401`
  (tracked in `service_nonces`, pruned on a slow cadence).
- All `/api/svc/*` endpoints validate this via `require_service()` in `portal/lib/svc_auth.php`.
  They are reachable by neither agents nor browsers.

### 6.1 Service endpoints (portal side, called by the backend)

| Endpoint | Purpose |
|---|---|
| `POST /api/svc/verify_agent.php` | Resolve a raw bearer token → `{agent_id, client_id, site_id, …, policy_etag}`. `200` even on unknown token (`ok:false`). |
| `POST /api/svc/job_result.php` | Persist a terminal result, idempotent by `job_id`. `delivered_via='realtime'`. Already-terminal → `{ok:true, already:true}`. |
| `POST /api/svc/job_create.php` | Mint a `jobs` row; `dispatch:"realtime"` returns it already `running` (poller can't grab it); `dispatch:"poll"` returns `queued`. |
| `POST /api/svc/job_requeue.php` | Drop an RT-claimed `running` job back to `queued`/`poll` when WS delivery failed. |
| `POST /api/svc/metrics_snapshot.php` | Batched presence + latest-metrics upsert (throttled to ≤ 1/agent/60 s). |
| `GET  /api/svc/agent_policy.php?agent_id=42` | Resolved effective policy + tool grants; backend refetches on `policy_etag` change. |

### 6.2 Backend routes (backend side, called by the portal)

Same shared secret + header scheme, reverse direction.

| Route | Purpose |
|---|---|
| `POST https://rt.8westit.com/internal/dispatch` | "Run now" → instantly reach an online agent. `delivered:false` → agent offline; portal leaves the job for the poller. |
| `GET  https://rt.8westit.com/internal/presence?agent_ids=42,43` | Live tiles for the dashboard (server-side render; no browser WS in Phase 1). |
| `GET  https://rt.8westit.com/healthz` | Localhost/Caddy health probe. |

### 6.3 The only writes that reach MySQL

- **Command results** → `job_result.php` immediately on `cmd_result`; on success the backend
  sends `cmd_result_ack`. On portal failure the backend retries with backoff and does **not**
  ack (the agent's REST fallback is the backstop).
- **Presence + latest metrics** → coalesced into `metrics_snapshot.php`, at most **once per
  agent per 60 s**, batched across all agents. This becomes the WS path's `last_seen_at`
  writer. **WS-connected agents therefore write to MySQL *less* than 60 s polling, not more.**
- **Live per-push metrics are never written** — they live in backend memory (+ optional
  SQLite cache). No metrics history table on shared hosting.

---

## 7. Close codes (backend → agent)

| Code | Meaning | Agent action |
|---|---|---|
| `1000` | normal close / `bye` acknowledged | none |
| `1002` | subprotocol/protocol violation | reconnect with backoff |
| `1003` | binary frame / unsupported data | reconnect with backoff |
| `1009` | frame too large | reconnect with backoff |
| `1011` | backend internal error / portal unreachable | reconnect with backoff; **polling carries load** |
| `1012` / `1001` | backend restarting / going away | reconnect with backoff |
| **`4401`** | auth failed / token revoked / archived | **stop WS for this token**; keep polling; restart WS after token rotates |
| **`4408`** | no `hello` within 10 s | reconnect with backoff |
| **`4409`** | duplicate connection (same agent reconnected) | the *older* socket closes silently |

---

## 8. Liveness & correctness mechanics

- **Keepalive.** Backend pings every **30 s**; agent must `pong`. Backend misses **2** pongs →
  close `1011`, mark agent `stale` then `offline` after grace. Agent treats **45 s of silence**
  (no ping/command/anything) as a dead socket → close + reconnect.
- **WS never replaces the portal heartbeat.** The agent keeps POSTing `/api/heartbeat.php` so
  `agents.last_seen_at` (the truth) stays fresh even if the backend is down.
- **Idempotent commands.** `job_id` is the idempotency key end to end. The agent keeps a small
  on-disk LRU (in `AgentState`) of `job_id`s seen in the last ~10 min. If the same `job_id`
  arrives via both WS `command` and a poll claim, it runs **once**. If a redelivered command's
  result was already produced, the agent re-sends `cmd_result` (cheap) rather than re-executing.
- **No double execution.** A `command` is only ever sent for a row the portal already marked
  `running` (minted `queued`, then flipped to `running` via `job_create.php dispatch:"realtime"`).
  The polling GET only claims `status='queued'`, so it cannot grab an RT-claimed job.
- **No lost results (two-phase ownership).**
  - Backend keeps a `command` in its per-agent outbox until `cmd_result` arrives.
  - Agent keeps a `cmd_result` in its outbox until `cmd_result_ack` arrives **or** a fallback
    timeout (`RtResultFallbackSecs`, default **30 s**) elapses — then it writes the result via
    the existing `POST /api/jobs.php` path. Idempotency by `job_id` makes the eventual
    double-write safe (the second write hits a non-`running` row and no-ops).
- **Backpressure.** Backend watches `ws.bufferedAmount`; above **1 MB** it stops sending
  non-critical frames and emits `error{code:"backpressure"}`; if saturated past a timeout it
  closes `1011`. Metrics are droppable; command/result frames are never silently dropped.

---

## 9. Sequence diagrams

### 9.1 Connect + authenticate

```
Agent                                   Backend                         Portal
  │                                        │                              │
  │── WSS connect (milepost.v1) ──────────►│                              │
  │── hello {token, agent_uid, ver, caps}─►│                              │
  │                                        │─ POST verify_agent.php ──────►│
  │                                        │◄── {ok:true, agent_id:42,…} ─│
  │◄── welcome {agent_id:42, metrics_      │  presence=ONLINE             │
  │     interval:60, ping_interval:30} ────│  (flush via metrics_snapshot)│
  │                                        │                              │
  │── metrics {cpu,mem,disk_c,…} (60s) ───►│  store latest in memory      │
  │◄── ping {id:e3f0} (every 30s) ─────────│                              │
  │── pong {ref:e3f0} ────────────────────►│                              │
```

### 9.2 Command round-trip (happy path)

```
Operator UI         Portal              Backend             Agent
    │                  │                   │                  │
    │─ "Run now" ─────►│                   │                  │
    │                  │─ job_create.php   │                  │
    │                  │   dispatch=realtime│                 │
    │                  │   → job_id=501,    │                 │
    │                  │     status=running │                 │
    │                  │─ POST /internal/dispatch ───────────►│
    │                  │   {job_id:501,job_type,payload,…}    │
    │                  │                   │─ command ───────►│  dedupe job_id (LRU)
    │                  │                   │◄─ cmd_ack ───────│  {accepted:true}
    │                  │                   │   (held in outbox)│  run JobRunner.Execute()
    │                  │                   │                  │  (gate = 1 SYSTEM shell)
    │                  │                   │◄─ cmd_result ────│  {status:done,exit:0,output}
    │                  │◄ job_result.php ──│  persist (idempotent by job_id)
    │                  │  {ok:true,already:false}             │
    │                  │                   │─ cmd_result_ack ►│  agent drops from outbox
    │◄ result shown ───│                   │                  │
```

### 9.3 Reconnect (socket death → backoff → re-auth)

```
Agent                                   Backend
  │── metrics ────────────────────────────►│
  │   …no ping/frame for 45 s…             │   (backend crashed / proxy dropped / network)
  │── (detect death) ──────────────────────│
  │   close local socket                    │
  │                                         │
  │   backoff: delay = rand(0, min(cap, base·2^attempt))
  │   base=5s  cap=60s ; after 10 fails cap→5min
  │   …wait…                                │   (meanwhile: MainLoop still polling /heartbeat
  │                                         │    + /jobs — product behaves exactly like today)
  │── WSS connect (milepost.v1) ───────────►│   backend back up
  │── hello {token,…} ─────────────────────►│   (verify cache may still be warm → no portal call)
  │◄── welcome {agent_id,…} ────────────────│   reset attempt=0 after socket survives ≥60s
  │── (resend any unacked cmd_result from outbox) ─►│
```

Duplicate-connection variant: if the agent reconnects before the backend noticed the old
socket died, the backend closes the **older** socket with **4409**; the new socket proceeds.

### 9.4 Fallback to polling (the safety guarantee — constraint 3)

Polling never stops. Three independent fallbacks keep the product fully working with the
backend down or a result unacked.

```
A) WS DOWN  (backend unreachable)
   Agent ── /api/heartbeat.php (≤60s) ──► Portal     ◄─ last_seen_at stays fresh (the truth)
   Agent ── GET /api/jobs.php (claims queued) ──► Portal ── runs job ── POST /api/jobs.php
   Dashboard: "live metrics unavailable, last seen X"   (presence from agent_metrics_latest)

B) RT COMMAND UNDELIVERABLE  (agent offline when "Run now" fired)
   Portal ─ /internal/dispatch ─► Backend ─► {delivered:false}
   Backend ─ job_requeue.php ─► Portal   (status running → queued, delivered_via → poll)
   …next poll cycle…  Agent ─ GET /api/jobs.php ─► claims it ─► runs ─► POST /api/jobs.php

C) RESULT UNACKED  (cmd_result sent, no cmd_result_ack within 30 s, or socket died)
   Agent (RtResultFallbackSecs elapsed) ─ POST /api/jobs.php {job_id,status,exit,output} ─► Portal
   Idempotent by job_id: if job_result.php already persisted it, this write hits a
   non-'running' row and no-ops.  No lost result, no double-apply.
```

When WS is **up**, the agent **relaxes** (never stops) polling: heartbeat at the server's
`heartbeat_secs` (≥ 60 s), and `DrainJobs` still runs each cycle. Because RT jobs are minted
`running`, the poller's `status='queued'` GET will not grab them — so the two channels never
double-execute.

---

## 10. Correctness summary

- **No double execution** — RT jobs are minted `running`; the poller only claims `queued`; the
  agent dedupes by `job_id` (on-disk LRU).
- **No lost results** — ack-gated agent outbox → REST fallback after 30 s; idempotent by `job_id`.
- **No lost commands** — per-agent backend outbox; undeliverable RT jobs are requeued to `poll`.
- **No DB overload** — live data stays in backend memory; only batched, rate-capped, throttled
  snapshots reach HostGator — fewer writes than today's polling.
- **No hard dependency on real-time** — every RT feature has a polling fallback; the PHP portal
  + MySQL remain the single source of truth at all times.

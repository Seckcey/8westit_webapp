# Milepost Real-Time Backend

WebSocket server for the Milepost RMM, running on the VPS beside the RustDesk
relay. It gives agents a real-time channel (instant command push, live metrics,
presence) **as an enhancement to** the existing 60-second HTTPS polling — which
never stops. When this backend is down, agents fall back to polling within 45 s
and nothing breaks.

This is component **1 of 4** from `realtime/PHASE1-SPEC.md` (the authoritative
contract). The other three (the .NET agent module, the MySQL migration, and the
portal `/api/svc/*` endpoints) interoperate with this service over the wire
protocol and shared-secret REST contract defined there.

## Architecture in one breath

```
Agent ──WSS──► Caddy (TLS 443) ──ws://127.0.0.1:8090──► this backend
                                                            │
                                          signed HTTPS (X-Milepost-Service + HMAC)
                                                            ▼
                                              PHP Portal  /api/svc/*  (MySQL = source of truth)
```

- **No database.** This service has **no MySQL driver and no DB credentials**
  (HostGator MySQL is unreachable from the VPS). It authenticates agents and
  persists results by calling the portal's REST API with a shared service
  secret. Live presence/metrics live in memory (optional SQLite cache).
- **TLS terminates at Caddy.** The Node process listens `ws://127.0.0.1:8090`
  only. Port 8090 is never exposed publicly.

## Requirements

- Node.js **>= 20** (uses `node --test`, `crypto.randomUUID`).
- One runtime dependency: [`ws`](https://www.npmjs.com/package/ws).
- Optional: `better-sqlite3` (native) — only used when `SQLITE_PATH` is set, for
  a metrics + result-outbox cache that survives a restart. Absent = pure
  in-memory; everything still works.

## Quick start (local)

```bash
cd realtime/backend
cp .env.sample .env
# generate the shared secret and paste it into BOTH .env (MILEPOST_SERVICE_SECRET)
# and the portal config.php ('service_secret'):
openssl rand -hex 32
npm install
npm start
```

Health check:

```bash
curl -s http://127.0.0.1:8090/healthz | jq
# { "ok": true, "connections": 0, "portal_reachable": true, "uptime_s": 3 }
```

## Configuration (`.env` only)

Config is **strictly** via environment variables. See `.env.sample` for the full
list with defaults. The required keys:

| Key | Meaning |
|---|---|
| `PORTAL_BASE_URL` | Portal origin, e.g. `https://support.8westit.com`. Service calls go to `<base>/api/svc/*.php`. |
| `MILEPOST_SERVICE_SECRET` | 64-hex shared secret. **Must be byte-identical** to the portal's `service_secret`. The process refuses to start with the placeholder or a non-64-hex value. |

Other notable keys: `PORT`/`BIND`/`WS_PATH` (listener), `PING_INTERVAL_SECS`
(keepalive), `VERIFY_CACHE_TTL_SECS` (token verify cache), `METRICS_SNAPSHOT_THROTTLE_SECS`
+ `PORTAL_MAX_WRITE_RPS` (write-rate protection for shared MySQL),
`MAX_WS_PAYLOAD_BYTES` (256 KB frame cap), `SQLITE_PATH` (blank = in-memory).

## The shared service secret

Generate once and put the **same** value in two places:

```bash
openssl rand -hex 32
```

- backend `.env` → `MILEPOST_SERVICE_SECRET`
- portal `config.php` → `'service_secret'`

Every backend→portal request carries a static header **and** an HMAC signature
(`X-Milepost-Service` + `X-Milepost-Timestamp` + `X-Milepost-Nonce` +
`X-Milepost-Sign`). The HMAC is the real check — HostGator sometimes strips
`Authorization`, and a stolen static header alone is replay-safe-rejected
(±300 s window, single-use nonce). The portal→backend `/internal/*` calls use
the identical scheme in reverse, verified here.

## Wire protocol (subprotocol `milepost.v1`)

One persistent WebSocket per agent, UTF-8 **text** frames, one JSON envelope
each:

```json
{ "t": "<type>", "id": "<uuid|null>", "ts": 1719600000, "ref": "<id|null>", "d": {} }
```

Lifecycle: `hello` (raw bearer token) → backend verifies via the portal →
`welcome`. Then `ping`/`pong` (30 s), `metrics` (cadence-driven, lossy),
`command`/`cmd_ack`/`cmd_result`/`cmd_result_ack` (durable, idempotent by
`job_id`), `event`, `bye`. Unknown types and unknown fields are ignored
(forward-compatible). Full message catalog + close codes are in
`realtime/PHASE1-SPEC.md` §1.

### Correctness guarantees (implemented here)

- **No double execution** — RT jobs are minted `running` by the portal; the
  poller only claims `queued`; the agent dedupes by `job_id`.
- **No lost results** — `cmd_result` is persisted to the portal *before* acking;
  the agent's REST fallback (30 s) is the backstop; idempotent by `job_id`. The
  optional SQLite ring keeps unacked results across a restart.
- **No lost commands** — per-agent outbox; on refusal or disconnect, undelivered
  RT jobs are requeued to the polling path (`job_requeue.php`).
- **No DB overload** — live metrics never hit MySQL per-push; only a **batched,
  throttled, rate-capped** snapshot does (≤ 1 write/agent/60 s), which is *fewer*
  writes than today's polling.

## Portal-facing REST (this backend hosts these)

Guarded by the service secret (except `/healthz`):

- `POST /internal/dispatch` — portal "Run now" pushes a job to an online agent.
  Returns `{ ok, delivered }`; `delivered:false` ⇒ agent offline, portal leaves
  it queued for polling.
- `GET /internal/presence?agent_ids=42,43` — live tiles for the dashboard.
- `GET /healthz` — `{ ok, connections, portal_reachable, uptime_s }`.

This backend **calls** the portal at: `verify_agent`, `job_result`,
`job_requeue`, `metrics_snapshot` (and `job_create` is portal-side). See
spec §2–§3.

## Deploy on the VPS (Caddy + Docker)

1. **DNS:** add `rt.8westit.com` A-record → VPS IP (same box as the relay).
2. **Secret:** `openssl rand -hex 32`; set it in `backend/.env` and the portal
   `config.php` (must match).
3. **Compose:** merge `realtime/deploy/docker-compose.snippet.yml` into the
   relay's `docker-compose.yml` (beside `hbbs`/`hbbr`), keep
   `realtime/deploy/Caddyfile`, then:
   ```bash
   docker compose up -d --build milepost-rt caddy
   ```
4. **TLS terminates at Caddy** (auto Let's Encrypt) on 443; the backend stays on
   `127.0.0.1:8090`. Keep any proxy read/idle timeout **above** the 30 s ping
   interval (nginx: `proxy_read_timeout 75s;`).
5. **Verify** with signed curl against `verify_agent` / `/internal/dispatch` and
   plain curl against `/healthz`.

**Firewall:** ufw allows `80/443` (Caddy) + existing RustDesk ports; **8090 stays
bound to localhost**; no DB port is ever opened (this service has no DB client).
The container runs non-root with a read-only FS except the mounted `./data`.

## Tests

```bash
npm test          # node --test
```

Coverage (`test/`):

- `protocol.test.js` — envelope framing + unknown-type/field ignore.
- `svcsign.test.js` — HMAC sign/verify round-trip + replay/timestamp rejection.
- `auth.test.js` — `hello` accept (mock portal) + reject (bad token / portal down) + verify cache.
- `presence.test.js` — connect → presence online → `/internal/presence` + `/healthz`.
- `command.test.js` — full `command` → `cmd_ack` → `cmd_result` → `job_result.php` → `cmd_result_ack` round-trip with a mock WS agent.
- `idempotency.test.js` — duplicate `job_id` result dedupe (`already:true`).
- `dispatch_requeue.test.js` — undeliverable/refused command requeues to poll.

## Rollback

Set the portal's `realtime.enabled=false` (agents drop the WS within one
heartbeat) **or** stop the `milepost-rt` container (agents detect a dead socket
in 45 s and keep polling). Nothing in the source-of-truth DB depends on this
backend.

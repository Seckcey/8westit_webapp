# Milepost — RMM (Remote Monitoring & Management)

**Milepost** is an open-source RMM by **8 West IT, LLC** — a self-hosted platform to track client
computers and remote into them for support. *Every endpoint, every mile.*

> **Deploying it? Follow [DEPLOYMENT.md](DEPLOYMENT.md)** — a detailed, step-by-step guide
> (DNS → relay → portal → agent → remote-in). This README is the architecture overview.

```
   Client PCs                 HostGator (cPanel)            Your $5 VPS
 ┌────────────┐  HTTPS POST  ┌──────────────────┐        ┌──────────────┐
 │ 8West Agent│ ─heartbeat─▶ │  PHP + MySQL     │        │ RustDesk     │
 │ (.NET svc) │ ─inventory─▶ │  Portal + API    │        │ relay        │
 │ + RustDesk │ ◀──jobs──    │  online/offline  │        │ (hbbs/hbbr)  │
 └─────┬──────┘  poll/result │  inventory/cmds  │        └──────▲───────┘
       │                     │  Remote-In button│───────────────┘
       └──────────── RustDesk remote session via your relay ──────────
```

## What each piece does
| Component | Lives on | Purpose |
|-----------|----------|---------|
| **`portal/`** | HostGator (PHP 7.4+/8.x + MySQL) | Dashboard: online/offline, inventory, run commands, launch remote sessions |
| **`agent/`** | Client PCs (.NET 4.8 Windows Service, MSI) | Checks in, reports inventory, runs your commands, manages RustDesk |
| **`relay/`** | A small Linux VPS | Relays the actual remote-desktop sessions (the one thing HostGator can't host) |
| **`realtime/`** | The same Linux VPS | *(optional)* Real-time backend — live presence/metrics + instant commands over WebSocket |

---

## Real-time backend (optional, additive)

By default the agent **polls** the portal every ~60 s over HTTPS — that's the always-on floor
and it never goes away. The optional **`realtime/`** backend adds a WebSocket channel for live
presence, live CPU/RAM/disk metrics, and instant "Run now" command delivery — with **automatic
fallback to polling** whenever the backend or a socket is unavailable.

```
  .NET Agent ──HTTPS poll (unchanged)──►  PHP Portal + MySQL  ◄──HTTPS service secret──┐
       ╚═══════════ WSS (new) ═══════════►  Milepost RT Backend (Node + ws, on the VPS)┘
```

Key design points (full contract in [`realtime/PHASE1-SPEC.md`](realtime/PHASE1-SPEC.md), wire
protocol in [`realtime/PROTOCOL.md`](realtime/PROTOCOL.md)):

- **The portal + MySQL stay the single source of truth.** The backend has **no DB driver** —
  HostGator's MySQL isn't reachable from the VPS — so it persists results and presence by
  calling the portal's `/api/svc/*` HTTPS API with a shared secret, and keeps live data in its
  own in-memory store.
- **Reuses the existing agent bearer token** for WS auth (no re-enrollment); the backend asks
  the portal to verify it.
- **Fewer DB writes than polling**, not more — live metrics stay in backend memory; only a
  batched, throttled snapshot reaches MySQL.
- **Purely additive & reversible** — flip one portal config flag (or stop the container) and
  every machine falls back to exactly today's 60 s polling behavior.

It also lays groundwork the roadmap depends on: a `Client → Site → Group → Device` policy /
inheritance model, and a governed "tool action" concept (approval-gated actions for future
AI/automation). Deploy it with **[DEPLOYMENT.md](DEPLOYMENT.md) → Part 6**.

---

## Setup, start to finish

### A. Database (HostGator cPanel)
1. **cPanel → MySQL Databases**: create a database (e.g. `youracct_8westrmm`) and a user, and add the user to the database with **All Privileges**.
2. **cPanel → phpMyAdmin** → select the DB → **Import** → upload `portal/db/schema.sql`.

### B. Portal (HostGator)
1. Copy `portal/config/config.sample.php` → `portal/config/config.php` and fill in the DB credentials, a random `app_secret` (`php -r "echo bin2hex(random_bytes(32));"`), your `base_url`, and (later) the RustDesk relay settings.
2. Upload the portal. **Recommended:** put the project *above* `public_html` and point the domain/subdomain's document root at `portal/public`. If you can't change the docroot, upload the **contents of `portal/public/`** into `public_html` and the `config/`, `lib/`, `db/` folders **one level above** `public_html`, then change `require __DIR__ . '/../lib/...'` paths accordingly (or just keep the folder structure and set a subdomain docroot — easiest).
3. Make sure the subdomain has SSL (cPanel → SSL/TLS, free Let's Encrypt). The app forces HTTPS.
4. Visit `https://support.8westit.com/setup.php` and create your admin login. (Setup self-disables afterward; you may delete the file.)

### C. RustDesk relay (VPS)
Follow **`relay/README.md`** — spin up a $5 VPS, run `docker compose up -d`, open the firewall, copy the generated public key into `config.php`.

### D. Build & deploy the agent (this Windows machine)
1. Install the **.NET SDK** and **WiX Toolset v3** (see top of `agent/build-agent.ps1`).
2. In the portal: **Agents & Keys → Generate** an enrollment key.
3. Build the MSI:
   ```powershell
   cd agent
   ./build-agent.ps1 -EnrollKey <paste-key> -PortalUrl https://support.8westit.com
   ```
   Output: `agent/dist/EightWestAgent.msi` (+ a ready-to-run `install.bat`).
4. On a client PC (as admin):
   ```
   msiexec /i EightWestAgent.msi ENROLLKEY=<key> PORTAL=https://support.8westit.com /qn
   ```
   The computer appears on the Dashboard within ~1 minute.
5. **RustDesk installs itself automatically.** A few minutes after the agent installs, it
   downloads + silently installs RustDesk, points it at your relay, and **Remote In** activates.

---

## Daily use
- **Dashboard** — live online/offline, who's logged in, public IP, last-seen. Auto-refreshes.
- **Computer page** — full hardware/software inventory, run PowerShell/CMD (as SYSTEM), restart, view command output.
- **Remote In** — opens a RustDesk session through your relay using the agent's reported ID + unattended password.

## Security notes
- All agent ↔ portal traffic is HTTPS with a per-agent bearer token (only a hash is stored).
- Enrollment requires a pre-shared key you generate; keys can be disabled anytime.
- Portal logins are `password_hash`/`password_verify` with CSRF-protected forms and an audit log.
- `config.php` is git-ignored and blocked from web access via `.htaccess`.
- Commands run as SYSTEM — this is a powerful tool; keep your portal password strong and unique.

## Local development / verification
PHP isn't required to *edit* this, but to run the portal locally you'd need PHP + MySQL
(e.g. XAMPP). In production it runs on HostGator's PHP/MySQL. The agent needs the .NET SDK
to build and a Windows machine to run.

## Repo layout
```
web_app/
├─ portal/         PHP + MySQL app (HostGator)
│  ├─ public/      web root (pages, API consumed by browser)
│  ├─ api/         agent-facing API (enroll, heartbeat, inventory, jobs)
│  ├─ lib/         bootstrap, auth, rendering
│  ├─ config/      config.sample.php → copy to config.php
│  └─ db/schema.sql
├─ agent/          .NET 4.8 Windows Service + WiX MSI
│  ├─ EightWestAgent/   C# source
│  ├─ installer/        Product.wxs
│  └─ build-agent.ps1
├─ relay/          RustDesk self-host (docker-compose + guide)
└─ realtime/       (optional) real-time backend — live presence/metrics + instant commands
   ├─ PHASE1-SPEC.md   full contract
   ├─ PROTOCOL.md      WebSocket wire protocol + service auth
   ├─ backend/         Node + ws server (runs on the VPS, ws://127.0.0.1:8090)
   └─ deploy/          Caddyfile + docker-compose snippet (TLS at rt.8westit.com)
```

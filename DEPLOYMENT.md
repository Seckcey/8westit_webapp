# 8 West IT RMM — Deployment Guide

A complete, do-this-then-that walkthrough to take the system live at
**support.8westit.com**. Plan ~60–90 minutes the first time.

```
   Client PCs                 HostGator (cPanel)            Your VPS
 ┌────────────┐  HTTPS POST  ┌──────────────────┐        ┌──────────────┐
 │ 8West Agent│ ─heartbeat─▶ │  support.        │        │ relay.       │
 │ (.NET svc) │ ─inventory─▶ │  8westit.com     │        │ 8westit.com  │
 │ +RustDesk  │ ◀──jobs──    │  Portal + MySQL  │        │ RustDesk relay│
 └─────┬──────┘  poll/result └──────────────────┘        └──────▲───────┘
       └───────────── RustDesk remote session via your relay ───┘
```

## Before you start — checklist
- [ ] Access to the **8westit.com DNS** (HostGator domain panel or wherever DNS is managed)
- [ ] A **HostGator hosting account** (cPanel) for the portal
- [ ] A **small Linux VPS** (~$5/mo, DigitalOcean/Vultr/Hetzner) for the remote relay
- [ ] This Windows PC with the build tools (already installed: .NET SDK 8, WiX 5)
- [ ] ~30 min for the relay, ~30 min for the portal, ~15 min per client

---

# Part 1 — DNS records (10 min)

In your DNS manager for **8westit.com**, create:

| Type | Name | Value | Purpose |
|------|------|-------|---------|
| A | `support` | (HostGator server IP) | the portal — support.8westit.com |
| A | `relay`   | (your VPS IP)          | the remote relay — relay.8westit.com |
| A | `rt`      | (your VPS IP — same box as `relay`) | the real-time backend — rt.8westit.com (optional; see Part 6) |

> HostGator's server IP is in cPanel → *Server Information* → "Shared IP Address".
> The VPS IP is shown when you create the droplet in Part 2.

DNS can take 15–60 min to propagate. You can continue with Parts 2–3 meanwhile.

---

# Part 2 — Remote relay on a VPS (30 min)

This is the only piece HostGator can't run. It just relays remote-desktop traffic.

### 2.1 Create the VPS
Create a **Ubuntu 22.04**, 1 GB RAM droplet (DigitalOcean "$6 Basic" is perfect).
Note its public IP and put it in the `relay` DNS record above.

### 2.2 Connect and install Docker
SSH in (`ssh root@<vps-ip>`), then:
```bash
curl -fsSL https://get.docker.com | sh
```

### 2.3 Start the relay
```bash
sudo mkdir -p /opt/rustdesk && cd /opt/rustdesk
```
Create `/opt/rustdesk/docker-compose.yml` (copy the one from this repo's `relay/` folder),
then:
```bash
echo "RELAY_HOST=relay.8westit.com" > .env
sudo docker compose up -d
```
Check both containers are running:
```bash
sudo docker compose ps          # hbbs and hbbr should say "Up"
```

### 2.4 Open the firewall
```bash
sudo ufw allow 21115:21119/tcp
sudo ufw allow 21116/udp
sudo ufw allow OpenSSH
sudo ufw --force enable
```

### 2.5 Copy the relay's public key  ← IMPORTANT, you need this in Part 3
```bash
sudo cat /opt/rustdesk/data/id_ed25519.pub
```
Copy that whole string somewhere safe. You'll paste it into the portal config.

---

# Part 3 — Portal on HostGator (30 min)

### 3.1 Create the subdomain
cPanel → **Domains** (or *Subdomains*) → create **support.8westit.com**.
When asked for the **Document Root**, set it to a path you'll upload to, e.g.:
```
/home/<cpaneluser>/rmm/portal/public
```
(So the app lives in `/home/<cpaneluser>/rmm/portal`, and only `public/` is web-facing —
the `config/`, `lib/`, `db/` folders stay private. This is the secure layout.)

### 3.2 Upload the portal
Using cPanel **File Manager** (or FTP/FileZilla), upload the local `portal/` folder so it
lands at `/home/<cpaneluser>/rmm/portal/` — i.e. you should end up with:
```
/home/<cpaneluser>/rmm/portal/public/   (index.php, login.php, api/, assets/ …)
/home/<cpaneluser>/rmm/portal/config/
/home/<cpaneluser>/rmm/portal/lib/
/home/<cpaneluser>/rmm/portal/db/
```
> Tip: zip the `portal` folder locally, upload the zip, and "Extract" in File Manager — far
> faster than uploading files one by one.

### 3.3 Create the database
cPanel → **MySQL Databases**:
1. Create a database, e.g. `cpaneluser_rmm`.
2. Create a user with a strong password.
3. **Add the user to the database** with **All Privileges**.
4. Write down the final names — cPanel prefixes them with your account name
   (e.g. database `cpaneluser_rmm`, user `cpaneluser_rmm`).

### 3.4 Import the tables
cPanel → **phpMyAdmin** → click your database on the left → **Import** tab →
choose `portal/db/schema.sql` → **Go**. You should see 7 tables created.

### 3.5 Configure the portal
In File Manager, go to `portal/config/`, copy `config.sample.php` to **`config.php`**, then
**Edit** `config.php` and fill in:
```php
'db' => [
    'host' => 'localhost',
    'port' => '',
    'name' => 'cpaneluser_rmm',     // your DB name
    'user' => 'cpaneluser_rmm',     // your DB user
    'pass' => 'your-db-password',
],
'app_secret' => '<64 random hex chars>',   // see below
'base_url'   => 'https://support.8westit.com',
'rustdesk'   => [
    'relay_host' => 'relay.8westit.com',
    'relay_key'  => '<the id_ed25519.pub string from Part 2.5>',
],
```
Generate `app_secret`: in cPanel → *Terminal* (or any PHP runner) run
`php -r "echo bin2hex(random_bytes(32));"`, or just mash 64 hex characters.

### 3.6 Turn on HTTPS
cPanel → **SSL/TLS Status** → make sure **support.8westit.com** has AutoSSL (green lock).
The portal forces HTTPS, so this must be on. If it's not there yet, click "Run AutoSSL".

### 3.7 Create your login
Visit **https://support.8westit.com/setup.php**, create your admin account.
The setup page disables itself afterward. You'll land on the login screen with your logo. ✅

---

# Part 4 — Build & deploy the agent (15 min)

### 4.1 Create an enrollment key
In the portal: **Agents & Keys → Generate** (optionally tie it to a client).
Copy the 64-character key.

### 4.2 Build the installer (on this Windows PC)
Easiest: **double-click `agent\make-installer.bat`**, paste the key when prompted.
Or from PowerShell:
```powershell
cd "<repo>\agent"
./build-agent.ps1 -EnrollKey <paste-the-key> -PortalUrl https://support.8westit.com
```
This produces:
- `agent/dist/EightWestAgent.msi` — the installer (~24 MB; RustDesk is bundled inside)
- `agent/dist/install.bat` — a one-click silent installer with your key baked in

> First-time setup on a NEW machine: run **`agent\setup-build-tools.bat`** once — it installs
> .NET SDK 8 + WiX 5 for you. (Already installed on this PC.) The first build also downloads
> the RustDesk client (~24 MB) once and caches it in `agent/installer/` for bundling.

### 4.3 Install on a client computer
Copy **EightWestAgent.msi** and **install.bat** to the client, then run **install.bat as
Administrator** (right-click → Run as administrator). Or from an admin prompt:
```
msiexec /i EightWestAgent.msi ENROLLKEY=<key> PORTAL=https://support.8westit.com /qn
```
What happens automatically:
1. Installs the **8 West IT RMM Agent** Windows service (runs as SYSTEM, auto-start).
2. Within ~1 min the computer appears on your **Dashboard** (online, with inventory).
3. The agent **silently installs the bundled RustDesk** (included in the MSI), points it at
   your relay, and sets an unattended password. Within a minute or two the **Remote In**
   button activates for that computer.

> RustDesk ships *inside* the MSI, so no GitHub download is needed on the client — it works
> even on locked-down networks. (If the bundled copy is ever missing, the agent falls back to
> downloading the version in `HKLM\SOFTWARE\8WestIT\Agent\RustDeskUrl`.)

---

# Part 5 — Set up YOUR remote-control app (one time, 5 min)

On **your** support workstation, install the RustDesk client from https://rustdesk.com,
then point it at your relay: **Settings → Network → ID/Relay Server**:
- **ID server:** `relay.8westit.com`
- **Relay server:** `relay.8westit.com`
- **Key:** the `id_ed25519.pub` string from Part 2.5

Now, from the portal, click **Remote In** on any online computer → it launches RustDesk and
connects straight through your relay using that machine's ID + unattended password. 🎉

---

# Part 6 — Real-time backend (optional, ~20 min)

This is the **Phase 1 real-time foundation**. It is **entirely optional and additive** — the
system in Parts 1–5 works exactly as before without it. Adding it gives you live presence,
live CPU/RAM/disk metrics, and instant "Run now" command delivery, with **automatic fallback
to 60 s polling** whenever the backend or an agent's WebSocket is unavailable.

It runs as a small **Node.js** service on the **same VPS as the RustDesk relay** (Part 2),
behind a TLS reverse proxy. It **never connects to HostGator MySQL** — HostGator's shared
MySQL isn't reachable from the VPS. Instead it calls the portal's HTTPS service API
(`/api/svc/*`) with a shared secret, and keeps live presence/metrics in its own in-memory
store. The PHP portal + MySQL stay the single source of truth.

> Wire-protocol reference: [`realtime/PROTOCOL.md`](realtime/PROTOCOL.md). Full contract:
> [`realtime/PHASE1-SPEC.md`](realtime/PHASE1-SPEC.md).

```
   Client PCs              HostGator                    Your VPS (one box)
 ┌────────────┐  HTTPS    ┌──────────────┐           ┌───────────────────────────┐
 │ 8West Agent│ ─poll────►│ support.     │◄──HTTPS───┤ rt.8westit.com (Caddy 443) │
 │ (.NET svc) │           │ 8westit.com  │  service  │   └─► milepost-rt (Node)    │
 │            │ ═WSS══════╪══════════════╪═══════════╪═════► ws://127.0.0.1:8090   │
 └────────────┘  realtime │ /api/svc/*   │  secret   │ relay.8westit.com (hbbs/hbbr)│
                          └──────────────┘           └───────────────────────────┘
```

### 6.1 DNS

Add the `rt` A-record from Part 1 (→ the **same VPS IP** as `relay`). TLS for `rt.8westit.com`
is obtained automatically by Caddy (Let's Encrypt), so `rt` must resolve before you start it.

### 6.2 Generate the shared service secret

This single 64-hex secret authenticates backend→portal calls. Generate it **once** on the VPS:
```bash
openssl rand -hex 32
```
You will paste this **identical** value in two places (they must be byte-for-byte equal):
- Portal `config.php` → `'service_secret'`
- Backend `.env` → `MILEPOST_SERVICE_SECRET`

### 6.3 Portal side (HostGator)

1. **Run the DB migration.** cPanel → **phpMyAdmin** → select your DB
   (`qygiabte_8westit_webapp`) → **Import** →
   `portal/db/migrations/2026-06-30_phase1_realtime.sql` → **Go**. The one-time site
   backfill is the last section of that same file, so there is nothing extra to run.
   All columns are additive/nullable — existing polling reads none of them, so this
   changes no behavior on its own.
   > MySQL 8 has no `ADD COLUMN IF NOT EXISTS`. If a re-run reports "Duplicate column name",
   > delete that one line and re-run — the rest is idempotent (`CREATE TABLE IF NOT EXISTS`).
2. **Upload the new PHP files**: `portal/lib/svc_auth.php`, `portal/lib/policy.php`, and the
   whole `portal/public/api/svc/` folder (including its `.htaccess`).
3. **Add the real-time keys to `config.php`** (inside the `return [ ... ]` array):
   ```php
   'service_secret'        => '<the 64-hex secret from 6.2>',   // == backend MILEPOST_SERVICE_SECRET
   'service_replay_window' => 300,                              // seconds; ±skew on service calls
   'realtime' => [
       'enabled'      => false,                                 // leave OFF until 6.5 verified
       'backend_url'  => 'https://rt.8westit.com',              // portal → backend /internal/*
       'agent_ws_url' => 'wss://rt.8westit.com/agent',          // advertised to agents
       'dispatch_timeout_ms' => 4000,
   ],
   ```
   Leave `'enabled' => false` for now — no agent will touch the backend until you flip it.

### 6.4 Backend side (VPS, beside RustDesk)

On the VPS, put the backend next to the relay (e.g. `/opt/milepost-rt`), copy the repo's
`realtime/backend/` there, plus `realtime/deploy/Caddyfile` and
`realtime/deploy/docker-compose.snippet.yml`.

1. **Create the `.env`** from the sample:
   ```bash
   cd /opt/milepost-rt/backend
   cp .env.sample .env
   ```
   Then edit `.env` and set at minimum:
   ```ini
   PORT=8090
   BIND=127.0.0.1                                  # bare-metal default; the compose OVERRIDES this to 0.0.0.0 (see below)
   WS_PATH=/agent
   PORTAL_BASE_URL=https://support.8westit.com     # the portal origin (svc endpoints live under /api/svc/)
   MILEPOST_SERVICE_SECRET=<the 64-hex secret from 6.2>   # MUST equal portal service_secret
   ```
   The remaining keys (ping/hello timeouts, metrics cadences, throttles, SQLite path, log
   level) have safe defaults documented in `.env.sample`. **Never log tokens or secrets.**
   > Networking note: under Docker, Caddy runs in its own container, so the backend must
   > listen on `0.0.0.0` *inside its container* for Caddy to reach it. The provided
   > `docker-compose.snippet.yml` sets `BIND=0.0.0.0` as a per-container override and
   > publishes **no host port**, so 8090 is never exposed on the VPS's public interface —
   > only Caddy (over the shared `milepost` network) can reach it. The `.env` `BIND` value
   > matters only for a bare-metal (non-Docker) run.
2. **Bring up the backend + Caddy.** The `docker-compose.snippet.yml` defines two services on
   a shared `milepost` network — `milepost-rt` (the Node backend; `BIND=0.0.0.0` in-container,
   no published host port) and `caddy` (TLS on 80/443). Merge it into your relay
   `docker-compose.yml`, or run it as its own stack alongside the relay containers:
   ```bash
   sudo docker compose up -d milepost-rt caddy
   sudo docker compose ps          # both should say "Up"
   ```
   Caddy uses `realtime/deploy/Caddyfile`, which reverse-proxies `rt.8westit.com` →
   `milepost-rt:8090` (the compose **service name**, resolved by Docker's embedded DNS — not
   `127.0.0.1`, which inside the Caddy container would be Caddy's own loopback). It forwards
   the WebSocket `Upgrade`/`Connection` headers automatically. On an nginx box instead of
   Caddy, proxy to the backend's reachable address and set
   `proxy_set_header Upgrade $http_upgrade;`, `proxy_set_header Connection "upgrade";`, and
   keep `proxy_read_timeout 75s;` — **above** the 30 s ping interval so pings keep the socket alive.

### 6.5 Firewall ports

The backend stays bound to **localhost**; only Caddy is public.
```bash
sudo ufw allow 80/tcp          # Caddy — HTTP→HTTPS redirect + ACME
sudo ufw allow 443/tcp         # Caddy — WSS + /internal/* (TLS)
# 21115:21119/tcp + 21116/udp (RustDesk, from Part 2.4) stay as-is.
# Do NOT publish/open 8090 — the compose publishes no host port for it; only Caddy reaches
# it over the shared Docker network. (If you ever run the backend bare-metal, keep BIND=127.0.0.1.)
# No DB port is opened anywhere; the backend has no DB client.
```
Optionally restrict `/internal/*` to HostGator's egress IPs at the proxy (the shared secret
already guards it).

### 6.6 Verify the backend before pointing agents at it

```bash
# Health probe (localhost on the VPS):
curl -s http://127.0.0.1:8090/healthz
# → {"ok":true,"connections":0,"portal_reachable":true,"uptime_s":…}

# Through Caddy / public TLS:
curl -s https://rt.8westit.com/healthz
```
`portal_reachable:true` confirms the backend can sign and reach `/api/svc/*` with the secret.
If it's `false`, the secret doesn't match the portal's `service_secret`, or `PORTAL_BASE_URL`
is wrong. Run the test suite anytime with `node --test` in `realtime/backend/`.

### 6.7 Turn it on — and the canary

1. **Flip the portal flag:** set `'enabled' => true` in `config.php`'s `realtime` block.
   Now `enroll.php` and `heartbeat.php` append `realtime_url` to their JSON. **Fielded
   (old) agents ignore the unknown field and keep polling** — only `1.1.0`+ agents act on it.
2. **Canary one machine first.** Either set `RealtimeUrl` in
   `HKLM\SOFTWARE\8WestIT\Agent` on one PC, or install agent **1.1.0** on one PC. Confirm:
   - Dashboard shows live CPU/RAM/disk for that machine (updates faster than 60 s).
   - "Run now" returns output near-instantly; `agents.rt_supported` flips to `1`.
   - **Pull the backend** (`docker compose stop milepost-rt`) → within ~45 s the agent falls
     back to full polling, the dashboard shows "live metrics unavailable, last seen X", and
     commands still run over the poll path. Bring it back → it reconnects on its own.
3. **Roll `1.1.0` out** through the normal MSI/update channel once the canary looks good.

### 6.8 Roll back any time

- Set `'enabled' => false` in `config.php` → agents drop the WS within one heartbeat and
  behave exactly as before.
- Or `sudo docker compose stop milepost-rt` → agents detect the dead socket in ~45 s, back
  off, and keep polling.

Nothing in the truth DB depends on the backend; the new tables/columns are simply unused while
real-time is off. **No MSI rebuild is needed to enable real-time** — only the portal config
change; the registry keys exist purely as a per-machine override / kill switch
(`RealtimeEnabled=0` forces pure polling on that PC).

---

# Verifying it all works
1. Dashboard shows the client **online** (green dot) within a minute of install.
2. Click the computer → **Inventory** shows real CPU/RAM/disk/software.
3. **Run a command** (e.g. PowerShell `Get-Service`) → output comes back in seconds.
4. Wait a few minutes, refresh → the computer's page shows a **RustDesk ID**, and
   **Remote In** appears. Click it → you control the machine.

---

# Troubleshooting

| Symptom | Fix |
|--------|-----|
| Client never appears on the dashboard | Confirm the client can reach `https://support.8westit.com` in a browser. Check the agent log on the client: `C:\ProgramData\8WestIT\Agent\agent.log`. |
| "Enrollment key not recognized" in the log | The key was disabled or mistyped. Generate a new one and reinstall, or pass it via `msiexec … ENROLLKEY=<key>`. |
| Dashboard shows it offline right after install | Give it 1–2 check-ins (≤60s each). Confirm the server's clock/SSL is fine. |
| **Remote In** never appears | Check the agent log for "RustDesk is ready". If the GitHub download is blocked on the client network, host the RustDesk installer yourself and set `RustDeskUrl`. |
| Remote session won't connect | Verify the VPS firewall (Part 2.4) and that your RustDesk client uses the same relay host + key (Part 5). |
| Portal shows a blank/500 page | Re-check `config.php` DB credentials; confirm `schema.sql` imported (7 tables). |

Agent service controls on a client (admin PowerShell):
```powershell
Get-Service EightWestAgent
Restart-Service EightWestAgent
Get-Content C:\ProgramData\8WestIT\Agent\agent.log -Tail 30
```

---

# Updating later
- **Portal:** re-upload changed files in `portal/`. (Schema changes: re-run the relevant SQL.)
- **Agent:** bump `<Version>` in the csproj + `Version` in `Product.wxs`, rebuild, reinstall
  (the MSI is a major-upgrade, so it replaces the old one cleanly).
- **RustDesk version:** set `RustDeskUrl` (registry or agent config) to a newer release URL.

# Security reminders
- Agent↔portal traffic is HTTPS with a per-agent bearer token (only a hash is stored).
- Enrollment needs a key you generate; disable keys anytime in the portal.
- Commands run as SYSTEM — keep your portal password strong and unique, and only create
  logins for techs you trust.
- `config.php` is blocked from the web (`.htaccess`) and git-ignored.

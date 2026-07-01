# Milepost — RMM platform (an 8 West IT, LLC product)

Open-source-first RMM (remote monitoring & management) SaaS for the MSP **8 West IT**.
Tagline: *"Every endpoint, every mile."* Live in production. **This GitHub repo is PUBLIC.**

## Repo layout
- `portal/` — PHP 8.x + MySQL web app (the dashboard/API). Docroot = `portal/public/`.
  - `portal/lib/` — shared helpers (`bootstrap.php`, `auth.php`, `render.php`, `realtime.php`, `policy.php`, …).
  - `portal/config/` — `config.sample.php` only; the real `config.php` is **gitignored** (secrets).
  - `portal/public/api/` — machine endpoints; `api/svc/*` = backend-only (HMAC service-signed).
  - `portal/cron/` — CLI-only maintenance scripts.
  - `portal/db/migrations/` — dated SQL migrations, run by hand via phpMyAdmin.
- `agent/EightWestAgent/` — C#/.NET Framework 4.8 Windows service; `agent/installer/Product.wxs` = WiX 5 MSI.
- `realtime/backend/` — Node + `ws` real-time backend (runs on a VPS). **Has NO DB driver** — it persists only by calling the portal's signed `/api/svc/*` endpoints.

## Where it runs (3 deploy targets)
1. **Portal** → HostGator shared hosting (cPanel). Live DB = `qygiabte_8westit_webapp`. Server layout:
   docroot `public_html/8westit/webapp` (= the app's `public/`); `lib/`, `config/`, `cron/`, `installers/`,
   `db/` are **siblings** under `public_html/8westit/` (that's how `../lib` paths resolve). Portal at
   `https://support.8westit.com`.
2. **Real-time backend (`milepost-rt`)** → a VPS that is the user's **BTCPay Server host**. Repo is cloned at
   `/opt/milepost`; container `milepost-rt`; behind BTCPay's nginx-proxy via
   `realtime/deploy/docker-compose.nginx-proxy.yml`. Agents connect `wss://rt.8westit.com/agent`.
3. **Agents** → Windows endpoints; service name `EightWestAgent`; config in registry
   `HKLM\SOFTWARE\8WestIT\Agent` (64-bit hive); state in `C:\ProgramData\8WestIT\Agent\` (survives MSI upgrade).

## Hard rules (read before changing code)
- **Never commit `config.php`** or any secret (DB creds, `app_secret`, `service_secret`, RustDesk relay key).
  The repo is PUBLIC. `.gitignore` covers `config.php`, `bin/obj`, `agent/dist`, `*.zip`.
- **SQL must be MySQL 8.0.46 (Percona) compatible.** No `ADD COLUMN IF NOT EXISTS` (MariaDB-only, fails live —
  use plain `ALTER`). `CREATE TABLE IF NOT EXISTS` is fine. **FK constraint names must be UNIQUE per database**
  (MySQL 8 enforces it; MariaDB does NOT, so local tests miss collisions — prefix them, e.g. `fk_<table>_<col>`).
  Write migrations with block `/* */` comments so a phpMyAdmin paste can't break on lost newlines.
- **Single-tenant portal:** every logged-in user is 8 West staff and may view any non-archived agent. There is
  intentionally NO per-client scoping on agent views — don't add it, and match the existing model.
- **The RT backend has no database.** Live data reaches MySQL only through the throttled, batched, HMAC-signed
  `POST /api/svc/metrics_snapshot.php`. Keep that pattern.

## Conventions
- Portal helpers: `db()`, `cfg()`, `e()`, `json_out()`, `json_err()`, `read_json_body()`, `audit()` in
  `lib/bootstrap.php`; `require_login()`, `current_user()`, `csrf_token()/csrf_check()` in `lib/auth.php`.
  `db()` pins the MySQL session to UTC (`+00:00`); all `DATETIME` columns store UTC.
- **JSON read endpoints** are session-authed top-level `public/*.php` pages (mirror `agent_live.php`): `enforce_https();
  if (!current_user()) json_err('Unauthorized', 401);` then `json_out([...])`. 404 (not 403) for missing/archived ids.
- **Bump `MP_ASSET_VER`** in `lib/render.php` whenever you change `app.css`/`app.js` (cache-buster).
- **Agent version** is derived from the assembly (`Worker.Version` → `Assembly … GetName().Version`) — bump it in
  `EightWestAgent.csproj` **and** `Product.wxs` together; don't hardcode a version string anywhere.
- Front-end is vanilla JS (no build step); build DOM from server data with `textContent`/`createElement`
  (never inject server strings into `innerHTML`). UI follows the Milepost design language (calm, soft corners; navy/red/blue).

## Build / test / run
- Agent: `dotnet build agent/EightWestAgent/EightWestAgent.csproj -c Release` (net48).
  MSI templates (for the portal to serve): `agent/build-templates.ps1` → `agent/dist/templates/agent-template-{lite,full}.msi`.
- RT backend tests: `cd realtime/backend && node --test`.
- Local test DB (no local PHP; PHP was only on the live server): **MariaDB 12.3** on port 3307 —
  `"C:\Program Files\MariaDB 12.3\bin\mariadbd.exe" --datadir=C:\mdbtest\data --port=3307` (passwordless root, no service).
  Validate migrations there, but remember MariaDB won't catch MySQL-8-only issues (see FK rule above).

## Deploy gotchas (all bit us once)
- **cPanel File Manager does NOT overwrite on re-upload → delete-then-upload** (portal files and MSIs alike).
  For many portal files at once, zip with the `public_html/8westit/` structure and Extract (overwrites).
- **The `milepost-rt` backend must be redeployed for any backend change** — easy to forget, and flat metrics keep
  flowing so it hides the problem. On the VPS: `cd /opt/milepost && git checkout origin/main -- <changed file>`
  (a plain `git pull` aborts on the hand-created, later-committed `docker-compose.nginx-proxy.yml`), then
  `cd realtime/deploy && docker compose -f docker-compose.nginx-proxy.yml up -d --build milepost-rt`. If
  `rt.8westit.com` 503s: `docker kill -s HUP nginx-gen`.
- **Agent auto-update** is config-gated in `config.php` `agent_update{enabled,target_version,variant,fleet_wide}`.
  Ship order: (1) build templates, (2) DELETE-then-upload the **unversioned** `agent-template-{lite,full}.msi` to
  `installers/`, (3) bump `target_version`. A perMachine MSI upgrade needs SYSTEM/elevation (the agent's one-shot
  SYSTEM scheduled task); a non-elevated `msiexec` fails with Error 1730/1603.

## Git
- Single-developer backup repo: work on `main`, commit, push (`origin` = https://github.com/Seckcey/8westit_webapp).
- Commit/push only when asked. End commit messages with a `Co-Authored-By: Claude …` line.

## Status & roadmap
- **Phase 1** (real-time foundation) and **Phase 2** (monitoring: telemetry backbone + wider metrics) are DONE and LIVE.
- Roadmap doc: `8 West IT/Milepost-Product-Roadmap.docx` (9 phases). **Next up = Phase 2 "smart alerting"**:
  policy-based thresholds inherited down Client→Site→Group→Device (on the existing `policies`/`policy_assignments`
  engine), an alert/event lifecycle (open→ack→resolve for MTTA), maintenance windows, multi-channel delivery.
- Deep project history, deploy specifics, and lessons live in the Claude memory files (`8west-rmm-project.md`).

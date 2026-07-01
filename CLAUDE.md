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
  `current_user()`/`csrf_check()` live in `auth.php` — a JSON/action endpoint must `require` `render.php` OR `auth.php`
  (NOT just a lib like `alerts.php` that only pulls `bootstrap.php`+`policy.php`), or you get a fatal undefined-function
  500 that `php -l` can't catch — verify new endpoints by actually hitting them, not just linting + lib-level tests.
- **Bump `MP_ASSET_VER`** in `lib/render.php` whenever you change `app.css`/`app.js` (cache-buster).
- **Agent version** is derived from the assembly (`Worker.Version` → `Assembly … GetName().Version`) — bump it in
  `EightWestAgent.csproj` **and** `Product.wxs` together; don't hardcode a version string anywhere.
- Front-end is vanilla JS (no build step); build DOM from server data with `textContent`/`createElement`
  (never inject server strings into `innerHTML`). UI follows the Milepost design language (calm, soft corners; navy/red/blue).
- **Alerting** (Phase 2 Step 3): metric thresholds live in policy `doc_json` under a `thresholds` key
  (inherited Client→Site→Group→Device by `lib/policy.php`, with a built-in floor in `lib/alerts.php`).
  `thresholds` is server-only — `agent_policy.php` strips it and `policy.php` excludes it from the policy etag
  (don't send it to agents / don't let it churn agent policy refetch). Detection runs best-effort in
  `metrics_snapshot.php` AFTER the sacred presence/metrics commit; it only enqueues into `alert_deliveries`.
  The 1-min `cron/alerts_dispatch.php` sends email (`lib/mailer.php`, a hand-rolled SMTP client — no composer)
  and runs the offline sweep. All gated by `config.php` `alerts.enabled` (default off).

## Build / test / run
- Agent: `dotnet build agent/EightWestAgent/EightWestAgent.csproj -c Release` (net48).
  MSI templates (for the portal to serve): `agent/build-templates.ps1` → `agent/dist/templates/agent-template-{lite,full}.msi`.
- RT backend tests: `cd realtime/backend && node --test`.
- Local test DB: **MariaDB 12.3** on port 3307 —
  `"C:\Program Files\MariaDB 12.3\bin\mariadbd.exe" --datadir=C:\mdbtest\data --port=3307` (passwordless root, no service).
  Validate migrations there, but remember MariaDB won't catch MySQL-8-only issues (see FK rule above).
- **Local PHP IS available** (winget `PHP.PHP.8.3`, 8.3.31) — usable for `php -l` lint + running lib code
  against the MariaDB test DB. No `php.ini` is loaded, so enable extensions on the CLI:
  `php -d extension_dir="<winget>\PHP.PHP.8.3_...\ext" -d extension=mbstring -d extension=pdo_mysql -d extension=openssl script.php`.
  Pattern: drop a temp `portal/config/config.php` pointing at `mp_alert_test` on :3307, run, then DELETE it
  (never leave a config.php behind — and never point tests at the live DB).

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
- **Phase 1** (real-time foundation) and **Phase 2** (monitoring + smart alerting) are DONE and LIVE.
- **Phase 2 "smart alerting" — Step 3 CORE: DONE + LIVE in production (2026-07-01)**, verified end-to-end (a real
  built-in-default disk alert emailed successfully). Portal-only. Threshold engine on the policy inheritance engine +
  alert lifecycle (open→ack→resolve) + email/in-app delivery + Alerts UI. Migration
  `db/migrations/2026-07-01_phase2_alerting.sql`. Mail via HostGator's local Exim (`alerts.smtp` host `localhost`:25,
  `secure`=`''`, no auth).
  GOTCHA burned in: `last_value` is a MySQL-8 reserved word (LAST_VALUE window fn) → column renamed `last_val`
  (MariaDB didn't flag it locally — audit new identifiers against the 8.0 reserved-word list, esp. window-fn words).
- **Phase 2 fast-follow — maintenance windows + webhook delivery: BUILT + tested, NOT yet deployed (2026-07-01).**
  Migration `db/migrations/2026-07-01_phase2_maint_webhook.sql` (maintenance_windows table + adds 'webhook' to
  alert_deliveries.channel). Maintenance windows (scoped like thresholds, one-off/daily/weekly, UTC) FULLY suppress
  alerting for matching devices (gated in `alerts_evaluate` + offline sweep + held in the dispatch cron); managed on the
  Alerts page. Webhooks in `config.php` `alerts.webhooks` (slack/discord/telegram/generic, https-only) sent by the cron via
  `lib/webhook.php` alongside email. DEPLOY = import migration → upload changed portal files → (optional) add `alerts.webhooks`.
- **Phase 3 (patch management) — MVP "scan & report" BUILT + tested, NOT yet deployed (2026-07-02).** Windows-Update-direct
  patch VISIBILITY only (no installs yet). **Agent 1.2.0** `PatchManager` shells a WUA COM PowerShell scan (`IsInstalled=0`)
  on startup + a timer, POSTs to `api/patch_report.php`, gated by a heartbeat `patch` directive that appears only when
  `config.php` `patch.enabled` is on. Portal: `agent_patch_status` table (migration `2026-07-02_phase3_patch.sql`),
  `lib/patch.php`, a Patch card on `agent.php`, a fleet badge on `index.php`. Chosen rollback approach (built later) =
  best-effort per-KB DISM/wusa uninstall + honest "not reversible" flags.
- **Phase 3 increment 2a — manual, human-approved patch INSTALL: BUILT + tested, NOT yet deployed (2026-07-02).** **Agent
  1.3.0** adds `PatchManager.Install(kbCsv)` (WUA COM download+install of selected KBs, runs on a BACKGROUND thread so a
  long install never stalls the heartbeat, re-scans + self-reports after) + on-demand `patch_scan`/`patch_install` job
  handling in `Worker.DrainJobs`. Portal: migration `2026-07-02_phase3_patch_install.sql` (appends `patch_scan`,`patch_install`
  to `jobs.job_type`); `agent.php` Patch card gains per-update checkboxes + "Install selected" (admin-only, KB-sanitized) +
  "Scan now". No automation, no auto-reboot (reboot stays a manual `restart` job) — the human click IS the approval; the
  actual WUA install is destructive so it's canary-validated, not locally testable. DEPLOY = import both patch migrations →
  upload portal files → ship the 1.3.0 templates → canary. **Remaining: (2b) ring-rollout AUTOMATION (`patch_settings` policy
  key stripped from agent payload like thresholds, `patch_rollouts` tables, rollout cron state machine, maintenance-window
  install gate, auto-approve + reboot policy — the destructive-automation decisions live here); (3) rollback + auto-halt.**
- Roadmap doc: `8 West IT/Milepost-Product-Roadmap.docx` (9 phases). Phase 3 = patch management (ring rollout + rollback).
- Deep project history, deploy specifics, and lessons live in the Claude memory files (`8west-rmm-project.md`).

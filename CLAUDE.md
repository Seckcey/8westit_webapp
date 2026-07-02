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
- **Phase 3 (patch management) — MVP "scan & report" BUILT + DEPLOYED + LIVE (2026-07-02).** Windows-Update-direct
  patch VISIBILITY only (no installs yet). **Agent 1.2.0** `PatchManager` shells a WUA COM PowerShell scan (`IsInstalled=0`)
  on startup + a timer, POSTs to `api/patch_report.php`, gated by a heartbeat `patch` directive that appears only when
  `config.php` `patch.enabled` is on. Portal: `agent_patch_status` table (migration `2026-07-02_phase3_patch.sql`),
  `lib/patch.php`, a Patch card on `agent.php`, a fleet badge on `index.php`. Chosen rollback approach (built later) =
  best-effort per-KB DISM/wusa uninstall + honest "not reversible" flags.
- **Phase 3 increment 2a — manual, human-approved patch INSTALL: BUILT + DEPLOYED + LIVE (2026-07-02).** **Agent
  1.3.0** adds `PatchManager.Install(kbCsv)` (WUA COM download+install of selected KBs, runs on a BACKGROUND thread so a
  long install never stalls the heartbeat, re-scans + self-reports after) + on-demand `patch_scan`/`patch_install` job
  handling in `Worker.DrainJobs`. Portal: migration `2026-07-02_phase3_patch_install.sql` (appends `patch_scan`,`patch_install`
  to `jobs.job_type`); `agent.php` Patch card gains per-update checkboxes + "Install selected" (admin-only, KB-sanitized) +
  "Scan now". No automation, no auto-reboot (reboot stays a manual `restart` job) — the human click IS the approval; the
  actual WUA install is destructive so it's canary-validated, not locally testable. DEPLOY = import both patch migrations →
  upload portal files → ship the 1.3.0 templates → canary.
- **Phase 3 increment 2b — ring-rollout AUTOMATION (portal-only, NO agent change): BUILT + DEPLOYED + LIVE
  (2026-07-02).** Portal Phase 3 (all 3 migrations + files + `patch_rollout.php` cron) is deployed on live and
  `patch.enabled=true`; agent 1.3.0 is on the canary — the full patch subsystem is reachable in production. New
  server-only `patch_settings` policy key (ring `canary|early|broad|exclude` / `auto_approve[]`
  classifications / `reboot{policy,grace_min,prompt_user}`) inherited by the same engine as `thresholds`, stripped from the
  agent payload in `agent_policy.php` + excluded from the policy etag in `policy.php`. Migration
  `2026-07-02_phase3_patch_rollout.sql` (`patch_rollouts` + `patch_rollout_targets`). Engine = 1-min CLI cron
  `cron/patch_rollout.php`: per running rollout it enrolls each ring's in-scope agents as targets, then a per-target state
  machine (`pending→installing→installed→verified`, `failed` on error) that creates `patch_install` jobs ONLY for
  auto-approved KBs (from the agent's last scan matching `auto_approve`) and ONLY while the rollout is online + inside a
  maintenance window; reboots `if_required` in-window with grace; a ring auto-HALTs if `failure_pct ≥ max_failure_pct` and
  auto-advances to the next ring after the bake time (`advance_after_min`). Helpers live in `lib/patch.php`
  (`patch_default_settings`, `patch_settings_for_agent`, `patch_auto_approve_kbs`, `patch_ring_agents`, rollout list/get).
  UI = new admin-only `patches.php` (Rollouts card: create/start/pause/halt/delete + per-state progress; Patch policy card:
  ring / auto-approve / reboot per scope) with `patches_data.php` + `patches_action.php` (admin-only, CSRF), plus a Patches
  nav link. Validated locally: engine 16 assertions (happy path + window-gate + auto-halt + reboot) + UI 5 assertions.
  DEPLOY (portal-only) = import migration `2026-07-02_phase3_patch_rollout.sql` → upload portal files → add 1-min
  `cron/patch_rollout.php` cron.
- **Phase 3 increment 3 — patch ROLLBACK: BUILT + tested, NOT yet deployed (2026-07-02).** **Agent 1.4.0** adds
  `PatchManager.Rollback(kbCsv)` = best-effort per-KB uninstall (DISM `Remove-WindowsPackage` first, `wusa /uninstall`
  fallback) with HONEST per-KB reversibility — servicing-stack / feature / many cumulative updates can't be removed and
  come back `ok=false, reversible=false` (fix-forward stays primary); runs on the same BACKGROUND thread as install,
  re-scans + self-reports after. `Worker.DrainJobs` now handles `patch_rollback` alongside `patch_install`. Portal:
  migration `2026-07-02_phase3_patch_rollback.sql` (appends `patch_rollback` to `jobs.job_type`); `lib/patch.php` gains
  `patch_kb_sanitize` + `patch_installed_kbs_for_agent` (distinct KBs from this device's *done* `patch_install` jobs —
  both manual + rollout auto-installs); admin-only per-device rollback UI on `agent.php` (a "Roll back — updates Milepost
  installed here" details block with honest copy) + a rollout-level **Roll back ring** button on `patches.php` for
  halted/paused/completed rollouts (new `rollout_rollback` action in `patches_action.php`, admin-only, queues one
  `patch_rollback` job per installed target). **The auto-HALT (2b) IS the "automatic" safety** — literal auto-uninstall
  is impossible for many Windows updates, so the design = auto-halt on failure + fix-forward + manual best-effort rollback.
  Validated locally: migration chain clean; agent `dotnet build` 0/0; 6 real-endpoint/helper assertions (kb-sanitize,
  installed-KB lookup, admin per-device rollback + junk-KB drop, tech 403 blocked, rollout_rollback per-target). DEPLOY =
  import `2026-07-02_phase3_patch_rollback.sql` → upload portal files (lib/patch.php, agent.php, patches.php,
  patches_action.php) → ship the 1.4.0 templates → canary. **Phase 3 patch core (WU scan/install/ring-rollout/rollback)
  is now feature-complete. Next Phase-3 increment (recommended): third-party app patching via winget** (reuses the same
  job/install/ring machinery). Lower-priority Phase-3 fast-follows: per-client/site compliance report; software
  inventory + license tracking.
- **Phase 3 — third-party app patching via winget (scan/report + manual install): BUILT + tested, NOT yet deployed
  (2026-07-02).** **Agent 1.5.0** adds `WingetManager.cs`: resolves winget.exe from the per-machine WindowsApps
  `Microsoft.DesktopAppInstaller_*` folder (NOT on SYSTEM's PATH; the user PATH entry is a 0-byte alias that fails under
  LocalSystem), `Scan()` runs `winget upgrade --include-unknown --accept-source-agreements --disable-interactivity` and
  parses the fixed-width table by header column positions (stops at the "N upgrades available." summary so the
  "require explicit targeting" second table is excluded), `Install(idsCsv)` runs `winget upgrade --id <id> --exact
  --silent --accept-*` per Id on a BACKGROUND thread + re-scans. `Worker.DrainJobs` handles `winget_scan`
  (inline)/`winget_install` (background) + a `winget` heartbeat directive drives self-scans on a timer (mirrors the
  `patch` directive). **winget runs in MACHINE context (LocalSystem) → user-scoped installs may not appear** (honest
  limitation, surfaced in the UI copy). **English-locale table headers assumed.** Portal: migration
  `2026-07-02_phase3_winget.sql` (`agent_app_updates` table + appends `winget_scan`,`winget_install` to `jobs.job_type`);
  `lib/winget.php` (`winget_enabled`/`winget_scan_interval_hours`/`winget_id_sanitize`/`winget_upsert_status`/`winget_status`);
  `api/winget_report.php` (agent-bearer ingest, mirrors patch_report.php); `heartbeat.php` advertises the `winget`
  directive when enabled; `config.php` `winget{enabled:false,scan_interval_hours:12}` kill-switch; a **Third-party apps
  card on `agent.php`** (upgradable list + admin-only "Upgrade selected" + "Scan now"). Version bumped 1.4.0→1.5.0
  (csproj + Product.wxs + Worker fallback literal). VERIFIED: agent `dotnet build` 0/0; the fixed-width parser validated
  4/4 against a realistic `winget upgrade` sample (columns sliced, second table excluded, empty/no-header → []); migration
  chain clean; 6 endpoint/helper assertions (id-sanitize, upsert/status round-trip, tech winget_scan login-only, admin
  winget_install drops junk Ids, tech winget_install 403-blocked). 1.5.0 templates rebuilt; portal zip
  `Downloads/milepost-phase3-winget-portal.zip`. DEPLOY = import `2026-07-02_phase3_winget.sql` → upload portal files
  (lib/winget.php, api/winget_report.php, api/heartbeat.php, agent.php) → ship 1.5.0 templates → set `config.php`
  `winget.enabled=true`. **Scan-and-report + manual install only (no ring automation for winget yet)** — a future
  increment can fold winget into the ring rollout like WU.
- **Phase 3 — three portal-only fast-follows (winget-in-rollout + compliance report + software/licenses): BUILT +
  tested, NOT yet deployed (2026-07-02). NO agent change** (all reuse the 1.5.0 agent + existing inventory).
  **(A) winget folded into the ring rollout:** new server-only `patch_settings.winget` (`{auto_upgrade,include,exclude}`,
  default OFF, opt-in per policy, stripped from the agent payload + etag like the rest of patch_settings). Migration
  `2026-07-02_phase3_winget_rollout.sql` adds `patch_rollout_targets.app_list` + `winget_job_id`. `cron/patch_rollout.php`
  `patch_target_step` now runs a PARALLEL winget track: `pending` creates BOTH a `patch_install` (WU KBs) AND a
  `winget_install` (auto-upgrade app Ids from `winget_auto_upgrade_ids()`) job; `installing` waits for both; `installed`
  fails only if the SPECIFIC installed KBs/apps are still pending (intersection), else reboots (WU) → `verified`. Policy UI
  = a "Auto-upgrade third-party apps (winget)" toggle on the `patches.php` Patch-policy editor (persisted in
  `patches_action.php` `prule_save`). **(B) Patch-compliance report:** `patch_compliance_report()` in `lib/patch.php` (one
  aggregate query grouped by client→site over `agent_patch_status` + `agent_app_updates`, weighted avg compliance) rendered
  as a read-only card on `patches.php`. **(C) Software inventory + license tracking:** `lib/software.php`
  (`software_fleet_inventory()` aggregates installed apps across `inventory.data_json['software']` — already collected by
  the agent — with search; `licenses_list()` auto-counts license "seats used" by substring-matching `match_name` against
  fleet software) + new `software.php` page (Licenses card w/ admin CRUD + expiry flags; Software inventory card w/ search)
  + a Software nav link. Migration `2026-07-02_phase3_software_licenses.sql` (`software_licenses` table, FK `fk_swlic_user`).
  VERIFIED: migration chain clean; all files `php -l` clean; **A** engine 10 assertions (winget-ON drives both tracks
  pending→installing→installed→verified; winget-OFF creates 0 winget jobs) + prule_save winget round-trip; **B** 4
  aggregation asserts + 3 render asserts; **C** 5 aggregation asserts (fleet counts, versions, seats auto-count, search) +
  render/admin-save + tech-blocked. DEPLOY (portal-only) = import the 3 migrations (`…_winget_rollout`,
  `…_software_licenses`; the winget_rollout one ALTERs patch_rollout_targets) → upload changed portal files (cron/patch_rollout.php,
  lib/patch.php, lib/render.php, lib/software.php, public/patches.php, public/patches_action.php, public/software.php).
- **Phase 4 (Automation & Self-Healing) — STARTED on branch `phase-4-automation` (2026-07-03). NOTE: built on a git
  BRANCH (not main) — the user chose to "fork" for Phase 4 (feature branch off main; merge when stable). Phase 4 was
  chosen over Phase 5 after confirming Phase 4 was NOT already done (only groundwork existed: the jobs framework +
  unused `tool_actions`/`tool_invocations` tables + Phase-2 alert events; `agent/SelfHeal.cs` is agent-BINARY self-heal,
  NOT endpoint self-healing playbooks).** **Increment 1 = Script Library: BUILT + tested, NOT deployed.** Migration
  `2026-07-03_phase4_scripts.sql` (`scripts` table: name[unique]/description/language ENUM('powershell','cmd')/body/
  version/run_count/created_by; FK `fk_scripts_user`). `lib/scripts.php` = list/get/`script_save` (create or update with
  version++ + duplicate-name guard via uq_scripts_name catch + empty-body guard) / delete / `script_run_on_agent` (enqueue
  a job with the script's `language` as `job_type` + `body` as `payload`, run_count++ — reuses the existing agent
  JobRunner). New admin-only **`automation.php`** page (Script library card: table + CRUD editor w/ body textarea) + an
  **"Automation" nav link** in `render.php` (after Software). `agent.php` gains a "Run saved script" card (admin-only,
  `run_script` action → `script_run_on_agent`). VERIFIED (MariaDB 3307, temp config deleted): migration clean; **9
  CRUD/run assertions** (create/list/update+version-bump/dup-name-reject/empty-body-reject/run-creates-job-w-right-type+
  payload+agent/run_count++) + automation.php admin-save (count 1→2) + tech 403-blocked (stayed 2) + agent.php run_script
  admin (job created) + tech-blocked + render (Script library + saved script visible). Runs are POLL-queued (≤ heartbeat
  latency), single-device — RT-immediate + multi-device/scoped run are future polish.
  **Increment 2 = scheduled scripts: BUILT + tested, NOT deployed.** Migration `2026-07-03_phase4_scripts_schedules.sql`
  (`script_schedules`: script_id FK/scope(global/client/site/group/device)/recurrence ENUM('interval','daily','weekly')/
  at_time(UTC)/dow/interval_min/is_enabled/last_run_at; FKs `fk_sched_script`,`fk_sched_user`). `lib/scripts.php` +=
  `schedules_list`/`schedule_scope_agents`/`schedule_is_due` (interval since last_run; daily/weekly once/day when now
  passes at_time, weekly gated on dow; single catch-up if the cron was down)/`schedule_save`/`schedule_delete`/
  `schedule_set_enabled`, and `script_run_on_agent` now takes `?int $uid` (null = system run). New 1-min CLI cron
  **`cron/script_dispatch.php`** fires each enabled due schedule → one job per in-scope agent → stamps last_run_at. A
  Schedules card on `automation.php` (list + admin CRUD + on/off toggle; JS scope-target picker + recurrence field
  toggle). **MIGRATION-ORDER LESSON: name FK-child migrations so they sort AFTER the parent table** — `_script_schedules`
  sorted BEFORE `_scripts` (`_`<`s`) so the FK failed; renamed to `_scripts_schedules` (`.`<`_` puts `scripts.sql`
  first). VERIFIED: 12 unit asserts (is_due interval/daily/weekly due+not-due, save, scope, reject-missing-script) + cron
  fired a due schedule (1 job, last_run set) then did NOT re-fire + automation.php admin save & tech-block + render.
  DEPLOY adds a cPanel cron `* * * * * …/cron/script_dispatch.php`.
  **Increment 3 = event-driven automations + self-healing playbooks: BUILT + tested, NOT deployed.** Migration
  `2026-07-03_phase4_scripts_automations.sql` (`automations`: match_rule[substring on alert.rule_key]/match_severity/
  scope/script_id[action]/cooldown_min/max_per_day/is_enabled; `automation_runs`: automation_id/alert_id[BIGINT]/agent_id/
  job_id + `uq_autorun(automation_id,alert_id)` = fire-once-per-alert; FKs fk_autom_*/fk_autorun_*). `lib/automation.php` =
  `automation_enabled()` (config `automation.enabled`, DEFAULT-OFF master kill-switch), CRUD, `automation_matches($a,$alert)`
  (rule substring + severity + the alert agent's scope), `automation_playbook_templates()` (3 ready self-heal scripts:
  clear temp / flush DNS / restart Spooler). New 1-min CLI cron **`cron/automation_run.php`** (gated by automation_enabled;
  scans OPEN alerts [last 24h] × enabled automations → fires the script on the alerting device; guardrails: once-per-alert
  via uq_autorun, per-agent cooldown, per-automation daily cap). `automation.php` gains an **Automations card** (admin CRUD
  + on/off + gate-off notice) and a **Self-healing playbooks card** (one-click "Add to library" → script_save).
  `config.sample.php` gained the `automation` block. VERIFIED: migration clean; ~20 asserts — matcher 6 + engine
  end-to-end (fired on a disk alert, daily-cap stop, dedupe on re-run, cooldown blocked a 2nd alert, master-gate-off
  no-op) + playbook_add + admin save + tech-blocked + render. **Phase 4 core (script library → scheduled → event-driven/
  self-healing) is now feature-complete on branch `phase-4-automation`. Remaining: (4) AI-assisted scripting (Phase-9 AI
  track).** DEPLOY of the whole Phase-4 branch = merge → import the phase-4 migrations in filename order → upload portal
  files → add the `script_dispatch.php` + `automation_run.php` crons → (optionally, last) flip `automation.enabled`.
- **DEPLOYED + LIVE (2026-07-03):** the Phase 3 fast-follows (winget-in-rollout + patch-compliance report + software
  inventory/license tracking) AND Phase 4 core (script library → scheduled scripts → event-driven automations +
  self-healing playbooks) are on production — the **Automation** and **Software** nav links are confirmed live/working
  (so the 5 migrations imported + portal files uploaded). `main` @ merge `b86be17`. The **`config.php automation.enabled`
  master switch stays OFF** until automations are reviewed (nothing auto-runs a script until it's flipped on). Phase 4 was
  built on branch `phase-4-automation` (kept as a backup, fully merged into main). Remaining Phase 4: (4) AI-assisted
  scripting (Phase-9 AI track).
- Roadmap doc: `8 West IT/Milepost-Product-Roadmap.docx` (9 phases). Phase 3 = patch management (ring rollout + rollback).
- Deep project history, deploy specifics, and lessons live in the Claude memory files (`8west-rmm-project.md`).

<?php
/**
 * 8 West IT RMM — configuration sample.
 *
 * COPY this file to config.php and fill in real values.
 * Keep config.php OUT of the web root and OUT of git (.gitignore covers it).
 *
 * On HostGator: create the MySQL DB + user in cPanel > MySQL Databases,
 * then paste those credentials below.
 */

return [
    // --- Database (from cPanel > MySQL Databases) ---
    'db' => [
        'host' => 'localhost',
        'port' => '',                      // leave blank for default 3306
        'name' => 'youracct_8westrmm',     // cPanel prefixes with your account name
        'user' => 'youracct_rmm',
        'pass' => 'CHANGE_ME_STRONG_PASSWORD',
        'charset' => 'utf8mb4',
    ],

    // --- App ---
    // Random 64-char secret used to sign sessions / tokens. Generate with:
    //   php -r "echo bin2hex(random_bytes(32));"
    'app_secret' => 'CHANGE_ME_64_HEX_CHARS',

    // Public base URL of the portal (used in the agent installer config).
    'base_url' => 'https://support.8westit.com',

    // Require HTTPS for all portal + API traffic (leave true in production).
    'force_https' => true,

    // --- RustDesk self-hosted relay (your $5 VPS) ---
    // Agents are configured to use this relay; the portal deep-links into it.
    'rustdesk' => [
        'relay_host' => 'relay.8westit.com', // VPS hostname or IP running hbbs/hbbr
        'relay_key'  => 'PASTE_RUSTDESK_PUBLIC_KEY', // contents of id_ed25519.pub from the VPS
    ],

    // --- Milepost real-time backend integration (Phase 1) ---
    // Shared secret used to authenticate the VPS real-time backend <-> this portal.
    // MUST be byte-identical to the backend's MILEPOST_SERVICE_SECRET. Generate with:
    //   openssl rand -hex 32
    'service_secret'        => 'CHANGE_ME_64_HEX',
    'service_replay_window' => 300,                    // seconds; ±skew allowed on service calls

    'realtime' => [
        'enabled'      => true,
        'backend_url'  => 'https://rt.8westit.com',    // portal -> backend /internal/* base
        'agent_ws_url' => 'wss://rt.8westit.com/agent', // advertised to agents via enroll/heartbeat
        'dispatch_timeout_ms' => 4000,                 // portal HTTP timeout to the backend
    ],

    // --- Agent auto-update (self-update / targeted push) ---
    // HARD KILL-SWITCH — DEFAULT OFF. While 'enabled' is false OR 'target_version' is empty,
    // resolve_agent_update() returns null and ZERO agents ever receive an "update" directive.
    // Rollout: (1) upload the new agent-template-<variant>.msi to /installers; (2) set
    // 'enabled' => true and 'target_version' to that MSI's version; (3) it now reaches ONLY the
    // endpoints you CANARY — open an agent page and click "Update to latest" (sets that device's
    // target_version) for one/a few machines; verify they upgrade and reconnect; (4) to roll the
    // WHOLE fleet, set 'fleet_wide' => true (every endpoint below target_version then updates).
    // Version-INCREASE-ONLY: agents only update when target > current. Recovery from a bad build
    // is FIX-FORWARD (ship a higher good version) — there is no auto-rollback.
    //
    // EnrollKey PRESERVED across auto-update (was a caveat through 1.1.5; fixed + validated live 1.1.6 -> 1.1.7):
    //   agent_update.php still streams the UNPATCHED template MSI (download.php byte-patches the real
    //   64-hex key; the auto-update path cannot, or the SHA-256 the agent verifies would not match), so
    //   that MSI carries the build placeholder ('8WESTIT-ENROLLKEY-PLACEHOLDER-...') in its ENROLLKEY.
    //   But the installer no longer clobbers a real registry key with it: Product.wxs runs a
    //   RegistrySearch (Property EXISTINGENROLLKEY, Bitness="always64" so it reads the 64-bit hive that
    //   cmpRegConfig writes) plus a SetProperty that overrides ENROLLKEY with the existing key ONLY when
    //   the incoming key is the placeholder. So a MajorUpgrade keeps HKLM\SOFTWARE\8WestIT\Agent\EnrollKey
    //   intact, while fresh installs from download.php (real byte-patched key) and
    //   `msiexec ENROLLKEY=<real>` are unaffected (their key != placeholder). On the normal path nothing
    //   re-enrolls anyway: identity + bearer token live in state.json (untouched by the MSI). And it is now
    //   SAFE to reset/revoke an auto-updated agent's token — on the next HTTP 401 it clears the token and
    //   re-enrolls with its real registry EnrollKey, which passes enroll.php's /^[a-f0-9]{64}$/i check (no
    //   manual reinstall from download.php needed).
    'agent_update' => [
        'enabled'        => false,  // master gate; false = nobody updates
        'target_version' => '',     // version of the uploaded template, e.g. '1.1.2'; empty = nobody updates
        'variant'        => 'lite', // 'lite' | 'full' — which template MSI agents pull
        'fleet_wide'     => false,  // false = CANARY (only endpoints opted in via the agent page);
                                    // true = roll out to EVERY endpoint below target_version
    ],

    // --- Telemetry / time-series store (Phase 2) ---
    // Retention windows for the metric history maintained by the cron (cron/metrics_rollup.php,
    // run e.g. every 15 min). This whole block is OPTIONAL — the defaults below apply when it is
    // absent. The values MUST satisfy raw_days < hour_days < day_days; the cron refuses to run on
    // an inverted config so it can never prune an aggregate before the tier that feeds it.
    'telemetry' => [
        'raw_days'  => 14,   // 1/min raw samples (powers the 6h/24h/7d device charts)
        'hour_days' => 90,   // hourly rollups
        'day_days'  => 730,  // daily rollups (~2 years)
    ],

    // --- Smart alerting (Phase 2, Step 3) ---
    // HARD KILL-SWITCH — DEFAULT OFF. While 'enabled' is false, metrics_snapshot.php evaluates NO
    // thresholds and the dispatch cron sends nothing, so importing the alerting migration + shipping
    // the code is a no-op until you flip this (mirrors realtime.enabled / agent_update.enabled).
    //
    // Thresholds themselves are NOT here — they live in policy docs (Alerts > Rules in the UI),
    // inherited Client->Site->Group->Device by lib/policy.php, with a built-in floor in lib/alerts.php
    // so alerting works out-of-the-box. This block is only the DELIVERY + evaluation switch.
    //
    // Channels for this step: in-app (always, when enabled) + email. Leave 'email_to' empty for
    // in-app-only (alerts still open/resolve and show in the portal; no mail is queued). When
    // 'smtp.host' is empty the mailer falls back to PHP mail() (fine for a quick start; real SMTP
    // creds deliver far more reliably from HostGator). Add the 1-min cron for near-real-time MTTA:
    //   * * * * * /usr/local/bin/php /home/qygiabte/public_html/8westit/cron/alerts_dispatch.php
    'alerts' => [
        'enabled'   => false,                       // master gate; false = no evaluation, no delivery
        'email_to'  => [],                          // recipients, e.g. ['ops@8westit.com']; [] = in-app only
        'from'      => 'milepost-alerts@8westit.com',
        'from_name' => 'Milepost Alerts',
        'smtp' => [
            'host'   => '',        // e.g. 'mail.8westit.com'; empty = use PHP mail()
            'port'   => 587,       // 587 for STARTTLS, 465 for implicit SSL, 25 for none
            'secure' => 'tls',     // 'tls' (STARTTLS) | 'ssl' (implicit) | '' (none)
            'user'   => '',
            'pass'   => '',
        ],
        'max_attempts'      => 5,   // per-delivery send attempts before it is marked failed
        'offline_after_min' => 10,  // default: alert when an agent has not been seen for this long

        // Optional outbound webhooks — the dispatch cron POSTs one notification per target for each
        // alert event, alongside email. Each entry: type 'slack'|'discord'|'generic' with an https
        // 'url', OR type 'telegram' with a bot 'token' + 'chat_id'. URLs MUST be https. [] disables.
        'webhooks' => [
            // ['type' => 'slack',    'url'   => 'https://hooks.slack.com/services/XXX/YYY/ZZZ'],
            // ['type' => 'discord',  'url'   => 'https://discord.com/api/webhooks/XXX/YYY'],
            // ['type' => 'telegram', 'token' => '123456:ABC-DEF...', 'chat_id' => '-1001234567890'],
            // ['type' => 'generic',  'url'   => 'https://example.com/milepost-hook'],
        ],
    ],

    // --- Patch management (Phase 3) ---
    // HARD KILL-SWITCH — DEFAULT OFF. While false, supporting agents do NOT scan/report and the
    // portal shows no patch data. The MVP is scan-and-report only (Windows Update, no installs yet).
    // Flip on, then patch visibility populates as scanning-capable agents report in.
    'patch' => [
        'enabled'             => false,  // master gate
        'scan_interval_hours' => 8,      // how often a supporting agent runs a Windows Update scan
    ],

    // --- Third-party app patching (Phase 3, winget) ---
    // HARD KILL-SWITCH — DEFAULT OFF. While false, supporting agents do NOT run `winget upgrade` and
    // the device page shows no third-party app data. Flip on and a 1.5.0+ agent reports available
    // application upgrades; admins can then queue winget_install jobs from the device page. NOTE:
    // winget runs in MACHINE context (the agent is LocalSystem) — user-scoped installs may not appear.
    'winget' => [
        'enabled'             => false,  // master gate
        'scan_interval_hours' => 12,     // how often a supporting agent runs `winget upgrade`
    ],

    // --- Automation & self-healing (Phase 4) ---
    // HARD KILL-SWITCH — DEFAULT OFF. While false, cron/automation_run.php does nothing: an OPEN alert
    // never auto-runs a script. Turn on ONLY after you've reviewed your automations (they run scripts
    // as SYSTEM on the alerting device). Per-automation is_enabled + cooldown + daily-cap add further
    // guardrails. Add the 1-min cron: * * * * * …/cron/automation_run.php
    'automation' => [
        'enabled' => false,  // master gate for event-driven automations / self-healing
    ],
];

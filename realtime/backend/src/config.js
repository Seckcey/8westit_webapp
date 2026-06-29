'use strict';

/**
 * Configuration loader. Config is STRICTLY via environment variables (.env).
 * `.env` is loaded by `loadDotEnv()` (a tiny zero-dependency parser) before
 * this module reads `process.env`, so no `dotenv` package is required.
 */

const fs = require('fs');
const path = require('path');

/**
 * Minimal .env parser (no dependency). Lines like KEY=VALUE; ignores blanks
 * and `#` comments. Does NOT overwrite an already-set process.env value, so
 * real environment / container env wins over the file (12-factor friendly).
 * @param {string} [file] path to the .env file
 */
function loadDotEnv(file) {
  const target = file || path.join(__dirname, '..', '.env');
  let text;
  try {
    text = fs.readFileSync(target, 'utf8');
  } catch (e) {
    return; // No .env file: rely on real environment.
  }
  for (const rawLine of text.split(/\r?\n/)) {
    const line = rawLine.trim();
    if (!line || line.startsWith('#')) continue;
    const eq = line.indexOf('=');
    if (eq === -1) continue;
    const key = line.slice(0, eq).trim();
    let val = line.slice(eq + 1).trim();
    // Strip a single layer of matching quotes.
    if (
      (val.startsWith('"') && val.endsWith('"')) ||
      (val.startsWith("'") && val.endsWith("'"))
    ) {
      val = val.slice(1, -1);
    }
    if (!(key in process.env)) process.env[key] = val;
  }
}

function intEnv(key, def) {
  const v = process.env[key];
  if (v === undefined || v === '') return def;
  const n = parseInt(v, 10);
  return Number.isFinite(n) ? n : def;
}

function strEnv(key, def) {
  const v = process.env[key];
  return v === undefined || v === '' ? def : v;
}

/**
 * Build the validated config object from process.env.
 * @returns {object} frozen config
 */
function buildConfig() {
  const cfg = {
    PORT: intEnv('PORT', 8090),
    BIND: strEnv('BIND', '127.0.0.1'),
    WS_PATH: strEnv('WS_PATH', '/agent'),

    PORTAL_BASE_URL: strEnv('PORTAL_BASE_URL', '').replace(/\/+$/, ''),
    MILEPOST_SERVICE_SECRET: strEnv('MILEPOST_SERVICE_SECRET', ''),
    SERVICE_REPLAY_WINDOW_SECS: intEnv('SERVICE_REPLAY_WINDOW_SECS', 300),

    PING_INTERVAL_SECS: intEnv('PING_INTERVAL_SECS', 30),
    HELLO_TIMEOUT_SECS: intEnv('HELLO_TIMEOUT_SECS', 10),
    DEFAULT_METRICS_INTERVAL_SECS: intEnv('DEFAULT_METRICS_INTERVAL_SECS', 60),
    WATCHING_METRICS_INTERVAL_SECS: intEnv('WATCHING_METRICS_INTERVAL_SECS', 15),
    VERIFY_CACHE_TTL_SECS: intEnv('VERIFY_CACHE_TTL_SECS', 300),
    METRICS_SNAPSHOT_THROTTLE_SECS: intEnv('METRICS_SNAPSHOT_THROTTLE_SECS', 60),
    PORTAL_MAX_WRITE_RPS: intEnv('PORTAL_MAX_WRITE_RPS', 5),
    WS_BUFFER_HIGH_WATER: intEnv('WS_BUFFER_HIGH_WATER', 1048576),
    MAX_WS_PAYLOAD_BYTES: intEnv('MAX_WS_PAYLOAD_BYTES', 262144),
    RESULT_FALLBACK_GRACE_SECS: intEnv('RESULT_FALLBACK_GRACE_SECS', 30),

    SQLITE_PATH: strEnv('SQLITE_PATH', ''),
    LOG_LEVEL: strEnv('LOG_LEVEL', 'info'),
  };
  return Object.freeze(cfg);
}

/**
 * Validate required config. Throws on fatal misconfiguration so the process
 * fails fast at startup rather than mis-authenticating later.
 * @param {object} cfg
 */
function validateConfig(cfg) {
  const errs = [];
  if (!cfg.PORTAL_BASE_URL) errs.push('PORTAL_BASE_URL is required');
  if (!cfg.MILEPOST_SERVICE_SECRET) {
    errs.push('MILEPOST_SERVICE_SECRET is required');
  } else if (cfg.MILEPOST_SERVICE_SECRET === 'CHANGE_ME_64_HEX') {
    errs.push('MILEPOST_SERVICE_SECRET still has its placeholder value');
  } else if (!/^[0-9a-fA-F]{64}$/.test(cfg.MILEPOST_SERVICE_SECRET)) {
    errs.push('MILEPOST_SERVICE_SECRET must be 64 hex chars (openssl rand -hex 32)');
  }
  if (errs.length) {
    throw new Error('Invalid configuration:\n  - ' + errs.join('\n  - '));
  }
}

module.exports = { loadDotEnv, buildConfig, validateConfig };

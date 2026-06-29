'use strict';

/**
 * Structured (JSON-line) logger. Zero dependencies.
 *
 * SECURITY: never log raw bearer tokens or the service secret. Helpers below
 * never serialize a `token`/`secret` field; callers must pass a token HASH or a
 * short prefix if any token reference is needed.
 */

const LEVELS = { error: 0, warn: 1, info: 2, debug: 3 };

/** Fields that must never be emitted, even if a caller passes them. */
const REDACT_KEYS = new Set([
  'token',
  'raw_token',
  'bearer',
  'secret',
  'service_secret',
  'MILEPOST_SERVICE_SECRET',
  'authorization',
  'password',
  'rustdesk_pass',
]);

function redact(obj) {
  if (obj == null || typeof obj !== 'object') return obj;
  const out = Array.isArray(obj) ? [] : {};
  for (const [k, v] of Object.entries(obj)) {
    if (REDACT_KEYS.has(k)) {
      out[k] = '[redacted]';
    } else if (v && typeof v === 'object') {
      out[k] = redact(v);
    } else {
      out[k] = v;
    }
  }
  return out;
}

function createLogger(level) {
  const threshold = LEVELS[level] !== undefined ? LEVELS[level] : LEVELS.info;

  function emit(lvl, msg, fields) {
    if (LEVELS[lvl] > threshold) return;
    const rec = {
      ts: new Date().toISOString(),
      level: lvl,
      msg,
      ...(fields ? redact(fields) : {}),
    };
    const line = JSON.stringify(rec);
    if (lvl === 'error' || lvl === 'warn') {
      process.stderr.write(line + '\n');
    } else {
      process.stdout.write(line + '\n');
    }
  }

  return {
    error: (msg, fields) => emit('error', msg, fields),
    warn: (msg, fields) => emit('warn', msg, fields),
    info: (msg, fields) => emit('info', msg, fields),
    debug: (msg, fields) => emit('debug', msg, fields),
    level,
  };
}

module.exports = { createLogger, redact };

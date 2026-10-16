-- lqcron schéma verze 1 (kontrakt docs/v4-contract.md § 2). Jediný zdroj:
-- Rust ho vkládá přes include_str!, PHP testy z něj zakládají testovací databázi.
-- Jen SQL kompatibilní se SQLite 3.24 (plán § 12.1).
CREATE TABLE IF NOT EXISTS meta (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS crons (
  code                 TEXT PRIMARY KEY,
  handler_class        TEXT NOT NULL,
  name                 TEXT,
  description          TEXT,
  schedule             TEXT,
  timeout              INTEGER,
  repeat_count         INTEGER NOT NULL DEFAULT 0,
  concurrency_mode     TEXT NOT NULL DEFAULT 'exclusive',
  max_queue_size       INTEGER,
  resources            TEXT NOT NULL DEFAULT '[]',
  after_code           TEXT,
  missed               TEXT NOT NULL DEFAULT 'skip',
  missed_grace_minutes INTEGER NOT NULL DEFAULT 5,
  defined              INTEGER NOT NULL DEFAULT 1,
  local_active         INTEGER NOT NULL DEFAULT 1,
  remote_active        INTEGER NOT NULL DEFAULT 1,
  last_scheduled_for   INTEGER,
  updated_at           INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS jobs (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  uuid          TEXT NOT NULL UNIQUE,
  origin_uuid   TEXT NOT NULL,
  cron_code     TEXT NOT NULL,
  arguments     TEXT,
  attempt       INTEGER NOT NULL DEFAULT 1,
  source        TEXT NOT NULL,
  scheduled_for INTEGER,
  available_at  INTEGER NOT NULL,
  created_at    INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS jobs_cron ON jobs (cron_code);
CREATE INDEX IF NOT EXISTS jobs_available ON jobs (available_at);
CREATE TABLE IF NOT EXISTS runs (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  uuid             TEXT NOT NULL UNIQUE,
  job_uuid         TEXT NOT NULL,
  origin_uuid      TEXT NOT NULL,
  cron_code        TEXT NOT NULL,
  attempt          INTEGER NOT NULL,
  max_attempts     INTEGER NOT NULL,
  source           TEXT NOT NULL,
  scheduled_for    INTEGER,
  job_created_at   INTEGER NOT NULL,
  timeout          INTEGER,
  concurrency_mode TEXT NOT NULL,
  resources        TEXT NOT NULL DEFAULT '[]',
  state            TEXT NOT NULL,
  stop_reason      TEXT,
  claimed_at       INTEGER NOT NULL,
  supervisor_pid   INTEGER,
  supervisor_start INTEGER,
  pid              INTEGER,
  pid_start        INTEGER,
  app_path         TEXT,
  started_at       INTEGER,
  finished_at      INTEGER,
  heartbeat_at     INTEGER,
  exit_code        INTEGER,
  signal           INTEGER,
  max_rss_kb       INTEGER,
  result           TEXT,
  error            TEXT,
  final            INTEGER,
  shadow           INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS runs_state ON runs (state);
CREATE INDEX IF NOT EXISTS runs_cron ON runs (cron_code);
CREATE TABLE IF NOT EXISTS run_events (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  run_uuid   TEXT NOT NULL,
  type       TEXT NOT NULL,
  payload    TEXT,
  created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS run_events_run ON run_events (run_uuid);
CREATE TABLE IF NOT EXISTS outbox (
  seq          INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id     TEXT NOT NULL UNIQUE,
  type         TEXT NOT NULL,
  run_uuid     TEXT,
  payload      BLOB NOT NULL,
  encoding     TEXT NOT NULL DEFAULT 'json',
  occurred_at  INTEGER NOT NULL,
  created_at   INTEGER NOT NULL,
  attempts     INTEGER NOT NULL DEFAULT 0,
  poisoned_at  INTEGER,
  last_tried_at INTEGER
);
CREATE INDEX IF NOT EXISTS outbox_run ON outbox (run_uuid);
CREATE TABLE IF NOT EXISTS outbox_rejected (
  seq         INTEGER PRIMARY KEY,
  event_id    TEXT NOT NULL UNIQUE,
  type        TEXT NOT NULL,
  run_uuid    TEXT,
  payload     BLOB NOT NULL,
  encoding    TEXT NOT NULL DEFAULT 'json',
  occurred_at INTEGER NOT NULL,
  created_at  INTEGER NOT NULL,
  rejected_at INTEGER NOT NULL,
  reason      TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS commands (
  command_id TEXT PRIMARY KEY,
  seq        INTEGER NOT NULL,
  type       TEXT NOT NULL,
  payload    TEXT NOT NULL,
  issued_at  INTEGER NOT NULL,
  expires_at INTEGER,
  fetched_at INTEGER NOT NULL,
  applied_at INTEGER,
  result     TEXT
);
CREATE TABLE IF NOT EXISTS services (
  name           TEXT PRIMARY KEY,
  mode           TEXT NOT NULL,
  pid            INTEGER,
  pid_start      INTEGER,
  app_path       TEXT,
  started_at     INTEGER,
  restarts       INTEGER NOT NULL DEFAULT 0,
  last_exit_at   INTEGER,
  last_exit_code INTEGER,
  backoff_until  INTEGER
);

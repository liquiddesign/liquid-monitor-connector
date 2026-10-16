//! Řádky tabulek `crons` a `runs` + pomocníci nad nimi.

use rusqlite::{Connection, OptionalExtension, Row};
use serde_json::{Value, json};

use crate::util::{Res, iso_opt};

pub const ACTIVE_STATES: [&str; 4] = ["claimed", "starting", "running", "stopping"];
pub const ACTIVE_SQL: &str = "('claimed','starting','running','stopping')";

/// Výchozí `max_queue_size` (plán § 6.2).
pub const DEFAULT_MAX_QUEUE: i64 = 10;
/// Výchozí timeout v sekundách (plán § 7.2).
pub const DEFAULT_TIMEOUT: i64 = 1000;

#[derive(Debug, Clone)]
#[allow(dead_code)] // zrcadlí tabulku `crons`
pub struct CronRow {
    pub code: String,
    pub handler_class: String,
    pub schedule: Option<String>,
    pub timeout: Option<i64>,
    pub repeat_count: i64,
    pub concurrency_mode: String,
    pub max_queue_size: Option<i64>,
    pub resources: Vec<String>,
    pub after_code: Option<String>,
    pub missed: String,
    pub missed_grace_minutes: i64,
    pub defined: bool,
    pub local_active: bool,
    pub remote_active: bool,
    pub last_scheduled_for: Option<i64>,
}

impl CronRow {
    pub fn active(&self) -> bool {
        self.defined && self.local_active && self.remote_active
    }

    pub fn effective_timeout(&self) -> i64 {
        self.timeout.unwrap_or(DEFAULT_TIMEOUT)
    }

    pub fn max_queue(&self) -> i64 {
        self.max_queue_size.unwrap_or(DEFAULT_MAX_QUEUE)
    }
}

pub fn parse_list(s: &str) -> Vec<String> {
    serde_json::from_str::<Vec<String>>(s).unwrap_or_default()
}

const CRON_COLS: &str = "code, handler_class, schedule, timeout, repeat_count, concurrency_mode, max_queue_size, resources, after_code, missed, missed_grace_minutes, defined, local_active, remote_active, last_scheduled_for";

fn cron_from_row(r: &Row) -> rusqlite::Result<CronRow> {
    Ok(CronRow {
        code: r.get(0)?,
        handler_class: r.get(1)?,
        schedule: r.get(2)?,
        timeout: r.get(3)?,
        repeat_count: r.get(4)?,
        concurrency_mode: r.get(5)?,
        max_queue_size: r.get(6)?,
        resources: parse_list(&r.get::<_, String>(7)?),
        after_code: r.get(8)?,
        missed: r.get(9)?,
        missed_grace_minutes: r.get(10)?,
        defined: r.get::<_, i64>(11)? != 0,
        local_active: r.get::<_, i64>(12)? != 0,
        remote_active: r.get::<_, i64>(13)? != 0,
        last_scheduled_for: r.get(14)?,
    })
}

pub fn load_crons(conn: &Connection) -> Res<Vec<CronRow>> {
    let mut st = conn.prepare(&format!("SELECT {CRON_COLS} FROM crons ORDER BY code"))?;
    let rows = st
        .query_map([], cron_from_row)?
        .collect::<Result<Vec<_>, _>>()?;
    Ok(rows)
}

pub fn load_cron(conn: &Connection, code: &str) -> Res<Option<CronRow>> {
    Ok(conn
        .query_row(
            &format!("SELECT {CRON_COLS} FROM crons WHERE code = ?1"),
            [code],
            cron_from_row,
        )
        .optional()?)
}

#[derive(Debug, Clone)]
#[allow(dead_code)] // zrcadlí tabulku `runs`
pub struct RunRow {
    pub id: i64,
    pub uuid: String,
    pub job_uuid: String,
    pub origin_uuid: String,
    pub cron_code: String,
    pub attempt: i64,
    pub max_attempts: i64,
    pub source: String,
    pub scheduled_for: Option<i64>,
    pub job_created_at: i64,
    pub timeout: Option<i64>,
    pub concurrency_mode: String,
    pub resources: Vec<String>,
    pub state: String,
    pub stop_reason: Option<String>,
    pub claimed_at: i64,
    pub supervisor_pid: Option<i64>,
    pub supervisor_start: Option<i64>,
    pub pid: Option<i64>,
    pub pid_start: Option<i64>,
    pub app_path: Option<String>,
    pub started_at: Option<i64>,
    pub finished_at: Option<i64>,
    pub heartbeat_at: Option<i64>,
    /// v2: skupina procesů PHP (zapisuje hlídač), čas startu jejího leadera a boot
    pub pgid: Option<i64>,
    pub pgid_start: Option<i64>,
    pub boot_id: Option<String>,
}

const RUN_COLS: &str = "id, uuid, job_uuid, origin_uuid, cron_code, attempt, max_attempts, source, scheduled_for, job_created_at, timeout, concurrency_mode, resources, state, stop_reason, claimed_at, supervisor_pid, supervisor_start, pid, pid_start, app_path, started_at, finished_at, heartbeat_at, pgid, pgid_start, boot_id";

fn run_from_row(r: &Row) -> rusqlite::Result<RunRow> {
    Ok(RunRow {
        id: r.get(0)?,
        uuid: r.get(1)?,
        job_uuid: r.get(2)?,
        origin_uuid: r.get(3)?,
        cron_code: r.get(4)?,
        attempt: r.get(5)?,
        max_attempts: r.get(6)?,
        source: r.get(7)?,
        scheduled_for: r.get(8)?,
        job_created_at: r.get(9)?,
        timeout: r.get(10)?,
        concurrency_mode: r.get(11)?,
        resources: parse_list(&r.get::<_, String>(12)?),
        state: r.get(13)?,
        stop_reason: r.get(14)?,
        claimed_at: r.get(15)?,
        supervisor_pid: r.get(16)?,
        supervisor_start: r.get(17)?,
        pid: r.get(18)?,
        pid_start: r.get(19)?,
        app_path: r.get(20)?,
        started_at: r.get(21)?,
        finished_at: r.get(22)?,
        heartbeat_at: r.get(23)?,
        pgid: r.get(24)?,
        pgid_start: r.get(25)?,
        boot_id: r.get(26)?,
    })
}

pub fn load_run(conn: &Connection, uuid: &str) -> Res<Option<RunRow>> {
    Ok(conn
        .query_row(
            &format!("SELECT {RUN_COLS} FROM runs WHERE uuid = ?1"),
            [uuid],
            run_from_row,
        )
        .optional()?)
}

pub fn active_runs(conn: &Connection) -> Res<Vec<RunRow>> {
    let mut st = conn.prepare(&format!(
        "SELECT {RUN_COLS} FROM runs WHERE state IN {ACTIVE_SQL} ORDER BY id"
    ))?;
    let rows = st
        .query_map([], run_from_row)?
        .collect::<Result<Vec<_>, _>>()?;
    Ok(rows)
}

pub fn latest_run_for_cron(conn: &Connection, code: &str) -> Res<Option<RunRow>> {
    Ok(conn
        .query_row(
            &format!("SELECT {RUN_COLS} FROM runs WHERE cron_code = ?1 ORDER BY id DESC LIMIT 1"),
            [code],
            run_from_row,
        )
        .optional()?)
}

/// Společná část payloadu `run.*` (kontrakt § 5.2).
pub fn run_common_payload(run: &RunRow) -> Value {
    json!({
        "run_uuid": run.uuid,
        "job_uuid": run.job_uuid,
        "origin_uuid": run.origin_uuid,
        "cron_code": run.cron_code,
        "attempt": run.attempt,
        "max_attempts": run.max_attempts,
        "source": run.source,
        "scheduled_for": iso_opt(run.scheduled_for),
        "app_path": run.app_path,
    })
}

pub fn merge(mut base: Value, extra: Value) -> Value {
    if let (Some(b), Value::Object(e)) = (base.as_object_mut(), extra) {
        for (k, v) in e {
            b.insert(k, v);
        }
    }
    base
}

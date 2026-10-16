//! Snímek `scheduler.heartbeat` (kontrakt § 5.4) — mimo outbox, jeden řádek v `meta`.

use rusqlite::Connection;
use serde_json::{Value, json};

use crate::db::{SCHEMA_VERSION, meta_i64, meta_json, meta_str};
use crate::model::ACTIVE_SQL;
use crate::pause::Pause;
use crate::util::{Res, VERSION, hostname, iso, iso_opt, now_ms};

pub struct OutboxStats {
    pub size: i64,
    pub oldest: Option<i64>,
    pub rejected: i64,
    pub poisoned: i64,
}

pub fn outbox_stats(conn: &Connection) -> Res<OutboxStats> {
    let (size, oldest): (i64, Option<i64>) =
        conn.query_row("SELECT COUNT(*), MIN(created_at) FROM outbox", [], |r| {
            Ok((r.get(0)?, r.get(1)?))
        })?;
    let rejected: i64 = conn.query_row("SELECT COUNT(*) FROM outbox_rejected", [], |r| r.get(0))?;
    let poisoned: i64 = conn.query_row(
        "SELECT COUNT(*) FROM outbox WHERE poisoned_at IS NOT NULL",
        [],
        |r| r.get(0),
    )?;
    Ok(OutboxStats {
        size,
        oldest,
        rejected,
        poisoned,
    })
}

/// Přepíše počty outboxu v uloženém snímku (deliver je přikládá aktuální).
pub fn refresh_outbox(hb: &mut Value, conn: &Connection) -> Res<()> {
    let s = outbox_stats(conn)?;
    if let Some(o) = hb.as_object_mut() {
        o.insert("outbox_size".into(), json!(s.size));
        o.insert("oldest_outbox_at".into(), iso_opt(s.oldest));
        o.insert("rejected_count".into(), json!(s.rejected));
        o.insert("poisoned_count".into(), json!(s.poisoned));
    }
    Ok(())
}

pub fn build(conn: &Connection, mode: &str, clock_skew_seconds: i64) -> Res<Value> {
    let queue_length: i64 = conn.query_row("SELECT COUNT(*) FROM jobs", [], |r| r.get(0))?;
    let running: Vec<String> = conn
        .prepare(&format!(
            "SELECT uuid FROM runs WHERE state IN {ACTIVE_SQL} ORDER BY id"
        ))?
        .query_map([], |r| r.get(0))?
        .collect::<Result<_, _>>()?;
    let s = outbox_stats(conn)?;
    let starving = meta_json(conn, "starving")?.unwrap_or(json!([]));
    let export_error = meta_json(conn, "export_error")?;
    Ok(json!({
        "instance_id": meta_str(conn, "instance_id")?,
        "hostname": hostname(),
        "version": VERSION,
        "schema_version": SCHEMA_VERSION,
        "mode": mode,
        "generated_at": iso(now_ms()),
        "last_tick_at": iso_opt(meta_i64(conn, "last_tick_at")?),
        "queue_length": queue_length,
        "running": running,
        "outbox_size": s.size,
        "oldest_outbox_at": iso_opt(s.oldest),
        "rejected_count": s.rejected,
        "poisoned_count": s.poisoned,
        "paused": Pause::load(conn)?.to_json(),
        "starving": starving,
        "clock_skew_seconds": clock_skew_seconds,
        "tick_refused": meta_str(conn, "tick_refused")?,
        "export_error": export_error,
    }))
}

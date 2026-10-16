//! Aplikace exportu definic do tabulky `crons` + `definitions.sync` (plán § 5.2).

use rusqlite::{Connection, params};
use serde_json::{Value, json};

use crate::db::{immediate, meta_get, meta_set};
use crate::export::Export;
use crate::model::load_crons;
use crate::outbox;
use crate::util::{Res, now_ms};

/// Aplikuje export, pokud se změnil hash nebo režim. Vrací `true`, když něco změnil.
pub fn apply_export(conn: &Connection, ex: &Export) -> Res<bool> {
    let tx = immediate(conn)?;
    let same_hash =
        meta_get(&tx, "export_hash")?.as_deref() == Some(ex.hash.as_str()) && !ex.hash.is_empty();
    let same_mode = meta_get(&tx, "export_mode")?.as_deref() == Some(ex.mode());
    if same_hash && same_mode {
        return Ok(false);
    }
    let now = now_ms();
    tx.execute("UPDATE crons SET defined = 0, updated_at = ?1", [now])?;
    for c in &ex.crons {
        let mode = c
            .concurrency_mode
            .clone()
            .unwrap_or_else(|| "exclusive".into());
        let resources = serde_json::to_string(&c.resources)?;
        tx.execute(
            "INSERT INTO crons (code, handler_class, name, description, schedule, timeout, repeat_count, concurrency_mode,
                                max_queue_size, resources, after_code, missed, missed_grace_minutes, defined, updated_at)
             VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?12, ?13, 1, ?14)
             ON CONFLICT(code) DO UPDATE SET
                handler_class = excluded.handler_class, name = excluded.name, description = excluded.description,
                schedule = excluded.schedule, timeout = excluded.timeout, repeat_count = excluded.repeat_count,
                concurrency_mode = excluded.concurrency_mode, max_queue_size = excluded.max_queue_size,
                resources = excluded.resources, after_code = excluded.after_code, missed = excluded.missed,
                missed_grace_minutes = excluded.missed_grace_minutes, defined = 1, updated_at = excluded.updated_at",
            params![
                c.code,
                c.handler_class,
                c.name,
                c.description,
                c.schedule,
                c.timeout,
                c.repeat_count.max(0),
                mode,
                c.max_queue_size,
                resources,
                c.after,
                c.missed.clone().unwrap_or_else(|| "skip".into()),
                c.missed_grace_minutes.unwrap_or(5),
                now
            ],
        )?;
    }
    let payload = sync_payload(&tx, ex)?;
    outbox::push(&tx, "definitions.sync", None, payload, now)?;
    meta_set(&tx, "export_hash", &ex.hash)?;
    meta_set(&tx, "export_at", &now.to_string())?;
    meta_set(&tx, "export_mode", ex.mode())?;
    meta_set(&tx, "export_error", "")?;
    tx.commit()?;
    Ok(true)
}

/// Payload `definitions.sync` (kontrakt § 5.2) z exportu + aktuálních příznaků `local_active`
/// / `remote_active` v `crons`.
pub fn sync_payload(conn: &Connection, ex: &Export) -> Res<Value> {
    let flags: std::collections::HashMap<String, (bool, bool)> = load_crons(conn)?
        .into_iter()
        .map(|c| (c.code, (c.local_active, c.remote_active)))
        .collect();
    let crons: Vec<Value> = ex
        .crons_raw
        .iter()
        .map(|raw| {
            let mut v = raw.clone();
            let code = v
                .get("code")
                .and_then(Value::as_str)
                .unwrap_or("")
                .to_string();
            let (l, r) = flags.get(&code).copied().unwrap_or((true, true));
            if let Some(o) = v.as_object_mut() {
                o.insert("local_active".into(), Value::Bool(l));
                o.insert("remote_active".into(), Value::Bool(r));
            }
            v
        })
        .collect();
    Ok(json!({
        "mode": ex.mode(),
        "hash": ex.hash,
        "environment": ex.config.environment,
        "timezone": ex.config.timezone,
        "connector_version": ex.connector_version,
        "crons": crons,
    }))
}

/// Payload `definitions.sync` jen z databáze (aplikovaná sada v `crons` + `meta.export_*`),
/// když export po ruce není.
pub fn sync_payload_from_db(conn: &Connection) -> Res<Value> {
    let crons: Vec<Value> = load_crons(conn)?
        .into_iter()
        .filter(|c| c.defined)
        .map(|c| {
            json!({
                "code": c.code,
                "handler_class": c.handler_class,
                "schedule": c.schedule,
                "timeout": c.timeout,
                "repeat_count": c.repeat_count,
                "concurrency_mode": c.concurrency_mode,
                "max_queue_size": c.max_queue_size,
                "resources": c.resources,
                "after": c.after_code,
                "missed": c.missed,
                "missed_grace_minutes": c.missed_grace_minutes,
                "local_active": c.local_active,
                "remote_active": c.remote_active,
            })
        })
        .collect();
    Ok(json!({
        "mode": meta_get(conn, "export_mode")?.filter(|m| !m.is_empty()).unwrap_or_else(|| "local".into()),
        "hash": meta_get(conn, "export_hash")?.unwrap_or_default(),
        "environment": null,
        "timezone": null,
        "connector_version": null,
        "crons": crons,
    }))
}

/// Selhaný nebo nečitelný export: `meta.export_error` + `definitions.failed`. Stejná
/// chyba se hlásí jen jednou (tick i `sync` běží každou chvíli), nová znovu.
pub fn record_export_failure(
    conn: &Connection,
    message: &str,
    exit_code: Option<i32>,
    stderr: &str,
    now: i64,
) -> Res<bool> {
    let tx = immediate(conn)?;
    let previous = crate::db::meta_json(&tx, "export_error")?
        .and_then(|v| v["message"].as_str().map(str::to_string));
    if previous.as_deref() == Some(message) {
        return Ok(false);
    }
    meta_set(
        &tx,
        "export_error",
        &json!({"at": crate::util::iso(now), "message": message}).to_string(),
    )?;
    outbox::push(
        &tx,
        "definitions.failed",
        None,
        json!({
            "message": message,
            "exit_code": exit_code,
            "stderr_tail": crate::util::tail_utf8(stderr, 4096),
        }),
        now,
    )?;
    tx.commit()?;
    Ok(true)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::db::testutil::fresh_db;
    use crate::model::load_cron;

    fn export(hash: &str, crons: Value) -> Export {
        Export::parse(
            json!({"format": 1, "hash": hash, "config": {"state_dir": "/tmp/x"}, "crons": crons})
                .to_string()
                .as_bytes(),
        )
        .unwrap()
    }

    #[test]
    fn upserts_keeps_flags_and_marks_missing_undefined() {
        let (_d, c) = fresh_db("defs");
        let e1 = export(
            "h1",
            json!([{"code": "a", "handler_class": "A", "schedule": "* * * * *"}, {"code": "b", "handler_class": "B"}]),
        );
        assert!(apply_export(&c, &e1).unwrap());
        assert!(!apply_export(&c, &e1).unwrap()); // stejný hash → nic
        c.execute("UPDATE crons SET local_active = 0 WHERE code = 'a'", [])
            .unwrap();
        let e2 = export(
            "h2",
            json!([{"code": "a", "handler_class": "A", "schedule": "*/5 * * * *", "repeat_count": 2}]),
        );
        assert!(apply_export(&c, &e2).unwrap());
        let a = load_cron(&c, "a").unwrap().unwrap();
        assert_eq!(a.schedule.as_deref(), Some("*/5 * * * *"));
        assert_eq!(a.repeat_count, 2);
        assert!(!a.local_active);
        assert!(!load_cron(&c, "b").unwrap().unwrap().defined);
        let blob: Vec<u8> = c
            .query_row("SELECT payload FROM outbox WHERE type='definitions.sync' ORDER BY seq DESC LIMIT 1", [], |r| r.get(0))
            .unwrap();
        let p: Value = serde_json::from_slice(&blob).unwrap();
        assert_eq!(p["mode"], "local");
        assert_eq!(p["crons"][0]["local_active"], false);
        assert_eq!(p["crons"][0]["remote_active"], true);
    }
}

//! Příkazy z LQDecku (plán § 8.3): tick je použije na začátku průchodu v pořadí `seq`.

use rusqlite::{Connection, params};
use serde_json::{Value, json};

use crate::db::{immediate, meta_set, meta_str};
use crate::export::Export;
use crate::model::load_cron;
use crate::outbox;
use crate::queue::{Enqueued, NewJob, enqueue};

/// Klíč, kterým `deliver` označí v payloadu příkaz s nečitelným `expires_at` (audit L11).
pub const INVALID_MARK: &str = "_lqcron_invalid";
use crate::runjob::{CancelOutcome, cancel};
use crate::util::{Res, now_ms};

struct Pending {
    command_id: String,
    ty: String,
    payload: Value,
    expires_at: Option<i64>,
}

fn allowed(ty: &str, remote: &[String]) -> bool {
    // jen znovu pošle stav, nic nemění → vždy povolený (není v `remoteCommands`)
    if ty == "definitions.resync" || remote.iter().any(|r| r == ty) {
        return true;
    }
    // vzdálený resume patří k povolené vzdálené pauze
    ty == "scheduler.resume" && remote.iter().any(|r| r == "scheduler.pause")
}

/// Aplikuje nevyřízené příkazy. Každý ve vlastní transakci s potvrzením do outboxu.
///
/// `export` = aktuálně aplikovaný export (pro `definitions.resync`); bez něj se definice
/// poskládají z tabulky `crons`.
pub fn apply(
    conn: &Connection,
    remote_commands: &[String],
    shadow: bool,
    export: Option<&Export>,
) -> Res<usize> {
    let pending: Vec<Pending> = conn
        .prepare("SELECT command_id, type, payload, expires_at FROM commands WHERE applied_at IS NULL ORDER BY seq, fetched_at")?
        .query_map([], |r| {
            let payload: String = r.get(2)?;
            Ok(Pending {
                command_id: r.get(0)?,
                ty: r.get(1)?,
                payload: serde_json::from_str(&payload).unwrap_or(Value::Null),
                expires_at: r.get(3)?,
            })
        })?
        .collect::<Result<_, _>>()?;
    let n = pending.len();
    for cmd in pending {
        let tx = immediate(conn)?;
        let now = now_ms();
        let result = execute(&tx, &cmd, remote_commands, shadow, export, now)?;
        let (ev, stored, reason) = match &result {
            Ok(()) => ("command.applied", "applied".to_string(), None),
            Err(reason) => (
                "command.failed",
                format!("failed:{reason}"),
                Some(reason.clone()),
            ),
        };
        let mut payload = json!({"command_id": cmd.command_id, "command_type": cmd.ty});
        if let Some(r) = reason {
            payload["reason"] = Value::String(r);
        }
        let ack = outbox::push(&tx, ev, None, payload, now)?;
        let changed = tx.execute(
            "UPDATE commands SET applied_at = ?1, result = ?2, ack_event_id = ?3 WHERE command_id = ?4 AND applied_at IS NULL",
            params![now, stored, ack, cmd.command_id],
        )?;
        if changed == 0 {
            continue; // jiný tick ho mezitím použil (zahodí i potvrzení; díky zámku ticku nenastane)
        }
        tx.commit()?;
    }
    Ok(n)
}

fn execute(
    conn: &Connection,
    cmd: &Pending,
    remote: &[String],
    shadow: bool,
    export: Option<&Export>,
    now: i64,
) -> Res<Result<(), String>> {
    if cmd.payload.get(INVALID_MARK).is_some() {
        return Ok(Err("invalid".into()));
    }
    if cmd.expires_at.is_some_and(|e| e < now) {
        return Ok(Err("expired".into()));
    }
    if !allowed(&cmd.ty, remote) {
        return Ok(Err("not_allowed".into()));
    }
    let p = &cmd.payload;
    match cmd.ty.as_str() {
        "cron.set_active" => {
            let (Some(code), Some(active)) = (
                p.get("cron_code").and_then(Value::as_str),
                p.get("active").and_then(Value::as_bool),
            ) else {
                return Ok(Err("invalid".into()));
            };
            if load_cron(conn, code)?.is_none() {
                return Ok(Err("unknown_cron".into()));
            }
            conn.execute(
                "UPDATE crons SET remote_active = ?1, updated_at = ?2 WHERE code = ?3",
                params![active as i64, now, code],
            )?;
            Ok(Ok(()))
        }
        "cron.run_now" => {
            if shadow {
                return Ok(Err("not_allowed".into()));
            }
            let Some(code) = p.get("cron_code").and_then(Value::as_str) else {
                return Ok(Err("invalid".into()));
            };
            match load_cron(conn, code)? {
                // frontový limit platí i pro „spustit teď“ (audit L7)
                Some(c) if c.defined => {
                    match enqueue(conn, &c, NewJob::new(code, "remote", now), true, now)? {
                        Enqueued::Queued(_) => Ok(Ok(())),
                        Enqueued::Rejected { .. } => Ok(Err("queue_full".into())),
                    }
                }
                _ => Ok(Err("unknown_cron".into())),
            }
        }
        "run.cancel" => {
            if shadow {
                return Ok(Err("not_allowed".into()));
            }
            let Some(uuid) = p.get("run_uuid").and_then(Value::as_str) else {
                return Ok(Err("invalid".into()));
            };
            match cancel(conn, uuid, now)? {
                CancelOutcome::NotActive => Ok(Err("run_not_active".into())),
                _ => Ok(Ok(())),
            }
        }
        "definitions.resync" => {
            // LQDeck po zrušení blokace (plán § 13.4) chce znovu celou aplikovanou sadu
            let payload = match export {
                Some(ex) => crate::defs::sync_payload(conn, ex)?,
                None => crate::defs::sync_payload_from_db(conn)?,
            };
            outbox::push(conn, "definitions.sync", None, payload, now)?;
            Ok(Ok(()))
        }
        "scheduler.pause" => {
            if meta_str(conn, "pause_remote")?.is_none() {
                meta_set(
                    conn,
                    "pause_remote",
                    &json!({"since": now, "command_id": cmd.command_id}).to_string(),
                )?;
            }
            Ok(Ok(()))
        }
        "scheduler.resume" => {
            if meta_str(conn, "pause_remote")?.is_some() {
                meta_set(conn, "pause_remote", "")?;
                meta_set(conn, "last_resume_at", &now.to_string())?;
            }
            Ok(Ok(()))
        }
        _ => Ok(Err("invalid".into())),
    }
}

/// Smaže provedené příkazy starší 30 dní, jejichž potvrzení LQDeck už přijal (není v
/// `outbox` ani `outbox_rejected`). Dokud potvrzení nedorazilo, LQDeck příkaz posílá
/// znovu a řádek brání jeho druhému provedení (audit L8).
pub fn prune(conn: &Connection, now: i64) -> Res<usize> {
    Ok(conn.execute(
        "DELETE FROM commands WHERE applied_at IS NOT NULL AND applied_at < ?1
           AND (ack_event_id IS NULL OR (
                NOT EXISTS (SELECT 1 FROM outbox o WHERE o.event_id = commands.ack_event_id)
            AND NOT EXISTS (SELECT 1 FROM outbox_rejected r WHERE r.event_id = commands.ack_event_id)))",
        [now - 30 * 24 * 3600 * 1000],
    )?)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::db::testutil::fresh_db;
    use crate::queue::tests::insert_cron;

    fn add(c: &Connection, id: &str, seq: i64, ty: &str, payload: Value, expires: Option<i64>) {
        c.execute(
            "INSERT INTO commands (command_id, seq, type, payload, issued_at, expires_at, fetched_at) VALUES (?1, ?2, ?3, ?4, 0, ?5, 0)",
            params![id, seq, ty, payload.to_string(), expires],
        )
        .unwrap();
    }

    fn results(c: &Connection) -> Vec<(String, String)> {
        c.prepare("SELECT command_id, result FROM commands ORDER BY seq")
            .unwrap()
            .query_map([], |r| Ok((r.get(0)?, r.get(1)?)))
            .unwrap()
            .map(Result::unwrap)
            .collect()
    }

    fn defaults() -> Vec<String> {
        vec![
            "cron.set_active".into(),
            "cron.run_now".into(),
            "run.cancel".into(),
        ]
    }

    #[test]
    fn applies_in_seq_order_once_with_confirmations() {
        let (_d, c) = fresh_db("cmd");
        insert_cron(&c, "a", None, "exclusive");
        add(
            &c,
            "c2",
            2,
            "cron.set_active",
            json!({"cron_code": "a", "active": true}),
            None,
        );
        add(
            &c,
            "c1",
            1,
            "cron.set_active",
            json!({"cron_code": "a", "active": false}),
            None,
        );
        add(
            &c,
            "c3",
            3,
            "cron.run_now",
            json!({"cron_code": "a"}),
            Some(1),
        ); // prošlý
        add(&c, "c4", 4, "scheduler.pause", json!({}), None); // nepovolený
        add(
            &c,
            "c5",
            5,
            "cron.set_active",
            json!({"cron_code": "nope", "active": false}),
            None,
        );
        add(&c, "c6", 6, "run.cancel", json!({"run_uuid": "x"}), None);
        apply(&c, &defaults(), false, None).unwrap();
        let active: i64 = c
            .query_row("SELECT remote_active FROM crons", [], |r| r.get(0))
            .unwrap();
        assert_eq!(active, 1); // false a pak true, v pořadí seq
        assert_eq!(
            results(&c),
            vec![
                ("c1".into(), "applied".into()),
                ("c2".into(), "applied".into()),
                ("c3".into(), "failed:expired".into()),
                ("c4".into(), "failed:not_allowed".into()),
                ("c5".into(), "failed:unknown_cron".into()),
                ("c6".into(), "failed:run_not_active".into()),
            ]
        );
        let n: i64 = c
            .query_row(
                "SELECT COUNT(*) FROM outbox WHERE type LIKE 'command.%'",
                [],
                |r| r.get(0),
            )
            .unwrap();
        assert_eq!(n, 6);
        // podruhé se nic neprovede
        apply(&c, &defaults(), false, None).unwrap();
        let n: i64 = c
            .query_row(
                "SELECT COUNT(*) FROM outbox WHERE type LIKE 'command.%'",
                [],
                |r| r.get(0),
            )
            .unwrap();
        assert_eq!(n, 6);
    }

    #[test]
    fn run_now_respects_queue_limit_and_invalid_expiry() {
        let (_d, c) = fresh_db("cmd-limit");
        insert_cron(&c, "a", None, "exclusive");
        c.execute("UPDATE crons SET max_queue_size = 1", [])
            .unwrap();
        add(&c, "c1", 1, "cron.run_now", json!({"cron_code": "a"}), None);
        add(&c, "c2", 2, "cron.run_now", json!({"cron_code": "a"}), None);
        add(
            &c,
            "c3",
            3,
            "cron.set_active",
            json!({"cron_code": "a", "active": false, INVALID_MARK: "expires_at"}),
            None,
        );
        apply(&c, &defaults(), false, None).unwrap();
        assert_eq!(
            results(&c),
            vec![
                ("c1".into(), "applied".into()),
                ("c2".into(), "failed:queue_full".into()),
                ("c3".into(), "failed:invalid".into()),
            ]
        );
        let active: i64 = c
            .query_row("SELECT remote_active FROM crons", [], |r| r.get(0))
            .unwrap();
        assert_eq!(active, 1);
    }

    #[test]
    fn prune_keeps_commands_until_confirmation_is_delivered() {
        let (_d, c) = fresh_db("cmd-prune");
        insert_cron(&c, "a", None, "exclusive");
        add(
            &c,
            "c1",
            1,
            "cron.set_active",
            json!({"cron_code": "a", "active": true}),
            None,
        );
        apply(&c, &defaults(), false, None).unwrap();
        let later = now_ms() + 31 * 24 * 3600 * 1000;
        assert_eq!(prune(&c, later).unwrap(), 0); // potvrzení ještě v outboxu
        c.execute("DELETE FROM outbox", []).unwrap(); // LQDeck ho přijal
        assert_eq!(prune(&c, later).unwrap(), 1);
    }

    fn sync_events(c: &Connection) -> Vec<Value> {
        c.prepare("SELECT payload FROM outbox WHERE type = 'definitions.sync' ORDER BY seq")
            .unwrap()
            .query_map([], |r| r.get::<_, Vec<u8>>(0))
            .unwrap()
            .map(|b| serde_json::from_slice(&b.unwrap()).unwrap())
            .collect()
    }

    fn export(shadow: bool) -> Export {
        Export::parse(
            json!({"format": 1, "hash": "h1", "config": {"state_dir": "/tmp/x", "shadow": shadow, "environment": "test"},
                   "crons": [{"code": "a", "handler_class": "App\\A", "schedule": "* * * * *", "schedule_source": "schedule"}]})
                .to_string()
                .as_bytes(),
        )
        .unwrap()
    }

    #[test]
    fn resync_resends_applied_definitions_always_allowed() {
        let (_d, c) = fresh_db("cmd-resync");
        let ex = export(false);
        crate::defs::apply_export(&c, &ex).unwrap();
        c.execute("UPDATE crons SET remote_active = 0", []).unwrap();
        add(&c, "c1", 1, "definitions.resync", json!({}), None);
        // povolený i s prázdným remoteCommands
        apply(&c, &[], false, Some(&ex)).unwrap();
        assert_eq!(results(&c), vec![("c1".into(), "applied".into())]);
        let ev = sync_events(&c);
        assert_eq!(ev.len(), 2); // apply_export + resync
        let p = &ev[1];
        assert_eq!(p["mode"], "local");
        assert_eq!(p["hash"], "h1");
        assert_eq!(p["environment"], "test");
        assert_eq!(p["crons"][0]["code"], "a");
        assert_eq!(p["crons"][0]["schedule_source"], "schedule");
        assert_eq!(p["crons"][0]["local_active"], true);
        assert_eq!(p["crons"][0]["remote_active"], false);
        let applied: i64 = c
            .query_row(
                "SELECT COUNT(*) FROM outbox WHERE type = 'command.applied'",
                [],
                |r| r.get(0),
            )
            .unwrap();
        assert_eq!(applied, 1);
    }

    #[test]
    fn resync_in_shadow_mode_and_without_export() {
        let (_d, c) = fresh_db("cmd-resync-shadow");
        let ex = export(true);
        crate::defs::apply_export(&c, &ex).unwrap();
        add(&c, "c1", 1, "definitions.resync", json!({}), None);
        apply(&c, &defaults(), true, Some(&ex)).unwrap();
        assert_eq!(sync_events(&c)[1]["mode"], "shadow");
        // bez exportu z tabulky crons + meta
        add(&c, "c2", 2, "definitions.resync", json!({}), None);
        apply(&c, &defaults(), true, None).unwrap();
        let p = &sync_events(&c)[2];
        assert_eq!(p["mode"], "shadow");
        assert_eq!(p["hash"], "h1");
        assert_eq!(p["crons"][0]["code"], "a");
        assert_eq!(p["crons"][0]["remote_active"], true);
    }

    #[test]
    fn run_now_and_remote_pause() {
        let (_d, c) = fresh_db("cmd2");
        insert_cron(&c, "a", None, "exclusive");
        add(
            &c,
            "c1",
            1,
            "cron.run_now",
            json!({"cron_code": "a"}),
            Some(i64::MAX),
        );
        add(&c, "c2", 2, "scheduler.pause", json!({}), None);
        add(&c, "c3", 3, "scheduler.resume", json!({}), None);
        let mut allowed = defaults();
        allowed.push("scheduler.pause".into());
        apply(&c, &allowed, false, None).unwrap();
        let src: String = c
            .query_row("SELECT source FROM jobs", [], |r| r.get(0))
            .unwrap();
        assert_eq!(src, "remote");
        assert!(meta_str(&c, "pause_remote").unwrap().is_none());
        assert!(meta_str(&c, "last_resume_at").unwrap().is_some());
    }
}

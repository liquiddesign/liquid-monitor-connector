//! Retence lokální pracovní historie (plán § 12).

use std::fs;
use std::path::Path;

use rusqlite::Connection;

use crate::db::immediate;
use crate::runjob::{args_path, log_path, marker_path, runs_dir};
use crate::util::{Res, file_mtime_ms};

pub const KEEP_MS: i64 = 7 * 24 * 3600 * 1000;

/// Smaže dokončené běhy starší 7 dní, jejichž události už v outboxu nejsou
/// (potvrzené, nebo přesunuté do `outbox_rejected`), a logy běhů starší 7 dní.
pub fn run(conn: &Connection, state_dir: &Path, now: i64) -> Res<usize> {
    let cutoff = now - KEEP_MS;
    let old: Vec<String> = conn
        .prepare(
            "SELECT r.uuid FROM runs r
             WHERE r.state IN ('finished','failed','timeout','cancelled','lost')
               AND COALESCE(r.finished_at, r.claimed_at) < ?1
               AND NOT EXISTS (SELECT 1 FROM outbox o WHERE o.run_uuid = r.uuid)",
        )?
        .query_map([cutoff], |r| r.get(0))?
        .collect::<Result<_, _>>()?;
    {
        let tx = immediate(conn)?;
        for uuid in &old {
            tx.execute("DELETE FROM run_events WHERE run_uuid = ?1", [uuid])?;
            tx.execute("DELETE FROM runs WHERE uuid = ?1", [uuid])?;
        }
        crate::commands::prune(&tx, now)?;
        tx.commit()?;
    }
    for uuid in &old {
        let _ = fs::remove_file(marker_path(state_dir, uuid));
        let _ = fs::remove_file(args_path(state_dir, uuid));
        let _ = fs::remove_file(log_path(state_dir, uuid));
    }
    // logy a osiřelé soubory běhů starší 7 dní (běžící běh log průběžně mění)
    if let Ok(rd) = fs::read_dir(runs_dir(state_dir)) {
        for e in rd.flatten() {
            let p = e.path();
            if file_mtime_ms(&p).is_some_and(|m| m < cutoff) {
                let _ = fs::remove_file(&p);
            }
        }
    }
    Ok(old.len())
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::db::testutil::fresh_db;
    use crate::outbox;
    use rusqlite::params;

    #[test]
    fn deletes_only_old_runs_without_pending_events() {
        let (d, c) = fresh_db("ret");
        let now = 100 * KEEP_MS;
        for (uuid, finished, state) in [
            ("old", now - KEEP_MS - 1, "finished"),
            ("old_pending", now - KEEP_MS - 1, "failed"),
            ("new", now - 1000, "finished"),
            ("act", now - KEEP_MS - 1, "running"),
        ] {
            c.execute(
                "INSERT INTO runs (uuid, job_uuid, origin_uuid, cron_code, attempt, max_attempts, source, job_created_at, concurrency_mode, state, claimed_at, finished_at)
                 VALUES (?1, ?1, ?1, 'a', 1, 1, 'cli', 0, 'exclusive', ?2, 0, ?3)",
                params![uuid, state, finished],
            )
            .unwrap();
        }
        outbox::push(
            &c,
            "run.failed",
            Some("old_pending"),
            serde_json::json!({}),
            0,
        )
        .unwrap();
        fs::create_dir_all(runs_dir(&d.0)).unwrap();
        fs::write(marker_path(&d.0, "old"), "{}").unwrap();
        c.execute(
            "INSERT INTO commands (command_id, seq, type, payload, issued_at, fetched_at, applied_at) VALUES ('x', 1, 't', '{}', 0, 0, 0)",
            [],
        )
        .unwrap();
        assert_eq!(run(&c, &d.0, now).unwrap(), 1);
        let left: Vec<String> = c
            .prepare("SELECT uuid FROM runs ORDER BY uuid")
            .unwrap()
            .query_map([], |r| r.get(0))
            .unwrap()
            .map(Result::unwrap)
            .collect();
        assert_eq!(left, vec!["act", "new", "old_pending"]);
        assert!(!marker_path(&d.0, "old").exists());
        let n: i64 = c
            .query_row("SELECT COUNT(*) FROM commands", [], |r| r.get(0))
            .unwrap();
        assert_eq!(n, 0);
    }
}

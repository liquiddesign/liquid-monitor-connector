//! Fronta `jobs` (plán § 6.2): zařazení s limitem a úklid zatuchlých jobů.

use rusqlite::{Connection, params};
use serde_json::json;

use crate::model::CronRow;
use crate::outbox;
use crate::util::{Res, iso, uuid_v7};

/// Stáří dostupného jobu, po kterém se smaže (`job.stale`).
pub const STALE_MS: i64 = 6 * 3600 * 1000;

#[derive(Debug, Clone)]
pub struct NewJob {
    pub cron_code: String,
    /// JSON text argumentů, nebo `None`.
    pub arguments: Option<String>,
    pub attempt: i64,
    pub source: String,
    pub scheduled_for: Option<i64>,
    pub available_at: i64,
    /// Opakování dědí `origin_uuid`; nový job ho má rovné vlastnímu uuid.
    pub origin_uuid: Option<String>,
    /// Návrat zaseknutého běhu do fronty zachová uuid jobu.
    pub uuid: Option<String>,
    pub created_at: Option<i64>,
}

impl NewJob {
    pub fn new(cron_code: &str, source: &str, now: i64) -> NewJob {
        NewJob {
            cron_code: cron_code.to_string(),
            arguments: None,
            attempt: 1,
            source: source.to_string(),
            scheduled_for: None,
            available_at: now,
            origin_uuid: None,
            uuid: None,
            created_at: None,
        }
    }
}

#[derive(Debug, PartialEq)]
pub enum Enqueued {
    Queued(String),
    Rejected {
        queue_size: i64,
        max_queue_size: i64,
    },
}

pub fn queue_size(conn: &Connection, code: &str) -> Res<i64> {
    Ok(conn.query_row(
        "SELECT COUNT(*) FROM jobs WHERE cron_code = ?1",
        [code],
        |r| r.get(0),
    )?)
}

/// Zařadí job + `job.queued` (nebo `job.rejected` při plné frontě) do outboxu.
/// Volá se uvnitř transakce. `enforce_limit = false` pro opakování, `after` a příkazy.
pub fn enqueue(
    conn: &Connection,
    cron: &CronRow,
    job: NewJob,
    enforce_limit: bool,
    now: i64,
) -> Res<Enqueued> {
    if enforce_limit {
        let size = queue_size(conn, &cron.code)?;
        let max = cron.max_queue();
        if size >= max {
            outbox::push(
                conn,
                "job.rejected",
                None,
                json!({
                    "cron_code": cron.code,
                    "source": job.source,
                    "reason": "queue_full",
                    "queue_size": size,
                    "max_queue_size": max,
                }),
                now,
            )?;
            return Ok(Enqueued::Rejected {
                queue_size: size,
                max_queue_size: max,
            });
        }
    }
    let uuid = job.uuid.clone().unwrap_or_else(uuid_v7);
    let origin = job.origin_uuid.clone().unwrap_or_else(|| uuid.clone());
    conn.execute(
        "INSERT INTO jobs (uuid, origin_uuid, cron_code, arguments, attempt, source, scheduled_for, available_at, created_at)
         VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9)",
        params![
            uuid,
            origin,
            job.cron_code,
            job.arguments,
            job.attempt,
            job.source,
            job.scheduled_for,
            job.available_at,
            job.created_at.unwrap_or(now)
        ],
    )?;
    outbox::push(
        conn,
        "job.queued",
        None,
        json!({
            "job_uuid": uuid,
            "origin_uuid": origin,
            "cron_code": job.cron_code,
            "source": job.source,
            "attempt": job.attempt,
            "available_at": iso(job.available_at),
            "has_arguments": job.arguments.as_deref().is_some_and(|a| a != "null"),
        }),
        now,
    )?;
    Ok(Enqueued::Queued(uuid))
}

/// Smaže dostupné joby čekající déle než 6 h; doba pauzy se nezapočítává (plán § 6.2).
pub fn clean_stale(
    conn: &Connection,
    now: i64,
    last_resume_at: Option<i64>,
    paused: bool,
) -> Res<usize> {
    if paused {
        return Ok(0);
    }
    let since_floor = last_resume_at.unwrap_or(0);
    let mut st = conn.prepare(
        "SELECT id, uuid, cron_code, available_at FROM jobs WHERE available_at <= ?1 AND MAX(available_at, ?2) < ?3",
    )?;
    let stale: Vec<(i64, String, String, i64)> = st
        .query_map(params![now, since_floor, now - STALE_MS], |r| {
            Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?))
        })?
        .collect::<Result<_, _>>()?;
    for (id, uuid, code, available_at) in &stale {
        conn.execute("DELETE FROM jobs WHERE id = ?1", [id])?;
        outbox::push(
            conn,
            "job.stale",
            None,
            json!({"job_uuid": uuid, "cron_code": code, "waited_seconds": (now - available_at) / 1000}),
            now,
        )?;
    }
    Ok(stale.len())
}

#[cfg(test)]
pub mod tests {
    use super::*;
    use crate::db::testutil::fresh_db;
    use crate::model::load_cron;

    pub fn insert_cron(conn: &Connection, code: &str, schedule: Option<&str>, mode: &str) {
        conn.execute(
            "INSERT INTO crons (code, handler_class, schedule, concurrency_mode, updated_at) VALUES (?1, ?2, ?3, ?4, 0)",
            params![code, format!("App\\{code}"), schedule, mode],
        )
        .unwrap();
    }

    fn count(conn: &Connection, sql: &str) -> i64 {
        conn.query_row(sql, [], |r| r.get(0)).unwrap()
    }

    #[test]
    fn queue_limit_counts_delayed_and_rejects() {
        let (_d, c) = fresh_db("queue");
        insert_cron(&c, "a", None, "exclusive");
        c.execute("UPDATE crons SET max_queue_size = 2", [])
            .unwrap();
        let cron = load_cron(&c, "a").unwrap().unwrap();
        let mut j = NewJob::new("a", "app", 1000);
        j.available_at = 999_999; // odložený se počítá taky
        assert!(matches!(
            enqueue(&c, &cron, j, true, 1000).unwrap(),
            Enqueued::Queued(_)
        ));
        assert!(matches!(
            enqueue(&c, &cron, NewJob::new("a", "app", 1000), true, 1000).unwrap(),
            Enqueued::Queued(_)
        ));
        assert_eq!(
            enqueue(&c, &cron, NewJob::new("a", "app", 1000), true, 1000).unwrap(),
            Enqueued::Rejected {
                queue_size: 2,
                max_queue_size: 2
            }
        );
        // opakování limit obchází
        assert!(matches!(
            enqueue(&c, &cron, NewJob::new("a", "retry", 1000), false, 1000).unwrap(),
            Enqueued::Queued(_)
        ));
        assert_eq!(
            count(&c, "SELECT COUNT(*) FROM outbox WHERE type='job.rejected'"),
            1
        );
        assert_eq!(
            count(&c, "SELECT COUNT(*) FROM outbox WHERE type='job.queued'"),
            3
        );
    }

    #[test]
    fn default_queue_limit_is_ten() {
        let (_d, c) = fresh_db("queue10");
        insert_cron(&c, "a", None, "exclusive");
        let cron = load_cron(&c, "a").unwrap().unwrap();
        for _ in 0..12 {
            enqueue(&c, &cron, NewJob::new("a", "app", 1), true, 1).unwrap();
        }
        assert_eq!(queue_size(&c, "a").unwrap(), 10);
    }

    #[test]
    fn stale_only_available_and_pause_not_counted() {
        let (_d, c) = fresh_db("stale");
        insert_cron(&c, "a", None, "exclusive");
        let cron = load_cron(&c, "a").unwrap().unwrap();
        let h = 3600 * 1000;
        let now = 100 * h;
        let mut old = NewJob::new("a", "app", now - 7 * h);
        old.available_at = now - 7 * h;
        enqueue(&c, &cron, old, false, now).unwrap();
        let mut delayed = NewJob::new("a", "app", now - 7 * h);
        delayed.available_at = now + h; // zpoždění ještě neuplynulo
        enqueue(&c, &cron, delayed, false, now).unwrap();
        // během pauzy se nemaže nic
        assert_eq!(clean_stale(&c, now, None, true).unwrap(), 0);
        // resume před hodinou → stáří se měří od něj
        assert_eq!(clean_stale(&c, now, Some(now - h), false).unwrap(), 0);
        assert_eq!(clean_stale(&c, now, None, false).unwrap(), 1);
        assert_eq!(queue_size(&c, "a").unwrap(), 1);
        assert_eq!(
            count(&c, "SELECT COUNT(*) FROM outbox WHERE type='job.stale'"),
            1
        );
    }
}

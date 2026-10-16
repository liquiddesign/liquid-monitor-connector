//! Pauza plánovače se dvěma nezávislými zdroji a pojistkou TTL (plán § 11.2).

use rusqlite::Connection;
use serde_json::{Value, json};

use crate::db::{meta_json, meta_set};
use crate::outbox;
use crate::util::{Res, iso, iso_opt};

#[derive(Debug, Clone, Default)]
pub struct Pause {
    /// `{since, ttl_until, drain_until}` (ms)
    pub deploy: Option<Value>,
    /// `{since, command_id}`
    pub remote: Option<Value>,
}

impl Pause {
    pub fn load(conn: &Connection) -> Res<Pause> {
        Ok(Pause {
            deploy: meta_json(conn, "pause_deploy")?,
            remote: meta_json(conn, "pause_remote")?,
        })
    }

    pub fn active(&self) -> bool {
        self.deploy.is_some() || self.remote.is_some()
    }

    /// Tvar pro heartbeat (kontrakt § 5.4).
    pub fn to_json(&self) -> Value {
        let deploy = self.deploy.as_ref().map(|d| {
            json!({
                "since": iso_opt(d["since"].as_i64()),
                "ttl_until": iso_opt(d["ttl_until"].as_i64()),
                "drain_until": iso_opt(d["drain_until"].as_i64()),
            })
        });
        let remote = self
            .remote
            .as_ref()
            .map(|r| json!({"since": iso_opt(r["since"].as_i64()), "command_id": r["command_id"]}));
        json!({"deploy": deploy, "remote": remote})
    }
}

/// Deploy pauza (`lqcron pause`). Opakované volání zachová `since`.
pub fn pause_deploy(
    conn: &Connection,
    now: i64,
    ttl_ms: Option<i64>,
    drain_ms: Option<i64>,
) -> Res<()> {
    let since = meta_json(conn, "pause_deploy")?
        .and_then(|v| v["since"].as_i64())
        .unwrap_or(now);
    let v = json!({
        "since": since,
        "ttl_until": ttl_ms.map(|t| now + t),
        "drain_until": drain_ms.map(|d| now + d),
    });
    meta_set(conn, "pause_deploy", &v.to_string())?;
    Ok(())
}

/// `lqcron resume` ruší jen deploy pauzu, `--all` i vzdálenou. Vrací, zda se něco zrušilo.
pub fn resume(conn: &Connection, now: i64, all: bool) -> Res<bool> {
    let p = Pause::load(conn)?;
    let mut changed = false;
    if p.deploy.is_some() {
        meta_set(conn, "pause_deploy", "")?;
        changed = true;
    }
    if all && p.remote.is_some() {
        meta_set(conn, "pause_remote", "")?;
        changed = true;
    }
    if changed {
        meta_set(conn, "last_resume_at", &now.to_string())?;
    }
    Ok(changed)
}

/// Pauza s prošlým TTL se sama zruší (`scheduler.pause_expired`).
pub fn expire(conn: &Connection, now: i64) -> Res<bool> {
    let Some(d) = meta_json(conn, "pause_deploy")? else {
        return Ok(false);
    };
    let Some(ttl) = d["ttl_until"].as_i64() else {
        return Ok(false);
    };
    if ttl > now {
        return Ok(false);
    }
    meta_set(conn, "pause_deploy", "")?;
    meta_set(conn, "last_resume_at", &now.to_string())?;
    outbox::push(
        conn,
        "scheduler.pause_expired",
        None,
        json!({"since": iso_opt(d["since"].as_i64()), "ttl_until": iso(ttl)}),
        now,
    )?;
    Ok(true)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::db::testutil::fresh_db;

    #[test]
    fn two_sources_and_ttl() {
        let (_d, c) = fresh_db("pause");
        pause_deploy(&c, 1000, Some(60_000), Some(5000)).unwrap();
        meta_set(
            &c,
            "pause_remote",
            &json!({"since": 500, "command_id": "x"}).to_string(),
        )
        .unwrap();
        let p = Pause::load(&c).unwrap();
        assert!(p.active());
        // resume bez --all nechá vzdálenou pauzu
        assert!(resume(&c, 2000, false).unwrap());
        assert!(Pause::load(&c).unwrap().active());
        assert!(resume(&c, 2000, true).unwrap());
        assert!(!Pause::load(&c).unwrap().active());

        pause_deploy(&c, 10_000, Some(1000), None).unwrap();
        assert!(!expire(&c, 10_500).unwrap());
        assert!(expire(&c, 11_000).unwrap());
        assert!(!Pause::load(&c).unwrap().active());
        let n: i64 = c
            .query_row(
                "SELECT COUNT(*) FROM outbox WHERE type='scheduler.pause_expired'",
                [],
                |r| r.get(0),
            )
            .unwrap();
        assert_eq!(n, 1);
    }

    #[test]
    fn repeated_pause_keeps_since() {
        let (_d, c) = fresh_db("pause2");
        pause_deploy(&c, 1000, None, None).unwrap();
        pause_deploy(&c, 5000, Some(10), None).unwrap();
        let p = Pause::load(&c).unwrap();
        assert_eq!(p.deploy.unwrap()["since"], 1000);
    }
}

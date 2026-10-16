//! Hlídané služby (plán § 10): `watchdog` se spouští každý tick, `keepalive` drží
//! `lqcron` naživu sám (restart s backoffem, přepnutí na nový slot).

use std::path::Path;
use std::process::Command;
use std::time::{Duration, Instant};

use rusqlite::{Connection, OptionalExtension, params};
use serde_json::json;

use crate::export::Service;
use crate::outbox;
use crate::util::{Res, ensure_dir, kill_group, pid_alive, proc_starttime, spawn_detached};

#[derive(Debug, Clone)]
struct Row {
    pid: Option<i64>,
    pid_start: Option<i64>,
    app_path: Option<String>,
    started_at: Option<i64>,
    restarts: i64,
    backoff_until: Option<i64>,
}

fn load(conn: &Connection, name: &str) -> Res<Option<Row>> {
    Ok(conn
        .query_row(
            "SELECT pid, pid_start, app_path, started_at, restarts, backoff_until FROM services WHERE name = ?1",
            [name],
            |r| {
                Ok(Row {
                    pid: r.get(0)?,
                    pid_start: r.get(1)?,
                    app_path: r.get(2)?,
                    started_at: r.get(3)?,
                    restarts: r.get(4)?,
                    backoff_until: r.get(5)?,
                })
            },
        )
        .optional()?)
}

pub struct Ctx<'a> {
    pub state_dir: &'a Path,
    pub app: &'a Path,
    pub php: &'a str,
    pub log_max_bytes: u64,
}

fn spawn(ctx: &Ctx, name: &str, svc: &Service) -> std::io::Result<i64> {
    let dir = ctx.state_dir.join("services");
    ensure_dir(&dir)?;
    let log = dir.join(format!("{name}.log"));
    if std::fs::metadata(&log)
        .map(|m| m.len() > ctx.log_max_bytes)
        .unwrap_or(false)
    {
        let _ = std::fs::rename(&log, dir.join(format!("{name}.log.1")));
    }
    let Some(first) = svc.command.first() else {
        return Err(std::io::Error::other("empty command"));
    };
    // `php` v příkazu = PHP projektu (v cronu bývá PATH minimální)
    let program = if first == "php" {
        ctx.php
    } else {
        first.as_str()
    };
    let mut cmd = Command::new(program);
    cmd.args(&svc.command[1..]).current_dir(ctx.app);
    Ok(spawn_detached(&mut cmd, Some(&log))? as i64)
}

#[allow(clippy::too_many_arguments)]
fn event(
    conn: &Connection,
    ty: &str,
    name: &str,
    svc_mode: &str,
    pid: Option<i64>,
    app: &str,
    restarts: i64,
    now: i64,
) -> Res<()> {
    outbox::push(
        conn,
        ty,
        None,
        json!({"service": name, "mode": svc_mode, "pid": pid, "exit_code": null, "app_path": app, "restarts": restarts}),
        now,
    )?;
    Ok(())
}

fn backoff_ms(restarts: i64) -> i64 {
    (5_000i64 << restarts.clamp(0, 6)).min(300_000)
}

/// Jeden průchod nad službami. `now` = wall clock ms.
pub fn tick(
    conn: &Connection,
    ctx: &Ctx,
    services: &std::collections::BTreeMap<String, Service>,
    now: i64,
) -> Res<()> {
    let app = ctx.app.to_string_lossy().into_owned();
    // služby, které z konfigurace zmizely
    let known: Vec<(String, String, Option<i64>, Option<i64>)> = conn
        .prepare("SELECT name, mode, pid, pid_start FROM services")?
        .query_map([], |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?)))?
        .collect::<Result<_, _>>()?;
    for (name, mode, pid, start) in known {
        if !services.contains_key(&name) {
            if mode == "keepalive" && pid_alive(pid, start) {
                kill_group(pid.unwrap_or(0), libc::SIGTERM);
            }
            conn.execute("DELETE FROM services WHERE name = ?1", [&name])?;
        }
    }
    for (name, svc) in services {
        let row = load(conn, name)?;
        if svc.mode == "keepalive" {
            keepalive(conn, ctx, name, svc, row, &app, now)?;
        } else {
            match spawn(ctx, name, svc) {
                Ok(pid) => {
                    let start = proc_starttime(pid);
                    conn.execute(
                        "INSERT INTO services (name, mode, pid, pid_start, app_path, started_at) VALUES (?1, 'watchdog', ?2, ?3, ?4, ?5)
                         ON CONFLICT(name) DO UPDATE SET mode = 'watchdog', pid = excluded.pid, pid_start = excluded.pid_start,
                            app_path = excluded.app_path, started_at = excluded.started_at",
                        params![name, pid, start, app, now],
                    )?;
                    if row.is_none() {
                        event(
                            conn,
                            "service.started",
                            name,
                            "watchdog",
                            Some(pid),
                            &app,
                            0,
                            now,
                        )?;
                    }
                }
                Err(e) => eprintln!("lqcron: service {name}: {e}"),
            }
        }
    }
    Ok(())
}

fn keepalive(
    conn: &Connection,
    ctx: &Ctx,
    name: &str,
    svc: &Service,
    row: Option<Row>,
    app: &str,
    now: i64,
) -> Res<()> {
    let mut restarts = row.as_ref().map(|r| r.restarts).unwrap_or(0);
    let mut was_running = false;
    if let Some(r) = &row {
        if pid_alive(r.pid, r.pid_start) {
            if r.app_path.as_deref() == Some(app) {
                return Ok(());
            }
            // přepnutý slot: ukončit službu ze starého slotu a spustit z nového
            let pid = r.pid.unwrap_or(0);
            kill_group(pid, libc::SIGTERM);
            let t = Instant::now();
            while pid_alive(r.pid, r.pid_start) && t.elapsed() < Duration::from_secs(3) {
                std::thread::sleep(Duration::from_millis(100));
            }
            if pid_alive(r.pid, r.pid_start) {
                kill_group(pid, libc::SIGKILL);
            }
            was_running = true;
        } else if r.pid.is_some() {
            // služba skončila sama
            let stable = r.started_at.is_some_and(|s| now - s >= 600_000);
            if stable {
                restarts = 0;
            }
            conn.execute(
                "UPDATE services SET pid = NULL, pid_start = NULL, last_exit_at = ?1, backoff_until = ?2, restarts = ?3 WHERE name = ?4",
                params![now, now + backoff_ms(restarts), restarts, name],
            )?;
            event(
                conn,
                "service.exited",
                name,
                "keepalive",
                r.pid,
                r.app_path.as_deref().unwrap_or(""),
                restarts,
                now,
            )?;
            return Ok(()); // restart nejdřív po backoffu
        } else if r.backoff_until.is_some_and(|b| b > now) {
            return Ok(());
        }
    }
    match spawn(ctx, name, svc) {
        Ok(pid) => {
            let start = proc_starttime(pid);
            let first = row.is_none() || row.as_ref().is_some_and(|r| r.started_at.is_none());
            if !first {
                restarts += 1;
            }
            conn.execute(
                "INSERT INTO services (name, mode, pid, pid_start, app_path, started_at, restarts) VALUES (?1, 'keepalive', ?2, ?3, ?4, ?5, ?6)
                 ON CONFLICT(name) DO UPDATE SET mode = 'keepalive', pid = excluded.pid, pid_start = excluded.pid_start,
                    app_path = excluded.app_path, started_at = excluded.started_at, restarts = excluded.restarts, backoff_until = NULL",
                params![name, pid, start, app, now, restarts],
            )?;
            let ty = if first && !was_running {
                "service.started"
            } else {
                "service.restarted"
            };
            event(conn, ty, name, "keepalive", Some(pid), app, restarts, now)?;
        }
        Err(e) => {
            eprintln!("lqcron: service {name}: {e}");
            conn.execute(
                "INSERT INTO services (name, mode, backoff_until, restarts) VALUES (?1, 'keepalive', ?2, ?3)
                 ON CONFLICT(name) DO UPDATE SET backoff_until = excluded.backoff_until, restarts = excluded.restarts",
                params![name, now + backoff_ms(restarts + 1), restarts + 1],
            )?;
        }
    }
    Ok(())
}

/// Služby (keepalive) běžící ze slotu `slot` — pro `wait-slot`.
pub fn running_from_slot(conn: &Connection, slot: &str) -> Res<Vec<String>> {
    type SvcRow = (String, Option<i64>, Option<i64>, Option<String>, String);
    let rows: Vec<SvcRow> = conn
        .prepare("SELECT name, pid, pid_start, app_path, mode FROM services")?
        .query_map([], |r| {
            Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?, r.get(4)?))
        })?
        .collect::<Result<_, _>>()?;
    Ok(rows
        .into_iter()
        .filter(|(_, pid, start, app, _)| app.as_deref() == Some(slot) && pid_alive(*pid, *start))
        .map(|(n, ..)| n)
        .collect())
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::db::testutil::fresh_db;
    use std::collections::BTreeMap;

    #[test]
    fn keepalive_starts_restarts_and_switches_slot() {
        let (d, c) = fresh_db("svc");
        let app1 = d.0.join("blue");
        let app2 = d.0.join("green");
        std::fs::create_dir_all(&app1).unwrap();
        std::fs::create_dir_all(&app2).unwrap();
        let mut svcs = BTreeMap::new();
        svcs.insert(
            "sleeper".to_string(),
            Service {
                command: vec!["sleep".into(), "30".into()],
                mode: "keepalive".into(),
            },
        );
        let ctx = Ctx {
            state_dir: &d.0,
            app: &app1,
            php: "php",
            log_max_bytes: 1 << 20,
        };
        tick(&c, &ctx, &svcs, 1000).unwrap();
        let pid: i64 = c
            .query_row("SELECT pid FROM services", [], |r| r.get(0))
            .unwrap();
        assert!(pid_alive(Some(pid), None));
        assert_eq!(
            running_from_slot(&c, &app1.to_string_lossy()).unwrap(),
            vec!["sleeper".to_string()]
        );
        // stejný slot → nic
        tick(&c, &ctx, &svcs, 2000).unwrap();
        let pid2: i64 = c
            .query_row("SELECT pid FROM services", [], |r| r.get(0))
            .unwrap();
        assert_eq!(pid, pid2);
        // přepnutý slot → restart z nového
        let ctx2 = Ctx {
            state_dir: &d.0,
            app: &app2,
            php: "php",
            log_max_bytes: 1 << 20,
        };
        tick(&c, &ctx2, &svcs, 3000).unwrap();
        let (pid3, app): (i64, String) = c
            .query_row("SELECT pid, app_path FROM services", [], |r| {
                Ok((r.get(0)?, r.get(1)?))
            })
            .unwrap();
        assert_ne!(pid3, pid);
        assert_eq!(app, app2.to_string_lossy());
        // služba zmizí z konfigurace → ukončí se
        tick(&c, &ctx2, &BTreeMap::new(), 4000).unwrap();
        let t = Instant::now();
        while pid_alive(Some(pid3), None) && t.elapsed() < Duration::from_secs(3) {
            std::thread::sleep(Duration::from_millis(50));
            // sklidit zombie (jsme rodič)
            unsafe {
                libc::waitpid(-1, std::ptr::null_mut(), libc::WNOHANG);
            }
        }
        assert!(!pid_alive(Some(pid3), None));
        let types: Vec<String> = c
            .prepare("SELECT type FROM outbox ORDER BY seq")
            .unwrap()
            .query_map([], |r| r.get(0))
            .unwrap()
            .map(Result::unwrap)
            .collect();
        assert_eq!(types, vec!["service.started", "service.restarted"]);
    }

    #[test]
    fn slow_slot_switch_does_not_hold_a_database_lock() {
        let (d, c) = fresh_db("svc-lock");
        let app1 = d.0.join("blue");
        let app2 = d.0.join("green");
        std::fs::create_dir_all(&app1).unwrap();
        std::fs::create_dir_all(&app2).unwrap();
        let mut svcs = BTreeMap::new();
        // služba ignoruje SIGTERM → přepnutí slotu čeká až 3 s
        svcs.insert(
            "stubborn".to_string(),
            Service {
                command: vec!["sh".into(), "-c".into(), "trap '' TERM; sleep 30".into()],
                mode: "keepalive".into(),
            },
        );
        let ctx = Ctx {
            state_dir: &d.0,
            app: &app1,
            php: "php",
            log_max_bytes: 1 << 20,
        };
        tick(&c, &ctx, &svcs, 1000).unwrap();
        std::thread::sleep(Duration::from_millis(200));
        let path = crate::db::db_path(&d.0);
        let writer = std::thread::spawn(move || {
            std::thread::sleep(Duration::from_millis(500));
            // FPM enqueue s busy_timeout 1 s
            let w = rusqlite::Connection::open(path).unwrap();
            w.busy_timeout(Duration::from_millis(1000)).unwrap();
            w.execute("INSERT INTO meta (key, value) VALUES ('probe', 'x')", [])
        });
        let ctx2 = Ctx {
            state_dir: &d.0,
            app: &app2,
            php: "php",
            log_max_bytes: 1 << 20,
        };
        tick(&c, &ctx2, &svcs, 2000).unwrap();
        assert!(
            writer.join().unwrap().is_ok(),
            "services held the SQLite write lock"
        );
        tick(&c, &ctx2, &BTreeMap::new(), 3000).unwrap();
        let _ = std::process::Command::new("pkill")
            .args(["-9", "-f", "trap '' TERM; sleep 30"])
            .status();
    }
}

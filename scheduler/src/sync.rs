//! `lqcron sync` — PHP export konfigurace a definic + jeho aplikace (plán § 5.2, kontrakt § 4).

use std::io::Read;
use std::path::Path;
use std::process::{Command, Stdio};
use std::time::{Duration, Instant};

use crate::db::{self, meta_set};
use crate::defs::apply_export;
use crate::export::{EXPORT_REL, Export};
use crate::util::{FileLock, Res, ensure_dir, fail, now_ms, set_umask};

const EXPORT_TIMEOUT: Duration = Duration::from_secs(300);

pub struct SyncArgs {
    pub php: Option<String>,
    /// Spuštěno tickem: když běží jiný sync, hned skončit.
    pub background: bool,
}

struct ExportRun {
    ok: bool,
    exit_code: Option<i32>,
    stderr: String,
}

fn run_export(app: &Path, php: &str) -> ExportRun {
    let mut child = match Command::new(php)
        .arg("vendor/bin/monitor-worker")
        .arg("export-definitions")
        .arg(format!("--output={EXPORT_REL}"))
        .current_dir(app)
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::piped())
        .spawn()
    {
        Ok(c) => c,
        Err(e) => {
            return ExportRun {
                ok: false,
                exit_code: None,
                stderr: format!("cannot start {php}: {e}"),
            };
        }
    };
    let mut stderr = child.stderr.take();
    let reader = std::thread::spawn(move || {
        let mut s = Vec::new();
        if let Some(e) = stderr.as_mut() {
            let _ = e.read_to_end(&mut s);
        }
        s
    });
    let start = Instant::now();
    let status = loop {
        match child.try_wait() {
            Ok(Some(s)) => break Some(s),
            Ok(None) if start.elapsed() < EXPORT_TIMEOUT => {
                std::thread::sleep(Duration::from_millis(100))
            }
            _ => {
                let _ = child.kill();
                let _ = child.wait();
                break None;
            }
        }
    };
    let err = String::from_utf8_lossy(&reader.join().unwrap_or_default()).into_owned();
    match status {
        Some(s) => ExportRun {
            ok: s.success(),
            exit_code: s.code(),
            stderr: err,
        },
        None => ExportRun {
            ok: false,
            exit_code: None,
            stderr: format!(
                "export timed out after {} s\n{err}",
                EXPORT_TIMEOUT.as_secs()
            ),
        },
    }
}

fn record_failure(state_dir: &Path, message: &str, run: &ExportRun) -> Res<()> {
    let conn = db::open_existing_res(state_dir)?;
    crate::defs::record_export_failure(&conn, message, run.exit_code, &run.stderr, now_ms())?;
    Ok(())
}

pub fn sync(args: &SyncArgs) -> Res<i32> {
    set_umask();
    let app = std::env::current_dir()?.canonicalize()?;
    // nečitelný export slotu → poslední přijatý ze stateDir (audit M2)
    let previous = crate::tick::load_export(&app).ok().map(|(e, _)| e);
    let php = args
        .php
        .clone()
        .or_else(|| previous.as_ref().and_then(|e| e.config.php_binary.clone()))
        .or_else(|| previous.as_ref().and_then(|e| e.php_binary.clone()))
        .unwrap_or_else(|| "php".into());
    // zámek: ve stateDir, dokud ho neznáme, tak ve slotu
    let lock_path = match &previous {
        Some(e) => {
            ensure_dir(&e.state_dir())?;
            e.state_dir().join("sync.lock")
        }
        None => {
            ensure_dir(&app.join("temp/lqcron"))?;
            app.join("temp/lqcron/sync.lock")
        }
    };
    let lock = if args.background {
        FileLock::try_lock(&lock_path)?
    } else {
        FileLock::lock_wait(&lock_path, Duration::from_secs(600))?
    };
    let Some(_lock) = lock else {
        if args.background {
            return Ok(0);
        }
        return Err(fail("another lqcron sync is running"));
    };
    if let Some(e) = &previous
        && db::db_path(&e.state_dir()).exists()
        && let Ok(c) = db::open_existing(&e.state_dir())
    {
        let _ = meta_set(&c, "sync_attempt_at", &now_ms().to_string());
        let _ = meta_set(
            &c,
            "sync_fingerprint",
            &crate::tick::sync_fingerprint(&app, e),
        );
    }

    let run = run_export(&app, &php);
    if !run.ok {
        let message = format!(
            "export-definitions failed{}: {}",
            run.exit_code
                .map(|c| format!(" (exit {c})"))
                .unwrap_or_default(),
            run.stderr.lines().last().unwrap_or("").trim()
        );
        eprintln!("lqcron: {message}");
        if let Some(e) = &previous {
            let _ = record_failure(&e.state_dir(), &message, &run);
        }
        return Ok(1);
    }
    let export = match Export::load(&app) {
        Ok(e) => e,
        Err(err) => {
            // stejný text jako tick, ať se chyba hlásí jen jednou
            let message = err.to_string();
            eprintln!("lqcron: {message}");
            if let Some(e) = &previous {
                let _ = record_failure(&e.state_dir(), &message, &run);
            }
            return Ok(1);
        }
    };
    if !export.config.enabled {
        println!("lqcron: scheduler is disabled in the export (scheduler.enabled: false)");
        return Ok(0);
    }
    let state_dir = export.state_dir();
    // Databázi zakládá tick (kvůli kontrole změny stateDir, plán § 4.2); sync ji jen migruje.
    if !db::db_path(&state_dir).exists() {
        println!("lqcron: export written; the database will be created by the next tick");
        return Ok(0);
    }
    let conn = db::open_or_create(&state_dir).map_err(|e| fail(e.to_string()))?;
    meta_set(&conn, "sync_attempt_at", &now_ms().to_string())?;
    meta_set(
        &conn,
        "sync_fingerprint",
        &crate::tick::sync_fingerprint(&app, &export),
    )?;
    for w in &export.warnings {
        eprintln!("lqcron: warning: {w}");
    }
    let changed = apply_export(&conn, &export)?;
    if !changed {
        meta_set(&conn, "export_error", "")?;
    }
    if !args.background {
        println!(
            "lqcron: {} cron definitions ({}), {}",
            export.crons.len(),
            export.mode(),
            if changed { "applied" } else { "unchanged" }
        );
    }
    Ok(0)
}

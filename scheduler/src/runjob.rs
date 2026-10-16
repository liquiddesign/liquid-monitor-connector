//! Hlídací proces `lqcron run-job` (plán § 7.0–7.4) a společné dokončení běhu,
//! které používá i tick pro běhy, jejichž hlídač umřel (`run-job --adopt`).

use std::fs::{self, OpenOptions};
use std::io::{Read, Write};
use std::os::unix::fs::OpenOptionsExt;
use std::os::unix::process::CommandExt;
use std::path::{Path, PathBuf};
use std::process::{Command, Stdio};
use std::time::{Duration, Instant};

use rusqlite::{Connection, params};
use serde::Deserialize;
use serde_json::{Value, json};

use crate::db::{immediate, open_existing_res};
use crate::export::Export;
use crate::model::{RunRow, load_cron, load_run, merge, run_common_payload};
use crate::outbox::{self, truncate_result};
use crate::queue::{NewJob, enqueue};
use crate::util::{
    Res, boot_id, boottime_ms, fail, group_alive, group_is_ours, iso, iso_opt, kill_group, now_ms,
    parse_iso, pid_alive, proc_starttime, read_tail,
};

/// Konec logu do hlášení (plán § 7.4).
pub const LOG_TAIL_BYTES: u64 = 64 * 1024;
/// Strop výsledku handleru (plán § 7.4).
pub const RESULT_MAX_BYTES: usize = 256 * 1024;
const HEARTBEAT_EVERY_MS: i64 = 30_000;
const LOG_CHECK_EVERY_MS: i64 = 5_000;
const POLL: Duration = Duration::from_millis(100);

/// Nastavení běhu (z `export.json` slotu).
#[derive(Debug, Clone)]
pub struct Env {
    pub state_dir: PathBuf,
    pub app: PathBuf,
    pub php: String,
    pub bootstrap: Option<String>,
    pub kill_grace_ms: i64,
    pub run_log_max_bytes: u64,
    pub report_log_tail: bool,
    pub log_redactor: bool,
}

impl Env {
    pub fn from_export(
        state_dir: &Path,
        app: &Path,
        php: Option<&str>,
        export: Option<&Export>,
    ) -> Env {
        let cfg = export.map(|e| &e.config);
        Env {
            state_dir: state_dir.to_path_buf(),
            app: app.to_path_buf(),
            php: php
                .map(str::to_string)
                .or_else(|| export.map(|e| e.job_php(None)))
                .unwrap_or_else(|| "php".into()),
            bootstrap: cfg.and_then(|c| c.bootstrap.clone()),
            kill_grace_ms: cfg.map(|c| c.kill_grace_seconds).unwrap_or(30).max(0) * 1000,
            run_log_max_bytes: cfg
                .map(|c| c.run_log_max_bytes)
                .unwrap_or(52_428_800)
                .max(LOG_TAIL_BYTES as i64 * 2) as u64,
            report_log_tail: cfg.map(|c| c.report_log_tail).unwrap_or(true),
            log_redactor: cfg.map(|c| c.log_redactor).unwrap_or(false),
        }
    }
}

pub fn runs_dir(state_dir: &Path) -> PathBuf {
    state_dir.join("runs")
}
pub fn args_path(state_dir: &Path, uuid: &str) -> PathBuf {
    runs_dir(state_dir).join(format!("{uuid}.args.json"))
}
pub fn log_path(state_dir: &Path, uuid: &str) -> PathBuf {
    runs_dir(state_dir).join(format!("{uuid}.log"))
}
pub fn marker_path(state_dir: &Path, uuid: &str) -> PathBuf {
    runs_dir(state_dir).join(format!("{uuid}.exit.json"))
}

/// Marker konce běhu (kontrakt § 3).
#[derive(Debug, Clone, Deserialize)]
pub struct Marker {
    pub status: String,
    #[serde(default)]
    pub explicit_fail: bool,
    #[serde(default)]
    pub result: Value,
    #[serde(default)]
    pub result_truncated: bool,
    #[serde(default)]
    pub error: Value,
    #[serde(default)]
    pub peak_memory_mb: Option<f64>,
    #[serde(default)]
    pub finished_at: Option<String>,
}

pub fn read_marker(state_dir: &Path, uuid: &str) -> Option<Marker> {
    let data = fs::read(marker_path(state_dir, uuid)).ok()?;
    match serde_json::from_slice::<Marker>(&data) {
        Ok(m) => Some(m),
        Err(_) => Some(Marker {
            status: "failed".into(),
            explicit_fail: false,
            result: Value::Null,
            result_truncated: false,
            error: json!({"class": "LqcronMarkerError", "message": "exit marker is not valid JSON", "trace": ""}),
            peak_memory_mb: None,
            finished_at: None,
        }),
    }
}

/// Co víme o skončení PHP procesu.
#[derive(Debug, Clone, Default)]
pub struct ExitInfo {
    pub exit_code: Option<i32>,
    pub signal: Option<i32>,
    pub max_rss_kb: Option<i64>,
    /// `true` = výsledek zná hlídací proces (rodič); `false` = dořešení po mrtvém hlídači.
    pub supervised: bool,
    pub error: Option<Value>,
}

fn decide(stop_reason: Option<&str>, marker: Option<&Marker>, exit: &ExitInfo) -> &'static str {
    match stop_reason {
        Some("cancel") => return "cancelled",
        Some("timeout") => return "timeout",
        Some("lost") => return "lost",
        _ => {}
    }
    if exit.error.is_some() {
        return "failed";
    }
    match marker {
        Some(m) if m.status != "finished" || m.explicit_fail => "failed",
        Some(_) => {
            if exit.supervised && (exit.exit_code != Some(0) || exit.signal.is_some()) {
                "failed"
            } else {
                "finished"
            }
        }
        None => {
            if exit.supervised {
                "failed"
            } else {
                "lost"
            }
        }
    }
}

fn synthesized_error(state: &str, run: &RunRow, marker: Option<&Marker>, exit: &ExitInfo) -> Value {
    if let Some(e) = &exit.error {
        return e.clone();
    }
    if let Some(m) = marker
        && !m.error.is_null()
    {
        return m.error.clone();
    }
    let how = match (exit.exit_code, exit.signal) {
        (_, Some(s)) => format!("signal {s}"),
        (Some(c), None) => format!("exit code {c}"),
        _ => "unknown exit status".into(),
    };
    match state {
        "timeout" => {
            json!({"class": "LqcronTimeout", "message": format!("run exceeded its timeout of {} s", run.timeout.unwrap_or(0)), "trace": ""})
        }
        "lost" => {
            json!({"class": "LqcronLost", "message": "the supervisor died and the run did not leave an exit marker", "trace": ""})
        }
        "cancelled" => Value::Null,
        _ if marker.is_none() => {
            json!({"class": "LqcronNoMarker", "message": format!("PHP finished ({how}) without writing the exit marker"), "trace": ""})
        }
        _ if marker.is_some_and(|m| m.explicit_fail) => Value::Null,
        _ => {
            json!({"class": "LqcronExitStatus", "message": format!("PHP finished with {how}"), "trace": ""})
        }
    }
}

/// Konec logu pro LQDeck, případně přes PHP redakci (kontrakt § 7.2).
fn log_tail(env: &Env, uuid: &str) -> (Value, bool) {
    let Ok((bytes, truncated)) = read_tail(&log_path(&env.state_dir, uuid), LOG_TAIL_BYTES) else {
        return (Value::Null, false);
    };
    let text = String::from_utf8_lossy(&bytes).into_owned();
    if !env.log_redactor {
        return (Value::String(text), truncated);
    }
    match redact(env, &text) {
        Some(r) => (Value::String(r), truncated),
        None => (
            Value::String("[log tail withheld: redaction failed]".into()),
            truncated,
        ),
    }
}

fn redact(env: &Env, text: &str) -> Option<String> {
    let mut cmd = Command::new(&env.php);
    cmd.arg("vendor/bin/monitor-worker").arg("redact-log");
    if let Some(b) = &env.bootstrap {
        cmd.arg(format!("--bootstrap={b}"));
    }
    let mut child = cmd
        .current_dir(&env.app)
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::null())
        .spawn()
        .ok()?;
    let mut stdin = child.stdin.take()?;
    let input = text.as_bytes().to_vec();
    let writer = std::thread::spawn(move || {
        let _ = stdin.write_all(&input);
    });
    let mut stdout = child.stdout.take()?;
    let reader = std::thread::spawn(move || {
        let mut s = Vec::new();
        let _ = stdout.read_to_end(&mut s);
        s
    });
    let start = Instant::now();
    loop {
        match child.try_wait() {
            Ok(Some(status)) => {
                let _ = writer.join();
                let out = reader.join().ok()?;
                return if status.success() {
                    Some(String::from_utf8_lossy(&out).into_owned())
                } else {
                    None
                };
            }
            Ok(None) if start.elapsed() < Duration::from_secs(30) => std::thread::sleep(POLL),
            _ => {
                let _ = child.kill();
                let _ = child.wait();
                return None;
            }
        }
    }
}

/// Dokončí běh: finální CAS + událost + opakování / `after` v jedné transakci.
/// Vrací finální stav, nebo `None`, když běh mezitím dokončil někdo jiný.
#[cfg(test)]
pub fn finalize(conn: &Connection, env: &Env, uuid: &str, exit: &ExitInfo) -> Res<Option<String>> {
    match prepare_finalize(conn, env, uuid, exit)? {
        Some(p) => commit_finalize(conn, env, uuid, exit, &p),
        None => Ok(None),
    }
}

/// Co se pro dokončení čte z disku a z PHP (marker, konec logu s redakcí, argumenty) —
/// jednou, mimo transakci a mimo opakované pokusy o zápis.
pub struct Prepared {
    marker: Option<Marker>,
    tail: Option<(Value, bool)>,
    arguments: Option<String>,
}

pub fn prepare_finalize(
    conn: &Connection,
    env: &Env,
    uuid: &str,
    exit: &ExitInfo,
) -> Res<Option<Prepared>> {
    let Some(pre) = load_run(conn, uuid)? else {
        return Ok(None);
    };
    if !matches!(pre.state.as_str(), "starting" | "running" | "stopping") {
        return Ok(None);
    }
    let marker = read_marker(&env.state_dir, uuid);
    let provisional = decide(pre.stop_reason.as_deref(), marker.as_ref(), exit);
    let tail = if matches!(provisional, "failed" | "timeout" | "lost") && env.report_log_tail {
        Some(log_tail(env, uuid))
    } else {
        None
    };
    let arguments = fs::read_to_string(args_path(&env.state_dir, uuid)).ok();
    Ok(Some(Prepared {
        marker,
        tail,
        arguments,
    }))
}

/// Strop jednoho textového pole chyby (zpráva, trace) při zápisu (audit M7).
pub const ERROR_FIELD_MAX: usize = 64 * 1024;

fn cap_error(mut e: Value) -> Value {
    if let Some(o) = e.as_object_mut() {
        for k in ["message", "trace", "class"] {
            if let Some(Value::String(v)) = o.get(k)
                && v.len() > ERROR_FIELD_MAX
            {
                let cut = format!(
                    "{}… [truncated, {} B]",
                    crate::util::truncate_utf8(v, ERROR_FIELD_MAX),
                    v.len()
                );
                o.insert(k.into(), Value::String(cut));
            }
        }
    }
    e
}

pub fn commit_finalize(
    conn: &Connection,
    env: &Env,
    uuid: &str,
    exit: &ExitInfo,
    p: &Prepared,
) -> Res<Option<String>> {
    let marker = p.marker.clone();
    let tail = p.tail.clone();
    let arguments = p.arguments.clone();
    let tx = immediate(conn)?;
    let Some(run) = load_run(&tx, uuid)? else {
        return Ok(None);
    };
    if !matches!(run.state.as_str(), "starting" | "running" | "stopping") {
        return Ok(None);
    }
    let state = decide(run.stop_reason.as_deref(), marker.as_ref(), exit);
    let now = now_ms();
    let finished_at = marker
        .as_ref()
        .and_then(|m| m.finished_at.as_deref())
        .and_then(parse_iso)
        .filter(|_| state == "finished" || state == "failed")
        .unwrap_or(now);
    let cron = load_cron(&tx, &run.cron_code)?;
    let defined = cron.as_ref().is_some_and(|c| c.defined);
    let is_final =
        matches!(state, "finished" | "cancelled") || run.attempt >= run.max_attempts || !defined;
    let (result, result_truncated) = match &marker {
        Some(m) => {
            let (r, t) = truncate_result(&m.result, RESULT_MAX_BYTES);
            (r, t || m.result_truncated)
        }
        None => (Value::Null, false),
    };
    let error = if state == "finished" {
        Value::Null
    } else {
        cap_error(synthesized_error(state, &run, marker.as_ref(), exit))
    };
    let ram_mb = marker.as_ref().and_then(|m| m.peak_memory_mb);
    let started = run.started_at;
    let duration_ms = finished_at - started.unwrap_or(run.claimed_at);

    let n = tx.execute(
        "UPDATE runs SET state = ?1, finished_at = ?2, exit_code = ?3, signal = ?4, max_rss_kb = ?5, result = ?6, error = ?7, final = ?8
         WHERE id = ?9 AND state = ?10",
        params![
            state,
            finished_at,
            exit.exit_code,
            exit.signal,
            exit.max_rss_kb,
            if result.is_null() { None } else { Some(result.to_string()) },
            if error.is_null() { None } else { Some(error.to_string()) },
            is_final as i64,
            run.id,
            run.state
        ],
    )?;
    if n == 0 {
        return Ok(None);
    }
    let mut run_for_payload = run.clone();
    if run_for_payload.app_path.is_none() {
        run_for_payload.app_path = Some(env.app.to_string_lossy().into_owned());
    }
    let common = run_common_payload(&run_for_payload);
    let payload = if state == "finished" {
        merge(
            common,
            json!({
                "final": true,
                "started_at": iso_opt(started),
                "finished_at": iso(finished_at),
                "duration_ms": duration_ms,
                "max_rss_kb": exit.max_rss_kb,
                "ram_mb": ram_mb,
                "exit_code": exit.exit_code,
                "result": result,
                "result_truncated": result_truncated,
            }),
        )
    } else {
        let (log_tail, log_tail_truncated) = if matches!(state, "failed" | "timeout" | "lost") {
            tail.unwrap_or((Value::Null, false))
        } else {
            (Value::Null, false)
        };
        merge(
            common,
            json!({
                "final": is_final,
                "outcome": state,
                "started_at": iso_opt(started),
                "finished_at": iso(finished_at),
                "duration_ms": duration_ms,
                "max_rss_kb": exit.max_rss_kb,
                "ram_mb": ram_mb,
                "exit_code": exit.exit_code,
                "signal": exit.signal,
                "error": error,
                "result": result,
                "log_tail": log_tail,
                "log_tail_truncated": log_tail_truncated,
            }),
        )
    };
    outbox::push(&tx, &format!("run.{state}"), Some(&run.uuid), payload, now)?;

    if let Some(cron) = &cron {
        if !is_final && matches!(state, "failed" | "timeout" | "lost") {
            // opakování: stejné argumenty, stejný origin, mimo frontový limit (plán § 6.4)
            let mut job = NewJob::new(&run.cron_code, "retry", now);
            job.attempt = run.attempt + 1;
            job.origin_uuid = Some(run.origin_uuid.clone());
            job.arguments = arguments
                .clone()
                .filter(|a| a.trim() != "null" && !a.trim().is_empty());
            enqueue(&tx, cron, job, false, now)?;
        }
        if state == "finished" {
            enqueue_dependents(&tx, &run.cron_code, now)?;
        }
    }
    tx.commit()?;
    let _ = fs::remove_file(args_path(&env.state_dir, uuid));
    Ok(Some(state.to_string()))
}

/// `after`: po úspěchu rodiče zařadí závislé crony (bez argumentů, mimo limit).
pub fn enqueue_dependents(conn: &Connection, parent: &str, now: i64) -> Res<()> {
    let codes: Vec<String> = conn
        .prepare("SELECT code FROM crons WHERE after_code = ?1 AND defined = 1 AND local_active = 1 AND remote_active = 1 ORDER BY code")?
        .query_map([parent], |r| r.get(0))?
        .collect::<Result<_, _>>()?;
    for code in codes {
        if let Some(c) = load_cron(conn, &code)? {
            enqueue(conn, &c, NewJob::new(&code, "after", now), false, now)?;
        }
    }
    Ok(())
}

#[derive(Debug, PartialEq)]
pub enum CancelOutcome {
    /// Běh ještě nezačal (`claimed`) → rovnou `cancelled`.
    Cancelled,
    /// Běh se zastavuje (`stopping`), `cancelled` zapíše hlídací proces.
    Stopping,
    NotActive,
}

/// Zrušení běhu (`lqcron cancel`, příkaz `run.cancel`). Volá se v transakci.
pub fn cancel(conn: &Connection, uuid: &str, now: i64) -> Res<CancelOutcome> {
    let Some(run) = load_run(conn, uuid)? else {
        return Ok(CancelOutcome::NotActive);
    };
    match run.state.as_str() {
        "claimed" => {
            let n = conn.execute(
                "UPDATE runs SET state = 'cancelled', stop_reason = 'cancel', finished_at = ?1, final = 1 WHERE id = ?2 AND state = 'claimed'",
                params![now, run.id],
            )?;
            if n == 0 {
                return Ok(CancelOutcome::NotActive);
            }
            let payload = merge(
                run_common_payload(&run),
                json!({
                    "final": true, "outcome": "cancelled", "started_at": null, "finished_at": iso(now),
                    "duration_ms": 0, "max_rss_kb": null, "ram_mb": null, "exit_code": null, "signal": null,
                    "error": null, "result": null, "log_tail": null, "log_tail_truncated": false,
                }),
            );
            outbox::push(conn, "run.cancelled", Some(uuid), payload, now)?;
            Ok(CancelOutcome::Cancelled)
        }
        "starting" | "running" => {
            conn.execute(
                "UPDATE runs SET state = 'stopping', stop_reason = 'cancel' WHERE id = ?1 AND state IN ('starting','running')",
                [run.id],
            )?;
            Ok(CancelOutcome::Stopping)
        }
        "stopping" => {
            conn.execute(
                "UPDATE runs SET stop_reason = 'cancel' WHERE id = ?1 AND state = 'stopping' AND stop_reason IS NULL",
                [run.id],
            )?;
            Ok(CancelOutcome::Stopping)
        }
        _ => Ok(CancelOutcome::NotActive),
    }
}

fn set_subreaper() {
    // SAFETY: prctl s konstantními argumenty.
    unsafe {
        libc::prctl(libc::PR_SET_CHILD_SUBREAPER, 1, 0, 0, 0);
    }
}

/// Sklidí skončené potomky; vrátí stav hlavního procesu, pokud skončil.
fn reap(main: i32) -> Option<(Option<i32>, Option<i32>, i64)> {
    let mut found = None;
    loop {
        let mut status: libc::c_int = 0;
        // SAFETY: nulovaná struktura rusage.
        let mut ru: libc::rusage = unsafe { std::mem::zeroed() };
        let r = unsafe { libc::wait4(-1, &mut status, libc::WNOHANG, &mut ru) };
        if r <= 0 {
            break;
        }
        if r == main {
            let (code, sig) = if libc::WIFEXITED(status) {
                (Some(libc::WEXITSTATUS(status)), None)
            } else if libc::WIFSIGNALED(status) {
                (None, Some(libc::WTERMSIG(status)))
            } else {
                (None, None)
            };
            found = Some((code, sig, ru.ru_maxrss));
        }
    }
    found
}

fn cap_log(path: &Path, max: u64) {
    let Ok(meta) = fs::metadata(path) else { return };
    if meta.len() <= max {
        return;
    }
    let Ok((tail, _)) = read_tail(path, LOG_TAIL_BYTES) else {
        return;
    };
    let dropped = meta.len() - tail.len() as u64;
    if let Ok(mut f) = OpenOptions::new().append(true).open(path)
        && f.set_len(0).is_ok()
    {
        let _ = f.write_all(format!("[… log zkrácen, vynecháno {dropped} B …]\n").as_bytes());
        let _ = f.write_all(&tail);
    }
}

fn heartbeat(conn: &Connection, run_id: i64) {
    if let Err(e) = conn.execute(
        "UPDATE runs SET heartbeat_at = ?1 WHERE id = ?2",
        params![boottime_ms(), run_id],
    ) {
        eprintln!("lqcron: run {run_id}: heartbeat failed: {e}");
    }
}

/// Vyprázdní skupinu procesů: SIGTERM (není-li už poslaný v `term_at`), po zbytku `grace`
/// SIGKILL (plán § 7.0). Skupinu, kterou nejde zabít (D stav), neprohlásí za prázdnou:
/// čeká dál, opakuje SIGKILL a hlásí to do logu běhu — běh zůstává `stopping` (audit L3).
fn empty_group(
    conn: &Connection,
    run_id: i64,
    pgid: i64,
    grace_ms: i64,
    term_at: Option<Instant>,
    main: Option<i32>,
) -> Option<(Option<i32>, Option<i32>, i64)> {
    let mut main_status = None;
    if !group_alive(pgid) {
        return main.and_then(reap);
    }
    let term_at = term_at.unwrap_or_else(|| {
        kill_group(pgid, libc::SIGTERM);
        Instant::now()
    });
    let mut killed_at: Option<Instant> = None;
    let mut warned = false;
    let mut last_hb = Instant::now();
    loop {
        match main {
            Some(m) => {
                if let Some(s) = reap(m) {
                    main_status = Some(s);
                }
            }
            None => {
                reap(-1);
            }
        }
        if !group_alive(pgid) {
            break;
        }
        match killed_at {
            None if term_at.elapsed().as_millis() as i64 >= grace_ms => {
                kill_group(pgid, libc::SIGKILL);
                killed_at = Some(Instant::now());
            }
            Some(k) if k.elapsed() >= Duration::from_secs(10) => {
                if !warned {
                    eprintln!(
                        "lqcron: process group {pgid} survives SIGKILL (uninterruptible?); run stays `stopping` until it is empty"
                    );
                    warned = true;
                }
                kill_group(pgid, libc::SIGKILL);
                killed_at = Some(Instant::now());
            }
            _ => {}
        }
        if last_hb.elapsed().as_millis() as i64 >= HEARTBEAT_EVERY_MS {
            heartbeat(conn, run_id);
            last_hb = Instant::now();
        }
        std::thread::sleep(POLL);
    }
    main_status.or_else(|| main.and_then(reap))
}

fn spawn_php(env: &Env, run: &RunRow) -> std::io::Result<i32> {
    let dir = runs_dir(&env.state_dir);
    crate::util::ensure_dir(&dir)?;
    let log = OpenOptions::new()
        .create(true)
        .append(true)
        .mode(0o660)
        .open(log_path(&env.state_dir, &run.uuid))?;
    let log2 = log.try_clone()?;
    let timeout = run.timeout.unwrap_or(crate::model::DEFAULT_TIMEOUT);
    let mut cmd = Command::new(&env.php);
    cmd.arg("vendor/bin/monitor-worker")
        .arg("execute")
        .arg("--local")
        .arg(format!("--run-id={}", run.uuid))
        .arg(format!("--cron-code={}", run.cron_code))
        .arg(format!(
            "--arguments-file={}",
            args_path(&env.state_dir, &run.uuid).display()
        ));
    if let Some(b) = &env.bootstrap {
        cmd.arg(format!("--bootstrap={b}"));
    }
    cmd.current_dir(&env.app)
        .env("LQCRON_RUN_ID", &run.uuid)
        .env("LQCRON_STATE_DIR", &env.state_dir)
        .env("LQCRON_TIMEOUT", timeout.to_string())
        .env("LQCRON_LOG_FILE", log_path(&env.state_dir, &run.uuid))
        .stdin(Stdio::null())
        .stdout(Stdio::from(log))
        .stderr(Stdio::from(log2));
    // SAFETY: setsid je async-signal-safe.
    unsafe {
        cmd.pre_exec(|| {
            libc::setsid();
            Ok(())
        });
    }
    let child = cmd.spawn()?;
    Ok(child.id() as i32)
}

/// Dokončení s opakováním zápisu (SQLite busy): čtení z disku/PHP jednou, mezi pokusy heartbeat.
fn finalize_retrying(
    conn: &Connection,
    env: &Env,
    run_id: i64,
    uuid: &str,
    exit: &ExitInfo,
) -> Res<Option<String>> {
    let mut last_err = None;
    let mut prepared = None;
    for _ in 0..10 {
        if prepared.is_none() {
            match prepare_finalize(conn, env, uuid, exit) {
                Ok(None) => return Ok(None),
                Ok(Some(p)) => prepared = Some(p),
                Err(e) => last_err = Some(e),
            }
        }
        if let Some(p) = &prepared {
            match commit_finalize(conn, env, uuid, exit, p) {
                Ok(s) => return Ok(s),
                Err(e) => last_err = Some(e),
            }
        }
        heartbeat(conn, run_id);
        std::thread::sleep(Duration::from_secs(1));
    }
    Err(last_err.unwrap_or_else(|| fail("finalize failed")))
}

/// `lqcron run-job <uuid>`: hlídací proces jednoho běhu.
pub fn run_job(env: &Env, uuid: &str, adopt: bool) -> Res<i32> {
    set_subreaper();
    let conn = open_existing_res(&env.state_dir)?;
    let me = std::process::id() as i64;
    let my_start = proc_starttime(me);
    let Some(run) = load_run(&conn, uuid)? else {
        return Err(fail(format!("run {uuid} not found")));
    };
    if adopt {
        return adopt_run(&conn, env, &run, me, my_start);
    }
    let n = conn.execute(
        "UPDATE runs SET state = 'starting', supervisor_pid = ?1, supervisor_start = ?2, heartbeat_at = ?3,
                         app_path = COALESCE(app_path, ?4), boot_id = ?5
         WHERE id = ?6 AND state = 'claimed'",
        params![me, my_start, boottime_ms(), env.app.to_string_lossy(), boot_id(), run.id],
    )?;
    if n == 0 {
        return Ok(0); // přechod vyhrál někdo jiný (zrušení, úklid)
    }
    let run = load_run(&conn, uuid)?.unwrap_or(run);
    let main = match spawn_php(env, &run) {
        Ok(pid) => pid,
        Err(e) => {
            let exit = ExitInfo {
                supervised: true,
                error: Some(
                    json!({"class": "LqcronSpawnError", "message": format!("cannot start {}: {e}", env.php), "trace": ""}),
                ),
                ..Default::default()
            };
            finalize_retrying(&conn, env, run.id, uuid, &exit)?;
            return Ok(1);
        }
    };
    // Skupinu procesů eviduje hlídač (ne PHP): pokryje i `phpBinary` wrapper, který forkuje.
    let pgid = main as i64;
    if let Err(e) = conn.execute(
        "UPDATE runs SET pgid = ?1, pgid_start = ?2 WHERE id = ?3",
        params![pgid, proc_starttime(pgid), run.id],
    ) {
        eprintln!("lqcron: run {uuid}: cannot record process group: {e}");
    }
    let state = supervise(&conn, env, &run, main)?;
    Ok(if state.as_deref() == Some("finished") {
        0
    } else {
        1
    })
}

fn supervise(conn: &Connection, env: &Env, run: &RunRow, main: i32) -> Res<Option<String>> {
    let pgid = main as i64;
    let started = Instant::now();
    let timeout_ms = match run.timeout {
        Some(0) => None,
        Some(t) => Some(t * 1000),
        None => Some(crate::model::DEFAULT_TIMEOUT * 1000),
    };
    let log = log_path(&env.state_dir, &run.uuid);
    let mut last_hb = Instant::now();
    let mut last_log = Instant::now();
    let mut last_state = Instant::now();
    let mut term_at: Option<Instant> = None;
    let mut killed = false;
    let status = loop {
        if let Some(s) = reap(main) {
            break s;
        }
        let elapsed = started.elapsed().as_millis() as i64;
        if last_hb.elapsed().as_millis() as i64 >= HEARTBEAT_EVERY_MS {
            heartbeat(conn, run.id);
            last_hb = Instant::now();
        }
        if last_log.elapsed().as_millis() as i64 >= LOG_CHECK_EVERY_MS {
            cap_log(&log, env.run_log_max_bytes);
            last_log = Instant::now();
        }
        if term_at.is_none() {
            if let Some(t) = timeout_ms
                && elapsed >= t
            {
                if let Err(e) = conn.execute(
                    "UPDATE runs SET state = 'stopping', stop_reason = COALESCE(stop_reason, 'timeout') WHERE id = ?1 AND state IN ('starting','running','stopping')",
                    [run.id],
                ) {
                    eprintln!("lqcron: run {}: cannot mark timeout: {e}", run.uuid);
                }
                kill_group(pgid, libc::SIGTERM);
                term_at = Some(Instant::now());
            } else if last_state.elapsed() >= Duration::from_millis(500) {
                last_state = Instant::now();
                let r: Option<(String, Option<String>)> = conn
                    .query_row(
                        "SELECT state, stop_reason FROM runs WHERE id = ?1",
                        [run.id],
                        |r| Ok((r.get(0)?, r.get(1)?)),
                    )
                    .ok();
                if let Some((state, reason)) = r
                    && state == "stopping"
                    && reason.is_some()
                {
                    kill_group(pgid, libc::SIGTERM);
                    term_at = Some(Instant::now());
                }
            }
        } else if let Some(t) = term_at
            && !killed
            && t.elapsed().as_millis() as i64 >= env.kill_grace_ms
        {
            kill_group(pgid, libc::SIGKILL);
            killed = true;
        }
        std::thread::sleep(POLL);
    };
    // Hlavní proces skončil; skupina musí být prázdná, jinak se běh počítá jako aktivní (stopping).
    if group_alive(pgid) {
        if let Err(e) = conn.execute(
            "UPDATE runs SET state = 'stopping' WHERE id = ?1 AND state IN ('starting','running')",
            [run.id],
        ) {
            eprintln!("lqcron: run {}: cannot mark stopping: {e}", run.uuid);
        }
        // kill grace jen jednou: po SIGTERM z timeoutu/zrušení běží dál jeho zbytek (audit L5)
        let grace = if killed { 0 } else { env.kill_grace_ms };
        empty_group(conn, run.id, pgid, grace, term_at, None);
    }
    cap_log(&log, env.run_log_max_bytes);
    let (code, sig, rss) = status;
    let exit = ExitInfo {
        exit_code: code,
        signal: sig,
        max_rss_kb: Some(rss),
        supervised: true,
        error: None,
    };
    finalize_retrying(conn, env, run.id, &run.uuid, &exit)
}

/// Skupina procesů běhu, kterou smí adopce ukončit — jen když prokazatelně patří tomuto
/// běhu (stejný boot, nerecyklovaný pid). Starší řádky bez `pgid` (v1) → `pid` + `pid_start`.
pub fn owned_group(run: &RunRow) -> Option<i64> {
    if run.pgid.is_some() {
        return group_is_ours(run.pgid, run.pgid_start, run.boot_id.as_deref())
            .then(|| run.pgid.unwrap_or(0));
    }
    let same_boot = match (run.boot_id.as_deref(), boot_id()) {
        (Some(b), Some(c)) => b == c,
        _ => true,
    };
    (same_boot && run.pid_start.is_some() && pid_alive(run.pid, run.pid_start))
        .then(|| run.pid.unwrap_or(0))
}

/// Dořešení běhu po mrtvém hlídacím procesu (plán § 7.3).
fn adopt_run(
    conn: &Connection,
    env: &Env,
    run: &RunRow,
    me: i64,
    my_start: Option<i64>,
) -> Res<i32> {
    let n = conn.execute(
        "UPDATE runs SET supervisor_pid = ?1, supervisor_start = ?2, heartbeat_at = ?3
         WHERE id = ?4 AND state IN ('starting','running','stopping') AND supervisor_pid IS ?5 AND supervisor_start IS ?6",
        params![me, my_start, boottime_ms(), run.id, run.supervisor_pid, run.supervisor_start],
    )?;
    if n == 0 {
        return Ok(0);
    }
    let has_marker = marker_path(&env.state_dir, &run.uuid).exists();
    // Jiný boot nebo recyklovaný pid: nic nezabíjet (audit H1), proces by byl cizí.
    let group = owned_group(run);
    let php_alive = group.is_some_and(group_alive);
    if !has_marker && php_alive {
        conn.execute(
            "UPDATE runs SET state = 'stopping', stop_reason = COALESCE(stop_reason, 'lost') WHERE id = ?1 AND state IN ('starting','running','stopping')",
            [run.id],
        )?;
    }
    if let Some(pgid) = group {
        empty_group(conn, run.id, pgid, env.kill_grace_ms, None, None);
    }
    let exit = ExitInfo::default();
    let state = finalize_retrying(conn, env, run.id, &run.uuid, &exit)?;
    Ok(if state.as_deref() == Some("finished") {
        0
    } else {
        1
    })
}

#[cfg(test)]
pub mod tests {
    use super::*;
    use crate::db::testutil::fresh_db;
    use crate::queue::tests::insert_cron;

    fn env(state_dir: &Path) -> Env {
        Env {
            state_dir: state_dir.to_path_buf(),
            app: state_dir.to_path_buf(),
            php: "php".into(),
            bootstrap: None,
            kill_grace_ms: 100,
            run_log_max_bytes: 1 << 20,
            report_log_tail: true,
            log_redactor: false,
        }
    }

    fn insert_run(c: &Connection, uuid: &str, code: &str, state: &str, attempt: i64, max: i64) {
        c.execute(
            "INSERT INTO runs (uuid, job_uuid, origin_uuid, cron_code, attempt, max_attempts, source, job_created_at, concurrency_mode, state, claimed_at, started_at)
             VALUES (?1, ?1 || '-job', 'origin-1', ?2, ?3, ?4, 'schedule', 1, 'exclusive', ?5, 1, 2)",
            params![uuid, code, attempt, max, state],
        )
        .unwrap();
    }

    fn write_marker(dir: &Path, uuid: &str, body: Value) {
        fs::create_dir_all(runs_dir(dir)).unwrap();
        fs::write(marker_path(dir, uuid), body.to_string()).unwrap();
    }

    fn types(c: &Connection) -> Vec<String> {
        c.prepare("SELECT type FROM outbox ORDER BY seq")
            .unwrap()
            .query_map([], |r| r.get(0))
            .unwrap()
            .map(Result::unwrap)
            .collect()
    }

    fn supervised(code: i32) -> ExitInfo {
        ExitInfo {
            exit_code: Some(code),
            supervised: true,
            max_rss_kb: Some(1000),
            ..Default::default()
        }
    }

    #[test]
    fn decision_table() {
        let fin = Marker {
            status: "finished".into(),
            explicit_fail: false,
            result: Value::Null,
            result_truncated: false,
            error: Value::Null,
            peak_memory_mb: None,
            finished_at: None,
        };
        let mut failm = fin.clone();
        failm.status = "failed".into();
        let mut explicit = fin.clone();
        explicit.explicit_fail = true;
        assert_eq!(decide(None, Some(&fin), &supervised(0)), "finished");
        assert_eq!(decide(None, Some(&fin), &supervised(1)), "failed");
        assert_eq!(decide(None, Some(&failm), &supervised(0)), "failed");
        assert_eq!(decide(None, Some(&explicit), &supervised(0)), "failed");
        assert_eq!(decide(None, None, &supervised(0)), "failed");
        assert_eq!(decide(None, None, &ExitInfo::default()), "lost");
        assert_eq!(decide(None, Some(&fin), &ExitInfo::default()), "finished");
        assert_eq!(
            decide(Some("timeout"), Some(&fin), &supervised(0)),
            "timeout"
        );
        assert_eq!(decide(Some("cancel"), None, &supervised(0)), "cancelled");
    }

    #[test]
    fn failed_run_is_retried_with_same_origin_and_arguments() {
        let (d, c) = fresh_db("fin-retry");
        insert_cron(&c, "a", None, "exclusive");
        insert_run(&c, "r1", "a", "running", 1, 2);
        fs::create_dir_all(runs_dir(&d.0)).unwrap();
        fs::write(args_path(&d.0, "r1"), r#"{"x":1}"#).unwrap();
        fs::write(log_path(&d.0, "r1"), "boom\n").unwrap();
        write_marker(
            &d.0,
            "r1",
            json!({"status": "failed", "error": {"class": "E", "message": "m", "trace": "#0"}}),
        );
        let s = finalize(&c, &env(&d.0), "r1", &supervised(1)).unwrap();
        assert_eq!(s.as_deref(), Some("failed"));
        let (attempt, origin, args, source): (i64, String, Option<String>, String) = c
            .query_row(
                "SELECT attempt, origin_uuid, arguments, source FROM jobs",
                [],
                |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?)),
            )
            .unwrap();
        assert_eq!(
            (attempt, origin.as_str(), args.as_deref(), source.as_str()),
            (2, "origin-1", Some(r#"{"x":1}"#), "retry")
        );
        assert_eq!(types(&c), vec!["run.failed", "job.queued"]);
        let blob: Vec<u8> = c
            .query_row(
                "SELECT payload FROM outbox WHERE type='run.failed'",
                [],
                |r| r.get(0),
            )
            .unwrap();
        let p: Value = serde_json::from_slice(&blob).unwrap();
        assert_eq!(p["final"], false);
        assert_eq!(p["log_tail"], "boom\n");
        assert_eq!(p["error"]["class"], "E");
        assert!(!args_path(&d.0, "r1").exists());
        // druhé dokončení nic neudělá (CAS)
        assert_eq!(
            finalize(&c, &env(&d.0), "r1", &supervised(1)).unwrap(),
            None
        );
    }

    #[test]
    fn last_attempt_is_final_and_not_retried() {
        let (d, c) = fresh_db("fin-final");
        insert_cron(&c, "a", None, "exclusive");
        insert_run(&c, "r1", "a", "running", 2, 2);
        let s = finalize(&c, &env(&d.0), "r1", &supervised(255)).unwrap();
        assert_eq!(s.as_deref(), Some("failed"));
        let n: i64 = c
            .query_row("SELECT COUNT(*) FROM jobs", [], |r| r.get(0))
            .unwrap();
        assert_eq!(n, 0);
        let fin: i64 = c
            .query_row("SELECT final FROM runs", [], |r| r.get(0))
            .unwrap();
        assert_eq!(fin, 1);
    }

    #[test]
    fn cancelled_is_never_retried() {
        let (d, c) = fresh_db("fin-cancel");
        insert_cron(&c, "a", None, "exclusive");
        insert_run(&c, "r1", "a", "running", 1, 3);
        assert_eq!(cancel(&c, "r1", 5).unwrap(), CancelOutcome::Stopping);
        let s = finalize(&c, &env(&d.0), "r1", &supervised(143)).unwrap();
        assert_eq!(s.as_deref(), Some("cancelled"));
        let n: i64 = c
            .query_row("SELECT COUNT(*) FROM jobs", [], |r| r.get(0))
            .unwrap();
        assert_eq!(n, 0);
        // zrušení převzatého (ještě nespuštěného) běhu je okamžité
        insert_run(&c, "r2", "a", "claimed", 1, 3);
        assert_eq!(cancel(&c, "r2", 5).unwrap(), CancelOutcome::Cancelled);
        assert_eq!(cancel(&c, "r2", 5).unwrap(), CancelOutcome::NotActive);
    }

    #[test]
    fn finished_enqueues_after_dependents_once() {
        let (d, c) = fresh_db("fin-after");
        insert_cron(&c, "parent", None, "exclusive");
        insert_cron(&c, "child", None, "exclusive");
        insert_cron(&c, "child_off", None, "exclusive");
        c.execute(
            "UPDATE crons SET after_code = 'parent' WHERE code IN ('child','child_off')",
            [],
        )
        .unwrap();
        c.execute(
            "UPDATE crons SET local_active = 0 WHERE code = 'child_off'",
            [],
        )
        .unwrap();
        insert_run(&c, "r1", "parent", "running", 2, 3); // úspěch až v opakovaném pokusu
        write_marker(
            &d.0,
            "r1",
            json!({"status": "finished", "result": {"n": 1}, "peak_memory_mb": 12}),
        );
        let s = finalize(&c, &env(&d.0), "r1", &supervised(0)).unwrap();
        assert_eq!(s.as_deref(), Some("finished"));
        let j: Vec<(String, String)> = c
            .prepare("SELECT cron_code, source FROM jobs")
            .unwrap()
            .query_map([], |r| Ok((r.get(0)?, r.get(1)?)))
            .unwrap()
            .map(Result::unwrap)
            .collect();
        assert_eq!(j, vec![("child".to_string(), "after".to_string())]);
        let blob: Vec<u8> = c
            .query_row(
                "SELECT payload FROM outbox WHERE type='run.finished'",
                [],
                |r| r.get(0),
            )
            .unwrap();
        let p: Value = serde_json::from_slice(&blob).unwrap();
        assert_eq!(p["result"]["n"], 1);
        assert_eq!(p["ram_mb"], 12.0);
        assert_eq!(p["final"], true);
    }

    #[test]
    fn failed_parent_does_not_enqueue_dependents() {
        let (d, c) = fresh_db("fin-after-fail");
        insert_cron(&c, "parent", None, "exclusive");
        insert_cron(&c, "child", None, "exclusive");
        c.execute(
            "UPDATE crons SET after_code = 'parent' WHERE code = 'child'",
            [],
        )
        .unwrap();
        insert_run(&c, "r1", "parent", "running", 1, 1);
        finalize(&c, &env(&d.0), "r1", &supervised(1)).unwrap();
        let n: i64 = c
            .query_row("SELECT COUNT(*) FROM jobs", [], |r| r.get(0))
            .unwrap();
        assert_eq!(n, 0);
    }

    #[test]
    fn lost_without_marker_when_supervisor_died() {
        let (d, c) = fresh_db("fin-lost");
        insert_cron(&c, "a", None, "exclusive");
        insert_run(&c, "r1", "a", "running", 1, 2);
        let s = finalize(&c, &env(&d.0), "r1", &ExitInfo::default()).unwrap();
        assert_eq!(s.as_deref(), Some("lost"));
        let n: i64 = c
            .query_row("SELECT COUNT(*) FROM jobs WHERE source='retry'", [], |r| {
                r.get(0)
            })
            .unwrap();
        assert_eq!(n, 1); // lost se opakuje jako failed
    }

    /// Proces v nové session (jako cizí `setsid` skupina), vrací (pid, starttime).
    pub fn foreign_group() -> (std::process::Child, i64, i64) {
        let mut cmd = Command::new("sleep");
        cmd.arg("30");
        // SAFETY: setsid je async-signal-safe.
        unsafe {
            cmd.pre_exec(|| {
                libc::setsid();
                Ok(())
            });
        }
        let child = cmd.spawn().unwrap();
        let pid = child.id() as i64;
        let mut start = None;
        for _ in 0..50 {
            start = proc_starttime(pid);
            if start.is_some() {
                break;
            }
            std::thread::sleep(Duration::from_millis(10));
        }
        (child, pid, start.unwrap())
    }

    fn adopt_case(
        tag: &str,
        pgid_start_delta: i64,
        boot: Option<String>,
    ) -> (bool, Option<String>) {
        let (d, c) = fresh_db(tag);
        insert_cron(&c, "a", None, "exclusive");
        insert_run(&c, "r1", "a", "running", 1, 1);
        let (mut child, pid, start) = foreign_group();
        // mrtvý hlídač, PHP „skupina“ = cizí proces
        c.execute(
            "UPDATE runs SET supervisor_pid = 999999999, supervisor_start = 1, pid = ?1, pid_start = ?2, pgid = ?1, pgid_start = ?2, boot_id = ?3",
            params![pid, start + pgid_start_delta, boot],
        )
        .unwrap();
        drop(c);
        run_job(&env(&d.0), "r1", true).unwrap();
        std::thread::sleep(Duration::from_millis(100));
        let alive = matches!(child.try_wait(), Ok(None));
        let _ = child.kill();
        let _ = child.wait();
        let c = crate::db::open_existing(&d.0).unwrap();
        let state: Option<String> = c.query_row("SELECT state FROM runs", [], |r| r.get(0)).ok();
        (alive, state)
    }

    #[test]
    fn adopt_never_kills_a_recycled_pid() {
        // stejný pid, jiný čas startu = recyklovaný pid → cizí proces žije dál, běh `lost`
        let (alive, state) = adopt_case("adopt-reuse", 1, boot_id());
        assert!(alive, "foreign process was killed");
        assert_eq!(state.as_deref(), Some("lost"));
    }

    #[test]
    fn adopt_never_kills_across_reboot() {
        let (alive, state) = adopt_case("adopt-boot", 0, Some("another-boot".into()));
        assert!(
            alive,
            "process from the current boot was killed for a run of another boot"
        );
        assert_eq!(state.as_deref(), Some("lost"));
    }

    #[test]
    fn adopt_kills_its_own_live_group() {
        let (alive, state) = adopt_case("adopt-own", 0, boot_id());
        assert!(!alive, "own orphaned group must be emptied");
        assert_eq!(state.as_deref(), Some("lost"));
    }

    #[test]
    fn timeout_kills_whole_group_with_a_single_grace_period() {
        let (d, c) = fresh_db("timeout-grace");
        insert_cron(&c, "a", None, "exclusive");
        insert_run(&c, "r1", "a", "claimed", 1, 1);
        c.execute("UPDATE runs SET timeout = 1", []).unwrap();
        // „PHP“, které SIGTERM ignoruje a má vnouče ve stejné skupině
        let php = d.0.join("fake-php.sh");
        fs::write(
            &php,
            "#!/bin/sh\ntrap '' TERM\nsleep 30 &\nwhile true; do sleep 1; done\n",
        )
        .unwrap();
        use std::os::unix::fs::PermissionsExt;
        fs::set_permissions(&php, fs::Permissions::from_mode(0o755)).unwrap();
        drop(c);
        let mut e = env(&d.0);
        e.php = php.to_string_lossy().into_owned();
        e.kill_grace_ms = 1000;
        let t = Instant::now();
        assert_eq!(run_job(&e, "r1", false).unwrap(), 1);
        let took = t.elapsed();
        // 1 s timeout + 1 s grace (ne 2× grace, audit L5)
        assert!(took < Duration::from_millis(2800), "{took:?}");
        let c = crate::db::open_existing(&d.0).unwrap();
        let (state, pgid): (String, i64) = c
            .query_row("SELECT state, pgid FROM runs", [], |r| {
                Ok((r.get(0)?, r.get(1)?))
            })
            .unwrap();
        assert_eq!(state, "timeout");
        assert!(!group_alive(pgid), "grandchild survived");
    }

    #[test]
    fn error_fields_are_capped() {
        let e = cap_error(
            json!({"class": "E", "message": "m".repeat(200_000), "trace": "t".repeat(70_000)}),
        );
        assert!(e["message"].as_str().unwrap().len() < ERROR_FIELD_MAX + 100);
        assert!(e["trace"].as_str().unwrap().len() < ERROR_FIELD_MAX + 100);
        assert_eq!(e["class"], "E");
    }

    #[test]
    fn log_is_capped_with_marker() {
        let (d, _c) = fresh_db("caplog");
        let p = d.0.join("x.log");
        fs::write(&p, vec![b'a'; 300_000]).unwrap();
        cap_log(&p, 200_000);
        let s = fs::read_to_string(&p).unwrap();
        assert!(s.starts_with("[… log zkrácen, vynecháno"));
        assert!(s.len() < 70_000);
    }
}

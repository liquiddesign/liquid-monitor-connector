//! Integrační test: tick → run-job → fake PHP `execute` → finished / failed / timeout /
//! cancelled → outbox → flush do mock LQDecku. Potřebuje `php` s rozšířením sqlite3;
//! bez něj se přeskočí.

use std::io::{BufRead, BufReader, Read, Write};
use std::net::TcpListener;
use std::path::{Path, PathBuf};
use std::process::Command;
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use rusqlite::Connection;
use serde_json::{Value, json};

const BIN: &str = env!("CARGO_BIN_EXE_lqcron");

fn php_available() -> bool {
    let ok = Command::new("php")
        .args(["-r", "exit(class_exists('SQLite3') ? 0 : 1);"])
        .status()
        .map(|s| s.success())
        .unwrap_or(false);
    // CI nastaví LQCRON_REQUIRE_PHP=1, aby se test nepřeskočil potichu
    assert!(
        ok || std::env::var("LQCRON_REQUIRE_PHP").is_err(),
        "php with the sqlite3 extension is required"
    );
    ok
}

struct Sandbox {
    root: PathBuf,
    app: PathBuf,
    state: PathBuf,
}

impl Drop for Sandbox {
    fn drop(&mut self) {
        // dobít případné zbytky (slow běhy) a uklidit
        let _ = Command::new("pkill")
            .args(["-f", &self.root.to_string_lossy()])
            .status();
        let _ = std::fs::remove_dir_all(&self.root);
    }
}

fn sandbox(url: Option<&str>) -> Sandbox {
    let root = std::env::temp_dir().join(format!(
        "lqcron-it-{}-{}",
        std::process::id(),
        rand_suffix()
    ));
    let app = root.join("app");
    let state = root.join("state");
    std::fs::create_dir_all(app.join("vendor/bin")).unwrap();
    std::fs::create_dir_all(app.join("temp/lqcron")).unwrap();
    std::fs::copy(
        Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/monitor-worker.php"),
        app.join("vendor/bin/monitor-worker"),
    )
    .unwrap();
    let sb = Sandbox { root, app, state };
    write_export(&sb, url);
    sb
}

fn rand_suffix() -> String {
    static N: std::sync::atomic::AtomicUsize = std::sync::atomic::AtomicUsize::new(0);
    let n = N.fetch_add(1, std::sync::atomic::Ordering::SeqCst);
    let t = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .unwrap()
        .as_nanos();
    format!("{n}-{t}")
}

fn write_export(sb: &Sandbox, url: Option<&str>) {
    let export = json!({
        "format": 1,
        "generated_at": "2026-10-16T10:00:00.000+00:00",
        "connector_version": "4.0.0",
        "app_path": sb.app,
        "php_binary": "php",
        "config_files": [],
        "hash": format!("h-{}", url.is_some()),
        "config": {
            "enabled": true,
            "shadow": false,
            "environment": "test",
            "state_dir": sb.state,
            "timezone": "Europe/Prague",
            "max_parallel": 4,
            "starvation_seconds": 300,
            "run_log_max_bytes": 52428800,
            "kill_grace_seconds": 1,
            "report_log_tail": true,
            "log_redactor": true,
            "remote_commands": ["cron.set_active", "cron.run_now", "run.cancel"],
            "resources": {},
            "services": {},
            "php_binary": "php",
            "bootstrap": null,
            "url": url,
            "api_key": url.map(|_| "test-key"),
            "verify_tls": true
        },
        "crons": [
            {"code": "ok", "handler_class": "App\\Ok", "schedule": null, "timeout": null, "repeat_count": 0, "concurrency_mode": "independent", "resources": [], "after": null},
            {"code": "after_ok", "handler_class": "App\\AfterOk", "schedule": null, "timeout": null, "repeat_count": 0, "concurrency_mode": "independent", "resources": [], "after": "ok"},
            {"code": "fail", "handler_class": "App\\Fail", "schedule": null, "timeout": null, "repeat_count": 1, "concurrency_mode": "independent", "resources": []},
            {"code": "slow", "handler_class": "App\\Slow", "schedule": null, "timeout": 2, "repeat_count": 0, "concurrency_mode": "independent", "resources": []},
            {"code": "crash", "handler_class": "App\\Crash", "schedule": null, "timeout": null, "repeat_count": 0, "concurrency_mode": "independent", "resources": []}
        ],
        "warnings": []
    });
    let s = serde_json::to_string_pretty(&export).unwrap();
    std::fs::write(sb.app.join("export.source.json"), &s).unwrap();
    std::fs::write(sb.app.join("temp/lqcron/export.json"), &s).unwrap();
}

/// Ukazatele `stateDir` mimo slot jdou do sandboxu, ne do ~/.local/state.
fn cmd(sb: &Sandbox) -> Command {
    let mut c = Command::new(BIN);
    c.current_dir(&sb.app)
        .env("XDG_STATE_HOME", sb.root.join("xdg"));
    c
}

fn lqcron(sb: &Sandbox, args: &[&str]) -> (i32, String, String) {
    lqcron_in(sb, &sb.app, args)
}

fn lqcron_in(sb: &Sandbox, dir: &Path, args: &[&str]) -> (i32, String, String) {
    let out = cmd(sb).current_dir(dir).args(args).output().unwrap();
    (
        out.status.code().unwrap_or(-1),
        String::from_utf8_lossy(&out.stdout).into_owned(),
        String::from_utf8_lossy(&out.stderr).into_owned(),
    )
}

fn db(sb: &Sandbox) -> Connection {
    let c = Connection::open(sb.state.join("lqcron.sqlite")).unwrap();
    c.busy_timeout(Duration::from_secs(5)).unwrap();
    c
}

fn enqueue(sb: &Sandbox, code: &str) -> String {
    let (rc, out, err) = lqcron(sb, &["enqueue", code, "--args={\"x\":1}"]);
    assert_eq!(rc, 0, "enqueue {code}: {err}");
    out.trim().to_string()
}

/// Tickuje, dokud poslední běh cronu není ve finálním stavu (nebo `want`).
fn wait_state(sb: &Sandbox, code: &str, want: &[&str], timeout: Duration) -> (String, String) {
    let start = Instant::now();
    loop {
        let (rc, _, err) = lqcron(sb, &["tick"]);
        assert!(rc == 0, "tick failed: {err}");
        let row: Option<(String, String)> = db(sb)
            .query_row(
                "SELECT uuid, state FROM runs WHERE cron_code = ?1 ORDER BY id DESC LIMIT 1",
                [code],
                |r| Ok((r.get(0)?, r.get(1)?)),
            )
            .ok();
        if let Some((uuid, state)) = &row
            && want.contains(&state.as_str())
        {
            return (uuid.clone(), state.clone());
        }
        if start.elapsed() >= timeout {
            let (_, st, _) = lqcron(sb, &["status"]);
            let (_, tk, tkerr) = lqcron(sb, &["tick"]);
            let c = db(sb);
            let runs: Vec<String> = c
                .prepare("SELECT cron_code || ':' || state || ':' || uuid FROM runs ORDER BY id")
                .unwrap()
                .query_map([], |r| r.get(0))
                .unwrap()
                .map(Result::unwrap)
                .collect();
            let ev: Vec<String> = c.prepare("SELECT type || ':' || CAST(payload AS TEXT) FROM outbox WHERE type LIKE 'job.%' OR type LIKE 'command%' ORDER BY seq").unwrap()
                .query_map([], |r| r.get(0)).unwrap().map(Result::unwrap).collect();
            eprintln!("RUNS {runs:#?}\nEV {ev:#?}");
            panic!(
                "{code}: timed out waiting for {want:?}, last {row:?}\n{st}\ntick: {tk} {tkerr}"
            );
        }
        std::thread::sleep(Duration::from_millis(300));
    }
}

fn payload(c: &Connection, ty: &str, run: &str) -> Value {
    let (blob, enc): (Vec<u8>, String) = c
        .query_row(
            "SELECT payload, encoding FROM outbox WHERE type = ?1 AND run_uuid = ?2 ORDER BY seq DESC LIMIT 1",
            [ty, run],
            |r| {
                let blob = match r.get_ref(0)? {
                    rusqlite::types::ValueRef::Text(t) | rusqlite::types::ValueRef::Blob(t) => t.to_vec(),
                    _ => vec![],
                };
                Ok((blob, r.get(1)?))
            },
        )
        .unwrap_or_else(|e| panic!("{ty} for {run}: {e}"));
    assert_eq!(enc, "json");
    serde_json::from_slice(&blob).unwrap()
}

struct Mock {
    url: String,
    events: Arc<Mutex<Vec<Value>>>,
}

fn mock() -> Mock {
    mock_with(vec![])
}

/// Mock LQDecku; `commands` vrací v `GET v4/commands`, dokud nedorazí jejich potvrzení.
fn mock_with(commands: Vec<Value>) -> Mock {
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let url = format!("http://{}/api/connector", listener.local_addr().unwrap());
    let events = Arc::new(Mutex::new(Vec::new()));
    let ev = events.clone();
    let pending = Arc::new(Mutex::new(commands));
    std::thread::spawn(move || {
        for stream in listener.incoming() {
            let Ok(mut stream) = stream else { continue };
            let mut reader = BufReader::new(stream.try_clone().unwrap());
            let mut line = String::new();
            reader.read_line(&mut line).unwrap_or(0);
            let mut len = 0usize;
            let mut key_ok = false;
            loop {
                let mut h = String::new();
                if reader.read_line(&mut h).unwrap_or(0) == 0 || h == "\r\n" {
                    break;
                }
                let lower = h.to_ascii_lowercase();
                if let Some(v) = lower.strip_prefix("content-length:") {
                    len = v.trim().parse().unwrap_or(0);
                }
                if lower.starts_with("x-api-key: test-key") {
                    key_ok = true;
                }
            }
            let mut body = vec![0u8; len];
            let _ = reader.read_exact(&mut body);
            let resp = if !key_ok {
                (401, "{}".to_string())
            } else if line.starts_with("GET /api/connector/v4/commands") {
                (
                    200,
                    json!({"commands": *pending.lock().unwrap()}).to_string(),
                )
            } else if line.starts_with("POST /api/connector/v4/events") {
                let v: Value = serde_json::from_slice(&body).unwrap();
                let ids: Vec<Value> = v["events"]
                    .as_array()
                    .unwrap()
                    .iter()
                    .map(|e| e["event_id"].clone())
                    .collect();
                for e in v["events"].as_array().unwrap() {
                    if e["type"]
                        .as_str()
                        .is_some_and(|t| t.starts_with("command."))
                    {
                        let id = e["payload"]["command_id"].clone();
                        pending.lock().unwrap().retain(|c| c["command_id"] != id);
                    }
                }
                ev.lock()
                    .unwrap()
                    .extend(v["events"].as_array().unwrap().iter().cloned());
                (
                    200,
                    json!({"accepted": ids, "rejected": [], "max_batch": 200}).to_string(),
                )
            } else {
                (404, "{}".to_string())
            };
            let out = format!(
                "HTTP/1.1 {} X\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}",
                resp.0,
                resp.1.len(),
                resp.1
            );
            let _ = stream.write_all(out.as_bytes());
        }
    });
    Mock { url, events }
}

#[test]
fn version_prints_schema() {
    let out = Command::new(BIN).arg("--version").output().unwrap();
    let s = String::from_utf8_lossy(&out.stdout);
    assert!(s.starts_with("lqcron "), "{s}");
    assert!(s.contains("schema 2"), "{s}");
}

#[test]
fn tick_run_job_lifecycle_and_delivery() {
    if !php_available() {
        eprintln!("integration test skipped: php with sqlite3 extension not available");
        return;
    }
    let sb = sandbox(None);

    // první tick založí databázi a aplikuje definice, nic neplánuje
    let (rc, _, err) = lqcron(&sb, &["tick"]);
    assert_eq!(rc, 0, "{err}");
    assert!(sb.state.join("lqcron.sqlite").exists());
    assert!(sb.state.join("instance_id").exists());
    let crons: i64 = db(&sb)
        .query_row("SELECT COUNT(*) FROM crons", [], |r| r.get(0))
        .unwrap();
    assert_eq!(crons, 5);
    assert!(
        std::fs::read_dir(sb.state.join("bin"))
            .unwrap()
            .next()
            .is_some(),
        "binary copy in stateDir/bin"
    );

    // finished + after
    enqueue(&sb, "ok");
    let (ok_run, state) = wait_state(
        &sb,
        "ok",
        &["finished", "failed", "timeout", "lost"],
        Duration::from_secs(30),
    );
    assert_eq!(state, "finished");
    let c = db(&sb);
    let p = payload(&c, "run.finished", &ok_run);
    assert_eq!(p["final"], true);
    assert_eq!(p["result"]["imported"], 120);
    assert_eq!(p["exit_code"], 0);
    assert!(p["max_rss_kb"].as_i64().unwrap() > 0);
    assert!(p["started_at"].as_str().is_some());
    let _ = payload(&c, "run.started", &ok_run);
    assert!(
        !sb.state.join(format!("runs/{ok_run}.args.json")).exists(),
        "args file removed"
    );
    let log = std::fs::read_to_string(sb.state.join(format!("runs/{ok_run}.log"))).unwrap();
    assert!(log.contains("hello from ok, arguments {\"x\":1}"), "{log}");
    let (_, state) = wait_state(&sb, "after_ok", &["finished"], Duration::from_secs(30));
    assert_eq!(state, "finished");

    // failed → opakování → finální failed, konec logu přes redakci
    enqueue(&sb, "fail");
    let t0 = Instant::now();
    let failed: Vec<(String, i64)> = loop {
        let (rc, _, err) = lqcron(&sb, &["tick"]);
        assert_eq!(rc, 0, "{err}");
        let rows: Vec<(String, i64)> = db(&sb)
            .prepare("SELECT uuid, attempt FROM runs WHERE cron_code = 'fail' AND state = 'failed' ORDER BY attempt")
            .unwrap()
            .query_map([], |r| Ok((r.get(0)?, r.get(1)?)))
            .unwrap()
            .map(Result::unwrap)
            .collect();
        if rows.len() == 2 {
            break rows;
        }
        assert!(
            t0.elapsed() < Duration::from_secs(40),
            "fail runs: {rows:?}"
        );
        std::thread::sleep(Duration::from_millis(300));
    };
    let p = payload(&db(&sb), "run.failed", &failed[0].0);
    assert_eq!(p["final"], false);
    assert_eq!(p["attempt"], 1);
    assert_eq!(p["error"]["message"], "boom");
    let tail = p["log_tail"].as_str().unwrap();
    assert!(
        tail.contains("*** token") && !tail.contains("SECRET"),
        "{tail}"
    );
    let p = payload(&db(&sb), "run.failed", &failed[1].0);
    assert_eq!(p["final"], true);
    assert_eq!(p["attempt"], 2);
    let origins: i64 = db(&sb)
        .query_row(
            "SELECT COUNT(DISTINCT origin_uuid) FROM runs WHERE cron_code = 'fail'",
            [],
            |r| r.get(0),
        )
        .unwrap();
    assert_eq!(origins, 1);

    // nenulový exit bez markeru → failed
    enqueue(&sb, "crash");
    let (crash, state) = wait_state(
        &sb,
        "crash",
        &["failed", "finished", "lost"],
        Duration::from_secs(30),
    );
    assert_eq!(state, "failed");
    let p = payload(&db(&sb), "run.failed", &crash);
    assert_eq!(p["exit_code"], 3);
    assert_eq!(p["error"]["class"], "LqcronNoMarker");

    // timeout → SIGTERM skupině (i vnoučeti), stav timeout
    enqueue(&sb, "slow");
    let (slow, state) = wait_state(
        &sb,
        "slow",
        &["timeout", "finished", "failed"],
        Duration::from_secs(40),
    );
    assert_eq!(state, "timeout");
    let p = payload(&db(&sb), "run.timeout", &slow);
    assert_eq!(p["outcome"], "timeout");
    assert_eq!(p["final"], true);
    assert!(p["signal"].as_i64().is_some() || p["exit_code"].as_i64().is_some());

    // zrušení běžícího běhu
    enqueue(&sb, "slow");
    let (slow2, _) = wait_state(&sb, "slow", &["running"], Duration::from_secs(30));
    assert_ne!(slow2, slow);
    let (rc, _, err) = lqcron(&sb, &["cancel", &slow2]);
    assert_eq!(rc, 0, "{err}");
    let (_, state) = wait_state(
        &sb,
        "slow",
        &["cancelled", "timeout", "failed"],
        Duration::from_secs(30),
    );
    assert_eq!(state, "cancelled");
    let jobs: i64 = db(&sb)
        .query_row("SELECT COUNT(*) FROM jobs", [], |r| r.get(0))
        .unwrap();
    assert_eq!(jobs, 0, "cancelled is not retried");

    // status --json
    let (rc, out, err) = lqcron(&sb, &["status", "--json"]);
    assert_eq!(rc, 0, "{err}");
    let st: Value = serde_json::from_str(&out).unwrap();
    assert_eq!(st["schema_version"], 2);
    assert!(st["outbox_size"].as_i64().unwrap() > 0);

    // doručení: flush do mock LQDecku vyprázdní outbox
    let m = mock();
    write_export(&sb, Some(&m.url));
    let (rc, out, err) = lqcron(&sb, &["flush", "--timeout=30"]);
    assert_eq!(rc, 0, "{out} {err}");
    let left: i64 = db(&sb)
        .query_row("SELECT COUNT(*) FROM outbox", [], |r| r.get(0))
        .unwrap();
    assert_eq!(left, 0);
    let events = m.events.lock().unwrap();
    let types: std::collections::BTreeSet<String> = events
        .iter()
        .map(|e| e["type"].as_str().unwrap().to_string())
        .collect();
    for t in [
        "definitions.sync",
        "job.queued",
        "run.started",
        "run.finished",
        "run.failed",
        "run.timeout",
        "run.cancelled",
    ] {
        assert!(types.contains(t), "{t} not delivered: {types:?}");
    }
    let seqs: Vec<i64> = events.iter().map(|e| e["seq"].as_i64().unwrap()).collect();
    let mut sorted = seqs.clone();
    sorted.sort();
    assert_eq!(seqs, sorted, "delivered in seq order");
}

#[test]
fn run_sync_executes_in_foreground() {
    if !php_available() {
        return;
    }
    let sb = sandbox(None);
    let (rc, _, err) = lqcron(&sb, &["tick"]);
    assert_eq!(rc, 0, "{err}");
    let (rc, out, err) = lqcron(&sb, &["run", "ok", "--sync"]);
    assert_eq!(rc, 0, "{err}");
    assert!(out.contains("hello from ok"), "{out}");
    assert!(err.contains("# finished"), "{err}");
}

#[test]
fn commands_outside_app_need_state_dir() {
    let dir = std::env::temp_dir();
    let out = Command::new(BIN)
        .arg("status")
        .current_dir(&dir)
        .output()
        .unwrap();
    assert_eq!(out.status.code(), Some(1));
    assert!(String::from_utf8_lossy(&out.stderr).contains("--state-dir"));
}

#[test]
fn state_dir_change_is_refused_without_move_state() {
    if !php_available() {
        return;
    }
    let sb = sandbox(None);
    let (rc, _, err) = lqcron(&sb, &["tick"]);
    assert_eq!(rc, 0, "{err}");
    // NEON přepíše stateDir
    let path = sb.app.join("temp/lqcron/export.json");
    let mut v: Value = serde_json::from_str(&std::fs::read_to_string(&path).unwrap()).unwrap();
    let new_state = sb.root.join("state2");
    v["config"]["state_dir"] = json!(new_state);
    std::fs::write(&path, v.to_string()).unwrap();
    std::fs::write(sb.app.join("export.source.json"), v.to_string()).unwrap();
    let (rc, _, err) = lqcron(&sb, &["tick"]);
    assert_eq!(rc, 2, "{err}");
    assert!(err.contains("--move-state"), "{err}");
    assert!(!new_state.join("lqcron.sqlite").exists());
    let (rc, _, err) = lqcron(&sb, &["tick", "--move-state"]);
    assert_eq!(rc, 0, "{err}");
    assert!(new_state.join("lqcron.sqlite").exists());
    let c = Connection::open(new_state.join("lqcron.sqlite")).unwrap();
    let sd: String = c
        .query_row("SELECT value FROM meta WHERE key = 'state_dir'", [], |r| {
            r.get(0)
        })
        .unwrap();
    assert_eq!(Path::new(&sd), new_state);
}

#[test]
fn fake_clock_needs_testing_flag() {
    if !php_available() {
        return;
    }
    let fake: i64 = 1_800_000_000_000; // 2027-01-15
    let last_tick = |sb: &Sandbox| -> i64 {
        db(sb)
            .query_row(
                "SELECT value FROM meta WHERE key = 'last_tick_at'",
                [],
                |r| r.get::<_, String>(0),
            )
            .unwrap()
            .parse()
            .unwrap()
    };
    // jen LQCRON_FAKE_NOW_MS (např. omylem exportovaná) hodiny neposune
    let sb = sandbox(None);
    let out = cmd(&sb)
        .arg("tick")
        .env("LQCRON_FAKE_NOW_MS", fake.to_string())
        .env_remove("LQCRON_TESTING")
        .output()
        .unwrap();
    assert!(
        out.status.success(),
        "{}",
        String::from_utf8_lossy(&out.stderr)
    );
    assert_ne!(last_tick(&sb), fake);
    // s LQCRON_TESTING=1 ano
    let sb = sandbox(None);
    let out = cmd(&sb)
        .arg("tick")
        .env("LQCRON_FAKE_NOW_MS", fake.to_string())
        .env("LQCRON_TESTING", "1")
        .output()
        .unwrap();
    assert!(
        out.status.success(),
        "{}",
        String::from_utf8_lossy(&out.stderr)
    );
    assert_eq!(last_tick(&sb), fake);
}

#[test]
fn state_dir_change_is_detected_from_a_fresh_slot() {
    if !php_available() {
        return;
    }
    // url + apiKey = identita projektu → ukazatel mimo slot (port 9 = nic neposlouchá)
    let sb = sandbox(Some("http://127.0.0.1:9/api/connector"));
    let (rc, _, err) = lqcron(&sb, &["tick"]);
    assert_eq!(rc, 0, "{err}");
    // nový blue/green slot bez temp/lqcron/state_dir, NEON s jinou cestou
    let green = sb.root.join("green");
    std::fs::create_dir_all(green.join("temp/lqcron")).unwrap();
    std::fs::create_dir_all(green.join("vendor/bin")).unwrap();
    std::fs::copy(
        sb.app.join("vendor/bin/monitor-worker"),
        green.join("vendor/bin/monitor-worker"),
    )
    .unwrap();
    let mut v: Value = serde_json::from_str(
        &std::fs::read_to_string(sb.app.join("temp/lqcron/export.json")).unwrap(),
    )
    .unwrap();
    v["config"]["state_dir"] = json!(sb.root.join("state-new"));
    std::fs::write(green.join("temp/lqcron/export.json"), v.to_string()).unwrap();
    std::fs::write(green.join("export.source.json"), v.to_string()).unwrap();
    let (rc, _, err) = lqcron_in(&sb, &green, &["tick"]);
    assert_eq!(rc, 2, "{err}");
    assert!(err.contains("stateDir changed"), "{err}");
    assert!(!sb.root.join("state-new/lqcron.sqlite").exists());
    // odmítnutí je vidět v heartbeatu staré databáze (audit L2)
    let hb: String = db(&sb)
        .query_row("SELECT value FROM meta WHERE key = 'heartbeat'", [], |r| {
            r.get(0)
        })
        .unwrap();
    assert!(hb.contains("state_dir_changed"), "{hb}");
}

#[test]
fn unreadable_export_falls_back_to_the_last_accepted_one() {
    if !php_available() {
        return;
    }
    let sb = sandbox(None);
    let (rc, _, err) = lqcron(&sb, &["tick"]);
    assert_eq!(rc, 0, "{err}");
    assert!(sb.state.join("export.last.json").exists());
    // rozbitý export (a sync, který ho „opraví“ zase rozbitým)
    std::fs::write(sb.app.join("temp/lqcron/export.json"), "{broken").unwrap();
    std::fs::write(sb.app.join("export.source.json"), "{broken").unwrap();
    let state = sb.state.to_string_lossy().into_owned();
    let (rc, _, err) = lqcron(&sb, &["enqueue", "ok", &format!("--state-dir={state}")]);
    assert_eq!(rc, 0, "{err}");
    // tick dál běží: převezme job, běh doběhne
    let (_, s) = wait_state(&sb, "ok", &["finished", "failed"], Duration::from_secs(30));
    assert_eq!(s, "finished");
    let c = db(&sb);
    let err: String = c
        .query_row(
            "SELECT value FROM meta WHERE key = 'export_error'",
            [],
            |r| r.get(0),
        )
        .unwrap();
    assert!(err.contains("export.json"), "{err}");
    let failed: i64 = c
        .query_row(
            "SELECT COUNT(*) FROM outbox WHERE type = 'definitions.failed'",
            [],
            |r| r.get(0),
        )
        .unwrap();
    assert_eq!(failed, 1, "reported once, not every tick");
}

#[test]
fn disabled_scheduler_still_cleans_up_but_starts_nothing() {
    if !php_available() {
        return;
    }
    let sb = sandbox(None);
    let (rc, _, err) = lqcron(&sb, &["tick"]);
    assert_eq!(rc, 0, "{err}");
    // zaseknutý `claimed` běh z doby před vypnutím + čekající job
    let c = db(&sb);
    c.execute(
        "INSERT INTO runs (uuid, job_uuid, origin_uuid, cron_code, attempt, max_attempts, source, job_created_at, concurrency_mode, state, claimed_at)
         VALUES ('stuck', 'stuck-job', 'o', 'ok', 1, 1, 'cli', 1, 'independent', 'claimed', 1)",
        [],
    )
    .unwrap();
    drop(c);
    let mut v: Value = serde_json::from_str(
        &std::fs::read_to_string(sb.app.join("temp/lqcron/export.json")).unwrap(),
    )
    .unwrap();
    v["config"]["enabled"] = json!(false);
    std::fs::write(sb.app.join("temp/lqcron/export.json"), v.to_string()).unwrap();
    std::fs::write(sb.app.join("export.source.json"), v.to_string()).unwrap();
    let (rc, _, err) = lqcron(&sb, &["tick"]);
    assert_eq!(rc, 0, "{err}");
    let c = db(&sb);
    let runs: i64 = c
        .query_row("SELECT COUNT(*) FROM runs", [], |r| r.get(0))
        .unwrap();
    let jobs: i64 = c
        .query_row("SELECT COUNT(*) FROM jobs", [], |r| r.get(0))
        .unwrap();
    assert_eq!(
        (runs, jobs),
        (0, 1),
        "stuck run requeued, but not started again"
    );
    let hb: String = c
        .query_row("SELECT value FROM meta WHERE key = 'heartbeat'", [], |r| {
            r.get(0)
        })
        .unwrap();
    assert!(hb.contains("\"mode\":\"disabled\""), "{hb}");
}

#[test]
fn definitions_resync_command_resends_definitions() {
    if !php_available() {
        return;
    }
    let m = mock_with(vec![json!({
        "command_id": "01a00000-0000-7000-8000-000000000001",
        "seq": 1,
        "type": "definitions.resync",
        "payload": {},
        "issued_at": "2026-10-16T10:00:00.000+00:00",
        "expires_at": null
    })]);
    let sb = sandbox(Some(&m.url));
    let (rc, _, err) = lqcron(&sb, &["tick"]);
    assert_eq!(rc, 0, "{err}");
    // 1. flush: první definitions.sync + stažení příkazu
    let (rc, out, err) = lqcron(&sb, &["flush", "--timeout=30"]);
    assert_eq!(rc, 0, "{out} {err}");
    let fetched: i64 = db(&sb)
        .query_row("SELECT COUNT(*) FROM commands", [], |r| r.get(0))
        .unwrap();
    assert_eq!(fetched, 1);
    // 2. tick příkaz použije, 3. flush pošle nový definitions.sync + command.applied
    let (rc, _, err) = lqcron(&sb, &["tick"]);
    assert_eq!(rc, 0, "{err}");
    let (rc, out, err) = lqcron(&sb, &["flush", "--timeout=30"]);
    assert_eq!(rc, 0, "{out} {err}");
    let events = m.events.lock().unwrap();
    let syncs: Vec<&Value> = events
        .iter()
        .filter(|e| e["type"] == "definitions.sync")
        .collect();
    assert_eq!(syncs.len(), 2, "resync must send the definitions again");
    assert_eq!(syncs[0]["payload"]["hash"], syncs[1]["payload"]["hash"]);
    assert_eq!(syncs[1]["payload"]["mode"], "local");
    assert_eq!(syncs[1]["payload"]["crons"].as_array().unwrap().len(), 5);
    assert!(
        events.iter().any(|e| e["type"] == "command.applied"
            && e["payload"]["command_type"] == "definitions.resync")
    );
}

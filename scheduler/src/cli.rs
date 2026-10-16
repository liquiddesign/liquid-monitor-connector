//! Obsluhové podpříkazy (plán § 11.1): status, plan, logs, pause/resume, wait-slot, verify,
//! cancel, enqueue, run, cron, outbox.

use std::collections::BTreeMap;
use std::fs;
use std::io::{Read, Seek, SeekFrom, Write};
use std::path::{Path, PathBuf};
use std::time::{Duration, Instant};

use chrono_tz::Tz;
use rusqlite::{Connection, params};
use serde_json::{Value, json};

use crate::cronexpr::Schedule;
use crate::db::{self, immediate, meta_i64, meta_json, meta_str};
use crate::export::Export;
use crate::model::{ACTIVE_SQL, active_runs, latest_run_for_cron, load_cron, load_crons, load_run};
use crate::pause::{self, Pause};
use crate::queue::{Enqueued, NewJob, enqueue};
use crate::runjob::{self, CancelOutcome, Env, args_path, log_path, runs_dir};
use crate::util::{
    Res, VERSION, current_exe, fail, free_bytes, iso, iso_opt, now_ms, pid_alive, sha256_hex,
    uuid_v7, write_atomic,
};

/// Rozparsované argumenty: poziční + `--klíč[=hodnota]`.
#[derive(Debug, Default, Clone)]
pub struct Opts {
    pub positional: Vec<String>,
    pub options: BTreeMap<String, Option<String>>,
}

const VALUE_OPTIONS: [&str; 8] = [
    "php",
    "state-dir",
    "app",
    "args",
    "delay",
    "hours",
    "timeout",
    "ttl",
];

impl Opts {
    pub fn parse(args: &[String]) -> Opts {
        let mut o = Opts::default();
        let mut i = 0;
        while i < args.len() {
            let a = &args[i];
            if let Some(rest) = a.strip_prefix("--") {
                if let Some((k, v)) = rest.split_once('=') {
                    o.options.insert(k.to_string(), Some(v.to_string()));
                } else if VALUE_OPTIONS.contains(&rest) && i + 1 < args.len() {
                    o.options
                        .insert(rest.to_string(), Some(args[i + 1].clone()));
                    i += 1;
                } else {
                    o.options.insert(rest.to_string(), None);
                }
            } else {
                o.positional.push(a.clone());
            }
            i += 1;
        }
        o
    }

    pub fn flag(&self, k: &str) -> bool {
        self.options.contains_key(k)
    }

    pub fn value(&self, k: &str) -> Option<&str> {
        self.options.get(k).and_then(|v| v.as_deref())
    }

    pub fn int(&self, k: &str) -> Res<Option<i64>> {
        match self.value(k) {
            None => Ok(None),
            Some(v) => v
                .parse::<i64>()
                .map(Some)
                .map_err(|_| fail(format!("--{k} expects a number, got {v:?}"))),
        }
    }
}

/// Kontext podpříkazů mimo `tick`: `stateDir` z `./temp/lqcron/export.json` nebo `--state-dir`.
pub struct Ctx {
    pub app: PathBuf,
    pub export: Option<Export>,
    pub state_dir: PathBuf,
}

impl Ctx {
    pub fn resolve(o: &Opts) -> Res<Ctx> {
        let app = match o.value("app") {
            Some(a) => PathBuf::from(a),
            None => std::env::current_dir()?,
        };
        let app = app.canonicalize().unwrap_or(app);
        let export = Export::load(&app).ok();
        let state_dir = match (o.value("state-dir"), &export) {
            (Some(s), _) => PathBuf::from(s),
            (None, Some(e)) => e.state_dir(),
            (None, None) => {
                return Err(fail(format!(
                    "cannot find stateDir: {} is missing; run lqcron from the application directory or pass --state-dir",
                    app.join(crate::export::EXPORT_REL).display()
                )));
            }
        };
        Ok(Ctx {
            app,
            export,
            state_dir,
        })
    }

    pub fn conn(&self) -> Res<Connection> {
        db::open_existing_res(&self.state_dir)
    }

    pub fn env(&self, php: Option<&str>) -> Env {
        Env::from_export(&self.state_dir, &self.app, php, self.export.as_ref())
    }

    fn mode(&self) -> &'static str {
        self.export.as_ref().map(|e| e.mode()).unwrap_or("local")
    }
}

fn ago(ms: Option<i64>) -> String {
    match ms {
        Some(t) => format!("{} ({} s ago)", iso(t), (now_ms() - t) / 1000),
        None => "never".into(),
    }
}

pub fn status(ctx: &Ctx, o: &Opts) -> Res<i32> {
    let conn = ctx.conn()?;
    let mut hb = crate::heartbeat::build(&conn, ctx.mode(), 0)?;
    let runs = active_runs(&conn)?;
    let jobs: Vec<Value> = conn
        .prepare("SELECT uuid, cron_code, source, attempt, available_at FROM jobs ORDER BY id LIMIT 100")?
        .query_map([], |r| {
            Ok(json!({"job_uuid": r.get::<_, String>(0)?, "cron_code": r.get::<_, String>(1)?, "source": r.get::<_, String>(2)?,
                      "attempt": r.get::<_, i64>(3)?, "available_at": iso(r.get::<_, i64>(4)?)}))
        })?
        .collect::<Result<_, _>>()?;
    let services: Vec<Value> = conn
        .prepare("SELECT name, mode, pid, pid_start, app_path, restarts, started_at FROM services ORDER BY name")?
        .query_map([], |r| {
            let pid: Option<i64> = r.get(2)?;
            let start: Option<i64> = r.get(3)?;
            Ok(json!({"name": r.get::<_, String>(0)?, "mode": r.get::<_, String>(1)?, "pid": pid,
                      "alive": pid_alive(pid, start), "app_path": r.get::<_, Option<String>>(4)?,
                      "restarts": r.get::<_, i64>(5)?, "started_at": iso_opt(r.get(6)?)}))
        })?
        .collect::<Result<_, _>>()?;
    let free = free_bytes(&ctx.state_dir);
    let writable = {
        let p = ctx
            .state_dir
            .join(format!(".write-probe-{}", std::process::id()));
        let ok = fs::write(&p, b"x").is_ok();
        let _ = fs::remove_file(&p);
        ok
    };
    let running: Vec<Value> = runs
        .iter()
        .map(|r| {
            json!({"run_uuid": r.uuid, "cron_code": r.cron_code, "state": r.state, "attempt": r.attempt,
                   "app_path": r.app_path, "started_at": iso_opt(r.started_at), "claimed_at": iso(r.claimed_at),
                   "stop_reason": r.stop_reason})
        })
        .collect();
    let extra = json!({
        "state_dir": ctx.state_dir,
        "last_tick_seen_at": iso_opt(meta_i64(&conn, "last_tick_seen_at")?),
        "export_hash": meta_str(&conn, "export_hash")?,
        "export_at": iso_opt(meta_i64(&conn, "export_at")?),
        "deliver_last_ok_at": iso_opt(meta_i64(&conn, "deliver_last_ok_at")?),
        "deliver_last_error": meta_json(&conn, "deliver_last_error")?,
        "disk_free_bytes": free,
        "writable": writable,
        "jobs": jobs,
        "runs": running,
        "services": services,
    });
    if let (Some(h), Value::Object(e)) = (hb.as_object_mut(), extra) {
        h.extend(e);
    }
    if o.flag("json") {
        println!("{}", serde_json::to_string_pretty(&hb)?);
        return Ok(0);
    }
    let s = |k: &str| hb[k].as_str().unwrap_or("-").to_string();
    println!(
        "lqcron {VERSION} (schema {}), instance {}, host {}",
        db::SCHEMA_VERSION,
        s("instance_id"),
        s("hostname")
    );
    println!("state dir:   {}", ctx.state_dir.display());
    println!(
        "last tick:   {}",
        ago(meta_i64(&conn, "last_tick_seen_at")?)
    );
    if let Some(r) = meta_str(&conn, "tick_refused")? {
        println!("TICK REFUSED: {r}");
    }
    println!("mode:        {}", s("mode"));
    println!(
        "export:      {} at {}{}",
        meta_str(&conn, "export_hash")?.unwrap_or_else(|| "-".into()),
        iso_opt(meta_i64(&conn, "export_at")?)
            .as_str()
            .unwrap_or("-"),
        meta_json(&conn, "export_error")?
            .map(|e| format!(
                ", LAST SYNC FAILED: {}",
                e["message"].as_str().unwrap_or("")
            ))
            .unwrap_or_default()
    );
    if let Some(cv) = ctx
        .export
        .as_ref()
        .and_then(|e| e.connector_version.as_deref())
        && cv != VERSION
    {
        println!("VERSION MISMATCH: export.json is from connector {cv}, this lqcron is {VERSION}");
    }
    let p = Pause::load(&conn)?;
    if p.active() {
        println!("PAUSED:      {}", p.to_json());
    }
    println!("queue:       {} jobs", hb["queue_length"]);
    for j in hb["jobs"].as_array().into_iter().flatten().take(20) {
        println!(
            "  {} {} attempt {} available {}",
            j["cron_code"].as_str().unwrap_or(""),
            j["source"].as_str().unwrap_or(""),
            j["attempt"],
            j["available_at"].as_str().unwrap_or("")
        );
    }
    println!("running:     {}", runs.len());
    for r in &runs {
        println!(
            "  {} {} [{}] slot {} since {}",
            r.uuid,
            r.cron_code,
            r.state,
            r.app_path.as_deref().unwrap_or("-"),
            iso(r.started_at.unwrap_or(r.claimed_at))
        );
    }
    println!(
        "outbox:      {} pending (oldest {}), {} rejected, {} poisoned",
        hb["outbox_size"],
        hb["oldest_outbox_at"].as_str().unwrap_or("-"),
        hb["rejected_count"],
        hb["poisoned_count"]
    );
    println!(
        "deliver:     last ok {}",
        ago(meta_i64(&conn, "deliver_last_ok_at")?)
    );
    if let Some(e) = meta_json(&conn, "deliver_last_error")? {
        println!(
            "             last error {} {}",
            e["at"].as_str().unwrap_or(""),
            e["message"].as_str().unwrap_or("")
        );
    }
    if !hb["starving"].as_array().is_none_or(|a| a.is_empty()) {
        println!("starving:    {}", hb["starving"]);
    }
    for s in hb["services"].as_array().into_iter().flatten() {
        println!(
            "service:     {} ({}) pid {} {} slot {} restarts {}",
            s["name"].as_str().unwrap_or(""),
            s["mode"].as_str().unwrap_or(""),
            s["pid"],
            if s["alive"] == true { "alive" } else { "dead" },
            s["app_path"].as_str().unwrap_or("-"),
            s["restarts"]
        );
    }
    println!(
        "disk:        {} MB free{}",
        free.map(|b| b / 1024 / 1024)
            .map(|v| v.to_string())
            .unwrap_or_else(|| "?".into()),
        if writable { "" } else { ", NOT WRITABLE" }
    );
    Ok(0)
}

pub fn plan(ctx: &Ctx, o: &Opts) -> Res<i32> {
    let export = ctx
        .export
        .as_ref()
        .ok_or_else(|| fail("plan needs temp/lqcron/export.json (run lqcron sync)"))?;
    let tz: Tz = export
        .config
        .timezone
        .parse()
        .map_err(|_| fail(format!("unknown timezone {}", export.config.timezone)))?;
    let hours = o.int("hours")?.unwrap_or(24).clamp(1, 24 * 31);
    let flags: BTreeMap<String, (bool, bool)> = match ctx.conn() {
        Ok(c) => load_crons(&c)?
            .into_iter()
            .map(|c| (c.code, (c.local_active, c.remote_active)))
            .collect(),
        Err(_) => BTreeMap::new(),
    };
    let now = now_ms();
    let mut items: Vec<(i64, String)> = Vec::new();
    let mut notes = Vec::new();
    for c in &export.crons {
        let (l, r) = flags.get(&c.code).copied().unwrap_or((true, true));
        let Some(s) = &c.schedule else {
            notes.push(json!({"cron_code": c.code, "note": "not scheduled in this environment"}));
            continue;
        };
        if !l || !r {
            notes.push(json!({"cron_code": c.code, "note": if !l { "disabled locally" } else { "disabled remotely" }}));
            continue;
        }
        match Schedule::parse(s) {
            Ok(e) => {
                for t in e.occurrences(&tz, now, now + hours * 3_600_000) {
                    items.push((t, c.code.clone()));
                }
            }
            Err(err) => {
                notes.push(json!({"cron_code": c.code, "note": format!("invalid schedule: {err}")}))
            }
        }
    }
    items.sort();
    if o.flag("json") {
        let runs: Vec<Value> = items
            .iter()
            .map(|(t, c)| {
                let local = chrono::DateTime::from_timestamp_millis(*t)
                    .map(|d| d.with_timezone(&tz).format("%Y-%m-%dT%H:%M%:z").to_string());
                json!({"cron_code": c, "at": iso(*t), "local": local})
            })
            .collect();
        println!(
            "{}",
            serde_json::to_string_pretty(
                &json!({"timezone": export.config.timezone, "environment": export.config.environment, "hours": hours, "runs": runs, "notes": notes})
            )?
        );
        return Ok(0);
    }
    println!(
        "# environment {}, timezone {}, next {hours} h",
        export.config.environment.as_deref().unwrap_or("-"),
        export.config.timezone
    );
    for (t, c) in &items {
        let local = chrono::DateTime::from_timestamp_millis(*t)
            .map(|d| d.with_timezone(&tz).format("%Y-%m-%d %H:%M %Z").to_string())
            .unwrap_or_default();
        println!("{local}  {c}");
    }
    for n in notes {
        println!(
            "# {}: {}",
            n["cron_code"].as_str().unwrap_or(""),
            n["note"].as_str().unwrap_or("")
        );
    }
    Ok(0)
}

pub fn logs(ctx: &Ctx, o: &Opts) -> Res<i32> {
    let what = o
        .positional
        .first()
        .ok_or_else(|| fail("usage: lqcron logs <cron_code|run_uuid> [--tail]"))?;
    let conn = ctx.conn()?;
    let run = match load_run(&conn, what)? {
        Some(r) => r,
        None => {
            latest_run_for_cron(&conn, what)?.ok_or_else(|| fail(format!("no run for {what}")))?
        }
    };
    let path = log_path(&ctx.state_dir, &run.uuid);
    eprintln!(
        "# {} {} [{}] {}",
        run.cron_code,
        run.uuid,
        run.state,
        path.display()
    );
    let mut pos = 0u64;
    let mut out = std::io::stdout();
    loop {
        if let Ok(mut f) = fs::File::open(&path) {
            let len = f.metadata()?.len();
            if len < pos {
                pos = 0; // zkrácený log
            }
            f.seek(SeekFrom::Start(pos))?;
            let mut buf = Vec::new();
            f.read_to_end(&mut buf)?;
            pos += buf.len() as u64;
            out.write_all(&buf)?;
            out.flush()?;
        }
        if !o.flag("tail") {
            break;
        }
        let state: Option<String> = conn
            .query_row("SELECT state FROM runs WHERE uuid = ?1", [&run.uuid], |r| {
                r.get(0)
            })
            .ok();
        if !state.is_some_and(|s| crate::model::ACTIVE_STATES.contains(&s.as_str())) {
            // ještě jednou dočíst konec
            if let Ok(mut f) = fs::File::open(&path) {
                f.seek(SeekFrom::Start(pos))?;
                let mut buf = Vec::new();
                f.read_to_end(&mut buf)?;
                out.write_all(&buf)?;
            }
            break;
        }
        std::thread::sleep(Duration::from_millis(500));
    }
    Ok(0)
}

fn active_count(conn: &Connection) -> Res<i64> {
    Ok(conn.query_row(
        &format!("SELECT COUNT(*) FROM runs WHERE state IN {ACTIVE_SQL}"),
        [],
        |r| r.get(0),
    )?)
}

pub fn pause_cmd(ctx: &Ctx, o: &Opts) -> Res<i32> {
    let conn = ctx.conn()?;
    let ttl = o.int("ttl")?.unwrap_or(1800);
    let drain = if o.flag("drain") {
        Some(o.int("drain")?.unwrap_or(120))
    } else {
        None
    };
    let now = now_ms();
    {
        let tx = immediate(&conn)?;
        pause::pause_deploy(
            &tx,
            now,
            if ttl > 0 { Some(ttl * 1000) } else { None },
            drain.map(|d| d * 1000),
        )?;
        tx.commit()?;
    }
    println!(
        "lqcron: paused (deploy){}",
        if ttl > 0 {
            format!(", resumes by itself in {ttl} s")
        } else {
            String::new()
        }
    );
    if let Some(d) = drain {
        let start = Instant::now();
        loop {
            let n = active_count(&conn)?;
            if n == 0 {
                println!("lqcron: no job running");
                break;
            }
            if start.elapsed() >= Duration::from_secs(d.max(0) as u64) {
                println!(
                    "lqcron: {n} job(s) still running after {d} s; they keep running in their slot (see lqcron status)"
                );
                break;
            }
            std::thread::sleep(Duration::from_millis(500));
        }
    }
    Ok(0)
}

pub fn resume_cmd(ctx: &Ctx, o: &Opts) -> Res<i32> {
    let conn = ctx.conn()?;
    let tx = immediate(&conn)?;
    let changed = pause::resume(&tx, now_ms(), o.flag("all"))?;
    tx.commit()?;
    let p = Pause::load(&conn)?;
    if p.remote.is_some() {
        println!(
            "lqcron: deploy pause cleared, but a REMOTE pause from LQDeck is still active (use --all to clear it)"
        );
    } else {
        println!(
            "lqcron: {}",
            if changed { "resumed" } else { "was not paused" }
        );
    }
    Ok(0)
}

pub fn wait_slot(ctx: &Ctx, o: &Opts) -> Res<i32> {
    let slot = o
        .positional
        .first()
        .ok_or_else(|| fail("usage: lqcron wait-slot <path> [--timeout=sec]"))?;
    let slot = Path::new(slot)
        .canonicalize()
        .unwrap_or_else(|_| PathBuf::from(slot));
    let slot_s = slot.to_string_lossy().into_owned();
    let timeout = Duration::from_secs(o.int("timeout")?.unwrap_or(1800).max(0) as u64);
    let conn = ctx.conn()?;
    let start = Instant::now();
    loop {
        let runs: Vec<String> = active_runs(&conn)?
            .into_iter()
            .filter(|r| r.app_path.as_deref() == Some(slot_s.as_str()))
            .map(|r| format!("{} ({})", r.cron_code, r.uuid))
            .collect();
        let services = crate::services::running_from_slot(&conn, &slot_s)?;
        if runs.is_empty() && services.is_empty() {
            println!("lqcron: nothing runs from {slot_s}");
            return Ok(0);
        }
        if start.elapsed() >= timeout {
            eprintln!(
                "lqcron: still running from {slot_s} after {} s: jobs [{}], services [{}]",
                timeout.as_secs(),
                runs.join(", "),
                services.join(", ")
            );
            return Ok(1);
        }
        std::thread::sleep(Duration::from_secs(1));
    }
}

pub fn verify(o: &Opts) -> Res<i32> {
    let bin = match o.positional.first() {
        Some(p) => PathBuf::from(p),
        None => current_exe(),
    };
    let mut sum_file = PathBuf::from(format!("{}.sha256", bin.display()));
    let mut target = bin.clone();
    if !sum_file.exists() {
        // spuštěno z kopie ve stateDir/bin → ověřit binárku balíčku ve slotu
        let arch = std::env::consts::ARCH;
        let candidate = std::env::current_dir()?.join(format!(
            "vendor/liquiddesign/liquid-monitor-connector/bin/lqcron-linux-{arch}"
        ));
        if PathBuf::from(format!("{}.sha256", candidate.display())).exists() {
            target = candidate;
            sum_file = PathBuf::from(format!("{}.sha256", target.display()));
        } else {
            return Err(fail(format!("{} not found", sum_file.display())));
        }
    }
    let expected = fs::read_to_string(&sum_file)?
        .split_whitespace()
        .next()
        .unwrap_or("")
        .to_ascii_lowercase();
    let actual = sha256_hex(&fs::read(&target)?);
    if expected == actual {
        println!("lqcron: {} OK ({actual})", target.display());
        if target != bin && sha256_hex(&fs::read(&bin)?) != actual {
            println!(
                "lqcron: note: the running copy {} differs (another version)",
                bin.display()
            );
        }
        Ok(0)
    } else {
        eprintln!(
            "lqcron: {} checksum MISMATCH: expected {expected}, got {actual}",
            target.display()
        );
        Ok(1)
    }
}

pub fn cancel_cmd(ctx: &Ctx, o: &Opts) -> Res<i32> {
    let uuid = o
        .positional
        .first()
        .ok_or_else(|| fail("usage: lqcron cancel <run_uuid>"))?;
    let conn = ctx.conn()?;
    let tx = immediate(&conn)?;
    let r = runjob::cancel(&tx, uuid, now_ms())?;
    tx.commit()?;
    match r {
        CancelOutcome::Cancelled => println!("lqcron: run {uuid} cancelled before it started"),
        CancelOutcome::Stopping => println!(
            "lqcron: run {uuid} is stopping (SIGTERM, then SIGKILL after the grace period)"
        ),
        CancelOutcome::NotActive => {
            eprintln!("lqcron: run {uuid} is not active");
            return Ok(1);
        }
    }
    Ok(0)
}

fn parse_args_json(o: &Opts) -> Res<Option<String>> {
    match o.value("args") {
        None => Ok(None),
        Some(a) => {
            let v: Value = serde_json::from_str(a)
                .map_err(|e| fail(format!("--args is not valid JSON: {e}")))?;
            if v.is_null() {
                Ok(None)
            } else {
                Ok(Some(v.to_string()))
            }
        }
    }
}

pub fn enqueue_cmd(ctx: &Ctx, o: &Opts) -> Res<i32> {
    let code = o
        .positional
        .first()
        .ok_or_else(|| fail("usage: lqcron enqueue <code> [--args=json] [--delay=s]"))?;
    let conn = ctx.conn()?;
    let now = now_ms();
    let tx = immediate(&conn)?;
    let cron = load_cron(&tx, code)?
        .filter(|c| c.defined)
        .ok_or_else(|| fail(format!("unknown cron {code}")))?;
    let mut job = NewJob::new(code, "cli", now);
    job.arguments = parse_args_json(o)?;
    job.available_at = now + o.int("delay")?.unwrap_or(0).max(0) * 1000;
    let r = enqueue(&tx, &cron, job, true, now)?;
    tx.commit()?;
    match r {
        Enqueued::Queued(u) => {
            println!("{u}");
            Ok(0)
        }
        Enqueued::Rejected {
            queue_size,
            max_queue_size,
        } => {
            eprintln!("lqcron: queue of {code} is full ({queue_size}/{max_queue_size})");
            Ok(3)
        }
    }
}

/// `lqcron run <code> [--sync]`: bez `--sync` = enqueue; se `--sync` běh hned v popředí
/// mimo pravidla souběhu (dev instance, testy).
pub fn run_cmd(ctx: &Ctx, o: &Opts) -> Res<i32> {
    if !o.flag("sync") {
        return enqueue_cmd(ctx, o);
    }
    let code = o
        .positional
        .first()
        .ok_or_else(|| fail("usage: lqcron run <code> [--args=json] [--sync]"))?;
    let conn = ctx.conn()?;
    let now = now_ms();
    let run_uuid = uuid_v7();
    let job_uuid = uuid_v7();
    let args = parse_args_json(o)?.unwrap_or_else(|| "null".into());
    {
        let tx = immediate(&conn)?;
        let cron = load_cron(&tx, code)?
            .filter(|c| c.defined)
            .ok_or_else(|| fail(format!("unknown cron {code}")))?;
        crate::util::ensure_dir(&runs_dir(&ctx.state_dir))?;
        write_atomic(
            &args_path(&ctx.state_dir, &run_uuid),
            args.as_bytes(),
            0o660,
        )?;
        tx.execute(
            "INSERT INTO runs (uuid, job_uuid, origin_uuid, cron_code, attempt, max_attempts, source, job_created_at,
                               timeout, concurrency_mode, resources, state, claimed_at, app_path)
             VALUES (?1, ?2, ?2, ?3, 1, ?4, 'cli', ?5, ?6, ?7, ?8, 'claimed', ?5, ?9)",
            params![
                run_uuid,
                job_uuid,
                code,
                cron.repeat_count.max(0) + 1,
                now,
                cron.effective_timeout(),
                cron.concurrency_mode,
                serde_json::to_string(&cron.resources)?,
                ctx.app.to_string_lossy()
            ],
        )?;
        tx.commit()?;
    }
    eprintln!("# run {run_uuid}");
    let env = ctx.env(o.value("php"));
    let code = runjob::run_job(&env, &run_uuid, false)?;
    if let Ok(log) = fs::read(log_path(&ctx.state_dir, &run_uuid)) {
        std::io::stdout().write_all(&log)?;
    }
    let state: String =
        conn.query_row("SELECT state FROM runs WHERE uuid = ?1", [&run_uuid], |r| {
            r.get(0)
        })?;
    eprintln!("# {state}");
    Ok(code)
}

pub fn cron_cmd(ctx: &Ctx, o: &Opts) -> Res<i32> {
    let (Some(action), Some(code)) = (o.positional.first(), o.positional.get(1)) else {
        return Err(fail("usage: lqcron cron disable|enable <code>"));
    };
    let active = match action.as_str() {
        "disable" => 0,
        "enable" => 1,
        _ => return Err(fail("usage: lqcron cron disable|enable <code>")),
    };
    let conn = ctx.conn()?;
    let n = conn.execute(
        "UPDATE crons SET local_active = ?1, updated_at = ?2 WHERE code = ?3",
        params![active, now_ms(), code],
    )?;
    if n == 0 {
        eprintln!("lqcron: unknown cron {code}");
        return Ok(1);
    }
    let c = load_cron(&conn, code)?.ok_or_else(|| fail("cron vanished"))?;
    println!(
        "lqcron: {code} {}{}",
        if active == 1 {
            "enabled locally"
        } else {
            "disabled locally"
        },
        if active == 1 && !c.remote_active {
            " (still disabled from LQDeck)"
        } else {
            ""
        }
    );
    Ok(0)
}

pub fn outbox_cmd(ctx: &Ctx, o: &Opts) -> Res<i32> {
    if o.positional.first().map(String::as_str) != Some("retry-rejected") {
        return Err(fail("usage: lqcron outbox retry-rejected"));
    }
    let conn = ctx.conn()?;
    let tx = immediate(&conn)?;
    let n = tx.execute(
        "INSERT INTO outbox (seq, event_id, type, run_uuid, payload, encoding, occurred_at, created_at)
         SELECT seq, event_id, type, run_uuid, payload, encoding, occurred_at, created_at FROM outbox_rejected
         WHERE event_id NOT IN (SELECT event_id FROM outbox)",
        [],
    )?;
    tx.execute("DELETE FROM outbox_rejected", [])?;
    tx.commit()?;
    println!("lqcron: {n} rejected event(s) moved back to the outbox");
    Ok(0)
}

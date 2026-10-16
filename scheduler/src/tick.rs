//! `lqcron tick` — jeden průchod plánovače (plán § 2). NIKDY nesahá na síť a nečeká na PHP.

use std::fs;
use std::path::{Path, PathBuf};
use std::process::Command;

use chrono_tz::Tz;
use rusqlite::{Connection, params};
use serde_json::json;

use crate::db::{self, OpenError, immediate, meta_i64, meta_set, meta_str};
use crate::export::{EXPORT_REL, Export};
use crate::model::{DEFAULT_TIMEOUT, RunRow, active_runs, parse_list};
use crate::outbox;
use crate::pause::{self, Pause};
use crate::runjob::{args_path, runs_dir};
use crate::select::{self, Candidate, Limits, Slot};
use crate::util::{
    FileLock, Res, VERSION, boot_id, boottime_ms, current_exe, ensure_dir, fail, file_mtime_ms,
    free_bytes, hostname, kill, kill_group, now_ms, pid_alive, set_umask, spawn_detached, uuid_v7,
    write_atomic,
};
use crate::{commands, defs, heartbeat, planner, queue, retention, services};

const CLAIM_STUCK_MS: i64 = 60_000;
const SUPERVISOR_STALE_MS: i64 = 120_000;
const OTHER_HOST_MS: i64 = 5 * 60_000;
const SYNC_EVERY_MS: i64 = 15 * 60_000;
/// O něco kratší než minuta, aby deliver (příkazy, heartbeat) běžel opravdu každý tick (audit L1).
const DELIVER_COMMANDS_EVERY_MS: i64 = 50_000;
const RETENTION_EVERY_MS: i64 = 3_600_000;
const DISK_LOW_BYTES: u64 = 500 * 1024 * 1024;
const BIN_KEEP_MS: i64 = 7 * 24 * 3600 * 1000;

#[derive(Debug, Default, Clone)]
pub struct TickArgs {
    pub php: Option<String>,
    pub move_state: bool,
}

/// Soubor ve slotu s posledním `stateDir`, nad kterým tick běžel (detekce změny cesty, plán § 4.2).
fn pointer_path(app: &Path) -> PathBuf {
    app.join("temp/lqcron/state_dir")
}

/// Per-uživatelský adresář ukazatelů mimo slot: `$XDG_STATE_HOME/lqcron` nebo
/// `~/.local/state/lqcron`. Čerstvý blue/green slot ho vidí taky (audit M5).
fn user_state_dir() -> Option<PathBuf> {
    if let Some(x) = std::env::var_os("XDG_STATE_HOME").filter(|v| !v.is_empty()) {
        return Some(PathBuf::from(x).join("lqcron"));
    }
    std::env::var_os("HOME")
        .filter(|v| !v.is_empty())
        .map(|h| PathBuf::from(h).join(".local/state/lqcron"))
}

fn short_hash(s: &str) -> String {
    crate::util::sha256_hex(s.as_bytes())[..16].to_string()
}

/// Ukazatel podle identity projektu v LQDecku (`url` + `apiKey`): stejný ve všech slotech
/// i layoutech deploye a dva projekty ho nesdílejí. Bez url/klíče není (pak jen slot).
fn project_pointer(export: &Export) -> Option<PathBuf> {
    let (Some(url), Some(key)) = (&export.config.url, &export.config.api_key) else {
        return None;
    };
    user_state_dir().map(|d| d.join(format!("project-{}", short_hash(&format!("{url}|{key}")))))
}

/// Ukazatel podle přesného slotu (pro nouzové čtení posledního exportu, když slot přijde
/// o `temp/`). K detekci změny `stateDir` se nepoužívá.
fn slot_user_pointer(app: &Path) -> Option<PathBuf> {
    user_state_dir().map(|d| d.join(format!("slot-{}", short_hash(&app.to_string_lossy()))))
}

fn read_pointer(p: &Path) -> Option<PathBuf> {
    fs::read_to_string(p)
        .ok()
        .map(|s| s.trim().to_string())
        .filter(|s| !s.is_empty())
        .map(PathBuf::from)
}

fn write_pointers(app: &Path, export: &Export, state_dir: &Path) {
    let data = state_dir.to_string_lossy();
    let _ = write_atomic(&pointer_path(app), data.as_bytes(), 0o660);
    for p in [project_pointer(export), slot_user_pointer(app)]
        .into_iter()
        .flatten()
    {
        if let Some(dir) = p.parent()
            && ensure_dir(dir).is_ok()
        {
            let _ = write_atomic(&p, data.as_bytes(), 0o660);
        }
    }
}

/// Kopie posledního přijatého exportu ve `stateDir` (audit M2).
pub const LAST_EXPORT: &str = "export.last.json";

/// Načte export slotu; když je nečitelný, padne na poslední přijatý ze `stateDir`.
/// Vrací export a případnou chybu čerstvého exportu.
pub fn load_export(app: &Path) -> Result<(Export, Option<String>), String> {
    let err = match Export::load(app) {
        Ok(e) => return Ok((e, None)),
        Err(e) => e.to_string(),
    };
    let candidates = [Some(pointer_path(app)), slot_user_pointer(app)];
    for p in candidates.into_iter().flatten() {
        if let Some(sd) = read_pointer(&p)
            && let Ok(data) = fs::read(sd.join(LAST_EXPORT))
            && let Ok(ex) = Export::parse(&data)
            && ex.state_dir() == sd
        {
            return Ok((ex, Some(err)));
        }
    }
    Err(err)
}

fn spawn_sync(exe: &Path, app: &Path, php: Option<&str>) {
    let mut cmd = Command::new(exe);
    cmd.arg("sync").arg("--background").current_dir(app);
    if let Some(p) = php {
        cmd.arg(format!("--php={p}"));
    }
    if let Err(e) = spawn_detached(&mut cmd, None) {
        eprintln!("lqcron: cannot start sync: {e}");
    }
}

fn spawn_deliver(bin: &Path, app: &Path, export: &Export, state_dir: &Path) {
    if export.config.url.is_none()
        || export.config.api_key.is_none()
        || FileLock::is_held(&state_dir.join("deliver.lock"))
    {
        return;
    }
    let mut cmd = Command::new(bin);
    cmd.arg("deliver")
        .arg(format!("--state-dir={}", state_dir.display()))
        .current_dir(app);
    let _ = spawn_detached(&mut cmd, None);
}

/// Odmítnutý tick: zapíše důvod a heartbeat a pošle ho do LQDecku (audit L2).
fn report_refusal(db_dir: &Path, reason: &str, export: &Export, exe: &Path, app: &Path) {
    let Ok(c) = db::open_existing(db_dir) else {
        mark_refused_raw(db_dir, reason);
        return;
    };
    let _ = meta_set(&c, "tick_refused", reason);
    match heartbeat::build(&c, export.mode(), 0) {
        Ok(hb) => {
            let _ = meta_set(&c, "heartbeat", &hb.to_string());
        }
        Err(e) => eprintln!("lqcron: cannot build heartbeat: {e}"),
    }
    spawn_deliver(exe, app, export, db_dir);
}

pub fn tick(args: &TickArgs) -> Res<i32> {
    set_umask();
    let app = std::env::current_dir()?.canonicalize()?;
    let exe = current_exe();
    let export_file = app.join(EXPORT_REL);
    let (export, export_err) = match load_export(&app) {
        Ok(x) => x,
        Err(e) => {
            if export_file.exists() {
                eprintln!("lqcron: {e}");
            } else {
                eprintln!(
                    "lqcron: {} missing, starting sync; scheduling starts next minute",
                    export_file.display()
                );
            }
            spawn_sync(&exe, &app, args.php.as_deref());
            return Ok(if export_file.exists() { 1 } else { 0 });
        }
    };
    if let Some(e) = &export_err {
        eprintln!("lqcron: {e}; using the last accepted export from stateDir");
    }
    let sync_php = args
        .php
        .clone()
        .or_else(|| export.config.php_binary.clone())
        .or_else(|| export.php_binary.clone());
    let enabled = export.config.enabled;
    let state_dir = export.state_dir();
    if !enabled && !db::db_path(&state_dir).exists() {
        if file_mtime_ms(&export_file).is_some_and(|m| now_ms() - m > SYNC_EVERY_MS) {
            spawn_sync(&exe, &app, sync_php.as_deref());
        }
        return Ok(0);
    }
    ensure_dir(&state_dir)?;
    let Some(_lock) = FileLock::try_lock(&state_dir.join("tick.lock"))? else {
        return Ok(0); // běží jiný tick
    };

    if let Some(refusal) = check_state_dir(&app, &export, &state_dir, args.move_state)? {
        eprintln!("lqcron: tick refused: {}", refusal.message);
        report_refusal(&refusal.db_dir, "state_dir_changed", &export, &exe, &app);
        return Ok(2);
    }

    let conn = match db::open_or_create(&state_dir) {
        Ok(c) => c,
        Err(OpenError::Incompatible { min_reader, schema }) => {
            mark_refused_raw(&state_dir, "schema");
            eprintln!(
                "lqcron: tick refused: database schema {schema} needs reader >= {min_reader}, this lqcron {VERSION} knows {}: rollback over an incompatible schema migration",
                db::SCHEMA_VERSION
            );
            return Ok(2);
        }
        Err(e) => return Err(fail(e.to_string())),
    };
    let now = now_ms();

    // jeden plánovač na stateDir (plán § 11.3)
    let host = hostname();
    let prev_host = meta_str(&conn, "hostname")?;
    let prev_seen = meta_i64(&conn, "last_tick_seen_at")?;
    if let Some(prev) = &prev_host
        && *prev != host
    {
        if prev_seen.is_some_and(|s| (now - s).abs() < OTHER_HOST_MS) {
            eprintln!(
                "lqcron: tick refused: host {prev} ticked over this stateDir less than 5 minutes ago"
            );
            drop(conn);
            report_refusal(&state_dir, "other_host", &export, &exe, &app);
            return Ok(2);
        }
        outbox::push(
            &conn,
            "scheduler.takeover",
            None,
            json!({"previous_hostname": prev, "hostname": host}),
            now,
        )?;
    }
    {
        let tx = immediate(&conn)?;
        meta_set(&tx, "hostname", &host)?;
        meta_set(&tx, "last_tick_seen_at", &now.to_string())?;
        meta_set(&tx, "tick_refused", "")?;
        meta_set(&tx, "state_dir", &state_dir.to_string_lossy())?;
        meta_set(&tx, "app_path", &app.to_string_lossy())?;
        tx.commit()?;
    }
    write_pointers(&app, &export, &state_dir);
    match &export_err {
        None => {
            // poslední přijatý export pro případ, že příští bude nečitelný (audit M2)
            if let Ok(data) = fs::read(&export_file) {
                let _ = write_atomic(&state_dir.join(LAST_EXPORT), &data, 0o640);
            }
        }
        Some(e) => {
            defs::record_export_failure(&conn, e, None, "", now)?;
        }
    }

    // verzovaná kopie binárky, ze které běží podprocesy (plán § 2)
    let bin = install_binary_copy(&state_dir, &exe).unwrap_or_else(|e| {
        eprintln!("lqcron: cannot copy binary into stateDir/bin: {e}");
        exe.clone()
    });
    prune_binary_copies(&state_dir, &bin);

    // zapisovatelnost a volné místo (plán § 4.1, § 8.1)
    let probe = state_dir.join(format!(".write-probe-{}", std::process::id()));
    if let Err(e) = fs::write(&probe, b"x") {
        eprintln!(
            "lqcron: stateDir {} is not writable: {e}",
            state_dir.display()
        );
    }
    let _ = fs::remove_file(&probe);
    let disk_low = free_bytes(&state_dir).is_some_and(|b| b < DISK_LOW_BYTES);
    meta_set(&conn, "disk_low", if disk_low { "1" } else { "" })?;

    let shadow = export.config.shadow;
    if enabled
        && export_err.is_none()
        && let Err(e) = defs::apply_export(&conn, &export)
    {
        eprintln!("lqcron: cannot apply export: {e}");
    }
    {
        let tx = immediate(&conn)?;
        pause::expire(&tx, now)?;
        tx.commit()?;
    }
    if enabled {
        commands::apply(&conn, &export.config.remote_commands, shadow, Some(&export))?;
    }

    let job_php = export.job_php(args.php.as_deref());
    // úklid ztracených běhů běží i s `enabled: false` (audit L13)
    reap_runs(&conn, &state_dir, &app, &bin, &job_php, now)?;

    let pause_state = Pause::load(&conn)?;
    let mut skew = 0;
    if enabled {
        match export.config.timezone.parse::<Tz>() {
            Ok(tz) => {
                let tx = immediate(&conn)?;
                let out = planner::plan(&tx, &tz, now, shadow, pause_state.active())?;
                tx.commit()?;
                skew = out.clock_skew_seconds;
                for (code, err) in out.invalid {
                    eprintln!("lqcron: cron {code}: {err}");
                }
            }
            Err(_) => eprintln!(
                "lqcron: unknown timezone {:?}, nothing planned",
                export.config.timezone
            ),
        }
    }

    {
        let tx = immediate(&conn)?;
        queue::clean_stale(
            &tx,
            now,
            meta_i64(&tx, "last_resume_at")?,
            pause_state.active(),
        )?;
        tx.commit()?;
    }
    if enabled && !shadow && !pause_state.active() {
        let claimed = claim(&conn, &export, &state_dir, &app, now)?;
        for uuid in claimed {
            let mut cmd = Command::new(&bin);
            cmd.arg("run-job")
                .arg(&uuid)
                .arg(format!("--state-dir={}", state_dir.display()))
                .arg(format!("--app={}", app.display()))
                .arg(format!("--php={job_php}"))
                .current_dir(&app);
            let log = crate::runjob::log_path(&state_dir, &uuid);
            if let Err(e) = spawn_detached(&mut cmd, Some(&log)) {
                eprintln!("lqcron: cannot start run-job {uuid}: {e}");
            }
        }
    } else {
        meta_set(&conn, "starving", "[]")?;
    }

    if enabled && !shadow {
        let ctx = services::Ctx {
            state_dir: &state_dir,
            app: &app,
            php: &job_php,
            log_max_bytes: export.config.run_log_max_bytes.max(1 << 20) as u64,
        };
        // bez transakce: spouštění a čekání na služby nesmí držet zámek SQLite (audit M6)
        services::tick(&conn, &ctx, &export.config.services, now)?;
    }

    let mode = if enabled { export.mode() } else { "disabled" };
    let hb = heartbeat::build(&conn, mode, skew)?;
    meta_set(&conn, "heartbeat", &hb.to_string())?;

    if meta_i64(&conn, "retention_at")?.is_none_or(|t| now - t >= RETENTION_EVERY_MS || t > now) {
        retention::run(&conn, &state_dir, now)?;
        meta_set(&conn, "retention_at", &now.to_string())?;
    }

    // sync: změna slotu / composeru / NEONů, nebo 15 min od posledního pokusu (plán § 5.2)
    let fp = sync_fingerprint(&app, &export);
    let attempt = meta_i64(&conn, "sync_attempt_at")?;
    let fp_changed = meta_get_str(&conn, "sync_fingerprint")?.as_deref() != Some(fp.as_str());
    if (fp_changed
        || export_err.is_some()
        || attempt.is_none_or(|a| now - a >= SYNC_EVERY_MS || a > now))
        && !FileLock::is_held(&state_dir.join("sync.lock"))
    {
        let mut cmd = Command::new(&bin);
        cmd.arg("sync").arg("--background").current_dir(&app);
        if let Some(p) = &sync_php {
            cmd.arg(format!("--php={p}"));
        }
        let _ = spawn_detached(&mut cmd, None);
    }

    // deliver: neprázdný outbox, nebo příkazy stažené dávno (plán § 8.1)
    let pending: i64 = conn.query_row(
        "SELECT COUNT(*) FROM outbox WHERE poisoned_at IS NULL OR last_tried_at IS NULL OR last_tried_at < ?1",
        [now - 3_600_000],
        |r| r.get(0),
    )?;
    let fetched = meta_i64(&conn, "commands_fetched_at")?;
    if pending > 0 || fetched.is_none_or(|f| now - f >= DELIVER_COMMANDS_EVERY_MS || f > now) {
        spawn_deliver(&bin, &app, &export, &state_dir);
    }
    Ok(0)
}

fn meta_get_str(conn: &Connection, key: &str) -> Res<Option<String>> {
    Ok(db::meta_get(conn, key)?)
}

/// Otisk, jehož změna spustí `sync`: slot + `installed.json` + NEONy z exportu.
pub fn sync_fingerprint(app: &Path, export: &Export) -> String {
    let mut parts = vec![app.to_string_lossy().into_owned()];
    parts.push(
        file_mtime_ms(&app.join("vendor/composer/installed.json"))
            .unwrap_or(0)
            .to_string(),
    );
    for f in &export.config_files {
        parts.push(format!("{f}={}", file_mtime_ms(Path::new(f)).unwrap_or(0)));
    }
    parts.join("|")
}

fn mark_refused_raw(state_dir: &Path, reason: &str) {
    if let Ok(c) = Connection::open(db::db_path(state_dir)) {
        let _ = c.busy_timeout(std::time::Duration::from_millis(5000));
        let _ = meta_set(&c, "tick_refused", reason);
    }
}

pub struct Refusal {
    /// Databáze, do které se odmítnutí zapíše (u změny cesty ta stará).
    pub db_dir: PathBuf,
    pub message: String,
}

/// Změna `stateDir` bez `--move-state` je chyba (plán § 4.2). Předchozí cesta se hledá
/// v ukazateli projektu mimo slot (audit M5) a ve slotu.
fn check_state_dir(
    app: &Path,
    export: &Export,
    state_dir: &Path,
    move_state: bool,
) -> Res<Option<Refusal>> {
    let db_file = db::db_path(state_dir);
    if db_file.exists() {
        // DB existuje: musí být otevřená pod stejnou cestou
        if let Ok(c) = Connection::open(&db_file) {
            let _ = c.busy_timeout(std::time::Duration::from_millis(5000));
            let recorded = c
                .query_row("SELECT value FROM meta WHERE key = 'state_dir'", [], |r| {
                    r.get::<_, String>(0)
                })
                .ok()
                .filter(|v| !v.is_empty());
            if let Some(rec) = recorded
                && Path::new(&rec) != state_dir
            {
                if move_state {
                    meta_set(&c, "state_dir", &state_dir.to_string_lossy())?;
                    return Ok(None);
                }
                return Ok(Some(Refusal {
                    db_dir: state_dir.to_path_buf(),
                    message: format!(
                        "database was last used as {rec}, now opened as {}; run `lqcron tick --move-state` if this is intended",
                        state_dir.display()
                    ),
                }));
            }
        }
        return Ok(None);
    }
    let candidates = [project_pointer(export), Some(pointer_path(app))];
    let prev = candidates
        .into_iter()
        .flatten()
        .filter_map(|p| read_pointer(&p))
        .find(|p| p != state_dir && db::db_path(p).exists());
    let Some(prev) = prev else {
        return Ok(None);
    };
    if !move_state {
        return Ok(Some(Refusal {
            message: format!(
                "stateDir changed from {} to {}; run `lqcron tick --move-state` to move the scheduler state",
                prev.display(),
                state_dir.display()
            ),
            db_dir: prev,
        }));
    }
    move_state_dir(&prev, state_dir)?;
    Ok(None)
}

/// `--move-state`: přesune databázi a soubory běhů do nového `stateDir`.
fn move_state_dir(old: &Path, new: &Path) -> Res<()> {
    let _old_lock =
        FileLock::lock_wait(&old.join("tick.lock"), std::time::Duration::from_secs(60))?
            .ok_or_else(|| fail("old stateDir is locked by a running tick"))?;
    let old_conn = db::open_existing_res(old)?;
    let active: i64 = old_conn.query_row(
        &format!(
            "SELECT COUNT(*) FROM runs WHERE state IN {}",
            crate::model::ACTIVE_SQL
        ),
        [],
        |r| r.get(0),
    )?;
    if active > 0 {
        return Err(fail(format!(
            "cannot move stateDir: {active} runs are still active in {}",
            old.display()
        )));
    }
    let _: (i64, i64, i64) = old_conn.query_row("PRAGMA wal_checkpoint(TRUNCATE)", [], |r| {
        Ok((r.get(0)?, r.get(1)?, r.get(2)?))
    })?;
    ensure_dir(new)?;
    {
        let mut dst = Connection::open(db::db_path(new))?;
        let backup = rusqlite::backup::Backup::new(&old_conn, &mut dst)?;
        backup.run_to_completion(256, std::time::Duration::from_millis(10), None)?;
    }
    if let Ok(id) = fs::read(old.join("instance_id")) {
        write_atomic(&new.join("instance_id"), &id, 0o660)?;
    }
    ensure_dir(&runs_dir(new))?;
    if let Ok(rd) = fs::read_dir(runs_dir(old)) {
        for e in rd.flatten() {
            let to = runs_dir(new).join(e.file_name());
            if fs::rename(e.path(), &to).is_err() {
                let _ = fs::copy(e.path(), &to);
            }
        }
    }
    drop(old_conn);
    let stamp = now_ms();
    fs::rename(
        db::db_path(old),
        old.join(format!("{}.moved-{stamp}", db::DB_FILE)),
    )?;
    let new_conn = db::open_existing_res(new)?;
    meta_set(&new_conn, "state_dir", &new.to_string_lossy())?;
    Ok(())
}

/// Zkopíruje binárku do `stateDir/bin/lqcron-<verze>` (jen při změně obsahu) a vrátí její cestu.
///
/// Obsah se porovnává hashem, ne velikostí (audit L12): dev build se stejnou verzí
/// a velikostí by jinak zůstal starý. Aby se binárka nehashovala každou minutu, leží vedle
/// kopie `lqcron-<verze>.src` = `velikost:mtime:sha256` zdrojové binárky; hash se počítá
/// jen když se velikost nebo mtime zdroje změní.
fn install_binary_copy(state_dir: &Path, exe: &Path) -> std::io::Result<PathBuf> {
    let dir = state_dir.join("bin");
    let target = dir.join(format!("lqcron-{VERSION}"));
    if exe == target {
        return Ok(target);
    }
    let src_meta = fs::metadata(exe)?;
    let stamp = format!("{}:{}", src_meta.len(), file_mtime_ms(exe).unwrap_or(0));
    let sidecar = dir.join(format!("lqcron-{VERSION}.src"));
    let recorded = fs::read_to_string(&sidecar).unwrap_or_default();
    if target.exists() && recorded.starts_with(&format!("{stamp}:")) {
        return Ok(target);
    }
    let data = fs::read(exe)?;
    let hash = crate::util::sha256_hex(&data);
    let target_hash = fs::read(&target).ok().map(|t| crate::util::sha256_hex(&t));
    if target_hash.as_deref() != Some(hash.as_str()) {
        ensure_dir(&dir)?;
        let tmp = dir.join(format!(".lqcron-{VERSION}.{}", std::process::id()));
        fs::write(&tmp, &data)?;
        use std::os::unix::fs::PermissionsExt;
        fs::set_permissions(&tmp, fs::Permissions::from_mode(0o770))?;
        fs::rename(&tmp, &target)?;
    }
    write_atomic(&sidecar, format!("{stamp}:{hash}").as_bytes(), 0o660)?;
    Ok(target)
}

/// Smaže kopie binárky starší 7 dní, ze kterých neběží žádný proces.
fn prune_binary_copies(state_dir: &Path, current: &Path) {
    let Ok(rd) = fs::read_dir(state_dir.join("bin")) else {
        return;
    };
    let now = now_ms();
    for e in rd.flatten() {
        let p = e.path();
        let is_current_sidecar = p.to_string_lossy() == format!("{}.src", current.display());
        if p == current
            || is_current_sidecar
            || !file_mtime_ms(&p).is_some_and(|m| now - m > BIN_KEEP_MS)
        {
            continue;
        }
        if !binary_in_use(&p) {
            let _ = fs::remove_file(&p);
        }
    }
}

fn binary_in_use(path: &Path) -> bool {
    let Ok(rd) = fs::read_dir("/proc") else {
        return true;
    };
    for e in rd.flatten() {
        if let Ok(link) = fs::read_link(e.path().join("exe"))
            && link == path
        {
            return true;
        }
    }
    false
}

/// Převzetí jobů z fronty v jedné transakci (plán § 6.3) → `claimed` + soubor s argumenty.
fn claim(
    conn: &Connection,
    export: &Export,
    state_dir: &Path,
    app: &Path,
    now: i64,
) -> Res<Vec<String>> {
    struct Job {
        id: i64,
        uuid: String,
        origin: String,
        code: String,
        arguments: Option<String>,
        attempt: i64,
        source: String,
        scheduled_for: Option<i64>,
        available_at: i64,
        created_at: i64,
        mode: String,
        resources: String,
        timeout: Option<i64>,
        repeat_count: i64,
    }
    let tx = immediate(conn)?;
    let last_resume = meta_i64(&tx, "last_resume_at")?.unwrap_or(0);
    let jobs: Vec<Job> = tx
        .prepare(
            "SELECT j.id, j.uuid, j.origin_uuid, j.cron_code, j.arguments, j.attempt, j.source, j.scheduled_for, j.available_at, j.created_at,
                    c.concurrency_mode, c.resources, c.timeout, c.repeat_count
             FROM jobs j JOIN crons c ON c.code = j.cron_code
             WHERE j.available_at <= ?1 AND c.defined = 1 AND c.local_active = 1 AND c.remote_active = 1
             ORDER BY j.id",
        )?
        .query_map([now], |r| {
            Ok(Job {
                id: r.get(0)?,
                uuid: r.get(1)?,
                origin: r.get(2)?,
                code: r.get(3)?,
                arguments: r.get(4)?,
                attempt: r.get(5)?,
                source: r.get(6)?,
                scheduled_for: r.get(7)?,
                available_at: r.get(8)?,
                created_at: r.get(9)?,
                mode: r.get(10)?,
                resources: r.get(11)?,
                timeout: r.get(12)?,
                repeat_count: r.get(13)?,
            })
        })?
        .collect::<Result<_, _>>()?;
    let active: Vec<Slot> = active_runs(&tx)?
        .into_iter()
        .map(|r| Slot {
            cron_code: r.cron_code,
            mode: r.concurrency_mode,
            resources: r.resources,
        })
        .collect();
    let candidates: Vec<Candidate> = jobs
        .iter()
        .map(|j| Candidate {
            job_id: j.id,
            slot: Slot {
                cron_code: j.code.clone(),
                mode: j.mode.clone(),
                resources: parse_list(&j.resources),
            },
            waiting_since: j.available_at.max(last_resume),
        })
        .collect();
    let limits = Limits {
        max_parallel: export.config.max_parallel,
        capacities: export.config.resources.clone(),
        starvation_ms: export.config.starvation_seconds.max(0) * 1000,
    };
    let sel = select::choose(&candidates, &active, &limits, now);
    meta_set(&tx, "starving", &serde_json::to_string(&sel.starving)?)?;
    let mut claimed = Vec::new();
    ensure_dir(&runs_dir(state_dir))?;
    for id in &sel.chosen {
        let Some(j) = jobs.iter().find(|j| j.id == *id) else {
            continue;
        };
        let run_uuid = uuid_v7();
        let args = j.arguments.clone().unwrap_or_else(|| "null".into());
        write_atomic(&args_path(state_dir, &run_uuid), args.as_bytes(), 0o660)?;
        let timeout = j.timeout.unwrap_or(DEFAULT_TIMEOUT);
        tx.execute(
            "INSERT INTO runs (uuid, job_uuid, origin_uuid, cron_code, attempt, max_attempts, source, scheduled_for, job_created_at,
                               timeout, concurrency_mode, resources, state, claimed_at, app_path)
             VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?12, 'claimed', ?13, ?14)",
            params![
                run_uuid,
                j.uuid,
                j.origin,
                j.code,
                j.attempt,
                j.repeat_count.max(0) + 1,
                j.source,
                j.scheduled_for,
                j.created_at,
                timeout,
                j.mode,
                j.resources,
                now,
                app.to_string_lossy()
            ],
        )?;
        tx.execute("DELETE FROM jobs WHERE id = ?1", [j.id])?;
        claimed.push(run_uuid);
    }
    if let Err(e) = tx.commit() {
        for u in &claimed {
            let _ = fs::remove_file(args_path(state_dir, u));
        }
        return Err(e.into());
    }
    Ok(claimed)
}

/// Vrátí převzatý, ale nespuštěný běh do fronty bez započtení pokusu (plán § 7.0).
fn requeue(
    conn: &Connection,
    state_dir: &Path,
    run: &RunRow,
    from_state: &str,
    now: i64,
) -> Res<bool> {
    let args = fs::read_to_string(args_path(state_dir, &run.uuid))
        .ok()
        .filter(|a| a.trim() != "null");
    let tx = immediate(conn)?;
    let n = tx.execute(
        "DELETE FROM runs WHERE id = ?1 AND state = ?2 AND pid IS NULL",
        params![run.id, from_state],
    )?;
    if n == 0 {
        return Ok(false);
    }
    tx.execute(
        "INSERT INTO jobs (uuid, origin_uuid, cron_code, arguments, attempt, source, scheduled_for, available_at, created_at)
         VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9)",
        params![run.job_uuid, run.origin_uuid, run.cron_code, args, run.attempt, run.source, run.scheduled_for, now, run.job_created_at],
    )?;
    tx.commit()?;
    let _ = fs::remove_file(args_path(state_dir, &run.uuid));
    Ok(true)
}

/// Úklid zaseknutých `claimed`/`starting` a ztracených běhů (plán § 7.0, § 7.3).
fn reap_runs(
    conn: &Connection,
    state_dir: &Path,
    app: &Path,
    bin: &Path,
    php: &str,
    now: i64,
) -> Res<()> {
    let boot = boottime_ms();
    let current_boot = boot_id();
    for run in active_runs(conn)? {
        if run.state == "claimed" {
            if now - run.claimed_at > CLAIM_STUCK_MS {
                requeue(conn, state_dir, &run, "claimed", now)?;
            }
            continue;
        }
        // pid a čas startu platí jen v bootu, ve kterém běh vznikl (audit H1)
        let other_boot = run
            .boot_id
            .as_deref()
            .is_some_and(|b| current_boot.as_deref().is_some_and(|c| c != b));
        let mut sup_alive = !other_boot && pid_alive(run.supervisor_pid, run.supervisor_start);
        if sup_alive
            && run
                .heartbeat_at
                .is_some_and(|h| boot - h > SUPERVISOR_STALE_MS)
        {
            // zaseknutý hlídač: nikdy nepracují dva nad jedním během
            kill(run.supervisor_pid.unwrap_or(0), libc::SIGKILL);
            sup_alive = false;
        }
        if sup_alive {
            continue;
        }
        if run.state == "starting" && run.pid.is_none() {
            if now - run.claimed_at > CLAIM_STUCK_MS {
                // PHP, které ještě nestihlo `running`, ukončit (jen naši skupinu) a vrátit do fronty
                if let Some(pgid) = crate::runjob::owned_group(&run) {
                    kill_group(pgid, libc::SIGKILL);
                }
                requeue(conn, state_dir, &run, "starting", now)?;
            }
            continue;
        }
        let run_app = run
            .app_path
            .clone()
            .map(PathBuf::from)
            .filter(|p| p.is_dir())
            .unwrap_or_else(|| app.to_path_buf());
        let mut cmd = Command::new(bin);
        cmd.arg("run-job")
            .arg(&run.uuid)
            .arg("--adopt")
            .arg(format!("--state-dir={}", state_dir.display()))
            .arg(format!("--app={}", run_app.display()))
            .arg(format!("--php={php}"))
            .current_dir(&run_app);
        let log = crate::runjob::log_path(state_dir, &run.uuid);
        let _ = ensure_dir(&runs_dir(state_dir));
        if let Err(e) = spawn_detached(&mut cmd, Some(&log)) {
            eprintln!("lqcron: cannot adopt run {}: {e}", run.uuid);
        }
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::db::testutil::{TempDir, fresh_db};
    use crate::queue::tests::insert_cron;

    fn insert_run(c: &Connection, uuid: &str, state: &str, claimed_at: i64) {
        c.execute(
            "INSERT INTO runs (uuid, job_uuid, origin_uuid, cron_code, attempt, max_attempts, source, job_created_at, concurrency_mode, state, claimed_at)
             VALUES (?1, ?1 || '-job', 'o', 'a', 1, 1, 'cli', 1, 'exclusive', ?2, ?3)",
            params![uuid, state, claimed_at],
        )
        .unwrap();
    }

    /// „Binárka“, která místo adopce jen zapíše své argumenty.
    fn recorder(dir: &Path) -> (PathBuf, PathBuf) {
        let out = dir.join("spawned.txt");
        let bin = dir.join("fake-lqcron.sh");
        fs::write(
            &bin,
            format!("#!/bin/sh\necho \"$@\" >> {}\n", out.display()),
        )
        .unwrap();
        use std::os::unix::fs::PermissionsExt;
        fs::set_permissions(&bin, fs::Permissions::from_mode(0o755)).unwrap();
        (bin, out)
    }

    fn wait_for(path: &Path) -> String {
        for _ in 0..100 {
            if let Ok(s) = fs::read_to_string(path)
                && !s.is_empty()
            {
                return s;
            }
            std::thread::sleep(std::time::Duration::from_millis(20));
        }
        String::new()
    }

    #[test]
    fn stuck_claimed_and_starting_go_back_to_the_queue_after_60s() {
        let (d, c) = fresh_db("reap-requeue");
        insert_cron(&c, "a", None, "exclusive");
        let now = 10_000_000;
        insert_run(&c, "fresh", "claimed", now - 30_000);
        insert_run(&c, "stuck", "claimed", now - 61_000);
        insert_run(&c, "starting", "starting", now - 61_000);
        c.execute(
            "UPDATE runs SET supervisor_pid = 999999999, supervisor_start = 1 WHERE uuid = 'starting'",
            [],
        )
        .unwrap();
        fs::create_dir_all(runs_dir(&d.0)).unwrap();
        fs::write(args_path(&d.0, "stuck"), r#"{"x":1}"#).unwrap();
        // PHP, které nestihlo `running`: jeho skupina se před vrácením do fronty ukončí
        let (mut php, pid, start) = crate::runjob::tests::foreign_group();
        c.execute(
            "UPDATE runs SET pgid = ?1, pgid_start = ?2, boot_id = ?3 WHERE uuid = 'starting'",
            params![pid, start, boot_id()],
        )
        .unwrap();
        let (bin, _) = recorder(&d.0);
        reap_runs(&c, &d.0, &d.0, &bin, "php", now).unwrap();
        let runs: Vec<String> = c
            .prepare("SELECT uuid FROM runs ORDER BY id")
            .unwrap()
            .query_map([], |r| r.get(0))
            .unwrap()
            .map(Result::unwrap)
            .collect();
        assert_eq!(runs, vec!["fresh"]);
        let jobs: Vec<(String, Option<String>, i64)> = c
            .prepare("SELECT uuid, arguments, attempt FROM jobs ORDER BY uuid")
            .unwrap()
            .query_map([], |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?)))
            .unwrap()
            .map(Result::unwrap)
            .collect();
        assert_eq!(jobs.len(), 2);
        assert_eq!(jobs[0], ("starting-job".into(), None, 1)); // bez započtení pokusu
        assert_eq!(jobs[1], ("stuck-job".into(), Some(r#"{"x":1}"#.into()), 1));
        std::thread::sleep(std::time::Duration::from_millis(100));
        assert!(
            !matches!(php.try_wait(), Ok(None)),
            "orphaned PHP group must be killed"
        );
        let _ = php.wait();
    }

    #[test]
    fn supervisor_with_stale_heartbeat_is_killed_and_run_adopted() {
        let (d, c) = fresh_db("reap-stale");
        insert_cron(&c, "a", None, "exclusive");
        insert_run(&c, "r", "running", 1);
        let (mut sup, pid, start) = crate::runjob::tests::foreign_group();
        c.execute(
            "UPDATE runs SET supervisor_pid = ?1, supervisor_start = ?2, heartbeat_at = ?3, boot_id = ?4",
            params![pid, start, boottime_ms() - 200_000, boot_id()],
        )
        .unwrap();
        let (bin, out) = recorder(&d.0);
        reap_runs(&c, &d.0, &d.0, &bin, "php", now_ms()).unwrap();
        std::thread::sleep(std::time::Duration::from_millis(100));
        assert!(
            !matches!(sup.try_wait(), Ok(None)),
            "stale supervisor must be killed"
        );
        let _ = sup.wait();
        assert!(wait_for(&out).contains("run-job r --adopt"));
    }

    #[test]
    fn supervisor_from_another_boot_counts_as_dead() {
        let (d, c) = fresh_db("reap-boot");
        insert_cron(&c, "a", None, "exclusive");
        insert_run(&c, "r", "running", 1);
        // „živý“ hlídač (tenhle proces), ale z jiného bootu → nesmí se mu věřit ani ho zabít
        let me = std::process::id() as i64;
        c.execute(
            "UPDATE runs SET supervisor_pid = ?1, supervisor_start = ?2, heartbeat_at = ?3, boot_id = 'other-boot'",
            params![me, crate::util::proc_starttime(me), boottime_ms()],
        )
        .unwrap();
        let (bin, out) = recorder(&d.0);
        reap_runs(&c, &d.0, &d.0, &bin, "php", now_ms()).unwrap();
        assert!(wait_for(&out).contains("run-job r --adopt"));
    }

    #[test]
    fn claim_applies_barrier_and_records_starving() {
        let (d, c) = fresh_db("claim-barrier");
        insert_cron(&c, "p1", None, "parallel");
        insert_cron(&c, "p2", None, "parallel");
        insert_cron(&c, "ex", None, "exclusive");
        let now = 100_000_000;
        // běží parallel, exclusive čeká 10 min, přichází další parallel
        c.execute(
            "INSERT INTO runs (uuid, job_uuid, origin_uuid, cron_code, attempt, max_attempts, source, job_created_at, concurrency_mode, state, claimed_at)
             VALUES ('run-p1', 'j', 'o', 'p1', 1, 1, 'cli', 1, 'parallel', 'running', ?1)",
            [now],
        )
        .unwrap();
        c.execute(
            "INSERT INTO jobs (uuid, origin_uuid, cron_code, source, available_at, created_at) VALUES ('j-ex', 'j-ex', 'ex', 'cli', ?1, ?1)",
            [now - 600_000],
        )
        .unwrap();
        c.execute(
            "INSERT INTO jobs (uuid, origin_uuid, cron_code, source, available_at, created_at) VALUES ('j-p2', 'j-p2', 'p2', 'cli', ?1, ?1)",
            [now - 1000],
        )
        .unwrap();
        let export = Export::parse(br#"{"config": {"state_dir": "/tmp/x"}}"#).unwrap();
        let app = TempDir::new("claim-app");
        let claimed = claim(&c, &export, &d.0, &app.0, now).unwrap();
        assert!(
            claimed.is_empty(),
            "parallel must wait behind the starving exclusive job"
        );
        assert_eq!(
            db::meta_get(&c, "starving").unwrap().as_deref(),
            Some(r#"["ex"]"#)
        );
        // parallel doběhne → exclusive se spustí
        c.execute("UPDATE runs SET state = 'finished'", []).unwrap();
        let claimed = claim(&c, &export, &d.0, &app.0, now).unwrap();
        assert_eq!(claimed.len(), 1);
        let code: String = c
            .query_row(
                "SELECT cron_code FROM runs WHERE uuid = ?1",
                [&claimed[0]],
                |r| r.get(0),
            )
            .unwrap();
        assert_eq!(code, "ex");
    }
}

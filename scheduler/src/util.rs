//! Drobné systémové pomůcky: čas, UUID v7, soubory, zámky, procesy.

use std::ffi::CString;
use std::fs::{self, File, OpenOptions};
use std::io::{self, Read, Seek, SeekFrom, Write};
use std::os::unix::ffi::OsStrExt;
use std::os::unix::fs::{DirBuilderExt, OpenOptionsExt};
use std::os::unix::process::CommandExt;
use std::path::{Path, PathBuf};
use std::process::{Command, Stdio};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

use chrono::{DateTime, Utc};

pub type Res<T> = Result<T, Box<dyn std::error::Error>>;

/// Chyba s textem — zkratka pro `Err(fail(..))`.
pub fn fail(msg: impl Into<String>) -> Box<dyn std::error::Error> {
    msg.into().into()
}

pub const VERSION: &str = env!("CARGO_PKG_VERSION");

/// Wall clock v unixových milisekundách.
///
/// `LQCRON_FAKE_NOW_MS` posune hodiny jen pro testy a jen spolu s `LQCRON_TESTING=1`,
/// aby náhodně exportovaná proměnná nemohla posunout hodiny plánovače v provozu.
pub fn now_ms() -> i64 {
    if std::env::var("LQCRON_TESTING").as_deref() == Ok("1")
        && let Ok(v) = std::env::var("LQCRON_FAKE_NOW_MS")
        && let Ok(n) = v.parse::<i64>()
    {
        return n;
    }
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_millis() as i64)
        .unwrap_or(0)
}

/// Monotónní čas `CLOCK_BOOTTIME` v ms (heartbeat hlídacího procesu, plán § 7.1).
pub fn boottime_ms() -> i64 {
    let mut ts = libc::timespec {
        tv_sec: 0,
        tv_nsec: 0,
    };
    // SAFETY: platný ukazatel na timespec.
    unsafe {
        libc::clock_gettime(libc::CLOCK_BOOTTIME, &mut ts);
    }
    ts.tv_sec * 1000 + ts.tv_nsec / 1_000_000
}

/// ISO 8601 v UTC s milisekundami a offsetem (kontrakt, konvence).
pub fn iso(ms: i64) -> String {
    match DateTime::<Utc>::from_timestamp_millis(ms) {
        Some(dt) => dt.format("%Y-%m-%dT%H:%M:%S%.3f+00:00").to_string(),
        None => "1970-01-01T00:00:00.000+00:00".to_string(),
    }
}

pub fn iso_opt(ms: Option<i64>) -> serde_json::Value {
    match ms {
        Some(v) => serde_json::Value::String(iso(v)),
        None => serde_json::Value::Null,
    }
}

pub fn parse_iso(s: &str) -> Option<i64> {
    DateTime::parse_from_rfc3339(s)
        .ok()
        .map(|d| d.timestamp_millis())
}

/// UUID v7 (časově řazené), malými písmeny s pomlčkami.
pub fn uuid_v7() -> String {
    let ms = now_real_ms() as u64;
    let mut rnd = [0u8; 10];
    if getrandom::fill(&mut rnd).is_err() {
        // Nouzově: nanosekundy + pid; UUID pak pořád unikátní v rámci procesu.
        let n = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .map(|d| d.as_nanos())
            .unwrap_or(0)
            ^ ((std::process::id() as u128) << 64);
        rnd.copy_from_slice(&n.to_le_bytes()[..10]);
    }
    let mut b = [0u8; 16];
    b[0..6].copy_from_slice(&ms.to_be_bytes()[2..8]);
    b[6] = 0x70 | (rnd[0] & 0x0f);
    b[7] = rnd[1];
    b[8] = 0x80 | (rnd[2] & 0x3f);
    b[9..16].copy_from_slice(&rnd[3..10]);
    let h: String = b.iter().map(|x| format!("{x:02x}")).collect();
    format!(
        "{}-{}-{}-{}-{}",
        &h[0..8],
        &h[8..12],
        &h[12..16],
        &h[16..20],
        &h[20..32]
    )
}

fn now_real_ms() -> i64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_millis() as i64)
        .unwrap_or(0)
}

pub fn set_umask() {
    // SAFETY: umask nemá žádné předpoklady.
    unsafe {
        libc::umask(0o002);
    }
}

pub fn hostname() -> String {
    let mut buf = [0u8; 256];
    // SAFETY: buffer o dané délce.
    let r = unsafe { libc::gethostname(buf.as_mut_ptr() as *mut libc::c_char, buf.len()) };
    if r != 0 {
        return "unknown".into();
    }
    let end = buf.iter().position(|&c| c == 0).unwrap_or(buf.len());
    String::from_utf8_lossy(&buf[..end]).into_owned()
}

/// Vytvoří adresář (i rodiče) s právy 0770.
pub fn ensure_dir(path: &Path) -> io::Result<()> {
    if path.is_dir() {
        return Ok(());
    }
    fs::DirBuilder::new()
        .recursive(true)
        .mode(0o770)
        .create(path)
}

/// Atomický zápis (tmp + rename).
pub fn write_atomic(path: &Path, data: &[u8], mode: u32) -> io::Result<()> {
    let tmp = path.with_extension(format!(
        "{}.tmp{}",
        path.extension()
            .map(|e| e.to_string_lossy().into_owned())
            .unwrap_or_default(),
        std::process::id()
    ));
    {
        let mut f = OpenOptions::new()
            .write(true)
            .create(true)
            .truncate(true)
            .mode(mode)
            .open(&tmp)?;
        f.write_all(data)?;
        f.sync_all().ok();
    }
    fs::rename(&tmp, path)
}

/// Posledních `max` bajtů souboru + příznak, že je soubor delší.
pub fn read_tail(path: &Path, max: u64) -> io::Result<(Vec<u8>, bool)> {
    let mut f = File::open(path)?;
    let len = f.metadata()?.len();
    let truncated = len > max;
    if truncated {
        f.seek(SeekFrom::Start(len - max))?;
    }
    let mut buf = Vec::new();
    f.read_to_end(&mut buf)?;
    Ok((buf, truncated))
}

/// `flock` zámek; drží se po dobu života hodnoty.
pub struct FileLock {
    _file: File,
}

impl FileLock {
    /// Nečekající pokus; `Ok(None)` = zámek drží jiný proces.
    pub fn try_lock(path: &Path) -> io::Result<Option<FileLock>> {
        let file = OpenOptions::new()
            .read(true)
            .write(true)
            .create(true)
            .truncate(false)
            .mode(0o660)
            .open(path)?;
        // SAFETY: platný fd.
        let r = unsafe {
            libc::flock(
                std::os::fd::AsRawFd::as_raw_fd(&file),
                libc::LOCK_EX | libc::LOCK_NB,
            )
        };
        if r == 0 {
            Ok(Some(FileLock { _file: file }))
        } else {
            let e = io::Error::last_os_error();
            if e.raw_os_error() == Some(libc::EWOULDBLOCK) {
                Ok(None)
            } else {
                Err(e)
            }
        }
    }

    /// Čeká na zámek nejvýš `timeout`.
    pub fn lock_wait(path: &Path, timeout: Duration) -> io::Result<Option<FileLock>> {
        let start = Instant::now();
        loop {
            if let Some(l) = Self::try_lock(path)? {
                return Ok(Some(l));
            }
            if start.elapsed() >= timeout {
                return Ok(None);
            }
            std::thread::sleep(Duration::from_millis(200));
        }
    }

    /// Je zámek právě držený jiným procesem?
    pub fn is_held(path: &Path) -> bool {
        matches!(Self::try_lock(path), Ok(None))
    }
}

/// Pole 22 (`starttime`) a stav procesu z `/proc/<pid>/stat`.
pub fn proc_stat(pid: i64) -> Option<(i64, char, i64)> {
    if pid <= 0 {
        return None;
    }
    let s = fs::read_to_string(format!("/proc/{pid}/stat")).ok()?;
    let close = s.rfind(')')?;
    let rest: Vec<&str> = s[close + 1..].split_whitespace().collect();
    // rest[0] = stav (pole 3), rest[2] = pgrp (pole 5), rest[19] = starttime (pole 22)
    let state = rest.first()?.chars().next()?;
    let pgrp = rest.get(2)?.parse::<i64>().ok()?;
    let start = rest.get(19)?.parse::<i64>().ok()?;
    Some((start, state, pgrp))
}

pub fn proc_starttime(pid: i64) -> Option<i64> {
    proc_stat(pid).map(|(s, _, _)| s)
}

/// Identifikátor aktuálního bootu (`/proc/sys/kernel/random/boot_id`). Pid a čas startu
/// procesu mají smysl jen v rámci jednoho bootu.
pub fn boot_id() -> Option<String> {
    fs::read_to_string("/proc/sys/kernel/random/boot_id")
        .ok()
        .map(|s| s.trim().to_string())
        .filter(|s| !s.is_empty())
}

/// Je skupina procesů `pgid` ta, kterou jsme spustili (leader s časem startu `start`
/// v bootu `boot`)? Dokud ve skupině něco žije, jádro pgid nepřidělí jinému procesu;
/// po vyprázdnění se ale pid může recyklovat — proto: jiný boot → ne; existuje proces
/// s pid = pgid a jiným časem startu → ne (recyklovaný pid); jinak ano.
pub fn group_is_ours(pgid: Option<i64>, start: Option<i64>, boot: Option<&str>) -> bool {
    let Some(pgid) = pgid.filter(|p| *p > 1) else {
        return false;
    };
    let current = boot_id();
    match (boot, current.as_deref()) {
        (Some(b), Some(c)) if b != c => return false,
        // bez známého bootu jen s živým leaderem s přesně stejným startem
        (None, _) | (_, None) => return start.is_some() && proc_starttime(pgid) == start,
        _ => {}
    }
    match proc_starttime(pgid) {
        Some(s) => start == Some(s),
        None => true,
    }
}

/// Žije proces s daným pid a časem startu (ochrana proti recyklovanému PID, plán § 7.3)?
pub fn pid_alive(pid: Option<i64>, start: Option<i64>) -> bool {
    let Some(pid) = pid else { return false };
    match proc_stat(pid) {
        Some((s, state, _)) => {
            if state == 'Z' || state == 'X' {
                return false;
            }
            match start {
                Some(expected) => s == expected,
                None => true,
            }
        }
        None => false,
    }
}

pub fn kill(pid: i64, sig: i32) -> bool {
    if pid <= 0 {
        return false;
    }
    // SAFETY: prosté volání kill.
    unsafe { libc::kill(pid as libc::pid_t, sig) == 0 }
}

pub fn kill_group(pgid: i64, sig: i32) -> bool {
    if pgid <= 1 {
        return false;
    }
    // SAFETY: prosté volání kill na skupinu.
    unsafe { libc::kill(-(pgid as libc::pid_t), sig) == 0 }
}

/// Žije ve skupině procesů ještě něco (mimo zombie)?
pub fn group_alive(pgid: i64) -> bool {
    if pgid <= 1 {
        return false;
    }
    // SAFETY: signál 0 jen ověřuje existenci.
    let r = unsafe { libc::kill(-(pgid as libc::pid_t), 0) };
    if r != 0 {
        let e = io::Error::last_os_error().raw_os_error();
        if e != Some(libc::EPERM) {
            return false;
        }
    }
    // kill() vidí i zombie; ty by skupinu „držely“ navždy, pokud je nikdo nesklidí.
    group_has_live_member(pgid)
}

fn group_has_live_member(pgid: i64) -> bool {
    let Ok(rd) = fs::read_dir("/proc") else {
        return true;
    };
    for e in rd.flatten() {
        let name = e.file_name();
        let Some(pid) = name.to_str().and_then(|s| s.parse::<i64>().ok()) else {
            continue;
        };
        if let Some((_, state, pgrp)) = proc_stat(pid)
            && pgrp == pgid
            && state != 'Z'
            && state != 'X'
        {
            return true;
        }
    }
    false
}

/// Volné místo v bajtech (statvfs).
pub fn free_bytes(path: &Path) -> Option<u64> {
    let c = CString::new(path.as_os_str().as_bytes()).ok()?;
    // SAFETY: nulovaná struktura, platný C řetězec.
    let mut st: libc::statvfs = unsafe { std::mem::zeroed() };
    let r = unsafe { libc::statvfs(c.as_ptr(), &mut st) };
    if r != 0 {
        return None;
    }
    Some(st.f_bavail as u64 * st.f_frsize as u64)
}

/// Spustí proces odpojeně: nová session (`setsid`), stdio na /dev/null (nebo do `log`).
/// Rodič na něj nečeká; po skončení ticku ho převezme init. Vrací pid.
pub fn spawn_detached(cmd: &mut Command, log: Option<&Path>) -> io::Result<u32> {
    cmd.stdin(Stdio::null());
    match log {
        Some(p) => {
            let f = OpenOptions::new()
                .create(true)
                .append(true)
                .mode(0o660)
                .open(p)?;
            let f2 = f.try_clone()?;
            cmd.stdout(Stdio::from(f)).stderr(Stdio::from(f2));
        }
        None => {
            cmd.stdout(Stdio::null()).stderr(Stdio::null());
        }
    }
    // SAFETY: setsid je async-signal-safe.
    unsafe {
        cmd.pre_exec(|| {
            libc::setsid();
            Ok(())
        });
    }
    let child = cmd.spawn()?;
    Ok(child.id())
}

/// Cesta k běžící binárce.
pub fn current_exe() -> PathBuf {
    std::env::current_exe().unwrap_or_else(|_| PathBuf::from("lqcron"))
}

pub fn file_mtime_ms(path: &Path) -> Option<i64> {
    let m = fs::metadata(path).ok()?.modified().ok()?;
    Some(m.duration_since(UNIX_EPOCH).ok()?.as_millis() as i64)
}

/// sha256 v hex.
pub fn sha256_hex(data: &[u8]) -> String {
    use sha2::Digest;
    let d = sha2::Sha256::digest(data);
    d.iter().map(|b| format!("{b:02x}")).collect()
}

/// Ořízne řetězec na nejvýš `max` bajtů na hranici znaku (od začátku).
pub fn truncate_utf8(s: &str, max: usize) -> &str {
    if s.len() <= max {
        return s;
    }
    let mut end = max;
    while end > 0 && !s.is_char_boundary(end) {
        end -= 1;
    }
    &s[..end]
}

/// Posledních nejvýš `max` bajtů řetězce na hranici znaku.
pub fn tail_utf8(s: &str, max: usize) -> &str {
    if s.len() <= max {
        return s;
    }
    let mut start = s.len() - max;
    while start < s.len() && !s.is_char_boundary(start) {
        start += 1;
    }
    &s[start..]
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn uuid_v7_shape() {
        let a = uuid_v7();
        assert_eq!(a.len(), 36);
        assert_eq!(&a[14..15], "7");
        let b = uuid_v7();
        assert_ne!(a, b);
    }

    #[test]
    fn iso_roundtrip() {
        let s = iso(1_792_000_000_123);
        assert!(s.ends_with(".123+00:00"));
        assert_eq!(parse_iso(&s), Some(1_792_000_000_123));
    }

    #[test]
    fn own_process_is_alive() {
        let pid = std::process::id() as i64;
        let st = proc_starttime(pid);
        assert!(st.is_some());
        assert!(pid_alive(Some(pid), st));
        assert!(!pid_alive(Some(pid), st.map(|s| s + 1)));
    }
}

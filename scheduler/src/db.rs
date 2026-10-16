//! SQLite (kontrakt § 2): založení, migrace, kompatibilita schématu (plán § 12.1), `meta`.

use std::path::{Path, PathBuf};
use std::time::Duration;

use rusqlite::{Connection, OpenFlags, OptionalExtension, TransactionBehavior, params};

use crate::util::{Res, ensure_dir, fail, now_ms, uuid_v7, write_atomic};

/// Verze schématu, kterou tahle binárka zná a zakládá.
pub const SCHEMA_VERSION: i64 = 2;
/// Nejnižší verze schématu, kterou musí znát čtenář/zapisovatel databáze této binárky.
pub const MIN_READER_VERSION: i64 = 1;

pub const DB_FILE: &str = "lqcron.sqlite";

pub fn db_path(state_dir: &Path) -> PathBuf {
    state_dir.join(DB_FILE)
}

/// Schéma verze 1 — jen SQL kompatibilní se SQLite 3.24 (kontrakt § 2).
const SCHEMA_V1: &str = include_str!("schema_v1.sql");

/// v2: `runs.pgid`, `runs.pgid_start`, `runs.boot_id`, `commands.ack_event_id` (aditivní).
const SCHEMA_V2: &str = include_str!("schema_v2.sql");

/// Migrace podle cílové verze. Index = verze - 1. Každá je aditivní (plán § 12.1).
const MIGRATIONS: &[&str] = &[SCHEMA_V1, SCHEMA_V2];

#[derive(Debug)]
pub enum OpenError {
    Missing(PathBuf),
    /// `min_reader_version` databáze je vyšší, než tahle binárka zná.
    Incompatible {
        min_reader: i64,
        schema: i64,
    },
    Other(Box<dyn std::error::Error>),
}

impl std::fmt::Display for OpenError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            OpenError::Missing(p) => write!(
                f,
                "database {} does not exist (run `lqcron tick` first)",
                p.display()
            ),
            OpenError::Incompatible { min_reader, schema } => write!(
                f,
                "database schema {schema} requires reader version >= {min_reader}, this lqcron knows {SCHEMA_VERSION}: rollback over an incompatible schema migration"
            ),
            OpenError::Other(e) => write!(f, "{e}"),
        }
    }
}

impl std::error::Error for OpenError {}

impl From<rusqlite::Error> for OpenError {
    fn from(e: rusqlite::Error) -> Self {
        OpenError::Other(Box::new(e))
    }
}

fn configure(conn: &Connection) -> rusqlite::Result<()> {
    conn.busy_timeout(Duration::from_millis(5000))?;
    let _: String = conn.query_row("PRAGMA journal_mode=WAL", [], |r| r.get(0))?;
    conn.execute_batch("PRAGMA synchronous=NORMAL; PRAGMA foreign_keys=OFF;")?;
    Ok(())
}

/// Otevře existující databázi a ověří kompatibilitu. Nezakládá ani nemigruje.
pub fn open_existing(state_dir: &Path) -> Result<Connection, OpenError> {
    let path = db_path(state_dir);
    if !path.exists() {
        return Err(OpenError::Missing(path));
    }
    let conn = Connection::open_with_flags(
        &path,
        OpenFlags::SQLITE_OPEN_READ_WRITE | OpenFlags::SQLITE_OPEN_NO_MUTEX,
    )?;
    configure(&conn)?;
    check_compat(&conn)?;
    // Aditivní migrace smí udělat kterýkoli proces lqcron (ne PHP); založit DB ne.
    let v = schema_version(&conn)?;
    if (1..SCHEMA_VERSION).contains(&v) {
        migrate(&conn, state_dir).map_err(OpenError::Other)?;
    }
    Ok(conn)
}

/// Otevře databázi, případně ji založí, a zmigruje na [`SCHEMA_VERSION`].
/// Smí jen tick a `sync` (kontrakt § 1: zakládá a migruje jen `lqcron`).
pub fn open_or_create(state_dir: &Path) -> Result<Connection, OpenError> {
    ensure_dir(state_dir).map_err(|e| OpenError::Other(Box::new(e)))?;
    let path = db_path(state_dir);
    let conn = Connection::open_with_flags(
        &path,
        OpenFlags::SQLITE_OPEN_READ_WRITE
            | OpenFlags::SQLITE_OPEN_CREATE
            | OpenFlags::SQLITE_OPEN_NO_MUTEX,
    )?;
    configure(&conn)?;
    check_compat(&conn)?;
    migrate(&conn, state_dir).map_err(OpenError::Other)?;
    Ok(conn)
}

fn has_meta(conn: &Connection) -> rusqlite::Result<bool> {
    conn.query_row(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='meta'",
        [],
        |r| r.get::<_, i64>(0),
    )
    .map(|n| n > 0)
}

pub fn check_compat(conn: &Connection) -> Result<(), OpenError> {
    if !has_meta(conn)? {
        return Ok(());
    }
    let min_reader = meta_get(conn, "min_reader_version")?
        .and_then(|v| v.parse::<i64>().ok())
        .unwrap_or(0);
    if min_reader > SCHEMA_VERSION {
        let schema = schema_version(conn)?;
        return Err(OpenError::Incompatible { min_reader, schema });
    }
    Ok(())
}

pub fn schema_version(conn: &Connection) -> rusqlite::Result<i64> {
    if !has_meta(conn)? {
        return Ok(0);
    }
    Ok(meta_get(conn, "schema_version")?
        .and_then(|v| v.parse::<i64>().ok())
        .unwrap_or(0))
}

fn migrate(conn: &Connection, state_dir: &Path) -> Res<()> {
    if schema_version(conn)? >= SCHEMA_VERSION {
        return Ok(());
    }
    let tx = rusqlite::Transaction::new_unchecked(conn, TransactionBehavior::Immediate)?;
    let current = schema_version(&tx)?;
    for (i, sql) in MIGRATIONS.iter().enumerate() {
        let target = i as i64 + 1;
        if target > current {
            tx.execute_batch(sql)?;
        }
    }
    let now = now_ms();
    if meta_get(&tx, "instance_id")?.is_none() {
        let id_file = state_dir.join("instance_id");
        let id = std::fs::read_to_string(&id_file)
            .ok()
            .map(|s| s.trim().to_string())
            .filter(|s| s.len() == 36)
            .unwrap_or_else(uuid_v7);
        write_atomic(&id_file, format!("{id}\n").as_bytes(), 0o660)?;
        meta_set(&tx, "instance_id", &id)?;
        meta_set(&tx, "created_at", &now.to_string())?;
    }
    meta_set(&tx, "schema_version", &SCHEMA_VERSION.to_string())?;
    let min_reader = meta_get(&tx, "min_reader_version")?
        .and_then(|v| v.parse::<i64>().ok())
        .unwrap_or(0);
    if min_reader < MIN_READER_VERSION {
        meta_set(&tx, "min_reader_version", &MIN_READER_VERSION.to_string())?;
    }
    tx.commit()?;
    Ok(())
}

pub fn meta_get(conn: &Connection, key: &str) -> rusqlite::Result<Option<String>> {
    conn.query_row("SELECT value FROM meta WHERE key = ?1", [key], |r| r.get(0))
        .optional()
}

/// Neprázdná hodnota z `meta` (prázdný řetězec = nenastaveno).
pub fn meta_str(conn: &Connection, key: &str) -> rusqlite::Result<Option<String>> {
    Ok(meta_get(conn, key)?.filter(|v| !v.is_empty()))
}

pub fn meta_i64(conn: &Connection, key: &str) -> rusqlite::Result<Option<i64>> {
    Ok(meta_get(conn, key)?.and_then(|v| v.parse::<i64>().ok()))
}

pub fn meta_json(conn: &Connection, key: &str) -> rusqlite::Result<Option<serde_json::Value>> {
    Ok(meta_str(conn, key)?.and_then(|v| serde_json::from_str(&v).ok()))
}

pub fn meta_set(conn: &Connection, key: &str, value: &str) -> rusqlite::Result<()> {
    conn.execute(
        "INSERT INTO meta (key, value) VALUES (?1, ?2) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
        params![key, value],
    )?;
    Ok(())
}

pub fn immediate(conn: &Connection) -> rusqlite::Result<rusqlite::Transaction<'_>> {
    rusqlite::Transaction::new_unchecked(conn, TransactionBehavior::Immediate)
}

/// Otevře existující databázi; chybějící je chyba s textem.
pub fn open_existing_res(state_dir: &Path) -> Res<Connection> {
    open_existing(state_dir).map_err(|e| fail(e.to_string()))
}

#[cfg(test)]
pub mod testutil {
    use super::*;

    pub struct TempDir(pub PathBuf);

    impl TempDir {
        pub fn new(tag: &str) -> TempDir {
            let p = std::env::temp_dir().join(format!(
                "lqcron-test-{tag}-{}-{}",
                std::process::id(),
                uuid_v7()
            ));
            std::fs::create_dir_all(&p).unwrap();
            TempDir(p)
        }
    }

    impl Drop for TempDir {
        fn drop(&mut self) {
            std::fs::remove_dir_all(&self.0).ok();
        }
    }

    pub fn fresh_db(tag: &str) -> (TempDir, Connection) {
        let d = TempDir::new(tag);
        let c = open_or_create(&d.0).unwrap();
        (d, c)
    }
}

#[cfg(test)]
mod tests {
    use super::testutil::*;
    use super::*;

    #[test]
    fn creates_schema_and_instance_id() {
        let (d, c) = fresh_db("db");
        assert_eq!(schema_version(&c).unwrap(), SCHEMA_VERSION);
        let id = meta_get(&c, "instance_id").unwrap().unwrap();
        let file = std::fs::read_to_string(d.0.join("instance_id")).unwrap();
        assert_eq!(file.trim(), id);
        drop(c);
        // druhé otevření nic nemění
        let c = open_or_create(&d.0).unwrap();
        assert_eq!(meta_get(&c, "instance_id").unwrap().unwrap(), id);
    }

    #[test]
    fn v1_database_is_migrated_additively_to_v2() {
        let d = TempDir::new("v1");
        let c = Connection::open(db_path(&d.0)).unwrap();
        c.execute_batch(SCHEMA_V1).unwrap();
        meta_set(&c, "schema_version", "1").unwrap();
        meta_set(&c, "min_reader_version", "1").unwrap();
        drop(c);
        let c = open_existing(&d.0).unwrap();
        assert_eq!(schema_version(&c).unwrap(), 2);
        assert_eq!(
            meta_get(&c, "min_reader_version").unwrap().as_deref(),
            Some("1")
        );
        c.execute(
            "UPDATE runs SET pgid = 1, pgid_start = 2, boot_id = 'b'",
            [],
        )
        .unwrap();
        c.execute("UPDATE commands SET ack_event_id = 'x'", [])
            .unwrap();
    }

    #[test]
    fn refuses_newer_min_reader() {
        let (d, c) = fresh_db("compat");
        meta_set(&c, "min_reader_version", "3").unwrap();
        meta_set(&c, "schema_version", "3").unwrap();
        drop(c);
        assert!(matches!(
            open_existing(&d.0),
            Err(OpenError::Incompatible { .. })
        ));
        // novější schéma se stejnou min_reader_version otevřít jde (rollback, plán § 12.1)
        let c = Connection::open(db_path(&d.0)).unwrap();
        meta_set(&c, "min_reader_version", "1").unwrap();
        drop(c);
        assert!(open_existing(&d.0).is_ok());
    }

    #[test]
    fn php_never_creates_missing_db() {
        let d = TempDir::new("missing");
        assert!(matches!(open_existing(&d.0), Err(OpenError::Missing(_))));
    }
}

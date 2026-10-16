//! `export.json` (kontrakt § 4) — konfigurace a definice cronů ze slotu.

use std::collections::BTreeMap;
use std::path::{Path, PathBuf};

use serde::Deserialize;
use serde_json::Value;

use crate::util::{Res, fail};

pub const EXPORT_REL: &str = "temp/lqcron/export.json";

pub fn export_path(app: &Path) -> PathBuf {
    app.join(EXPORT_REL)
}

#[derive(Debug, Clone, Deserialize)]
#[serde(untagged)]
pub enum VerifyTls {
    Flag(bool),
    CaPath(String),
}

impl Default for VerifyTls {
    fn default() -> Self {
        VerifyTls::Flag(true)
    }
}

#[derive(Debug, Clone, Deserialize)]
pub struct Service {
    pub command: Vec<String>,
    #[serde(default = "default_service_mode")]
    pub mode: String,
}

fn default_service_mode() -> String {
    "watchdog".into()
}

fn default_true() -> bool {
    true
}
fn default_tz() -> String {
    "UTC".into()
}
fn default_starvation() -> i64 {
    300
}
fn default_log_max() -> i64 {
    52_428_800
}
fn default_grace() -> i64 {
    30
}
fn default_remote_commands() -> Vec<String> {
    vec![
        "cron.set_active".into(),
        "cron.run_now".into(),
        "run.cancel".into(),
    ]
}

#[derive(Debug, Clone, Deserialize)]
pub struct Config {
    #[serde(default = "default_true")]
    pub enabled: bool,
    #[serde(default)]
    pub shadow: bool,
    #[serde(default)]
    pub environment: Option<String>,
    pub state_dir: String,
    #[serde(default = "default_tz")]
    pub timezone: String,
    #[serde(default)]
    pub max_parallel: Option<i64>,
    #[serde(default = "default_starvation")]
    pub starvation_seconds: i64,
    #[serde(default = "default_log_max")]
    pub run_log_max_bytes: i64,
    #[serde(default = "default_grace")]
    pub kill_grace_seconds: i64,
    #[serde(default = "default_true")]
    pub report_log_tail: bool,
    #[serde(default)]
    pub log_redactor: bool,
    #[serde(default = "default_remote_commands")]
    pub remote_commands: Vec<String>,
    #[serde(default)]
    pub resources: BTreeMap<String, i64>,
    #[serde(default)]
    pub services: BTreeMap<String, Service>,
    #[serde(default)]
    pub php_binary: Option<String>,
    #[serde(default)]
    pub bootstrap: Option<String>,
    #[serde(default)]
    pub url: Option<String>,
    #[serde(default)]
    pub api_key: Option<String>,
    #[serde(default)]
    pub verify_tls: VerifyTls,
}

#[derive(Debug, Clone, Deserialize)]
pub struct CronDef {
    pub code: String,
    #[serde(default)]
    pub handler_class: String,
    #[serde(default)]
    pub name: Option<String>,
    #[serde(default)]
    pub description: Option<String>,
    #[serde(default)]
    pub schedule: Option<String>,
    #[serde(default)]
    pub timeout: Option<i64>,
    #[serde(default)]
    pub repeat_count: i64,
    #[serde(default)]
    pub concurrency_mode: Option<String>,
    #[serde(default)]
    pub max_queue_size: Option<i64>,
    #[serde(default)]
    pub resources: Vec<String>,
    #[serde(default)]
    pub after: Option<String>,
    #[serde(default)]
    pub missed: Option<String>,
    #[serde(default)]
    pub missed_grace_minutes: Option<i64>,
}

#[derive(Debug, Clone, Deserialize)]
#[allow(dead_code)] // zrcadlí kontrakt § 4
pub struct Export {
    #[serde(default)]
    pub format: i64,
    #[serde(default)]
    pub generated_at: Option<String>,
    #[serde(default)]
    pub connector_version: Option<String>,
    #[serde(default)]
    pub app_path: Option<String>,
    #[serde(default)]
    pub php_binary: Option<String>,
    #[serde(default)]
    pub config_files: Vec<String>,
    #[serde(default)]
    pub hash: String,
    pub config: Config,
    #[serde(default)]
    pub crons: Vec<CronDef>,
    #[serde(default)]
    pub warnings: Vec<String>,
    /// Původní JSON definic (pro `definitions.sync`, zachová i pole, která Rust nezná).
    #[serde(skip)]
    pub crons_raw: Vec<Value>,
}

/// Cyklus v závislostech `after` (A po B po … po A) by zařazoval crony donekonečna.
/// Vrací kódy cyklu (první = poslední), nebo `None`.
fn after_cycle(crons: &[CronDef]) -> Option<Vec<String>> {
    let parent: std::collections::HashMap<&str, &str> = crons
        .iter()
        .filter_map(|c| c.after.as_deref().map(|a| (c.code.as_str(), a)))
        .collect();
    for c in crons {
        let mut path = vec![c.code.as_str()];
        let mut cur = c.code.as_str();
        while let Some(&p) = parent.get(cur) {
            if let Some(pos) = path.iter().position(|x| *x == p) {
                let mut cycle: Vec<String> = path[pos..].iter().map(|s| s.to_string()).collect();
                cycle.push(p.to_string());
                return Some(cycle);
            }
            path.push(p);
            cur = p;
        }
    }
    None
}

impl Export {
    pub fn parse(data: &[u8]) -> Res<Export> {
        let raw: Value =
            serde_json::from_slice(data).map_err(|e| fail(format!("export.json: {e}")))?;
        let mut ex: Export =
            serde_json::from_value(raw.clone()).map_err(|e| fail(format!("export.json: {e}")))?;
        if ex.format != 0 && ex.format != 1 {
            return Err(fail(format!(
                "export.json: unsupported format {}",
                ex.format
            )));
        }
        if !Path::new(&ex.config.state_dir).is_absolute() {
            return Err(fail(
                "export.json: config.state_dir must be an absolute path",
            ));
        }
        for c in &ex.crons {
            if let Some(s) = c.schedule.as_deref().filter(|s| !s.trim().is_empty())
                && let Err(e) = crate::cronexpr::Schedule::parse(s)
            {
                return Err(fail(format!(
                    "export.json: cron {}: invalid schedule: {e}",
                    c.code
                )));
            }
        }
        if let Some(cycle) = after_cycle(&ex.crons) {
            return Err(fail(format!(
                "export.json: `after` dependencies form a cycle: {}",
                cycle.join(" → ")
            )));
        }
        ex.crons_raw = raw
            .get("crons")
            .and_then(Value::as_array)
            .cloned()
            .unwrap_or_default();
        Ok(ex)
    }

    pub fn load(app: &Path) -> Res<Export> {
        let p = export_path(app);
        let data = std::fs::read(&p).map_err(|e| fail(format!("{}: {e}", p.display())))?;
        Export::parse(&data)
    }

    pub fn mode(&self) -> &'static str {
        if self.config.shadow {
            "shadow"
        } else {
            "local"
        }
    }

    pub fn state_dir(&self) -> PathBuf {
        PathBuf::from(&self.config.state_dir)
    }

    /// PHP pro joby a redakci: `config.php_binary` → `php_binary` exportu → `fallback`.
    pub fn job_php(&self, fallback: Option<&str>) -> String {
        self.config
            .php_binary
            .clone()
            .or_else(|| self.php_binary.clone())
            .or_else(|| fallback.map(str::to_string))
            .unwrap_or_else(|| "php".into())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_contract_example_with_defaults() {
        let j = br#"{
          "format": 1, "hash": "abc",
          "config": {"state_dir": "/tmp/x", "timezone": "Europe/Prague", "verify_tls": "/etc/ca.pem",
                     "services": {"d": {"command": ["php", "x.php"], "mode": "keepalive"}}},
          "crons": [{"code": "a", "handler_class": "A", "schedule": "* * * * *", "extra": 1}]
        }"#;
        let e = Export::parse(j).unwrap();
        assert_eq!(e.config.starvation_seconds, 300);
        assert_eq!(e.config.kill_grace_seconds, 30);
        assert!(e.config.report_log_tail);
        assert_eq!(e.config.remote_commands.len(), 3);
        assert!(matches!(e.config.verify_tls, VerifyTls::CaPath(_)));
        assert_eq!(e.crons_raw[0]["extra"], 1);
        assert_eq!(e.mode(), "local");
    }

    #[test]
    fn validates_every_expression_of_a_schedule() {
        let ok = br#"{"config": {"state_dir": "/tmp/x"}, "crons": [{"code": "a", "schedule": "0 13 * * * | 15 23 * * *"}]}"#;
        assert!(Export::parse(ok).is_ok());
        let bad = br#"{"config": {"state_dir": "/tmp/x"}, "crons": [{"code": "a", "schedule": "0 13 * * * | 15 25 * * *"}]}"#;
        let err = Export::parse(bad).unwrap_err().to_string();
        assert!(err.contains("cron a"), "{err}");
    }

    #[test]
    fn rejects_after_cycles() {
        let cyc = br#"{"config": {"state_dir": "/tmp/x"}, "crons": [
            {"code": "a", "after": "c"}, {"code": "b", "after": "a"}, {"code": "c", "after": "b"}, {"code": "d", "after": "a"}]}"#;
        let err = Export::parse(cyc).unwrap_err().to_string();
        assert!(err.contains("cycle"), "{err}");
        let selfloop =
            br#"{"config": {"state_dir": "/tmp/x"}, "crons": [{"code": "a", "after": "a"}]}"#;
        assert!(Export::parse(selfloop).is_err());
        let chain = br#"{"config": {"state_dir": "/tmp/x"}, "crons": [{"code": "a"}, {"code": "b", "after": "a"}, {"code": "c", "after": "b"}]}"#;
        assert!(Export::parse(chain).is_ok());
    }

    #[test]
    fn rejects_relative_state_dir() {
        let j = br#"{"config": {"state_dir": "rel"}}"#;
        assert!(Export::parse(j).is_err());
    }
}

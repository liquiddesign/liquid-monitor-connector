//! `lqcron deliver` / `flush` — jediný proces, který mluví s LQDeckem (plán § 8.1, § 8.3,
//! kontrakt § 6). Stáhne příkazy, pošle outbox v pořadí `seq`, maže jen potvrzené.

use std::path::Path;
use std::sync::Arc;
use std::time::{Duration, Instant};

use rusqlite::{Connection, params};
use serde_json::{Value, json};

use crate::db::{immediate, meta_i64, meta_json, meta_set, meta_str};
use crate::export::{Export, VerifyTls};
use crate::heartbeat;
use crate::outbox::decode;
use crate::util::{FileLock, Res, VERSION, fail, iso, now_ms, parse_iso};

const MAX_EVENTS: usize = 200;
const MAX_BYTES: usize = 2 * 1024 * 1024;
const FIVEXX_BEFORE_BISECT: u32 = 5;
const POISON_RETRY_MS: i64 = 3_600_000;
/// Heartbeat aspoň 1×/min: o něco kratší než minuta, aby se při spouštění z ticku
/// (každou minutu) nevynechal kvůli zaokrouhlení (audit L1).
const HEARTBEAT_EVERY_MS: i64 = 50_000;

/// Časování (testy ho zkracují).
#[derive(Debug, Clone)]
pub struct Timing {
    pub backoff: Vec<Duration>,
    pub max_runtime: Option<Duration>,
    pub retry_after_cap: Duration,
}

impl Timing {
    pub fn production(max_runtime: Option<Duration>) -> Timing {
        Timing {
            backoff: [15, 30, 60, 120, 240, 300]
                .iter()
                .map(|s| Duration::from_secs(*s))
                .collect(),
            max_runtime,
            retry_after_cap: Duration::from_secs(600),
        }
    }
}

pub struct Client {
    agent: ureq::Agent,
    base: String,
    api_key: String,
}

#[derive(Debug)]
pub enum Reply {
    Status {
        code: u16,
        retry_after: Option<u64>,
        body: String,
    },
    Transport(String),
}

impl Client {
    pub fn new(base: &str, api_key: &str, verify: &VerifyTls) -> Res<Client> {
        use ureq::tls::{PemItem, RootCerts, TlsConfig};
        let mut tls = TlsConfig::builder();
        match verify {
            VerifyTls::Flag(false) => tls = tls.disable_verification(true),
            VerifyTls::CaPath(p) => {
                let pem = std::fs::read(p).map_err(|e| fail(format!("verify_tls CA {p}: {e}")))?;
                let certs: Vec<_> = ureq::tls::parse_pem(&pem)
                    .filter_map(|i| match i {
                        Ok(PemItem::Certificate(c)) => Some(c),
                        _ => None,
                    })
                    .collect();
                if certs.is_empty() {
                    return Err(fail(format!("verify_tls CA {p}: no certificates found")));
                }
                tls = tls.root_certs(RootCerts::Specific(Arc::new(certs)));
            }
            VerifyTls::Flag(true) => {}
        }
        let config = ureq::Agent::config_builder()
            .timeout_connect(Some(Duration::from_secs(3)))
            .timeout_global(Some(Duration::from_secs(30)))
            .http_status_as_error(false)
            .user_agent(format!("lqcron/{VERSION}"))
            .tls_config(tls.build())
            .build();
        Ok(Client {
            agent: config.into(),
            base: base.trim_end_matches('/').to_string(),
            api_key: api_key.to_string(),
        })
    }

    fn finish(r: Result<ureq::http::Response<ureq::Body>, ureq::Error>) -> Reply {
        match r {
            Ok(mut resp) => {
                let code = resp.status().as_u16();
                let retry_after = resp
                    .headers()
                    .get("retry-after")
                    .and_then(|v| v.to_str().ok())
                    .and_then(|v| v.trim().parse::<u64>().ok());
                let body = resp.body_mut().read_to_string().unwrap_or_default();
                Reply::Status {
                    code,
                    retry_after,
                    body,
                }
            }
            Err(e) => Reply::Transport(e.to_string()),
        }
    }

    pub fn post_events(&self, body: &[u8]) -> Reply {
        Self::finish(
            self.agent
                .post(format!("{}/v4/events", self.base))
                .header("X-Connector-Version", VERSION)
                .header("X-Api-Key", &self.api_key)
                .header("Accept", "application/json")
                .header("Content-Type", "application/json")
                .send(body),
        )
    }

    pub fn get_commands(&self, instance_id: &str) -> Reply {
        Self::finish(
            self.agent
                .get(format!("{}/v4/commands", self.base))
                .query("instance_id", instance_id)
                .header("X-Connector-Version", VERSION)
                .header("X-Api-Key", &self.api_key)
                .header("Accept", "application/json")
                .call(),
        )
    }
}

fn record_error(conn: &Connection, message: &str, status: Option<u16>) {
    let _ = meta_set(
        conn,
        "deliver_last_error",
        &json!({"at": iso(now_ms()), "message": message, "status": status}).to_string(),
    );
}

/// Stáhne nevyřízené příkazy do tabulky `commands` (kontrakt § 6.2).
pub fn fetch_commands(conn: &Connection, client: &Client) -> Res<usize> {
    let instance = meta_str(conn, "instance_id")?.unwrap_or_default();
    let body = match client.get_commands(&instance) {
        Reply::Status {
            code: 200, body, ..
        } => body,
        Reply::Status { code, body, .. } => {
            return Err(fail(format!(
                "GET v4/commands: HTTP {code} {}",
                crate::util::truncate_utf8(&body, 200)
            )));
        }
        Reply::Transport(e) => return Err(fail(format!("GET v4/commands: {e}"))),
    };
    let v: Value = serde_json::from_str(&body)
        .map_err(|e| fail(format!("GET v4/commands: invalid JSON: {e}")))?;
    let list = v
        .get("commands")
        .and_then(Value::as_array)
        .ok_or_else(|| fail("GET v4/commands: missing `commands`"))?;
    let now = now_ms();
    let tx = immediate(conn)?;
    let mut n = 0;
    for c in list {
        let (Some(id), Some(ty)) = (c["command_id"].as_str(), c["type"].as_str()) else {
            continue;
        };
        let mut payload = c.get("payload").cloned().unwrap_or(json!({}));
        // nečitelné `expires_at` = neplatný příkaz, ne „nikdy nevyprší“ (audit L11)
        let expires_raw = c.get("expires_at").filter(|v| !v.is_null());
        let expires = expires_raw.and_then(Value::as_str).and_then(parse_iso);
        if expires_raw.is_some() && expires.is_none() {
            if !payload.is_object() {
                payload = json!({});
            }
            payload[crate::commands::INVALID_MARK] = json!("expires_at");
        }
        n += tx.execute(
            "INSERT INTO commands (command_id, seq, type, payload, issued_at, expires_at, fetched_at) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7)
             ON CONFLICT(command_id) DO NOTHING",
            params![
                id,
                c["seq"].as_i64().unwrap_or(0),
                ty,
                payload.to_string(),
                c["issued_at"].as_str().and_then(parse_iso).unwrap_or(now),
                expires,
                now
            ],
        )?;
    }
    meta_set(&tx, "commands_fetched_at", &now.to_string())?;
    tx.commit()?;
    Ok(n)
}

struct Row {
    seq: i64,
    event_id: String,
    envelope: Value,
    bytes: usize,
}

fn load_batch(
    conn: &Connection,
    poisoned: bool,
    limit: usize,
    now: i64,
    skip: &std::collections::HashSet<String>,
) -> Res<Vec<Row>> {
    let sql = if poisoned {
        "SELECT seq, event_id, type, payload, encoding, occurred_at FROM outbox
         WHERE poisoned_at IS NOT NULL AND (last_tried_at IS NULL OR last_tried_at < ?1) ORDER BY seq LIMIT ?2"
    } else {
        "SELECT seq, event_id, type, payload, encoding, occurred_at FROM outbox
         WHERE poisoned_at IS NULL AND ?1 = ?1 ORDER BY seq LIMIT ?2"
    };
    let mut st = conn.prepare(sql)?;
    let raw: Vec<(i64, String, String, Vec<u8>, String, i64)> = st
        .query_map(
            params![now - POISON_RETRY_MS, (limit + skip.len()) as i64],
            |r| {
                Ok((
                    r.get(0)?,
                    r.get(1)?,
                    r.get(2)?,
                    crate::outbox::payload_bytes(r, 3)?,
                    r.get(4)?,
                    r.get(5)?,
                ))
            },
        )?
        .collect::<Result<_, _>>()?;
    let mut out = Vec::new();
    let mut total = 0;
    for (seq, event_id, ty, payload, enc, occurred) in raw {
        if out.len() >= limit {
            break;
        }
        if skip.contains(&event_id) {
            continue; // dočasně odmítnutá událost s odkladem
        }
        let mut payload =
            decode(&payload, &enc).unwrap_or_else(|e| json!({"undecodable": e.to_string()}));
        // strop 1 MB nekomprimovaně i pro události zapsané PHP (audit M7)
        if serde_json::to_vec(&payload).map(|b| b.len()).unwrap_or(0)
            > crate::outbox::EVENT_MAX_BYTES
        {
            crate::outbox::shrink(&mut payload);
        }
        let envelope = json!({
            "event_id": event_id,
            "seq": seq,
            "type": ty,
            "occurred_at": iso(occurred),
            "payload": payload,
        });
        let bytes = serde_json::to_vec(&envelope).map(|b| b.len()).unwrap_or(0);
        if !out.is_empty() && total + bytes > MAX_BYTES {
            break;
        }
        total += bytes;
        out.push(Row {
            seq,
            event_id,
            envelope,
            bytes,
        });
    }
    Ok(out)
}

fn heartbeat_snapshot(conn: &Connection, mode: &str) -> Res<Value> {
    let mut hb = match meta_json(conn, "heartbeat")? {
        Some(h) => h,
        None => heartbeat::build(conn, mode, 0)?,
    };
    heartbeat::refresh_outbox(&mut hb, conn)?;
    Ok(hb)
}

fn request_body(conn: &Connection, rows: &[Row], mode: &str) -> Res<Vec<u8>> {
    let body = json!({
        "instance_id": meta_str(conn, "instance_id")?,
        "sent_at": iso(now_ms()),
        "heartbeat": heartbeat_snapshot(conn, mode)?,
        "events": rows.iter().map(|r| r.envelope.clone()).collect::<Vec<_>>(),
    });
    Ok(serde_json::to_vec(&body)?)
}

#[derive(Debug)]
struct Ack {
    accepted: Vec<String>,
    rejected: Vec<(String, String, bool)>,
    max_batch: Option<usize>,
}

fn parse_ack(body: &str) -> Option<Ack> {
    let v: Value = serde_json::from_str(body).ok()?;
    let accepted = v
        .get("accepted")?
        .as_array()?
        .iter()
        .filter_map(|x| x.as_str().map(str::to_string))
        .collect();
    let rejected = v
        .get("rejected")
        .and_then(Value::as_array)
        .map(|a| {
            a.iter()
                .filter_map(|r| {
                    Some((
                        r.get("event_id")?.as_str()?.to_string(),
                        r.get("reason")
                            .and_then(Value::as_str)
                            .unwrap_or("")
                            .to_string(),
                        r.get("permanent").and_then(Value::as_bool).unwrap_or(false),
                    ))
                })
                .collect()
        })
        .unwrap_or_default();
    let max_batch = v
        .get("max_batch")
        .and_then(Value::as_u64)
        .map(|m| m as usize);
    Some(Ack {
        accepted,
        rejected,
        max_batch,
    })
}

fn move_to_rejected(conn: &Connection, event_id: &str, reason: &str, now: i64) -> Res<()> {
    conn.execute(
        "INSERT INTO outbox_rejected (seq, event_id, type, run_uuid, payload, encoding, occurred_at, created_at, rejected_at, reason)
         SELECT seq, event_id, type, run_uuid, payload, encoding, occurred_at, created_at, ?2, ?3 FROM outbox WHERE event_id = ?1
         ON CONFLICT(event_id) DO NOTHING",
        params![event_id, now, reason],
    )?;
    conn.execute("DELETE FROM outbox WHERE event_id = ?1", [event_id])?;
    Ok(())
}

fn apply_ack(conn: &Connection, rows: &[Row], ack: &Ack, now: i64) -> Res<()> {
    let tx = immediate(conn)?;
    for id in &ack.accepted {
        tx.execute("DELETE FROM outbox WHERE event_id = ?1", [id])?;
    }
    for (id, reason, permanent) in &ack.rejected {
        if *permanent {
            move_to_rejected(&tx, id, reason, now)?;
        }
    }
    for r in rows {
        tx.execute(
            "UPDATE outbox SET attempts = attempts + 1, last_tried_at = ?1 WHERE event_id = ?2",
            params![now, r.event_id],
        )?;
    }
    meta_set(&tx, "deliver_last_ok_at", &now.to_string())?;
    if let Some(m) = ack.max_batch {
        meta_set(&tx, "max_batch", &m.to_string())?;
    }
    tx.commit()?;
    Ok(())
}

/// Kolikrát smí LQDeck tutéž událost dočasně odmítnout (v dávkách, kde jiné události
/// prošly), než se pošle samostatně na izolační sondu.
const TEMP_REJECTIONS_BEFORE_ISOLATION: i64 = 5;

/// Odklad dočasně odmítnutých událostí: `meta.deliver_rejections` =
/// `{event_id: {"n": počet, "next_at": ms}}` (kontrakt § 2.1). Přežije restart `deliver`.
type Deferred = serde_json::Map<String, Value>;

fn load_deferred(conn: &Connection) -> Res<Deferred> {
    Ok(meta_json(conn, "deliver_rejections")?
        .and_then(|v| v.as_object().cloned())
        .unwrap_or_default())
}

fn save_deferred(conn: &Connection, d: &Deferred) -> Res<()> {
    let stored = if d.is_empty() {
        String::new()
    } else {
        Value::Object(d.clone()).to_string()
    };
    meta_set(conn, "deliver_rejections", &stored)?;
    Ok(())
}

fn not_due(d: &Deferred, now: i64) -> std::collections::HashSet<String> {
    d.iter()
        .filter(|(_, v)| v["next_at"].as_i64().is_some_and(|t| t > now))
        .map(|(k, _)| k.clone())
        .collect()
}

/// Zapíše dočasná odmítnutí z dávky, ze které LQDeck něco přijal (jen tehdy je jasné, že
/// neběží výpadek). Vrací události, které dosáhly prahu a jdou na izolační sondu.
fn note_temporary_rejections(
    conn: &Connection,
    rows: &[Row],
    ack: &Ack,
    now: i64,
    timing: &Timing,
) -> Res<Vec<String>> {
    let mut d = load_deferred(conn)?;
    for id in &ack.accepted {
        d.remove(id);
    }
    let mut isolate = Vec::new();
    for (id, _, permanent) in &ack.rejected {
        if *permanent {
            d.remove(id);
            continue;
        }
        if !rows.iter().any(|r| r.event_id == *id) {
            continue;
        }
        let n = d.get(id).and_then(|v| v["n"].as_i64()).unwrap_or(0) + 1;
        let wait = jitter(timing.backoff[((n - 1) as usize).min(timing.backoff.len() - 1)]);
        d.insert(
            id.clone(),
            json!({"n": n, "next_at": now + wait.as_millis() as i64}),
        );
        if n >= TEMP_REJECTIONS_BEFORE_ISOLATION {
            isolate.push(id.clone());
        }
    }
    let keys: Vec<String> = d.keys().cloned().collect();
    for k in keys {
        let exists: i64 = conn.query_row(
            "SELECT COUNT(*) FROM outbox WHERE event_id = ?1",
            [&k],
            |r| r.get(0),
        )?;
        if exists == 0 {
            d.remove(&k);
        }
    }
    save_deferred(conn, &d)?;
    Ok(isolate)
}

fn mark_tried(conn: &Connection, rows: &[Row], now: i64) {
    for r in rows {
        let _ = conn.execute(
            "UPDATE outbox SET attempts = attempts + 1, last_tried_at = ?1 WHERE event_id = ?2",
            params![now, r.event_id],
        );
    }
}

fn jitter(d: Duration) -> Duration {
    let mut b = [0u8; 4];
    let _ = getrandom::fill(&mut b);
    let r = u32::from_le_bytes(b) as f64 / u32::MAX as f64; // 0..1
    d.mul_f64(0.8 + 0.4 * r)
}

pub struct Deliverer<'a> {
    pub conn: &'a Connection,
    pub client: &'a Client,
    pub timing: Timing,
    pub mode: String,
    /// `flush`: na odložené (dočasně odmítnuté) události počkat; `deliver` skončí.
    pub wait_deferred: bool,
}

#[derive(Debug, PartialEq)]
pub enum Done {
    Empty,
    Deadline,
}

impl Deliverer<'_> {
    fn sleep_until(&self, d: Duration, deadline: Option<Instant>) -> bool {
        let until = Instant::now() + d;
        loop {
            let now = Instant::now();
            if now >= until {
                return true;
            }
            if deadline.is_some_and(|dl| now >= dl) {
                return false;
            }
            std::thread::sleep((until - now).min(Duration::from_millis(200)));
        }
    }

    /// Izolační sonda: událost, kterou LQDeck opakovaně dočasně odmítá, pošle samostatně.
    /// Odmítne-li ji i tak (a jiné události v téže relaci prošly), je otrávená (plán § 8.1).
    fn isolate(&self, event_id: &str, now: i64) -> Res<()> {
        let rows: Vec<Row> = load_batch(self.conn, false, MAX_EVENTS, now, &Default::default())?
            .into_iter()
            .filter(|r| r.event_id == event_id)
            .collect();
        if rows.is_empty() {
            return Ok(());
        }
        let body = request_body(self.conn, &rows, &self.mode)?;
        let reply = self.client.post_events(&body);
        let now = now_ms();
        let Reply::Status { code, body, .. } = reply else {
            return Ok(());
        };
        if !(200..300).contains(&code) {
            return Ok(());
        }
        let Some(ack) = parse_ack(&body) else {
            return Ok(());
        };
        apply_ack(self.conn, &rows, &ack, now)?;
        let temp = ack.rejected.iter().any(|(id, _, p)| !*p && id == event_id);
        let mut d = load_deferred(self.conn)?;
        d.remove(event_id);
        save_deferred(self.conn, &d)?;
        if temp {
            self.conn.execute(
                "UPDATE outbox SET poisoned_at = ?1, last_tried_at = ?1 WHERE event_id = ?2 AND poisoned_at IS NULL",
                params![now, event_id],
            )?;
            eprintln!(
                "lqcron: event {event_id} is repeatedly rejected by LQDeck even on its own; skipping it, retry hourly"
            );
        }
        Ok(())
    }

    pub fn run(&self) -> Res<Done> {
        let start = Instant::now();
        let deadline = self.timing.max_runtime.map(|m| start + m);
        let mut failures = 0usize;
        let mut limit = meta_i64(self.conn, "max_batch")?
            .map(|m| (m.max(1) as usize).min(MAX_EVENTS))
            .unwrap_or(MAX_EVENTS);
        let server_max = limit;
        let mut fivexx: (Option<i64>, u32) = (None, 0);
        let mut sent_any = false;
        loop {
            if deadline.is_some_and(|d| Instant::now() >= d) {
                return Ok(Done::Deadline);
            }
            let now = now_ms();
            // otrávená událost: samostatně, nejvýš jednou za hodinu
            let none = Default::default();
            let mut rows = load_batch(self.conn, true, 1, now, &none)?;
            let poisoned_try = !rows.is_empty();
            let deferred = load_deferred(self.conn)?;
            let skip = not_due(&deferred, now);
            if !poisoned_try {
                rows = load_batch(self.conn, false, limit, now, &skip)?;
            }
            if rows.is_empty() && !skip.is_empty() && self.wait_deferred {
                // flush: počkat na nejbližší odloženou událost
                let next = deferred
                    .values()
                    .filter_map(|v| v["next_at"].as_i64())
                    .filter(|t| *t > now)
                    .min()
                    .unwrap_or(now);
                let wait = Duration::from_millis((next - now).clamp(0, 300_000) as u64);
                if !self.sleep_until(wait, deadline) {
                    return Ok(Done::Deadline);
                }
                continue;
            }
            if rows.is_empty() {
                let last_ok = meta_i64(self.conn, "deliver_last_ok_at")?;
                if sent_any || last_ok.is_some_and(|t| now - t < HEARTBEAT_EVERY_MS && t <= now) {
                    return Ok(Done::Empty);
                }
            }
            let body = request_body(self.conn, &rows, &self.mode)?;
            let reply = self.client.post_events(&body);
            let now = now_ms();
            let backoff = |failures: usize| {
                jitter(self.timing.backoff[failures.min(self.timing.backoff.len() - 1)])
            };
            match reply {
                Reply::Status { code, body, .. } if (200..300).contains(&code) => {
                    match parse_ack(&body) {
                        Some(ack) => {
                            apply_ack(self.conn, &rows, &ack, now)?;
                            sent_any = true;
                            fivexx = (None, 0);
                            let progressed = rows.iter().any(|r| {
                                ack.accepted.contains(&r.event_id)
                                    || ack
                                        .rejected
                                        .iter()
                                        .any(|(id, _, p)| *p && *id == r.event_id)
                            });
                            if poisoned_try || rows.is_empty() {
                                failures = 0;
                            } else if progressed {
                                failures = 0;
                                for id in note_temporary_rejections(
                                    self.conn,
                                    &rows,
                                    &ack,
                                    now,
                                    &self.timing,
                                )? {
                                    self.isolate(&id, now)?;
                                }
                            } else {
                                // Nic nepřijato (typicky výpadek DB LQDecku → vše `permanent: false`):
                                // backoff bez počítání, jinak by se po pár kolech otrávil celý backlog.
                                record_error(
                                    self.conn,
                                    "events temporarily rejected by LQDeck",
                                    Some(code),
                                );
                                if !self.sleep_until(backoff(failures), deadline) {
                                    return Ok(Done::Deadline);
                                }
                                failures += 1;
                            }
                            if let Some(m) = ack.max_batch {
                                limit = m.clamp(1, MAX_EVENTS);
                            } else if limit < server_max {
                                limit = server_max;
                            }
                            if rows.is_empty() {
                                return Ok(Done::Empty);
                            }
                        }
                        None => {
                            record_error(
                                self.conn,
                                "HTTP 2xx without accepted/rejected body",
                                Some(code),
                            );
                            mark_tried(self.conn, &rows, now);
                            if !self.sleep_until(backoff(failures), deadline) {
                                return Ok(Done::Deadline);
                            }
                            failures += 1;
                        }
                    }
                }
                Reply::Status { code: 413, .. } => {
                    record_error(self.conn, "HTTP 413 payload too large", Some(413));
                    if rows.is_empty() {
                        // 413 na samotný heartbeat: nic k půlení, jen backoff (audit L9)
                        if !self.sleep_until(backoff(failures), deadline) {
                            return Ok(Done::Deadline);
                        }
                        failures += 1;
                    } else if rows.len() <= 1 {
                        if let Some(r) = rows.first() {
                            let tx = immediate(self.conn)?;
                            move_to_rejected(&tx, &r.event_id, "payload_too_large", now)?;
                            tx.commit()?;
                        }
                    } else {
                        limit = (rows.len() / 2).max(1);
                    }
                }
                Reply::Status { code, .. } if code >= 500 => {
                    record_error(self.conn, &format!("HTTP {code}"), Some(code));
                    mark_tried(self.conn, &rows, now);
                    if poisoned_try {
                        // samostatný pokus otrávené události selhal → zkusí se zase za hodinu
                        continue;
                    }
                    let first = rows.first().map(|r| r.seq);
                    if first.is_some() && fivexx.0 == first {
                        fivexx.1 += 1;
                    } else {
                        fivexx = (first, 1);
                    }
                    if first.is_some() && fivexx.1 >= FIVEXX_BEFORE_BISECT {
                        if rows.len() > 1 {
                            limit = (rows.len() / 2).max(1);
                            continue;
                        }
                        // jediná událost: je to ona, nebo výpadek? Ověří se prázdnou dávkou.
                        let probe = request_body(self.conn, &[], &self.mode)?;
                        if let Reply::Status { code, body, .. } = self.client.post_events(&probe)
                            && (200..300).contains(&code)
                            && parse_ack(&body).is_some()
                        {
                            let r = &rows[0];
                            self.conn.execute(
                                "UPDATE outbox SET poisoned_at = ?1, last_tried_at = ?1 WHERE seq = ?2",
                                params![now_ms(), r.seq],
                            )?;
                            eprintln!(
                                "lqcron: event {} (seq {}, {} B) poisons LQDeck; skipping it, retry hourly",
                                r.event_id, r.seq, r.bytes
                            );
                            fivexx = (None, 0);
                            limit = server_max;
                            continue;
                        }
                        limit = server_max;
                        fivexx = (None, 0);
                    }
                    if !self.sleep_until(backoff(failures), deadline) {
                        return Ok(Done::Deadline);
                    }
                    failures += 1;
                }
                Reply::Status {
                    code: 429,
                    retry_after: Some(s),
                    ..
                } => {
                    record_error(self.conn, "HTTP 429", Some(429));
                    mark_tried(self.conn, &rows, now);
                    let d = Duration::from_secs(s).min(self.timing.retry_after_cap);
                    if !self.sleep_until(d, deadline) {
                        return Ok(Done::Deadline);
                    }
                }
                other => {
                    let (msg, status) = match &other {
                        Reply::Status { code, body, .. } => (
                            format!("HTTP {code} {}", crate::util::truncate_utf8(body, 200)),
                            Some(*code),
                        ),
                        Reply::Transport(e) => (e.clone(), None),
                    };
                    record_error(self.conn, &msg, status);
                    mark_tried(self.conn, &rows, now);
                    if poisoned_try {
                        continue;
                    }
                    if !self.sleep_until(backoff(failures), deadline) {
                        return Ok(Done::Deadline);
                    }
                    failures += 1;
                }
            }
        }
    }
}

pub struct DeliverArgs {
    pub flush: bool,
    pub timeout: Option<Duration>,
}

pub fn deliver(state_dir: &Path, export: &Export, args: &DeliverArgs) -> Res<i32> {
    crate::util::set_umask();
    let (Some(url), Some(key)) = (&export.config.url, &export.config.api_key) else {
        return Err(fail("export has no url / api_key: nothing to deliver to"));
    };
    let lock_path = state_dir.join("deliver.lock");
    let lock = if args.flush {
        FileLock::lock_wait(&lock_path, Duration::from_secs(900))?
    } else {
        FileLock::try_lock(&lock_path)?
    };
    let Some(_lock) = lock else {
        if args.flush {
            return Err(fail("lqcron deliver is still running"));
        }
        return Ok(0);
    };
    let conn = crate::db::open_existing_res(state_dir)?;
    let client = Client::new(url, key, &export.config.verify_tls)?;
    if let Err(e) = fetch_commands(&conn, &client) {
        record_error(&conn, &e.to_string(), None);
        if args.flush {
            eprintln!("lqcron: {e}");
        }
    }
    let timing = if args.flush {
        Timing::production(args.timeout)
    } else {
        Timing::production(Some(Duration::from_secs(600)))
    };
    let d = Deliverer {
        conn: &conn,
        client: &client,
        timing,
        mode: export.mode().to_string(),
        wait_deferred: args.flush,
    };
    let done = d.run()?;
    if args.flush {
        let left: i64 = conn.query_row(
            "SELECT COUNT(*) FROM outbox WHERE poisoned_at IS NULL",
            [],
            |r| r.get(0),
        )?;
        let rejected: i64 =
            conn.query_row("SELECT COUNT(*) FROM outbox_rejected", [], |r| r.get(0))?;
        let poisoned: i64 = conn.query_row(
            "SELECT COUNT(*) FROM outbox WHERE poisoned_at IS NOT NULL",
            [],
            |r| r.get(0),
        )?;
        println!("lqcron: outbox {left} pending, {poisoned} poisoned, {rejected} rejected");
        return Ok(if done == Done::Empty && left == 0 {
            0
        } else {
            1
        });
    }
    Ok(0)
}

#[cfg(test)]
pub mod tests {
    use super::*;
    use crate::db::testutil::fresh_db;
    use crate::outbox::push;
    use std::io::{BufRead, BufReader, Read as _, Write as _};
    use std::net::TcpListener;
    use std::sync::Mutex;

    pub struct Mock {
        pub url: String,
        pub requests: Arc<Mutex<Vec<(String, String)>>>,
    }

    /// Jednoduchý HTTP server: `handler(method_path, body) -> (status, extra headers, body)`.
    pub fn mock<F>(handler: F) -> Mock
    where
        F: Fn(&str, &str) -> (u16, Vec<(String, String)>, String) + Send + Sync + 'static,
    {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let url = format!("http://{}", listener.local_addr().unwrap());
        let requests = Arc::new(Mutex::new(Vec::new()));
        let reqs = requests.clone();
        let handler = Arc::new(handler);
        std::thread::spawn(move || {
            for stream in listener.incoming() {
                let Ok(mut stream) = stream else { continue };
                let mut reader = BufReader::new(stream.try_clone().unwrap());
                let mut line = String::new();
                if reader.read_line(&mut line).is_err() {
                    continue;
                }
                let mut len = 0usize;
                loop {
                    let mut h = String::new();
                    if reader.read_line(&mut h).is_err() || h == "\r\n" || h.is_empty() {
                        break;
                    }
                    let lower = h.to_ascii_lowercase();
                    if let Some(v) = lower.strip_prefix("content-length:") {
                        len = v.trim().parse().unwrap_or(0);
                    }
                }
                let mut body = vec![0u8; len];
                let _ = reader.read_exact(&mut body);
                let body = String::from_utf8_lossy(&body).into_owned();
                let mp = line
                    .split_whitespace()
                    .take(2)
                    .collect::<Vec<_>>()
                    .join(" ");
                reqs.lock().unwrap().push((mp.clone(), body.clone()));
                let (status, headers, resp) = handler(&mp, &body);
                let mut out = format!(
                    "HTTP/1.1 {status} X\r\nContent-Length: {}\r\nConnection: close\r\n",
                    resp.len()
                );
                for (k, v) in headers {
                    out.push_str(&format!("{k}: {v}\r\n"));
                }
                out.push_str("\r\n");
                out.push_str(&resp);
                let _ = stream.write_all(out.as_bytes());
            }
        });
        Mock { url, requests }
    }

    /// Server, který přijme vše kromě událostí s payloadem `{"poison": true}` (na ty padá 500).
    pub fn accept_all(mp: &str, body: &str) -> (u16, Vec<(String, String)>, String) {
        if mp.starts_with("GET") {
            return (200, vec![], r#"{"commands": []}"#.into());
        }
        let v: Value = serde_json::from_str(body).unwrap();
        let events = v["events"].as_array().unwrap();
        if events.iter().any(|e| e["payload"]["poison"] == true) {
            return (500, vec![], "boom".into());
        }
        let ids: Vec<Value> = events.iter().map(|e| e["event_id"].clone()).collect();
        (
            200,
            vec![],
            json!({"accepted": ids, "rejected": [], "max_batch": 200}).to_string(),
        )
    }

    fn fast() -> Timing {
        Timing {
            backoff: vec![Duration::from_millis(10)],
            max_runtime: Some(Duration::from_secs(20)),
            retry_after_cap: Duration::from_millis(50),
        }
    }

    fn count(c: &Connection, sql: &str) -> i64 {
        c.query_row(sql, [], |r| r.get(0)).unwrap()
    }

    fn run(c: &Connection, url: &str) -> Done {
        let client = Client::new(url, "key", &VerifyTls::Flag(true)).unwrap();
        Deliverer {
            conn: c,
            client: &client,
            timing: fast(),
            mode: "local".into(),
            wait_deferred: true,
        }
        .run()
        .unwrap()
    }

    #[test]
    fn sends_in_seq_order_and_deletes_only_accepted() {
        let (_d, c) = fresh_db("deliver");
        for i in 0..450 {
            push(&c, "job.queued", None, json!({"i": i}), i).unwrap();
        }
        let m = mock(accept_all);
        assert_eq!(run(&c, &m.url), Done::Empty);
        assert_eq!(count(&c, "SELECT COUNT(*) FROM outbox"), 0);
        let reqs = m.requests.lock().unwrap();
        // dávky po 200 + heartbeat + hlavičky
        let first: Value = serde_json::from_str(&reqs[0].1).unwrap();
        assert_eq!(first["events"].as_array().unwrap().len(), 200);
        assert_eq!(first["events"][0]["seq"], 1);
        assert!(first["heartbeat"]["outbox_size"].as_i64().is_some());
        assert!(first["sent_at"].as_str().is_some());
    }

    #[test]
    fn rejected_permanent_moves_and_transient_stays() {
        let (_d, c) = fresh_db("deliver-rej");
        let a = push(&c, "x.a", None, json!({}), 1).unwrap();
        let b = push(&c, "x.b", None, json!({}), 2).unwrap();
        let cc = push(&c, "x.c", None, json!({}), 3).unwrap();
        let (a2, b2, c2) = (a.clone(), b.clone(), cc.clone());
        let calls = Arc::new(Mutex::new(0));
        let calls2 = calls.clone();
        let m = mock(move |mp, _| {
            if mp.starts_with("GET") {
                return (200, vec![], r#"{"commands":[]}"#.into());
            }
            let mut n = calls2.lock().unwrap();
            *n += 1;
            if *n == 1 {
                (200, vec![], json!({"accepted": [a2], "rejected": [{"event_id": b2, "reason": "unknown_type", "permanent": true}, {"event_id": c2, "reason": "db", "permanent": false}]}).to_string())
            } else {
                (
                    200,
                    vec![],
                    json!({"accepted": [c2.clone()], "rejected": []}).to_string(),
                )
            }
        });
        assert_eq!(run(&c, &m.url), Done::Empty);
        assert_eq!(count(&c, "SELECT COUNT(*) FROM outbox"), 0);
        assert_eq!(count(&c, "SELECT COUNT(*) FROM outbox_rejected"), 1);
        let reason: String = c
            .query_row("SELECT reason FROM outbox_rejected", [], |r| r.get(0))
            .unwrap();
        assert_eq!(reason, "unknown_type");
    }

    #[test]
    fn retries_on_outage_and_2xx_without_body() {
        let (_d, c) = fresh_db("deliver-retry");
        push(&c, "x", None, json!({}), 1).unwrap();
        let calls = Arc::new(Mutex::new(0));
        let calls2 = calls.clone();
        let m = mock(move |mp, body| {
            if mp.starts_with("GET") {
                return (200, vec![], r#"{"commands":[]}"#.into());
            }
            let mut n = calls2.lock().unwrap();
            *n += 1;
            match *n {
                1 => (503, vec![], "".into()),
                2 => (426, vec![], "".into()),
                3 => (200, vec![], "OK".into()),
                4 => (429, vec![("Retry-After".into(), "1".into())], "".into()),
                _ => accept_all(mp, body),
            }
        });
        assert_eq!(run(&c, &m.url), Done::Empty);
        assert_eq!(count(&c, "SELECT COUNT(*) FROM outbox"), 0);
        assert!(*calls.lock().unwrap() >= 5);
    }

    #[test]
    fn payload_too_large_halves_then_rejects_single() {
        let (_d, c) = fresh_db("deliver-413");
        for i in 0..8 {
            push(&c, "x", None, json!({"big": i == 5}), i).unwrap();
        }
        let m = mock(|mp, body| {
            if mp.starts_with("GET") {
                return (200, vec![], r#"{"commands":[]}"#.into());
            }
            let v: Value = serde_json::from_str(body).unwrap();
            if v["events"]
                .as_array()
                .unwrap()
                .iter()
                .any(|e| e["payload"]["big"] == true)
            {
                return (413, vec![], "".into());
            }
            accept_all(mp, body)
        });
        assert_eq!(run(&c, &m.url), Done::Empty);
        assert_eq!(count(&c, "SELECT COUNT(*) FROM outbox"), 0);
        assert_eq!(
            count(
                &c,
                "SELECT COUNT(*) FROM outbox_rejected WHERE reason = 'payload_too_large'"
            ),
            1
        );
    }

    #[test]
    fn poisoned_event_does_not_block_queue() {
        let (_d, c) = fresh_db("deliver-poison");
        for i in 0..6 {
            push(&c, "x", None, json!({"poison": i == 2}), i).unwrap();
        }
        let m = mock(accept_all);
        assert_eq!(run(&c, &m.url), Done::Empty);
        assert_eq!(
            count(&c, "SELECT COUNT(*) FROM outbox WHERE poisoned_at IS NULL"),
            0
        );
        assert_eq!(
            count(
                &c,
                "SELECT COUNT(*) FROM outbox WHERE poisoned_at IS NOT NULL"
            ),
            1
        );
        assert_eq!(count(&c, "SELECT COUNT(*) FROM outbox_rejected"), 0); // nemaže se
    }

    /// Mock: události s `{"bad": true}` dočasně odmítá, ostatní přijímá; `max_batch` 2.
    fn rejects_bad(mp: &str, body: &str) -> (u16, Vec<(String, String)>, String) {
        if mp.starts_with("GET") {
            return (200, vec![], r#"{"commands":[]}"#.into());
        }
        let v: Value = serde_json::from_str(body).unwrap();
        let (mut accepted, mut rejected) = (vec![], vec![]);
        for e in v["events"].as_array().unwrap() {
            if e["payload"]["bad"] == true {
                rejected.push(
                    json!({"event_id": e["event_id"], "reason": "error", "permanent": false}),
                );
            } else {
                accepted.push(e["event_id"].clone());
            }
        }
        (
            200,
            vec![],
            json!({"accepted": accepted, "rejected": rejected, "max_batch": 2}).to_string(),
        )
    }

    fn deliverer<'a>(
        c: &'a Connection,
        client: &'a Client,
        timing: Timing,
        wait: bool,
    ) -> Deliverer<'a> {
        Deliverer {
            conn: c,
            client,
            timing,
            mode: "local".into(),
            wait_deferred: wait,
        }
    }

    #[test]
    fn repeated_temporary_rejection_poisons_after_isolation() {
        let (_d, c) = fresh_db("deliver-temp-reject");
        push(&c, "x", None, json!({"bad": true}), 0).unwrap();
        for i in 1..12 {
            push(&c, "x", None, json!({"i": i}), i).unwrap();
        }
        meta_set(&c, "max_batch", "2").unwrap(); // dávky [bad, dobrá]
        let m = mock(rejects_bad);
        let client = Client::new(&m.url, "key", &VerifyTls::Flag(true)).unwrap();
        let mut t = fast();
        t.backoff = vec![Duration::ZERO]; // odklad 0 → bad jde v každé dávce s dobrými
        assert_eq!(deliverer(&c, &client, t, true).run().unwrap(), Done::Empty);
        assert_eq!(
            count(&c, "SELECT COUNT(*) FROM outbox WHERE poisoned_at IS NULL"),
            0
        );
        assert_eq!(
            count(
                &c,
                "SELECT COUNT(*) FROM outbox WHERE poisoned_at IS NOT NULL"
            ),
            1
        );
        assert_eq!(count(&c, "SELECT COUNT(*) FROM outbox_rejected"), 0); // nemaže se
        // 5 smíšených dávek + 1 izolační sonda
        assert_eq!(count(&c, "SELECT attempts FROM outbox"), 6);
        assert!(meta_str(&c, "deliver_rejections").unwrap().is_none());
        let mut hb = json!({});
        crate::heartbeat::refresh_outbox(&mut hb, &c).unwrap();
        assert_eq!(hb["poisoned_count"], 1);
        // další běh ji nepošle (samostatný pokus až za hodinu)
        let before = m.requests.lock().unwrap().len();
        let client = Client::new(&m.url, "key", &VerifyTls::Flag(true)).unwrap();
        assert_eq!(
            deliverer(&c, &client, fast(), false).run().unwrap(),
            Done::Empty
        );
        let after: Vec<String> = m.requests.lock().unwrap()[before..]
            .iter()
            .map(|r| r.1.clone())
            .collect();
        assert!(after.iter().all(|b| !b.contains("\"bad\":true")));
    }

    #[test]
    fn rejection_of_everything_is_an_outage_not_poison() {
        let (_d, c) = fresh_db("deliver-db-outage");
        for i in 0..5 {
            push(&c, "x", None, json!({"bad": true, "i": i}), i).unwrap();
        }
        let m = mock(rejects_bad); // vše `permanent: false` = výpadek DB LQDecku
        let client = Client::new(&m.url, "key", &VerifyTls::Flag(true)).unwrap();
        let mut t = fast();
        t.max_runtime = Some(Duration::from_millis(500));
        assert_eq!(
            deliverer(&c, &client, t, true).run().unwrap(),
            Done::Deadline
        );
        assert_eq!(
            count(&c, "SELECT COUNT(*) FROM outbox WHERE poisoned_at IS NULL"),
            5
        );
        assert!(meta_str(&c, "deliver_rejections").unwrap().is_none());
        assert!(count(&c, "SELECT MAX(attempts) FROM outbox") >= 5);
    }

    #[test]
    fn temporarily_rejected_event_waits_for_its_own_backoff() {
        let (_d, c) = fresh_db("deliver-defer");
        push(&c, "x", None, json!({"bad": true}), 0).unwrap();
        push(&c, "x", None, json!({"i": 1}), 1).unwrap();
        for i in 2..6 {
            push(&c, "x", None, json!({"i": i}), i).unwrap();
        }
        let m = mock(rejects_bad);
        let client = Client::new(&m.url, "key", &VerifyTls::Flag(true)).unwrap();
        let mut t = fast();
        t.backoff = vec![Duration::from_secs(3600)];
        // deliver (ne flush): odloženou událost nečeká, ostatní doručí
        assert_eq!(deliverer(&c, &client, t, false).run().unwrap(), Done::Empty);
        assert_eq!(count(&c, "SELECT COUNT(*) FROM outbox"), 1);
        let reqs = m.requests.lock().unwrap();
        let with_bad = reqs.iter().filter(|r| r.1.contains("\"bad\":true")).count();
        assert_eq!(with_bad, 1, "sent again without waiting for its backoff");
        let d: Value =
            serde_json::from_str(&meta_str(&c, "deliver_rejections").unwrap().unwrap()).unwrap();
        assert_eq!(d.as_object().unwrap().values().next().unwrap()["n"], 1);
    }

    #[test]
    fn total_outage_does_not_poison() {
        let (_d, c) = fresh_db("deliver-outage");
        push(&c, "x", None, json!({}), 1).unwrap();
        let m = mock(|_, _| (502, vec![], "bad gateway".into()));
        let client = Client::new(&m.url, "key", &VerifyTls::Flag(true)).unwrap();
        let mut t = fast();
        t.max_runtime = Some(Duration::from_millis(600));
        let done = Deliverer {
            conn: &c,
            client: &client,
            timing: t,
            mode: "local".into(),
            wait_deferred: true,
        }
        .run()
        .unwrap();
        assert_eq!(done, Done::Deadline);
        assert_eq!(
            count(&c, "SELECT COUNT(*) FROM outbox WHERE poisoned_at IS NULL"),
            1
        );
        assert!(meta_str(&c, "deliver_last_error").unwrap().is_some());
    }

    #[test]
    fn heartbeat_only_when_outbox_empty() {
        let (_d, c) = fresh_db("deliver-hb");
        let m = mock(accept_all);
        assert_eq!(run(&c, &m.url), Done::Empty);
        let reqs = m.requests.lock().unwrap();
        assert_eq!(reqs.len(), 1);
        let v: Value = serde_json::from_str(&reqs[0].1).unwrap();
        assert_eq!(v["events"].as_array().unwrap().len(), 0);
        drop(reqs);
        // do minuty se prázdný heartbeat znovu neposílá
        assert_eq!(run(&c, &m.url), Done::Empty);
        assert_eq!(m.requests.lock().unwrap().len(), 1);
    }

    #[test]
    fn fetches_commands_idempotently() {
        let (_d, c) = fresh_db("deliver-cmd");
        let m = mock(|mp, _| {
            assert!(mp.contains("instance_id="));
            (200, vec![], json!({"commands": [{"command_id": "c1", "seq": 3, "type": "cron.run_now", "payload": {"cron_code": "a"}, "issued_at": "2026-10-16T10:00:00.000+00:00", "expires_at": "2026-10-16T10:15:00.000+00:00"}]}).to_string())
        });
        let client = Client::new(&m.url, "key", &VerifyTls::Flag(true)).unwrap();
        assert_eq!(fetch_commands(&c, &client).unwrap(), 1);
        assert_eq!(fetch_commands(&c, &client).unwrap(), 0);
        let exp: i64 = c
            .query_row("SELECT expires_at FROM commands", [], |r| r.get(0))
            .unwrap();
        assert_eq!(exp, parse_iso("2026-10-16T10:15:00Z").unwrap());
        assert!(meta_i64(&c, "commands_fetched_at").unwrap().is_some());
    }

    #[test]
    fn payload_too_large_on_heartbeat_only_backs_off() {
        let (_d, c) = fresh_db("deliver-413-empty");
        let m = mock(|_, _| (413, vec![], "".into()));
        let client = Client::new(&m.url, "key", &VerifyTls::Flag(true)).unwrap();
        let mut t = fast();
        t.backoff = vec![Duration::from_millis(200)];
        t.max_runtime = Some(Duration::from_millis(700));
        assert_eq!(
            deliverer(&c, &client, t, true).run().unwrap(),
            Done::Deadline
        );
        // s backoffem ~4 pokusy, ne stovky
        assert!(m.requests.lock().unwrap().len() <= 6);
    }

    #[test]
    fn unparseable_expiry_marks_command_invalid() {
        let (_d, c) = fresh_db("deliver-cmd-exp");
        let m = mock(|_, _| {
            (200, vec![], json!({"commands": [
                {"command_id": "c1", "seq": 1, "type": "cron.run_now", "payload": {"cron_code": "a"}, "issued_at": "x", "expires_at": "tomorrow"},
                {"command_id": "c2", "seq": 2, "type": "cron.run_now", "payload": {"cron_code": "a"}, "issued_at": "x", "expires_at": null}
            ]}).to_string())
        });
        let client = Client::new(&m.url, "key", &VerifyTls::Flag(true)).unwrap();
        fetch_commands(&c, &client).unwrap();
        let rows: Vec<(String, String, Option<i64>)> = c
            .prepare("SELECT command_id, payload, expires_at FROM commands ORDER BY seq")
            .unwrap()
            .query_map([], |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?)))
            .unwrap()
            .map(Result::unwrap)
            .collect();
        assert!(rows[0].1.contains(crate::commands::INVALID_MARK));
        assert!(!rows[1].1.contains(crate::commands::INVALID_MARK)); // null = bez expirace
        assert_eq!(rows[1].2, None);
    }

    #[test]
    fn zstd_payload_goes_out_as_plain_json() {
        let (_d, c) = fresh_db("deliver-zstd");
        push(
            &c,
            "run.failed",
            Some("r"),
            json!({"log_tail": "y".repeat(50_000)}),
            1,
        )
        .unwrap();
        let m = mock(accept_all);
        run(&c, &m.url);
        let reqs = m.requests.lock().unwrap();
        let v: Value = serde_json::from_str(&reqs[0].1).unwrap();
        assert_eq!(
            v["events"][0]["payload"]["log_tail"]
                .as_str()
                .unwrap()
                .len(),
            50_000
        );
    }
}

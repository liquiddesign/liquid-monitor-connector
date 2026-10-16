//! Outbox (plán § 8.1, kontrakt § 5): zápis událostí ve stejné transakci jako změna stavu.

use rusqlite::{Connection, params};
use serde_json::{Value, json};

use crate::util::{Res, now_ms, tail_utf8, truncate_utf8, uuid_v7};

/// Strop velikosti jedné události (po kompresi), plán § 8.1.
pub const EVENT_MAX_BYTES: usize = 1024 * 1024;
/// Payload nad touto velikostí se v outboxu ukládá komprimovaně (zstd).
pub const COMPRESS_OVER: usize = 8 * 1024;

/// Zapíše událost do outboxu. Volá se uvnitř transakce se změnou stavu.
pub fn push(
    conn: &Connection,
    ty: &str,
    run_uuid: Option<&str>,
    payload: Value,
    occurred_at: i64,
) -> Res<String> {
    let mut payload = payload;
    let mut bytes = serde_json::to_vec(&payload)?;
    // Strop platí pro nekomprimovanou velikost — do HTTP jde čisté JSON (audit M7).
    if bytes.len() > EVENT_MAX_BYTES {
        shrink(&mut payload);
        bytes = serde_json::to_vec(&payload)?;
    }
    let (blob, encoding) = encode(&bytes);
    let event_id = uuid_v7();
    conn.execute(
        "INSERT INTO outbox (event_id, type, run_uuid, payload, encoding, occurred_at, created_at) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7)",
        params![event_id, ty, run_uuid, blob, encoding, occurred_at, now_ms()],
    )?;
    Ok(event_id)
}

fn encode(bytes: &[u8]) -> (Vec<u8>, &'static str) {
    if bytes.len() > COMPRESS_OVER
        && let Ok(c) = zstd::bulk::compress(bytes, 3)
    {
        return (c, "zstd");
    }
    (bytes.to_vec(), "json")
}

/// Bajty sloupce `payload`: Rust zapisuje BLOB, PHP (pdo_sqlite i sqlite3) váže řetězec
/// jako TEXT — čtenář musí unést obojí (kontrakt § 2).
pub fn payload_bytes(r: &rusqlite::Row, idx: usize) -> rusqlite::Result<Vec<u8>> {
    use rusqlite::types::ValueRef;
    match r.get_ref(idx)? {
        ValueRef::Text(t) | ValueRef::Blob(t) => Ok(t.to_vec()),
        ValueRef::Null => Ok(b"null".to_vec()),
        other => Ok(format!("{:?}", other).into_bytes()),
    }
}

/// Payload z outboxu jako JSON (do LQDecku jde vždy čisté JSON, kontrakt § 5.2).
pub fn decode(blob: &[u8], encoding: &str) -> Res<Value> {
    let bytes = if encoding == "zstd" {
        zstd::stream::decode_all(blob)?
    } else {
        blob.to_vec()
    };
    Ok(serde_json::from_slice(&bytes)?)
}

/// Výsledek handleru nad stropem → `{"truncated": true, "bytes": N, "head": …}` (plán § 7.4).
pub fn truncate_result(v: &Value, max: usize) -> (Value, bool) {
    if v.is_null() {
        return (Value::Null, false);
    }
    if v.get("truncated").and_then(Value::as_bool) == Some(true) {
        return (v.clone(), true);
    }
    let s = serde_json::to_string(v).unwrap_or_default();
    if s.len() <= max {
        return (v.clone(), false);
    }
    (
        json!({"truncated": true, "bytes": s.len(), "head": truncate_utf8(&s, 4096)}),
        true,
    )
}

/// Ořízne velká pole, aby událost prošla stropem 1 MB (plán § 8.1, poslední pojistka).
pub fn shrink(payload: &mut Value) {
    if let Some(obj) = payload.as_object_mut() {
        if let Some(r) = obj.get("result").cloned() {
            let (t, truncated) = truncate_result(&r, 4096);
            obj.insert("result".into(), t);
            if truncated {
                obj.insert("result_truncated".into(), Value::Bool(true));
            }
        }
        if let Some(Value::String(s)) = obj.get("log_tail").cloned() {
            obj.insert(
                "log_tail".into(),
                Value::String(tail_utf8(&s, 16 * 1024).to_string()),
            );
            obj.insert("log_tail_truncated".into(), Value::Bool(true));
        }
        if let Some(Value::Object(err)) = obj.get_mut("error")
            && let Some(Value::String(t)) = err.get("trace").cloned()
        {
            err.insert(
                "trace".into(),
                Value::String(truncate_utf8(&t, 16 * 1024).to_string()),
            );
        }
        if let Some(Value::Object(d)) = obj.get("data").cloned() {
            let s = serde_json::to_string(&d).unwrap_or_default();
            if s.len() > 16 * 1024 {
                obj.insert("data".into(), json!({"truncated": true}));
            }
        }
        if serde_json::to_vec(obj).map(|b| b.len()).unwrap_or(0) > EVENT_MAX_BYTES {
            obj.insert("truncated".into(), Value::Bool(true));
            for k in ["crons", "result", "log_tail", "error", "data"] {
                if obj.contains_key(k) {
                    obj.insert(k.into(), Value::Null);
                }
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::db::testutil::fresh_db;

    #[test]
    fn small_payload_is_plain_json_large_is_zstd() {
        let (_d, c) = fresh_db("outbox");
        push(&c, "job.queued", None, json!({"a": 1}), 1).unwrap();
        let big = "x".repeat(100_000);
        push(&c, "run.failed", Some("r"), json!({"log_tail": big}), 2).unwrap();
        let rows: Vec<(Vec<u8>, String)> = c
            .prepare("SELECT payload, encoding FROM outbox ORDER BY seq")
            .unwrap()
            .query_map([], |r| Ok((r.get(0)?, r.get(1)?)))
            .unwrap()
            .map(Result::unwrap)
            .collect();
        assert_eq!(rows[0].1, "json");
        assert_eq!(rows[1].1, "zstd");
        assert!(rows[1].0.len() < 10_000);
        let v = decode(&rows[1].0, &rows[1].1).unwrap();
        assert_eq!(v["log_tail"].as_str().unwrap().len(), 100_000);
    }

    #[test]
    fn cap_applies_to_uncompressed_size() {
        let (_d, c) = fresh_db("outbox-cap-plain");
        // dobře komprimovatelné 2 MB: po zstd malé, do HTTP by ale šly celé 2 MB
        push(
            &c,
            "run.failed",
            Some("r"),
            json!({"log_tail": "a".repeat(2_000_000)}),
            1,
        )
        .unwrap();
        let (blob, enc): (Vec<u8>, String) = c
            .query_row("SELECT payload, encoding FROM outbox", [], |r| {
                Ok((r.get(0)?, r.get(1)?))
            })
            .unwrap();
        let v = decode(&blob, &enc).unwrap();
        assert!(serde_json::to_vec(&v).unwrap().len() <= EVENT_MAX_BYTES);
        assert_eq!(v["log_tail_truncated"], true);
    }

    #[test]
    fn oversized_event_is_truncated_under_cap() {
        let (_d, c) = fresh_db("outbox-cap");
        // nekomprimovatelný obsah nad 1 MB
        let mut s = String::new();
        let mut x: u64 = 1;
        while s.len() < 3_000_000 {
            x = x
                .wrapping_mul(6364136223846793005)
                .wrapping_add(1442695040888963407);
            s.push_str(&format!("{x:x}"));
        }
        push(
            &c,
            "run.failed",
            Some("r"),
            json!({"result": {"blob": s}, "log_tail": "ok"}),
            1,
        )
        .unwrap();
        let (blob, enc): (Vec<u8>, String) = c
            .query_row("SELECT payload, encoding FROM outbox", [], |r| {
                Ok((r.get(0)?, r.get(1)?))
            })
            .unwrap();
        assert!(blob.len() <= EVENT_MAX_BYTES);
        let v = decode(&blob, &enc).unwrap();
        assert_eq!(v["result"]["truncated"], true);
        assert_eq!(v["result_truncated"], true);
    }
}

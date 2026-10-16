//! Vznik běhů z rozvrhu (plán § 6.1).

use chrono_tz::Tz;
use rusqlite::{Connection, params};
use serde_json::json;

use crate::cronexpr::Schedule;
use crate::db::{meta_i64, meta_json, meta_set};
use crate::model::load_crons;
use crate::outbox;
use crate::queue::{Enqueued, NewJob, enqueue};
use crate::util::{Res, iso};

/// Nejdelší okno, které se při dohánění prochází po minutách.
const MAX_WINDOW_MS: i64 = 31 * 24 * 3600 * 1000;

#[derive(Debug, Default, PartialEq)]
pub struct PlanOutcome {
    pub first_tick: bool,
    /// Hodiny šly pozpátku: o kolik sekund.
    pub clock_skew_seconds: i64,
    pub queued: Vec<String>,
    pub rejected: Vec<String>,
    pub missed: Vec<(String, usize)>,
    pub shadow: Vec<(String, i64)>,
    pub invalid: Vec<(String, String)>,
}

/// Výskyty, které během pauzy nešly zařadit (`meta.pause_missed`, kontrakt § 2.1):
/// `{cron_code: {"count": n, "from": ms, "to": ms}}`.
type PauseMissed = serde_json::Map<String, serde_json::Value>;

/// Naplánuje výskyty v `(last_tick_at, now]`. Volá se uvnitř transakce ticku.
///
/// Během pauzy (`paused`) se výskyty nezařazují, jen se posouvají `last_tick_at` /
/// `last_scheduled_for` a výskyty se sčítají do `meta.pause_missed`. První tick po
/// skončení pauzy je vyhodnotí jako po krátkém výpadku podle `missed` (jeden souhrn
/// `run.missed`, u `once` jeden běh) — po víkendové pauze tak neběží každý cron 10×
/// a nepadají tisíce `job.rejected` (audit M3).
pub fn plan(conn: &Connection, tz: &Tz, now: i64, shadow: bool, paused: bool) -> Res<PlanOutcome> {
    let mut out = PlanOutcome::default();
    let Some(last) = meta_i64(conn, "last_tick_at")? else {
        // První tick: nic se zpětně nedoplňuje.
        meta_set(conn, "last_tick_at", &now.to_string())?;
        out.first_tick = true;
        return Ok(out);
    };
    if now < last {
        // Hodiny jdou pozpátku: nic se nezařadí, last_tick_at se nepřepisuje.
        out.clock_skew_seconds = (last - now) / 1000;
        return Ok(out);
    }
    let mut pause_missed: PauseMissed = meta_json(conn, "pause_missed")?
        .and_then(|v| v.as_object().cloned())
        .unwrap_or_default();
    for cron in load_crons(conn)? {
        if !cron.active() {
            continue;
        }
        let Some(schedule) = cron.schedule.as_deref().filter(|s| !s.trim().is_empty()) else {
            continue;
        };
        let expr = match Schedule::parse(schedule) {
            Ok(e) => e,
            Err(e) => {
                out.invalid.push((cron.code.clone(), e));
                continue;
            }
        };
        let from = last
            .max(cron.last_scheduled_for.unwrap_or(i64::MIN))
            .max(now - MAX_WINDOW_MS);
        let occ = expr.occurrences(tz, from, now);
        if paused {
            if let (Some(&first), Some(&latest)) = (occ.first(), occ.last()) {
                let e = pause_missed
                    .entry(cron.code.clone())
                    .or_insert_with(|| json!({"count": 0, "from": first, "to": latest}));
                e["count"] = json!(e["count"].as_i64().unwrap_or(0) + occ.len() as i64);
                e["from"] = json!(e["from"].as_i64().unwrap_or(first).min(first));
                e["to"] = json!(latest);
                conn.execute(
                    "UPDATE crons SET last_scheduled_for = ?1 WHERE code = ?2",
                    params![latest, cron.code],
                )?;
            }
            continue;
        }
        // výskyty z pauzy se počítají jako zmeškané (krátký výpadek)
        let held = pause_missed.remove(&cron.code);
        let (held_count, held_from, held_to) = match &held {
            Some(h) => (
                h["count"].as_i64().unwrap_or(0),
                h["from"].as_i64(),
                h["to"].as_i64(),
            ),
            None => (0, None, None),
        };
        if occ.is_empty() && held_count == 0 {
            continue;
        }
        let grace_ms = cron.missed_grace_minutes.max(0) * 60_000;
        let (recent, older): (Vec<i64>, Vec<i64>) =
            occ.iter().partition(|&&o| now - o < grace_ms.max(60_000));
        let missed_latest = older.last().copied().into_iter().chain(held_to).max();

        // nejvýš jeden job na cron a tick: poslední výskyt v okně
        let pick: Option<(i64, &str)> = if let Some(&r) = recent.last() {
            Some((r, "schedule"))
        } else if cron.missed == "once" {
            missed_latest.map(|o| (o, "missed"))
        } else {
            None
        };
        let total_missed = older.len() as i64 + held_count;
        let skipped = total_missed
            - if pick.is_some_and(|(_, s)| s == "missed") {
                1
            } else {
                0
            };
        if skipped > 0 {
            let from_ms = older
                .first()
                .copied()
                .into_iter()
                .chain(held_from)
                .min()
                .unwrap_or(now);
            let to_ms = missed_latest.unwrap_or(now);
            outbox::push(
                conn,
                "run.missed",
                None,
                json!({
                    "cron_code": cron.code,
                    "count": skipped,
                    "from": iso(from_ms),
                    "to": iso(to_ms),
                }),
                now,
            )?;
            out.missed.push((cron.code.clone(), skipped as usize));
        }
        if let Some((at, source)) = pick {
            if shadow {
                outbox::push(
                    conn,
                    "run.shadow",
                    None,
                    json!({"cron_code": cron.code, "scheduled_for": iso(at), "would_start_at": iso(now)}),
                    now,
                )?;
                out.shadow.push((cron.code.clone(), at));
            } else {
                let mut job = NewJob::new(&cron.code, source, now);
                job.scheduled_for = Some(at);
                match enqueue(conn, &cron, job, true, now)? {
                    Enqueued::Queued(u) => out.queued.push(u),
                    Enqueued::Rejected { .. } => out.rejected.push(cron.code.clone()),
                }
            }
        }
        if let Some(&latest) = occ.last() {
            conn.execute(
                "UPDATE crons SET last_scheduled_for = ?1 WHERE code = ?2",
                params![latest, cron.code],
            )?;
        }
    }
    // po pauze: zbytky cronů, které mezitím zmizely nebo se vypnuly, zahodit
    let stored = if paused && !pause_missed.is_empty() {
        serde_json::Value::Object(pause_missed).to_string()
    } else {
        String::new()
    };
    meta_set(conn, "pause_missed", &stored)?;
    meta_set(conn, "last_tick_at", &now.to_string())?;
    Ok(out)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::db::testutil::fresh_db;
    use crate::queue::tests::insert_cron;
    use crate::util::parse_iso;

    fn t(s: &str) -> i64 {
        parse_iso(s).unwrap()
    }

    fn jobs(c: &Connection) -> Vec<(String, String, Option<i64>)> {
        c.prepare("SELECT cron_code, source, scheduled_for FROM jobs ORDER BY id")
            .unwrap()
            .query_map([], |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?)))
            .unwrap()
            .map(Result::unwrap)
            .collect()
    }

    fn tz() -> Tz {
        "Europe/Prague".parse().unwrap()
    }

    #[test]
    fn first_tick_plans_nothing() {
        let (_d, c) = fresh_db("plan-first");
        insert_cron(&c, "a", Some("* * * * *"), "exclusive");
        let o = plan(&c, &tz(), t("2026-10-16T10:00:30Z"), false, false).unwrap();
        assert!(o.first_tick);
        assert!(jobs(&c).is_empty());
        let o = plan(&c, &tz(), t("2026-10-16T10:01:30Z"), false, false).unwrap();
        assert_eq!(o.queued.len(), 1);
        assert_eq!(jobs(&c)[0].2, Some(t("2026-10-16T10:01:00Z")));
    }

    #[test]
    fn gap_between_ticks_enqueues_at_most_one() {
        let (_d, c) = fresh_db("plan-gap");
        insert_cron(&c, "a", Some("* * * * *"), "exclusive");
        meta_set(&c, "last_tick_at", &t("2026-10-16T10:00:30Z").to_string()).unwrap();
        let o = plan(&c, &tz(), t("2026-10-16T10:04:30Z"), false, false).unwrap();
        assert_eq!(o.queued.len(), 1);
        assert_eq!(jobs(&c)[0].2, Some(t("2026-10-16T10:04:00Z")));
        // stejné okno podruhé (last_scheduled_for) nic nezdvojí
        meta_set(&c, "last_tick_at", &t("2026-10-16T10:00:30Z").to_string()).unwrap();
        let o = plan(&c, &tz(), t("2026-10-16T10:04:40Z"), false, false).unwrap();
        assert!(o.queued.is_empty());
    }

    #[test]
    fn clock_going_backwards_plans_nothing_and_keeps_last_tick() {
        let (_d, c) = fresh_db("plan-back");
        insert_cron(&c, "a", Some("* * * * *"), "exclusive");
        let last = t("2026-10-16T10:05:00Z");
        meta_set(&c, "last_tick_at", &last.to_string()).unwrap();
        let o = plan(&c, &tz(), t("2026-10-16T10:03:00Z"), false, false).unwrap();
        assert_eq!(o.clock_skew_seconds, 120);
        assert!(jobs(&c).is_empty());
        assert_eq!(meta_i64(&c, "last_tick_at").unwrap(), Some(last));
        // čas předběhne last_tick_at → jen výskyty po něm
        let o = plan(&c, &tz(), t("2026-10-16T10:06:10Z"), false, false).unwrap();
        assert_eq!(o.queued.len(), 1);
        assert_eq!(jobs(&c)[0].2, Some(t("2026-10-16T10:06:00Z")));
    }

    #[test]
    fn missed_skip_reports_and_once_enqueues_one() {
        let (_d, c) = fresh_db("plan-missed");
        insert_cron(&c, "skip", Some("0 * * * *"), "exclusive");
        insert_cron(&c, "once", Some("0 * * * *"), "exclusive");
        c.execute("UPDATE crons SET missed = 'once' WHERE code = 'once'", [])
            .unwrap();
        // server neběžel 5 h
        meta_set(&c, "last_tick_at", &t("2026-10-16T05:00:30Z").to_string()).unwrap();
        let o = plan(&c, &tz(), t("2026-10-16T10:30:00Z"), false, false).unwrap();
        let j = jobs(&c);
        assert_eq!(j.len(), 1);
        assert_eq!(j[0].0, "once");
        assert_eq!(j[0].1, "missed");
        assert_eq!(j[0].2, Some(t("2026-10-16T10:00:00Z")));
        assert!(o.missed.contains(&("skip".to_string(), 5)));
        assert!(o.missed.contains(&("once".to_string(), 4)));
        let n: i64 = c
            .query_row(
                "SELECT COUNT(*) FROM outbox WHERE type = 'run.missed'",
                [],
                |r| r.get(0),
            )
            .unwrap();
        assert_eq!(n, 2); // jedna souhrnná událost na cron
    }

    #[test]
    fn grace_window_still_runs_on_time() {
        let (_d, c) = fresh_db("plan-grace");
        insert_cron(&c, "a", Some("0 10 * * *"), "exclusive");
        meta_set(&c, "last_tick_at", &t("2026-10-16T07:59:30Z").to_string()).unwrap();
        // 10:00 Praha = 08:00 UTC; tick o 4 min později je pořád „včas“
        plan(&c, &tz(), t("2026-10-16T08:04:00Z"), false, false).unwrap();
        let j = jobs(&c);
        assert_eq!(j.len(), 1);
        assert_eq!(j[0].1, "schedule");
    }

    #[test]
    fn inactive_and_unscheduled_crons_are_skipped() {
        let (_d, c) = fresh_db("plan-inactive");
        insert_cron(&c, "off", Some("* * * * *"), "exclusive");
        insert_cron(&c, "manual", None, "exclusive");
        c.execute("UPDATE crons SET remote_active = 0 WHERE code = 'off'", [])
            .unwrap();
        meta_set(&c, "last_tick_at", &t("2026-10-16T10:00:30Z").to_string()).unwrap();
        plan(&c, &tz(), t("2026-10-16T10:01:30Z"), false, false).unwrap();
        assert!(jobs(&c).is_empty());
    }

    #[test]
    fn shadow_mode_emits_events_without_jobs() {
        let (_d, c) = fresh_db("plan-shadow");
        insert_cron(&c, "a", Some("* * * * *"), "exclusive");
        meta_set(&c, "last_tick_at", &t("2026-10-16T10:00:30Z").to_string()).unwrap();
        let o = plan(&c, &tz(), t("2026-10-16T10:01:30Z"), true, false).unwrap();
        assert_eq!(o.shadow.len(), 1);
        assert!(jobs(&c).is_empty());
    }

    #[test]
    fn several_expressions_enqueue_once_per_tick_and_report_missed() {
        let (_d, c) = fresh_db("plan-multi");
        insert_cron(&c, "a", Some("0 13 * * * | 15 23 * * *"), "exclusive");
        insert_cron(&c, "b", Some("*/5 * * * * | */10 * * * *"), "exclusive");
        meta_set(&c, "last_tick_at", &t("2026-10-16T10:59:30Z").to_string()).unwrap();
        // 13:00 Praha = 11:00 UTC
        plan(&c, &tz(), t("2026-10-16T11:00:20Z"), false, false).unwrap();
        let j = jobs(&c);
        assert_eq!(j.len(), 2); // a v 13:00 a b jednou (11:00 je v obou výrazech)
        assert_eq!(
            j[0],
            (
                "a".to_string(),
                "schedule".to_string(),
                Some(t("2026-10-16T11:00:00Z"))
            )
        );
        assert_eq!(j[1].2, Some(t("2026-10-16T11:00:00Z")));
        // server neběžel přes 23:15 a ráno 13:00 dalšího dne: jeden souhrn, nic nezařazeno (skip)
        c.execute("DELETE FROM jobs", []).unwrap();
        c.execute("DELETE FROM crons WHERE code = 'b'", []).unwrap();
        meta_set(&c, "last_tick_at", &t("2026-10-16T11:01:00Z").to_string()).unwrap();
        let o = plan(&c, &tz(), t("2026-10-17T12:00:00Z"), false, false).unwrap();
        assert!(jobs(&c).is_empty());
        assert_eq!(o.missed, vec![("a".to_string(), 2)]);
    }

    #[test]
    fn invalid_expression_in_schedule_is_not_planned() {
        let (_d, c) = fresh_db("plan-multi-bad");
        insert_cron(&c, "a", Some("* * * * * | 61 * * * *"), "exclusive");
        meta_set(&c, "last_tick_at", &t("2026-10-16T10:00:30Z").to_string()).unwrap();
        let o = plan(&c, &tz(), t("2026-10-16T10:01:30Z"), false, false).unwrap();
        assert!(jobs(&c).is_empty());
        assert_eq!(o.invalid.len(), 1);
    }

    #[test]
    fn pause_does_not_queue_and_resume_behaves_like_short_outage() {
        let (_d, c) = fresh_db("plan-pause");
        insert_cron(&c, "skip", Some("* * * * *"), "exclusive");
        insert_cron(&c, "once", Some("*/10 * * * *"), "exclusive");
        c.execute("UPDATE crons SET missed = 'once' WHERE code = 'once'", [])
            .unwrap();
        meta_set(&c, "last_tick_at", &t("2026-10-16T10:00:30Z").to_string()).unwrap();
        // 3 h pauzy, tick každou minutu
        let mut now = t("2026-10-16T10:01:30Z");
        while now < t("2026-10-16T13:07:00Z") {
            let o = plan(&c, &tz(), now, false, true).unwrap();
            assert!(o.queued.is_empty() && o.rejected.is_empty() && o.missed.is_empty());
            now += 60_000;
        }
        assert!(jobs(&c).is_empty());
        let n: i64 = c
            .query_row("SELECT COUNT(*) FROM outbox", [], |r| r.get(0))
            .unwrap();
        assert_eq!(n, 0, "no job.rejected flood during the pause");
        // první tick po resume
        let o = plan(&c, &tz(), now, false, false).unwrap();
        let j = jobs(&c);
        // `skip`: aktuální minuta je „včas“ → jeden běh, zbytek pauzy jen v souhrnu
        assert_eq!(j.iter().filter(|x| x.0 == "skip").count(), 1);
        // `once`: jeden dohnaný běh za celou pauzu
        let once: Vec<_> = j.iter().filter(|x| x.0 == "once").collect();
        assert_eq!(once.len(), 1);
        assert_eq!(once[0].1, "missed");
        assert_eq!(once[0].2, Some(t("2026-10-16T13:00:00Z")));
        assert_eq!(o.missed.iter().find(|m| m.0 == "once").unwrap().1, 17); // 10:10–12:50
        let skip_missed = o.missed.iter().find(|m| m.0 == "skip").unwrap().1;
        assert!(skip_missed > 170, "{skip_missed}");
        assert!(meta_json(&c, "pause_missed").unwrap().is_none());
        // další tick už nic navíc
        let o = plan(&c, &tz(), now + 30_000, false, false).unwrap();
        assert!(o.missed.is_empty());
    }

    #[test]
    fn dst_spring_fixed_hour_runs_once_after_jump() {
        let (_d, c) = fresh_db("plan-dst");
        insert_cron(&c, "a", Some("30 2 * * *"), "exclusive");
        meta_set(&c, "last_tick_at", &t("2026-03-29T00:59:30Z").to_string()).unwrap();
        plan(&c, &tz(), t("2026-03-29T01:00:20Z"), false, false).unwrap();
        let j = jobs(&c);
        assert_eq!(j.len(), 1);
        assert_eq!(j[0].2, Some(t("2026-03-29T01:00:00Z")));
    }
}

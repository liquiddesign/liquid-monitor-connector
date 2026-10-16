//! Výběr jobů k převzetí: pravidla souběhu (port `CanCronStartJob`), `maxParallel`,
//! `resources` a bariéra proti vyhladovění (plán § 6.3).

use std::collections::BTreeMap;

#[derive(Debug, Clone)]
pub struct Slot {
    pub cron_code: String,
    pub mode: String,
    pub resources: Vec<String>,
}

#[derive(Debug, Clone)]
pub struct Candidate {
    pub job_id: i64,
    pub slot: Slot,
    /// Od kdy job čeká (ms): `max(available_at, last_resume_at)`.
    pub waiting_since: i64,
}

#[derive(Debug, Default)]
pub struct Limits {
    pub max_parallel: Option<i64>,
    pub capacities: BTreeMap<String, i64>,
    pub starvation_ms: i64,
}

#[derive(Debug, Default, Clone)]
struct Block {
    by_mode: bool,
    by_max: bool,
    resources: Vec<String>,
}

fn norm(mode: &str) -> &str {
    match mode {
        "parallel" | "fully_parallel" | "independent" => mode,
        _ => "exclusive",
    }
}

/// Brání běžící `run` spuštění `cand`?
pub fn mode_blocks(cand: &Slot, run: &Slot) -> bool {
    let cm = norm(&cand.mode);
    if run.cron_code == cand.cron_code {
        return cm != "fully_parallel";
    }
    let rm = norm(&run.mode);
    match cm {
        "exclusive" => rm != "independent",
        "parallel" | "fully_parallel" => rm == "exclusive",
        _ => false, // independent
    }
}

fn capacity(limits: &Limits, res: &str) -> i64 {
    limits.capacities.get(res).copied().unwrap_or(1)
}

fn check(cand: &Slot, active: &[Slot], limits: &Limits) -> Option<Block> {
    let mut b = Block::default();
    if active.iter().any(|r| mode_blocks(cand, r)) {
        b.by_mode = true;
    }
    if let Some(max) = limits.max_parallel
        && max > 0
        && active.len() as i64 >= max
    {
        b.by_max = true;
    }
    for res in &cand.resources {
        let used = active.iter().filter(|r| r.resources.contains(res)).count() as i64;
        if used >= capacity(limits, res) {
            b.resources.push(res.clone());
        }
    }
    if b.by_mode || b.by_max || !b.resources.is_empty() {
        Some(b)
    } else {
        None
    }
}

/// Spuštění `x` by čekajícímu `s` (zablokovanému z důvodů `why`) znovu bránilo.
fn would_block(x: &Slot, s: &Slot, why: &Block) -> bool {
    if mode_blocks(s, x) {
        return true;
    }
    if why.by_max {
        return true;
    }
    // jen skupina prostředků, na kterou vyhladovělý job opravdu čeká (audit L6)
    x.resources.iter().any(|r| why.resources.contains(r))
}

#[derive(Debug, Default)]
pub struct Selection {
    pub chosen: Vec<i64>,
    /// Kódy cronů, na které čeká bariéra (do heartbeatu `starving`).
    pub starving: Vec<String>,
}

/// Výběr k převzetí: FIFO s přeskakováním a bariérou proti vyhladovění.
///
/// 1. průchod určí vyhladovělé joby (zablokované déle než `starvation_ms`), seřazené podle
///    toho, jak dlouho čekají. 2. průchod spouští v pořadí `id`; job, který by bránil
///    vyhladovělému jobu s vyšší prioritou (déle čekajícímu), se nespustí — bez ohledu na
///    své `id`, takže ji neobejde ani odložený job zařazený dřív (audit L6).
pub fn choose(candidates: &[Candidate], active: &[Slot], limits: &Limits, now: i64) -> Selection {
    let mut starving: Vec<(usize, Block)> = candidates
        .iter()
        .enumerate()
        .filter(|(_, c)| limits.starvation_ms > 0 && now - c.waiting_since > limits.starvation_ms)
        .filter_map(|(i, c)| check(&c.slot, active, limits).map(|why| (i, why)))
        .collect();
    starving.sort_by_key(|(i, _)| (candidates[*i].waiting_since, candidates[*i].job_id));
    let rank = |i: usize| starving.iter().position(|(j, _)| *j == i);

    let mut active: Vec<Slot> = active.to_vec();
    let mut started = vec![false; candidates.len()];
    let mut sel = Selection::default();
    for (i, c) in candidates.iter().enumerate() {
        let my_rank = rank(i);
        let barred = starving.iter().enumerate().any(|(r, (j, why))| {
            *j != i
                && !started[*j]
                && my_rank.is_none_or(|mine| r < mine)
                && would_block(&c.slot, &candidates[*j].slot, why)
        });
        if barred {
            continue;
        }
        if check(&c.slot, &active, limits).is_none() {
            active.push(c.slot.clone());
            sel.chosen.push(c.job_id);
            started[i] = true;
        }
    }
    for (j, _) in &starving {
        let code = &candidates[*j].slot.cron_code;
        if !started[*j] && !sel.starving.contains(code) {
            sel.starving.push(code.clone());
        }
    }
    sel
}

#[cfg(test)]
mod tests {
    use super::*;

    fn slot(code: &str, mode: &str) -> Slot {
        Slot {
            cron_code: code.into(),
            mode: mode.into(),
            resources: vec![],
        }
    }

    fn cand(id: i64, code: &str, mode: &str, since: i64) -> Candidate {
        Candidate {
            job_id: id,
            slot: slot(code, mode),
            waiting_since: since,
        }
    }

    fn lim() -> Limits {
        Limits {
            max_parallel: None,
            capacities: BTreeMap::new(),
            starvation_ms: 300_000,
        }
    }

    #[test]
    fn concurrency_matrix() {
        let ex = slot("e", "exclusive");
        let par = slot("p", "parallel");
        let full = slot("f", "fully_parallel");
        let ind = slot("i", "independent");
        // exclusive blokuje vše kromě independent
        assert!(mode_blocks(&slot("x", "exclusive"), &par));
        assert!(!mode_blocks(&slot("x", "exclusive"), &ind));
        assert!(mode_blocks(&slot("x", "parallel"), &ex));
        assert!(!mode_blocks(&slot("x", "parallel"), &par));
        assert!(!mode_blocks(&slot("x", "parallel"), &full));
        assert!(mode_blocks(&slot("x", "fully_parallel"), &ex));
        assert!(!mode_blocks(&slot("x", "independent"), &ex));
        // vlastní instance
        assert!(mode_blocks(&slot("p", "parallel"), &par));
        assert!(!mode_blocks(&slot("f", "fully_parallel"), &full));
        assert!(mode_blocks(&slot("i", "independent"), &ind));
        assert!(mode_blocks(&slot("e", "exclusive"), &ex));
    }

    #[test]
    fn fifo_sees_previously_claimed_in_same_pass() {
        let c = vec![
            cand(1, "a", "exclusive", 0),
            cand(2, "b", "exclusive", 0),
            cand(3, "c", "independent", 0),
        ];
        let s = choose(&c, &[], &lim(), 0);
        assert_eq!(s.chosen, vec![1, 3]);
    }

    #[test]
    fn max_parallel_and_resources() {
        let mut l = lim();
        l.max_parallel = Some(2);
        let c = vec![
            cand(1, "a", "parallel", 0),
            cand(2, "b", "parallel", 0),
            cand(3, "c", "parallel", 0),
        ];
        assert_eq!(choose(&c, &[], &l, 0).chosen, vec![1, 2]);

        let mut l = lim();
        l.capacities.insert("qi".into(), 1);
        let mut a = cand(1, "a", "parallel", 0);
        a.slot.resources = vec!["qi".into()];
        let mut b = cand(2, "b", "parallel", 0);
        b.slot.resources = vec!["qi".into()];
        let d = cand(3, "d", "parallel", 0);
        assert_eq!(choose(&[a, b, d], &[], &l, 0).chosen, vec![1, 3]);
    }

    #[test]
    fn barrier_holds_back_parallel_jobs_for_starving_exclusive() {
        let running = vec![slot("p1", "parallel")];
        let now = 1_000_000;
        // exclusive čeká déle než 300 s, za ním přichází další parallel
        let c = vec![
            cand(1, "ex", "exclusive", now - 301_000),
            cand(2, "p2", "parallel", now - 1000),
            cand(3, "ind", "independent", now - 1000),
        ];
        let s = choose(&c, &running, &lim(), now);
        assert_eq!(s.chosen, vec![3]); // parallel zadržen, independent smí
        assert_eq!(s.starving, vec!["ex".to_string()]);
        // bez překročení prahu se parallel spustí (dnešní FIFO s přeskakováním)
        let c = vec![
            cand(1, "ex", "exclusive", now - 10_000),
            cand(2, "p2", "parallel", now - 1000),
        ];
        assert_eq!(choose(&c, &running, &lim(), now).chosen, vec![2]);
    }

    #[test]
    fn delayed_job_with_lower_id_does_not_bypass_barrier() {
        let now = 1_000_000;
        let running = vec![slot("p1", "parallel")];
        // job 1 byl odložený (zařazen dřív, dostupný teprve teď), job 2 vyhladovělý exclusive
        let c = vec![
            cand(1, "p2", "parallel", now - 1000),
            cand(2, "ex", "exclusive", now - 301_000),
        ];
        let s = choose(&c, &running, &lim(), now);
        assert!(s.chosen.is_empty());
        assert_eq!(s.starving, vec!["ex".to_string()]);
    }

    #[test]
    fn barrier_only_for_the_awaited_resource() {
        let now = 1_000_000;
        let mut l = lim();
        l.capacities.insert("qi".into(), 1);
        let mut running = slot("r", "parallel");
        running.resources = vec!["qi".into()];
        let mut starving = cand(1, "s", "parallel", 0);
        starving.slot.resources = vec!["qi".into(), "pg".into()];
        let mut uses_pg = cand(2, "o", "parallel", now);
        uses_pg.slot.resources = vec!["pg".into()];
        // `s` čeká na qi, ne na pg → `o` s pg smí
        let s = choose(&[starving, uses_pg], &[running], &l, now);
        assert_eq!(s.chosen, vec![2]);
    }

    #[test]
    fn barrier_has_no_deadlock_between_two_starving_jobs() {
        let now = 1_000_000;
        let c = vec![cand(1, "e1", "exclusive", 0), cand(2, "e2", "exclusive", 0)];
        // nic neběží → první se spustí, druhý čeká na něj
        assert_eq!(choose(&c, &[], &lim(), now).chosen, vec![1]);
    }

    #[test]
    fn barrier_on_resource_group() {
        let now = 1_000_000;
        let mut l = lim();
        l.capacities.insert("qi".into(), 1);
        let mut running = slot("r", "parallel");
        running.resources = vec!["qi".into()];
        let mut starving = cand(1, "s", "parallel", 0);
        starving.slot.resources = vec!["qi".into()];
        let mut other_qi = cand(2, "o", "parallel", now);
        other_qi.slot.resources = vec!["qi".into()];
        let free = cand(3, "f", "parallel", now);
        // `o` by skupinu stejně nedostal, `f` skupinu nepotřebuje → smí
        let s = choose(&[starving, other_qi, free], &[running], &l, now);
        assert_eq!(s.chosen, vec![3]);
    }
}

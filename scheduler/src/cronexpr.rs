//! Cron výrazy se stejnou sémantikou jako `dragonmantank/cron-expression` 3.x
//! (tutéž knihovnu používá PHP strana — `enqueue-due`, crunz).
//!
//! Port vyhodnocení polí je záměrně doslovný (`AbstractField::isSatisfied`,
//! `isInIncrementsOfRanges`, `DayOfWeekField` s přepínáním formátu `w`/`N`, …), aby
//! výrazy běžely stejně jako dnes. Odlišné je jen chování při přechodu
//! letního/zimního času, které určuje plán § 6.1 (viz [`CronExpr::is_due`]).

use chrono::{DateTime, Datelike, Duration, NaiveDate, NaiveDateTime, TimeZone, Timelike, Utc};
use chrono_tz::Tz;

const MONTH_LITERALS: [&str; 12] = [
    "JAN", "FEB", "MAR", "APR", "MAY", "JUN", "JUL", "AUG", "SEP", "OCT", "NOV", "DEC",
];
const DOW_LITERALS: [&str; 7] = ["MON", "TUE", "WED", "THU", "FRI", "SAT", "SUN"];

#[derive(Debug, Clone)]
enum DomItem {
    Last,
    Nearest(i64),
}

#[derive(Debug, Clone)]
enum DowItem {
    /// `xL` — poslední daný den v týdnu v měsíci (`w` 0..6)
    Last(u32),
    /// `x#n` — n-tý den v týdnu (`N` 1..7)
    Nth(u32, u32),
}

#[derive(Debug, Clone)]
struct DomSpec {
    star: bool,
    question: bool,
    days: u32, // bit d = den d (1..31)
    special: Vec<DomItem>,
}

#[derive(Debug, Clone)]
struct DowSpec {
    star: bool,
    question: bool,
    weekdays: u8, // bit w = den v týdnu ve formátu `w` (0 = neděle)
    special: Vec<DowItem>,
}

#[derive(Debug, Clone)]
pub struct CronExpr {
    minutes: u64,
    hours: u32,
    months: u16,
    dom: DomSpec,
    dow: DowSpec,
    minute_star: bool,
    hour_star: bool,
    month_star: bool,
    /// Pole hodin obsahuje `*` nebo krok → vyhodnocuje se po skutečných okamžicích (plán § 6.1).
    pub hour_wildcard: bool,
}

fn alias(expr: &str) -> Option<&'static str> {
    match expr.to_ascii_lowercase().as_str() {
        "@yearly" | "@annually" => Some("0 0 1 1 *"),
        "@monthly" => Some("0 0 1 * *"),
        "@weekly" => Some("0 0 * * 0"),
        "@daily" | "@midnight" => Some("0 0 * * *"),
        "@hourly" => Some("0 * * * *"),
        _ => None,
    }
}

/// PHP `(int)` přetypování řetězce: úvodní celé číslo, jinak 0.
fn php_int(s: &str) -> i64 {
    let s = s.trim_start();
    let bytes = s.as_bytes();
    let mut i = 0;
    let mut neg = false;
    if i < bytes.len() && (bytes[i] == b'+' || bytes[i] == b'-') {
        neg = bytes[i] == b'-';
        i += 1;
    }
    let mut n: i64 = 0;
    while i < bytes.len() && bytes[i].is_ascii_digit() {
        n = n
            .saturating_mul(10)
            .saturating_add((bytes[i] - b'0') as i64);
        i += 1;
    }
    if neg { -n } else { n }
}

/// Zjednodušené PHP `is_numeric` (celé číslo se znaménkem, volitelně desetinné).
fn is_numeric(s: &str) -> bool {
    let t = s.strip_prefix(['+', '-']).unwrap_or(s);
    if t.is_empty() {
        return false;
    }
    let mut parts = t.splitn(2, '.');
    let a = parts.next().unwrap_or("");
    let b = parts.next();
    let digits = |x: &str| x.chars().all(|c| c.is_ascii_digit());
    match b {
        None => !a.is_empty() && digits(a),
        Some(b) => (!a.is_empty() || !b.is_empty()) && digits(a) && digits(b),
    }
}

/// `filter_var($x, FILTER_VALIDATE_INT)` je pravdivé (nenulové celé číslo). Záporné kroky
/// PHP sice validací pustí, ale nevyhodnotí smysluplně — tady jsou neplatné.
fn valid_step(s: &str) -> bool {
    let t = s.strip_prefix('+').unwrap_or(s);
    !t.is_empty()
        && t.chars().all(|c| c.is_ascii_digit())
        && (t == "0" || !t.starts_with('0'))
        && php_int(t) > 0
}

struct Field {
    start: i64,
    end: i64,
    /// literals[i] odpovídá hodnotě `literal_base + i`
    literals: &'static [&'static str],
    literal_base: i64,
}

const MINUTE: Field = Field {
    start: 0,
    end: 59,
    literals: &[],
    literal_base: 0,
};
const HOUR: Field = Field {
    start: 0,
    end: 23,
    literals: &[],
    literal_base: 0,
};
const DOM: Field = Field {
    start: 1,
    end: 31,
    literals: &[],
    literal_base: 0,
};
const MONTH: Field = Field {
    start: 1,
    end: 12,
    literals: &MONTH_LITERALS,
    literal_base: 1,
};
const DOW: Field = Field {
    start: 0,
    end: 7,
    literals: &DOW_LITERALS,
    literal_base: 1,
};

impl Field {
    fn convert_literals(&self, v: &str) -> String {
        let up = v.to_ascii_uppercase();
        for (i, lit) in self.literals.iter().enumerate() {
            if *lit == up {
                return (self.literal_base + i as i64).to_string();
            }
        }
        v.to_string()
    }

    /// `AbstractField::validate`
    fn validate(&self, value: &str, special: &dyn Fn(&str) -> bool) -> bool {
        let value = self.convert_literals(value);
        if value == "*" {
            return true;
        }
        if value.contains(',') {
            return value.split(',').all(special);
        }
        if value.contains('/') {
            let mut it = value.split('/');
            let range = it.next().unwrap_or("");
            let step = it.next().unwrap_or("");
            if is_numeric(range) {
                return false;
            }
            return special(range) && valid_step(step);
        }
        if value.contains('-') {
            if value.matches('-').count() > 1 {
                return false;
            }
            let mut it = value.split('-');
            let a = self.convert_literals(it.next().unwrap_or(""));
            let b = self.convert_literals(it.next().unwrap_or(""));
            if a == "*" || b == "*" {
                return false;
            }
            return special(&a) && special(&b);
        }
        if !is_numeric(&value) || value.contains('.') {
            return false;
        }
        let n = php_int(&value);
        n >= self.start && n <= self.end
    }

    /// `AbstractField::isSatisfied`; `Err` = výjimka v PHP (výraz pak nikdy není splatný).
    fn is_satisfied(&self, date_value: i64, value: &str) -> Result<bool, String> {
        if value.contains('/') {
            return self.in_increments(date_value, value);
        }
        if value.contains('-') {
            let mut it = value.splitn(2, '-');
            let a = php_int(&self.convert_literals(it.next().unwrap_or("").trim()));
            let b = php_int(&self.convert_literals(it.next().unwrap_or("").trim()));
            return Ok(date_value >= a && date_value <= b);
        }
        Ok(value == "*" || date_value == php_int(value))
    }

    fn in_increments(&self, date_value: i64, value: &str) -> Result<bool, String> {
        let mut chunks = value.splitn(2, '/');
        let mut range = chunks.next().unwrap_or("").trim().to_string();
        let step_s = chunks.next().unwrap_or("0").trim();
        if step_s == "0" {
            return Ok(false);
        }
        let step = php_int(step_s);
        if step <= 0 {
            return Ok(false);
        }
        if range == "*" {
            range = format!("{}-{}", self.start, self.end);
        }
        let mut rc = range.splitn(2, '-');
        let first = rc.next().unwrap_or("");
        let range_start = php_int(first);
        let range_end = rc.next().map(php_int).unwrap_or(range_start);
        if range_start < self.start || range_start > self.end || range_start > range_end {
            return Err(format!("Invalid range start requested: {value}"));
        }
        if range_end < self.start || range_end > self.end || range_end < range_start {
            return Err(format!("Invalid range end requested: {value}"));
        }
        if step > self.end {
            let count = self.end - self.start + 1;
            return Ok(date_value == self.start + step % count);
        }
        if step > range_end - range_start {
            return Ok(date_value == range_start);
        }
        let mut v = range_start;
        while v <= range_end {
            if v == date_value {
                return Ok(true);
            }
            v += step;
        }
        Ok(false)
    }

    /// Bitová maska hodnot `start..=end`, které část výrazu splňuje.
    fn mask(&self, part: &str, convert_whole: bool) -> Result<u64, String> {
        let mut mask = 0u64;
        for item in part.split(',') {
            let item = item.trim();
            let item = if convert_whole {
                self.convert_literals(item)
            } else {
                item.to_string()
            };
            for v in self.start..=self.end {
                if self.is_satisfied(v, &item)? {
                    mask |= 1 << v;
                }
            }
        }
        Ok(mask)
    }
}

fn validate_simple(f: &Field, v: &str) -> bool {
    f.validate(v, &|x| validate_simple(f, x))
}

fn dom_validate(value: &str) -> bool {
    if value.contains(',') && (value.contains('W') || value.contains('L')) {
        return false;
    }
    if DOM.validate(value, &|v| dom_validate(v)) {
        return true;
    }
    if value == "?" || value == "L" {
        return true;
    }
    if let Some(prefix) = value.strip_suffix('W') {
        // `LW` PHP formálně připustí, ale vyhodnotí nesmyslně — nepodporujeme.
        return prefix != "L" && dom_validate(prefix);
    }
    false
}

fn dow_validate(value: &str) -> bool {
    if DOW.validate(value, &|v| dow_validate(v)) {
        return true;
    }
    if value == "?" {
        return true;
    }
    if value.contains('#') {
        let chunks: Vec<&str> = value.split('#').collect();
        let c0 = DOW.convert_literals(chunks[0]);
        let c1 = chunks.get(1).copied().unwrap_or("");
        if DOW.validate(&c0, &|v| dow_validate(v))
            && is_numeric(c1)
            && (1..=5).contains(&php_int(c1))
        {
            return true;
        }
    }
    if let Some(prefix) = value.strip_suffix('L') {
        return dow_validate(prefix);
    }
    false
}

fn days_in_month(year: i32, month: u32) -> u32 {
    let (ny, nm) = if month == 12 {
        (year + 1, 1)
    } else {
        (year, month + 1)
    };
    NaiveDate::from_ymd_opt(ny, nm, 1)
        .and_then(|d| d.pred_opt())
        .map(|d| d.day())
        .unwrap_or(31)
}

/// `DayOfMonthField::getNearestWeekday`
/// Doslovně včetně přetečení data za konec měsíce (PHP `createFromFormat` / `setDate`),
/// aby `31W` v třicetidenním měsíci běžel stejně jako v PHP.
fn nearest_weekday(year: i32, month: u32, target: i64) -> Option<u32> {
    let first = NaiveDate::from_ymd_opt(year, month, 1)?;
    let day_of = |d: i64| first.checked_add_signed(Duration::days(d - 1));
    let tdate = day_of(target)?;
    if tdate.weekday().number_from_monday() < 6 {
        return Some(tdate.day());
    }
    let last = days_in_month(tdate.year(), tdate.month()) as i64;
    for i in [-1i64, 1, -2, 2] {
        let adj = target + i;
        if adj > 0 && adj <= last {
            let d = day_of(adj)?;
            if d.weekday().number_from_monday() < 6 && d.month() == month {
                return Some(d.day());
            }
        }
    }
    None
}

impl CronExpr {
    pub fn parse(expr: &str) -> Result<CronExpr, String> {
        let source = expr.trim().to_string();
        let effective = alias(&source).map(str::to_string).unwrap_or(source.clone());
        let parts: Vec<&str> = effective.split_whitespace().collect();
        if parts.len() != 5 {
            return Err(format!("{source} is not a valid CRON expression"));
        }
        if parts[0] == "?"
            || parts[1] == "?"
            || parts[3] == "?"
            || (parts[2] == "?" && parts[4] == "?")
        {
            return Err(format!("{source} is not a valid CRON expression"));
        }
        let invalid =
            |pos: usize| format!("Invalid CRON field value {} at position {pos}", parts[pos]);
        if !validate_simple(&MINUTE, parts[0]) {
            return Err(invalid(0));
        }
        if !validate_simple(&HOUR, parts[1]) {
            return Err(invalid(1));
        }
        if !dom_validate(parts[2]) {
            return Err(invalid(2));
        }
        if !validate_simple(&MONTH, parts[3]) {
            return Err(invalid(3));
        }
        if !dow_validate(parts[4]) {
            return Err(invalid(4));
        }

        let minutes = MINUTE.mask(parts[0], false)?;
        let hours = HOUR.mask(parts[1], false)? as u32;
        let months = MONTH.mask(parts[3], true)? as u16;

        // den v měsíci
        let mut dom = DomSpec {
            star: parts[2] == "*",
            question: parts[2] == "?",
            days: 0,
            special: vec![],
        };
        if !dom.star && !dom.question {
            for item in parts[2].split(',') {
                let item = item.trim();
                if item == "?" {
                    dom.days |= u32::MAX;
                } else if item == "L" {
                    dom.special.push(DomItem::Last);
                } else if let Some(p) = item.find('W').filter(|&p| p > 0) {
                    dom.special.push(DomItem::Nearest(php_int(&item[..p])));
                } else {
                    for d in 1..=31 {
                        if DOM.is_satisfied(d, item)? {
                            dom.days |= 1 << d;
                        }
                    }
                }
            }
        }

        // den v týdnu
        let mut dow = DowSpec {
            star: parts[4] == "*",
            question: parts[4] == "?",
            weekdays: 0,
            special: vec![],
        };
        if !dow.star && !dow.question {
            for item in parts[4].split(',') {
                let item = item.trim();
                if item == "?" {
                    dow.weekdays = 0x7f;
                    continue;
                }
                let value = DOW.convert_literals(item);
                if let Some(p) = value.find('L').filter(|&p| p > 0) {
                    let wd = php_int(&DOW.convert_literals(&value[..p])).rem_euclid(7) as u32;
                    dow.special.push(DowItem::Last(wd));
                    continue;
                }
                if value.find('#').is_some_and(|p| p > 0) {
                    let chunks: Vec<&str> = value.split('#').collect();
                    let nth_s = chunks.get(1).copied().unwrap_or("");
                    if !is_numeric(nth_s) {
                        return Err(format!("Hashed weekdays must be numeric, {nth_s} given"));
                    }
                    let nth = php_int(nth_s);
                    let mut wd_s = chunks[0].to_string();
                    if wd_s == "0" {
                        wd_s = "7".into();
                    }
                    let wd = php_int(&DOW.convert_literals(&wd_s));
                    if !(0..=7).contains(&wd) || !(1..=5).contains(&nth) {
                        return Err(format!("Invalid hashed weekday {value}"));
                    }
                    dow.special.push(DowItem::Nth(wd as u32, nth as u32));
                    continue;
                }
                let mut v = value.clone();
                if v.contains('-') {
                    let mut ps: Vec<String> = v.split('-').map(str::to_string).collect();
                    if ps[0] == "7" {
                        ps[0] = "0".into();
                    } else if ps.get(1).map(String::as_str) == Some("0") {
                        ps[1] = "7".into();
                    }
                    v = ps.join("-");
                }
                let iso_format = v.chars().any(|c| c == '7');
                for w in 0..7i64 {
                    let field_value = if iso_format && w == 0 { 7 } else { w };
                    if DOW.is_satisfied(field_value, &v)? {
                        dow.weekdays |= 1 << w;
                    }
                }
            }
        }

        let hour_wildcard = parts[1].contains('*') || parts[1].contains('/');
        Ok(CronExpr {
            minutes,
            hours,
            months,
            dom,
            dow,
            minute_star: parts[0] == "*",
            hour_star: parts[1] == "*",
            month_star: parts[3] == "*",
            hour_wildcard,
        })
    }

    fn dom_matches(&self, t: &NaiveDateTime) -> bool {
        let day = t.day();
        if self.dom.days & (1 << day) != 0 {
            return true;
        }
        for s in &self.dom.special {
            match s {
                DomItem::Last => {
                    if day == days_in_month(t.year(), t.month()) {
                        return true;
                    }
                }
                DomItem::Nearest(target) => {
                    if nearest_weekday(t.year(), t.month(), *target) == Some(day) {
                        return true;
                    }
                }
            }
        }
        false
    }

    fn dow_matches(&self, t: &NaiveDateTime) -> bool {
        let w = t.weekday().num_days_from_sunday();
        if self.dow.weekdays & (1 << w) != 0 {
            return true;
        }
        let dim = days_in_month(t.year(), t.month());
        for s in &self.dow.special {
            match s {
                DowItem::Last(wd) => {
                    if *wd == w && dim - t.day() < 7 {
                        return true;
                    }
                }
                DowItem::Nth(wd, nth) => {
                    let n = t.weekday().number_from_monday();
                    if n != *wd {
                        continue;
                    }
                    // n-tý výskyt dne v měsíci
                    let mut count = 0;
                    let mut found = dim + 1;
                    for d in 1..=dim {
                        let x = NaiveDate::from_ymd_opt(t.year(), t.month(), d)
                            .map(|x| x.weekday().number_from_monday())
                            .unwrap_or(0);
                        if x == *wd {
                            count += 1;
                            if count >= *nth {
                                found = d;
                                break;
                            }
                        }
                    }
                    if t.day() == found {
                        return true;
                    }
                }
            }
        }
        false
    }

    /// Odpovídá místní čas (na minutu) výrazu? Totéž co `isDue` v dragonmantank.
    pub fn matches(&self, t: &NaiveDateTime) -> bool {
        if !self.minute_star && self.minutes & (1 << t.minute()) == 0 {
            return false;
        }
        if !self.hour_star && self.hours & (1 << t.hour()) == 0 {
            return false;
        }
        if !self.month_star && self.months & (1 << t.month()) == 0 {
            return false;
        }
        let dom_in = !self.dom.star;
        let dow_in = !self.dow.star;
        if dom_in && dow_in {
            // Obě pole omezená → stačí jedno (OR), `?` se z OR vynechá.
            let d = !self.dom.question && self.dom_matches(t);
            let w = !self.dow.question && self.dow_matches(t);
            return d || w;
        }
        if dom_in && !self.dom.question && !self.dom_matches(t) {
            return false;
        }
        if dow_in && !self.dow.question && !self.dow_matches(t) {
            return false;
        }
        true
    }

    /// Je výraz splatný v minutě `minute_ms` (UTC, zarovnané na minutu) v zóně `tz`?
    ///
    /// Plán § 6.1: výraz s pevnou hodinou se vyhodnocuje v místním čase — neexistující
    /// čas (jarní posun) se spustí v první minutě po skoku, dvojitý čas (podzim) jen
    /// jednou. Výraz s `*` nebo krokem v poli hodin se vyhodnocuje po skutečných
    /// okamžicích, takže v opakované hodině běží znovu.
    pub fn is_due(&self, tz: &Tz, minute_ms: i64) -> bool {
        let Some(utc) = DateTime::<Utc>::from_timestamp_millis(minute_ms) else {
            return false;
        };
        let local = utc.with_timezone(tz).naive_local();
        if self.hour_wildcard {
            return self.matches(&local);
        }
        if self.matches(&local) {
            return match tz.from_local_datetime(&local) {
                chrono::LocalResult::Ambiguous(first, _) => first.timestamp_millis() == minute_ms,
                _ => true,
            };
        }
        let prev = (utc - Duration::minutes(1)).with_timezone(tz).naive_local();
        if local - prev > Duration::minutes(1) {
            let mut t = prev + Duration::minutes(1);
            while t < local {
                if self.matches(&t) {
                    return true;
                }
                t += Duration::minutes(1);
            }
        }
        false
    }

    /// Splatné minuty v intervalu `(from_excl, to_incl]` (ms), nejvýš `limit` posledních.
    pub fn occurrences(&self, tz: &Tz, from_excl: i64, to_incl: i64) -> Vec<i64> {
        let mut out = Vec::new();
        let mut m = from_excl.div_euclid(60_000) * 60_000 + 60_000;
        while m <= to_incl {
            if self.is_due(tz, m) {
                out.push(m);
            }
            m += 60_000;
        }
        out
    }
}

/// Oddělovač více výrazů v `crons[].schedule` (kontrakt § 4, PHP `PullCron::EXPRESSION_SEPARATOR`).
pub const EXPRESSION_SEPARATOR: char = '|';

/// Rozvrh cronu: jeden nebo víc výrazů (`"0 13 * * * | 15 23 * * *"`). Výskyt je
/// sjednocení výskytů všech výrazů; stejná minuta se počítá jednou.
#[derive(Debug, Clone)]
pub struct Schedule {
    pub expressions: Vec<CronExpr>,
}

impl Schedule {
    /// Chyba, když je kterýkoli výraz nevalidní, nebo když žádný výraz není.
    pub fn parse(schedule: &str) -> Result<Schedule, String> {
        let mut expressions = Vec::new();
        for part in schedule.split(EXPRESSION_SEPARATOR) {
            let part = part.trim();
            if part.is_empty() {
                continue;
            }
            expressions.push(CronExpr::parse(part)?);
        }
        if expressions.is_empty() {
            return Err(format!("{schedule:?} contains no cron expression"));
        }
        Ok(Schedule { expressions })
    }

    /// Splatný, když je splatný kterýkoli výraz (PHP `CronDefinition::isDue`).
    #[cfg(test)]
    pub fn is_due(&self, tz: &Tz, minute_ms: i64) -> bool {
        self.expressions.iter().any(|e| e.is_due(tz, minute_ms))
    }

    /// Splatné minuty v `(from_excl, to_incl]`, seřazené a bez duplicit.
    pub fn occurrences(&self, tz: &Tz, from_excl: i64, to_incl: i64) -> Vec<i64> {
        let mut out: Vec<i64> = self
            .expressions
            .iter()
            .flat_map(|e| e.occurrences(tz, from_excl, to_incl))
            .collect();
        out.sort_unstable();
        out.dedup();
        out
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn utc(s: &str) -> i64 {
        crate::util::parse_iso(s).unwrap()
    }

    fn local(s: &str) -> NaiveDateTime {
        NaiveDateTime::parse_from_str(s, "%Y-%m-%d %H:%M").unwrap()
    }

    #[test]
    fn validity() {
        for ok in [
            "* * * * *",
            "*/5 * * * *",
            "0 0 1 1 *",
            "@hourly",
            "@Daily",
            "0 8-17 * * 1-5",
            "0,15,30,45 * * * *",
            "0 0 L * *",
            "0 0 15W * *",
            "0 0 ? * MON",
            "0 0 * JAN-MAR *",
            "0 0 * * 5L",
            "0 0 * * FRI#2",
            "0 0 * * 1#1,5L",
            "5-10/2 * * * *",
            "0 */2 * * *",
            "0 0 * * 7",
            "0 0 * * mon-fri",
        ] {
            assert!(CronExpr::parse(ok).is_ok(), "{ok} should be valid");
        }
        for bad in [
            "* * * *",
            "* * * * * *",
            "60 * * * *",
            "* 24 * * *",
            "? * * * *",
            "* * ? * ?",
            "5/10 * * * *",
            "*/0 * * * *",
            "0 0 1,L * *",
            "0 0 * * 8",
            "0 0 * * 1#6",
            "1-2-3 * * * *",
            "@reboot",
            "a * * * *",
        ] {
            assert!(CronExpr::parse(bad).is_err(), "{bad} should be invalid");
        }
    }

    #[test]
    fn matching_basics() {
        let e = CronExpr::parse("*/15 * * * *").unwrap();
        assert!(e.matches(&local("2026-10-16 10:45")));
        assert!(!e.matches(&local("2026-10-16 10:46")));

        let e = CronExpr::parse("0 8-17 * * 1-5").unwrap();
        assert!(e.matches(&local("2026-10-16 08:00"))); // pátek
        assert!(!e.matches(&local("2026-10-17 08:00"))); // sobota
        assert!(!e.matches(&local("2026-10-16 18:00")));

        // OR dne v měsíci a dne v týdnu, když jsou omezené oba
        let e = CronExpr::parse("0 0 13 * 5").unwrap();
        assert!(e.matches(&local("2026-10-13 00:00"))); // úterý 13.
        assert!(e.matches(&local("2026-10-16 00:00"))); // pátek

        let e = CronExpr::parse("0 0 L * *").unwrap();
        assert!(e.matches(&local("2026-02-28 00:00")));
        assert!(!e.matches(&local("2026-02-27 00:00")));

        // 15W: 15. 8. 2026 je sobota → pátek 14.
        let e = CronExpr::parse("0 0 15W * *").unwrap();
        assert!(e.matches(&local("2026-08-14 00:00")));
        assert!(!e.matches(&local("2026-08-15 00:00")));

        // poslední pátek a druhý pátek
        let e = CronExpr::parse("0 0 * * 5L").unwrap();
        assert!(e.matches(&local("2026-10-30 00:00")));
        assert!(!e.matches(&local("2026-10-23 00:00")));
        let e = CronExpr::parse("0 0 * * FRI#2").unwrap();
        assert!(e.matches(&local("2026-10-09 00:00")));
        assert!(!e.matches(&local("2026-10-16 00:00")));

        // neděle jako 0 i 7
        for x in ["0 0 * * 0", "0 0 * * 7", "0 0 * * SUN", "0 0 * * 5-0"] {
            let e = CronExpr::parse(x).unwrap();
            assert!(e.matches(&local("2026-10-18 00:00")), "{x}");
        }

        // kvirk dragonmantank: MON-SUN neděli nezahrne (formát `w`)
        let e = CronExpr::parse("0 0 * * MON-SUN").unwrap();
        assert!(!e.matches(&local("2026-10-18 00:00")));
        // kvirk: krok větší než rozsah pole → jediná hodnota
        let e = CronExpr::parse("*/90 * * * *").unwrap();
        assert!(e.matches(&local("2026-10-18 00:30")));
        assert!(!e.matches(&local("2026-10-18 00:00")));
    }

    #[test]
    fn dst_fixed_hour_spring_runs_after_jump() {
        let tz: Tz = "Europe/Prague".parse().unwrap();
        let e = CronExpr::parse("30 2 * * *").unwrap();
        // 2026-03-29: 02:00 CET → 03:00 CEST (01:00 UTC). 02:30 neexistuje.
        let occ = e.occurrences(
            &tz,
            utc("2026-03-28T23:00:00Z"),
            utc("2026-03-29T23:00:00Z"),
        );
        assert_eq!(occ, vec![utc("2026-03-29T01:00:00Z")]); // 03:00 CEST
    }

    #[test]
    fn dst_fixed_hour_autumn_runs_once() {
        let tz: Tz = "Europe/Prague".parse().unwrap();
        let e = CronExpr::parse("30 2 * * *").unwrap();
        // 2026-10-25: 03:00 CEST → 02:00 CET (01:00 UTC); 02:30 je dvakrát.
        let occ = e.occurrences(
            &tz,
            utc("2026-10-24T22:00:00Z"),
            utc("2026-10-25T22:00:00Z"),
        );
        assert_eq!(occ, vec![utc("2026-10-25T00:30:00Z")]); // první 02:30 (CEST)
    }

    #[test]
    fn dst_wildcard_hour_follows_real_time() {
        let tz: Tz = "Europe/Prague".parse().unwrap();
        let e = CronExpr::parse("30 * * * *").unwrap();
        let occ = e.occurrences(
            &tz,
            utc("2026-10-24T22:00:00Z"),
            utc("2026-10-25T04:00:00Z"),
        );
        // každá skutečná hodina, i opakovaná 02:30
        assert_eq!(occ.len(), 6);
        let e = CronExpr::parse("0 * * * *").unwrap();
        let occ = e.occurrences(
            &tz,
            utc("2026-03-28T22:00:00Z"),
            utc("2026-03-29T04:00:00Z"),
        );
        assert_eq!(occ.len(), 6); // hodinový cron o nic nepřijde
    }

    #[test]
    fn hourly_alias_and_day_steps() {
        let tz: Tz = "UTC".parse().unwrap();
        let e = CronExpr::parse("@hourly").unwrap();
        let occ = e.occurrences(
            &tz,
            utc("2026-10-16T00:00:00Z"),
            utc("2026-10-16T03:00:00Z"),
        );
        assert_eq!(occ.len(), 3);
        let e = CronExpr::parse("0 0 */2 * *").unwrap();
        assert!(e.matches(&local("2026-10-01 00:00")));
        assert!(!e.matches(&local("2026-10-02 00:00")));
    }

    #[test]
    fn several_expressions_union() {
        let tz: Tz = "Europe/Prague".parse().unwrap();
        let s = Schedule::parse("0 13 * * * | 15 23 * * *").unwrap();
        assert_eq!(s.expressions.len(), 2);
        let occ = s.occurrences(
            &tz,
            utc("2026-10-15T22:00:00Z"),
            utc("2026-10-16T22:00:00Z"),
        );
        // 13:00 a 23:15 Praha (CEST) = 11:00 a 21:15 UTC
        assert_eq!(
            occ,
            vec![utc("2026-10-16T11:00:00Z"), utc("2026-10-16T21:15:00Z")]
        );
        // stejná minuta ve dvou výrazech jen jednou
        let s = Schedule::parse("*/30 * * * *|0 * * * *").unwrap();
        let occ = s.occurrences(
            &tz,
            utc("2026-10-16T10:00:00Z"),
            utc("2026-10-16T11:00:00Z"),
        );
        assert_eq!(
            occ,
            vec![utc("2026-10-16T10:30:00Z"), utc("2026-10-16T11:00:00Z")]
        );
        // prázdné části se vynechají, jeden špatný výraz shodí celý rozvrh
        assert_eq!(
            Schedule::parse(" | 0 1 * * * | ")
                .unwrap()
                .expressions
                .len(),
            1
        );
        assert!(Schedule::parse("0 13 * * * | 99 * * * *").is_err());
        assert!(Schedule::parse(" | ").is_err());
        // DST pravidla platí pro každý výraz zvlášť
        let s = Schedule::parse("30 2 * * * | 0 * * * *").unwrap();
        let occ = s.occurrences(
            &tz,
            utc("2026-03-29T00:30:00Z"),
            utc("2026-03-29T01:30:00Z"),
        );
        assert_eq!(occ, vec![utc("2026-03-29T01:00:00Z")]); // 03:00 CEST: hodinový + dohnaný 02:30
    }

    /// Shoda s PHP `dragonmantank/cron-expression` (je-li k dispozici `php` a `vendor/`).
    #[test]
    fn parity_with_php_dragonmantank() {
        let manifest = std::path::Path::new(env!("CARGO_MANIFEST_DIR"));
        let autoload = manifest.join("../vendor/autoload.php");
        let lib = manifest.join("../vendor/dragonmantank/cron-expression");
        if !autoload.exists()
            || !lib.exists()
            || std::process::Command::new("php")
                .arg("-v")
                .output()
                .is_err()
        {
            // CI nastaví LQCRON_REQUIRE_PHP=1, aby se test nepřeskočil potichu
            assert!(
                std::env::var("LQCRON_REQUIRE_PHP").is_err(),
                "php or vendor/dragonmantank missing"
            );
            eprintln!("parity test skipped: php or vendor/dragonmantank missing");
            return;
        }
        let exprs = [
            "* * * * *",
            "*/7 * * * *",
            "5-40/6 */3 * * *",
            "0 8-17 * * 1-5",
            "15 2 1,15 * *",
            "0 0 13 * 5",
            "0 0 L * *",
            "0 12 15W * *",
            "0 6 * * 5L",
            "30 1 * * FRI#2",
            "0 0 * JAN-MAR,OCT *",
            "0 0 * * MON-SUN",
            "0 0 * * 0-7",
            "0 0 * * 5-0",
            "*/90 * * * *",
            "0 */30 * * *",
            "45 23 ? * MON,WED",
            "0 0 1 */2 *",
            "@weekly",
            "@monthly",
            "10,20 4-6 * * 6,0",
            "0 0 31W * *",
            "0 0 1W * *",
            // víc výrazů (CronDefinition::isDue: kterýkoli)
            "0 13 * * * | 15 23 * * *",
            "*/20 * * * 1-5 | 0 */6 * * 0,6",
        ];
        // každou 97. minutu přes 400 dní (pokryje všechny zbytky minut)
        let start = utc("2026-01-01T00:00:00Z");
        let times: Vec<i64> = (0..6000).map(|i| start + i * 97 * 60_000).collect();
        let script = format!(
            r#"<?php
require {autoload:?};
$exprs = json_decode($argv[1], true);
$times = json_decode(file_get_contents('php://stdin'), true);
$out = [];
foreach ($exprs as $e) {{
  $cs = array_map(fn ($x) => new Cron\CronExpression($x), array_values(array_filter(array_map('trim', explode('|', $e)), fn ($x) => $x !== '')));
  $row = '';
  foreach ($times as $t) {{
    $d = new DateTime('@' . intdiv($t, 1000));
    $d->setTimezone(new DateTimeZone('UTC'));
    $due = false;
    foreach ($cs as $c) {{ $due = $due || $c->isDue($d, 'UTC'); }}
    $row .= $due ? '1' : '0';
  }}
  $out[] = $row;
}}
echo json_encode($out);
"#,
            autoload = autoload.to_string_lossy()
        );
        let dir = std::env::temp_dir().join(format!("lqcron-parity-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let sp = dir.join("parity.php");
        std::fs::write(&sp, script).unwrap();
        let mut child = std::process::Command::new("php")
            .arg(&sp)
            .arg(serde_json::to_string(&exprs).unwrap())
            .stdin(std::process::Stdio::piped())
            .stdout(std::process::Stdio::piped())
            .spawn()
            .unwrap();
        {
            use std::io::Write;
            let mut stdin = child.stdin.take().unwrap();
            stdin
                .write_all(serde_json::to_string(&times).unwrap().as_bytes())
                .unwrap();
        }
        let out = child.wait_with_output().unwrap();
        std::fs::remove_dir_all(&dir).ok();
        let rows: Vec<String> = serde_json::from_slice(&out.stdout).expect("php output");
        let tz: Tz = "UTC".parse().unwrap();
        for (i, e) in exprs.iter().enumerate() {
            let c = Schedule::parse(e).unwrap();
            for (j, t) in times.iter().enumerate() {
                let php = rows[i].as_bytes()[j] == b'1';
                let ours = c.is_due(&tz, *t);
                assert_eq!(ours, php, "{e} at {}", crate::util::iso(*t));
            }
        }
    }
}

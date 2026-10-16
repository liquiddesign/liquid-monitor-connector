# Connector v4 — kontrakt mezi `lqcron`, PHP a LQDeckem

> Doplněk k [`v4-local-scheduler.md`](v4-local-scheduler.md) (dál jen „plán“). Plán říká **co a proč**,
> tenhle dokument **přesné tvary dat**: schéma SQLite, `export.json`, soubory běhu, události a HTTP API.
> Kdo mění tvar, mění ho tady — Rust (`scheduler/`), PHP (`src/Scheduler/`) a LQDeck
> (`liquid-monitor-back`) se podle něj řídí všichni tři.

Konvence:

- Časy v SQLite jsou **unixové milisekundy** (`INTEGER`), pokud není řečeno jinak.
- Časy v JSON (export, události, HTTP) jsou **ISO 8601 s milisekundami a offsetem**
  (`2026-10-16T10:00:00.123+00:00`), vždy v UTC.
- UUID jsou v7 (časově řazené), malými písmeny s pomlčkami.
- JSON se kóduje v UTF-8 bez escapování lomítek a Unicode (`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`).

## 1. Soubory ve `stateDir`

| Cesta | Obsah | Kdo zapisuje |
|---|---|---|
| `lqcron.sqlite` (+ `-wal`, `-shm`) | databáze, § 2 | všichni, zakládá a migruje jen `lqcron` |
| `instance_id` | UUID plánovače (jeden řádek) | `lqcron` při založení DB |
| `tick.lock`, `deliver.lock`, `sync.lock` | `flock` zámky | `lqcron` |
| `bin/lqcron-<verze>` | verzovaná kopie binárky (plán § 2) | tick |
| `runs/<run_uuid>.args.json` | argumenty jobu (JSON nebo `null`) | tick při převzetí; smaže `run-job` po skončení |
| `runs/<run_uuid>.log` | stdout + stderr PHP potomka (`O_APPEND`); do téhož souboru jde stderr hlídacího procesu `run-job` (diagnostika `lqcron: …`) | potomek (přes fd), zkracuje `run-job` |
| `runs/<run_uuid>.exit.json` | marker konce běhu, § 3 | PHP `execute` (tmp + `rename`) |
| `services/<name>.log` | výstup hlídané služby | `lqcron` |
| `export.last.json` | kopie posledního přijatého `export.json` (práva `0640`); tick na ni padne, když je export slotu nečitelný (+ `definitions.failed`, `meta.export_error`) | tick |
| `bin/lqcron-<verze>.src` | `velikost:mtime:sha256` binárky, ze které vznikla kopie (kopie se obnoví při změně obsahu) | tick |

Práva: adresáře `0770` (setgid, pokud ho má `stateDir`), soubory `0660`; vše s `umask 0002`.

Mimo `stateDir` drží tick ukazatele na poslední `stateDir` (jeden řádek s cestou):

- `$XDG_STATE_HOME/lqcron/project-<sha256(url|apiKey)[0:16]>` (bez `XDG_STATE_HOME`
  `~/.local/state/lqcron/…`) — podle identity projektu v LQDecku, takže ho vidí i čerstvý
  blue/green slot. Bez `url`/`apiKey` neexistuje.
- `…/lqcron/slot-<sha256(realpath slotu)[0:16]>` a ve slotu `temp/lqcron/state_dir` — jen pro
  nalezení `export.last.json`, když je export slotu nečitelný.

Podle ukazatele projektu (a slotu) tick pozná změnu `stateDir` v NEONu (plán § 4.2), i když v nové
cestě databáze ještě není; s `--move-state` přesune databázi, `instance_id` a `runs/` (jen když
nic neběží).

## 2. Schéma SQLite (verze 2)

Verze 1 je níže (`scheduler/src/schema_v1.sql`), verze 2 je aditivní migrace (§ 2.3);
`min_reader_version` zůstává `1`, PHP tedy nic měnit nemusí.

`PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA foreign_keys=OFF;`
`busy_timeout`: CLI 5000 ms, PHP z FPM 1000 ms. Jen SQL, které umí SQLite 3.24 (plán § 12.1):
žádné `STRICT`, `RETURNING`, `DROP COLUMN`, generované sloupce, `UPSERT` jen ve tvaru
`INSERT … ON CONFLICT(col) DO UPDATE` (3.24 ho umí).

```sql
CREATE TABLE meta (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

CREATE TABLE crons (
  code                 TEXT PRIMARY KEY,
  handler_class        TEXT NOT NULL,
  name                 TEXT,
  description          TEXT,
  schedule             TEXT,              -- efektivní výraz pro prostředí; NULL = neplánuje se (jen na vyžádání)
  timeout              INTEGER,           -- s; NULL = 1000, 0 = bez limitu
  repeat_count         INTEGER NOT NULL DEFAULT 0,
  concurrency_mode     TEXT NOT NULL DEFAULT 'exclusive',  -- exclusive|parallel|fully_parallel|independent
  max_queue_size       INTEGER,           -- NULL = 10
  resources            TEXT NOT NULL DEFAULT '[]',         -- JSON list<string>
  after_code           TEXT,              -- code rodiče (plán § 6.4)
  missed               TEXT NOT NULL DEFAULT 'skip',       -- skip|once
  missed_grace_minutes INTEGER NOT NULL DEFAULT 5,
  defined              INTEGER NOT NULL DEFAULT 1,         -- 0 = v posledním exportu chybí
  local_active         INTEGER NOT NULL DEFAULT 1,         -- lqcron cron disable/enable
  remote_active        INTEGER NOT NULL DEFAULT 1,         -- příkaz cron.set_active
  last_scheduled_for   INTEGER,           -- poslední zařazený výskyt (ms), brání dvojímu zařazení
  updated_at           INTEGER NOT NULL
);

CREATE TABLE jobs (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  uuid          TEXT NOT NULL UNIQUE,     -- uuid tohoto zařazení
  origin_uuid   TEXT NOT NULL,            -- uuid původního jobu; opakování ho dědí (pro `after` a LQDeck)
  cron_code     TEXT NOT NULL,
  arguments     TEXT,                     -- JSON nebo NULL
  attempt       INTEGER NOT NULL DEFAULT 1,
  source        TEXT NOT NULL,            -- schedule|missed|app|cli|remote|after|retry
  scheduled_for INTEGER,                  -- výskyt rozvrhu (jen schedule|missed)
  available_at  INTEGER NOT NULL,
  created_at    INTEGER NOT NULL
);
CREATE INDEX jobs_cron ON jobs (cron_code);
CREATE INDEX jobs_available ON jobs (available_at);

CREATE TABLE runs (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  uuid             TEXT NOT NULL UNIQUE,
  job_uuid         TEXT NOT NULL,
  origin_uuid      TEXT NOT NULL,
  cron_code        TEXT NOT NULL,
  attempt          INTEGER NOT NULL,
  max_attempts     INTEGER NOT NULL,      -- repeat_count + 1 v okamžiku převzetí
  source           TEXT NOT NULL,
  scheduled_for    INTEGER,
  job_created_at   INTEGER NOT NULL,
  timeout          INTEGER,               -- efektivní: NULL v crons → 1000; 0 = bez limitu
  concurrency_mode TEXT NOT NULL,
  resources        TEXT NOT NULL DEFAULT '[]',
  state            TEXT NOT NULL,         -- claimed|starting|running|stopping|finished|failed|timeout|cancelled|lost
  stop_reason      TEXT,                  -- timeout|cancel|lost — co vyvolalo stopping
  claimed_at       INTEGER NOT NULL,
  supervisor_pid   INTEGER,
  supervisor_start INTEGER,               -- starttime z /proc/<pid>/stat (pole 22, clock ticks)
  pid              INTEGER,
  pid_start        INTEGER,
  app_path         TEXT,                  -- realpath slotu
  started_at       INTEGER,               -- wall clock, zapisuje PHP při running
  finished_at      INTEGER,
  heartbeat_at     INTEGER,               -- CLOCK_BOOTTIME v ms (monotónní)
  exit_code        INTEGER,
  signal           INTEGER,
  max_rss_kb       INTEGER,
  result           TEXT,                  -- JSON
  error            TEXT,                  -- JSON {class, message, trace}
  final            INTEGER,               -- 1 = poslední pokus (vyplní se s finálním stavem)
  shadow           INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX runs_state ON runs (state);
CREATE INDEX runs_cron ON runs (cron_code);

CREATE TABLE run_events (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  run_uuid   TEXT NOT NULL,
  type       TEXT NOT NULL,               -- progress
  payload    TEXT,                        -- JSON
  created_at INTEGER NOT NULL
);
CREATE INDEX run_events_run ON run_events (run_uuid);

CREATE TABLE outbox (
  seq          INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id     TEXT NOT NULL UNIQUE,
  type         TEXT NOT NULL,
  run_uuid     TEXT,
  payload      BLOB NOT NULL,                  -- Rust zapisuje BLOB, PHP řetězec (TEXT); čtenář unese obojí
  encoding     TEXT NOT NULL DEFAULT 'json',   -- json|zstd (zstd jen z Rustu)
  occurred_at  INTEGER NOT NULL,
  created_at   INTEGER NOT NULL,
  attempts     INTEGER NOT NULL DEFAULT 0,
  poisoned_at  INTEGER,
  last_tried_at INTEGER
);
CREATE INDEX outbox_run ON outbox (run_uuid);

CREATE TABLE outbox_rejected (
  seq         INTEGER PRIMARY KEY,         -- původní seq
  event_id    TEXT NOT NULL UNIQUE,
  type        TEXT NOT NULL,
  run_uuid    TEXT,
  payload     BLOB NOT NULL,
  encoding    TEXT NOT NULL DEFAULT 'json',
  occurred_at INTEGER NOT NULL,
  created_at  INTEGER NOT NULL,
  rejected_at INTEGER NOT NULL,
  reason      TEXT NOT NULL
);

CREATE TABLE commands (
  command_id TEXT PRIMARY KEY,
  seq        INTEGER NOT NULL,
  type       TEXT NOT NULL,
  payload    TEXT NOT NULL,               -- JSON
  issued_at  INTEGER NOT NULL,
  expires_at INTEGER,
  fetched_at INTEGER NOT NULL,
  applied_at INTEGER,
  result     TEXT                         -- applied|failed:<reason>
);

CREATE TABLE services (
  name           TEXT PRIMARY KEY,
  mode           TEXT NOT NULL,            -- watchdog|keepalive
  pid            INTEGER,
  pid_start      INTEGER,
  app_path       TEXT,
  started_at     INTEGER,
  restarts       INTEGER NOT NULL DEFAULT 0,
  last_exit_at   INTEGER,
  last_exit_code INTEGER,
  backoff_until  INTEGER
);
```

### 2.1 Klíče `meta`

| Klíč | Hodnota |
|---|---|
| `schema_version` | `2` |
| `min_reader_version` | `1` |
| `instance_id` | UUID (totéž co soubor `instance_id`) |
| `created_at` | ms |
| `hostname` | hostname posledního ticku |
| `last_tick_at` | ms, wall clock posledního ticku, který plánoval (plán § 6.1) |
| `last_tick_seen_at` | ms, wall clock posledního ticku vůbec (i když hodiny šly pozpátku) |
| `tick_refused` | prázdné, nebo důvod, proč tick odmítá běžet (`schema`, `state_dir_changed`, `other_host`) |
| `state_dir` | absolutní cesta, pod kterou byla DB naposledy otevřena tickem |
| `app_path` | realpath slotu posledního ticku |
| `export_hash`, `export_at`, `export_mode` | poslední aplikovaný export (`local` / `shadow`) |
| `export_error` | prázdné, nebo poslední chyba `sync` (JSON `{at, message}`) |
| `pause_deploy` | prázdné, nebo JSON `{since, ttl_until, drain_until}` (ms) |
| `pause_remote` | prázdné, nebo JSON `{since, command_id}` |
| `last_resume_at` | ms (pro měření stáří jobů, plán § 6.2) |
| `heartbeat` | JSON snímek `scheduler.heartbeat` (§ 5.4), přepisuje tick |
| `deliver_last_ok_at` | ms posledního úspěšného HTTP 2xx s potvrzením |
| `deliver_last_error` | JSON `{at, message, status}` |
| `commands_fetched_at` | ms |
| `starving` | JSON list kódů, na které čeká bariéra (plán § 6.3) |
| `disk_low` | `1`, když má `stateDir` < 500 MB volného místa, jinak prázdné; přepisuje tick. PHP pak **nezapisuje `run.progress` do outboxu** (plán § 8.1 — stavové události ano) |
| `sync_fingerprint`, `sync_attempt_at` | otisk slotu / `installed.json` / NEONů a čas posledního pokusu `sync` (ms); tick podle nich spouští `sync` (plán § 5.2) |
| `retention_at` | ms posledního úklidu (retence běží nejvýš jednou za hodinu) |
| `max_batch` | `max_batch` z poslední odpovědi `v4/events` |
| `deliver_rejections` | prázdné, nebo JSON `{event_id: {"n": počet, "next_at": ms}}` — dočasná odmítnutí (`permanent: false`) v dávkách, kde LQDeck jiné události přijal. Událost se do `next_at` neposílá (vlastní backoff); při `n = 5` jde samostatně na izolační sondu a odmítne-li ji LQDeck i tak, dostane `poisoned_at`. Dávka bez jediného přijetí se bere jako výpadek (backoff, nepočítá se) (plán § 8.1) |
| `pause_missed` | prázdné, nebo JSON `{cron_code: {"count", "from", "to"}}` — výskyty, které během pauzy nešly zařadit; první tick po pauze je vyhodnotí podle `missed` (jeden `run.missed`, u `once` jeden běh) |

### 2.2 Kdo co zapisuje

- **PHP enqueue** (`Cron::schedulePullJob`): `INSERT jobs` + `INSERT outbox (job.queued)` v jedné
  transakci; při plné frontě jen `INSERT outbox (job.rejected)` a výjimka `QueueFull`.
- **PHP `execute`**: CAS `runs.state starting → running` + `pid`, `pid_start` (pole 22
  `/proc/self/stat`, clock ticks — stejná jednotka jako `supervisor_start`), `started_at`,
  `app_path` + `INSERT outbox (run.started)` v jedné transakci. **Když CAS nevyhraje** (řádek
  mezitím tick vrátil do fronty nebo běh zrušil), `execute` skončí bez spuštění handleru a bez
  markeru. Průběh: `INSERT run_events` +
  nejvýš jednou za 10 s `INSERT outbox (run.progress)`. Na konci jen marker (§ 3), **žádný
  finální stav** — ten zapisuje Rust.
- Všechno ostatní zapisuje Rust. `runs.app_path` vyplní už tick při převzetí (slot, ze kterého
  spouští `run-job`); `execute` ho při `running` přepíše na svůj realpath.

PHP před zápisem ověří `meta.min_reader_version <= 1` (verze schématu, kterou PHP zná);
jinak `SchedulerUnavailable`.

### 2.3 Verze 2 (aditivní, `scheduler/src/schema_v2.sql`)

```sql
ALTER TABLE runs ADD COLUMN pgid INTEGER;        -- skupina procesů PHP; zapisuje hlídač po spuštění
ALTER TABLE runs ADD COLUMN pgid_start INTEGER;  -- starttime leadera skupiny (jako supervisor_start)
ALTER TABLE runs ADD COLUMN boot_id TEXT;        -- /proc/sys/kernel/random/boot_id při `starting`
ALTER TABLE commands ADD COLUMN ack_event_id TEXT; -- event_id potvrzení command.applied/failed
```

- Pid a čas startu platí jen v bootu `boot_id`. Úklid (`run-job --adopt`, tick) zabíjí skupinu jen
  když je prokazatelně tohoto běhu: stejný boot a leader s pid = `pgid` buď neexistuje, nebo má
  čas startu `pgid_start` (jinak je pid recyklovaný). Běh z jiného bootu se uzavře bez zabíjení.
  Řádky bez `pgid` (v1) spadnou na `pid` + `pid_start`, a to jen s přesnou shodou startu.
- Skupinu eviduje hlídač, ne PHP: pokryje i `phpBinary` wrapper, který PHP spouští jako potomka.
- `commands` se maže až 30 dní po provedení **a** po doručení potvrzení (`ack_event_id` už není
  v `outbox` ani `outbox_rejected`); dřív by LQDeck nepotvrzený příkaz poslal znovu a provedl se dvakrát.
- Aditivní migraci smí udělat kterýkoli proces `lqcron` při otevření (ne PHP); databázi zakládá
  jen tick a `sync`.

## 3. Marker konce běhu `runs/<run_uuid>.exit.json`

Zapisuje PHP `execute` (i ze shutdown funkce při fatální chybě) atomicky: zápis do
`<uuid>.exit.json.tmp` a `rename`.

```json
{
  "run_uuid": "0192…",
  "status": "finished",             // finished | failed
  "explicit_fail": false,           // true = handler zavolal Cron::failJob()
  "result": {"imported": 120},      // poslední data z finishJob(), nebo failJob() data; null
  "result_truncated": false,
  "error": {                        // jen status=failed z výjimky / fatální chyby, jinak null
    "class": "RuntimeException",
    "message": "…",
    "trace": "#0 …"                 // getTraceAsString(), bez argumentů (zend.exception_ignore_args)
  },
  "peak_memory_mb": 182,
  "finished_at": "2026-10-16T10:00:00.123+00:00"
}
```

Výsledek nad 256 kB JSON nahradí `{"truncated": true, "bytes": N, "head": "<prvních 4 kB>"}`
a `result_truncated: true`. `error.message` a `error.trace` mají každé strop **64 kB** (oříznuto na hranici
znaku UTF-8 s poznámkou `[… truncated, N B more]`); stejný strop drží Rust u chyb, které skládá sám.
Marker se kóduje tak, aby zápis nikdy nespadl na datech handleru: neplatné UTF-8 se nahradí
(`JSON_INVALID_UTF8_SUBSTITUTE`), `NaN` / `INF` se zapíší jako `0` (`JSON_PARTIAL_OUTPUT_ON_ERROR`).
Ztracený marker by úspěšný běh změnil na selhaný a spustil ho znovu.

O finálním stavu rozhoduje Rust (plán § 7.4): marker `failed` → `failed`; marker `finished`
a exit 0 → `finished`; nenulový exit nebo signál → `failed` i s markerem `finished`; marker chybí
→ `failed` (PHP skončilo bez zápisu, typicky fatální chyba před registrací shutdown funkce).

## 4. `export.json`

`php vendor/bin/monitor-worker export-definitions --output=temp/lqcron/export.json` (spouští
`lqcron sync` v cwd slotu). Zápis atomický (tmp + `rename`), práva `0640`. Bez `--output` vypíše
JSON na stdout. Exit 0 = úspěch; jinak chyba na stderr a soubor se nemění.

```json
{
  "format": 1,
  "generated_at": "2026-10-16T10:00:00.000+00:00",
  "connector_version": "4.0.0",
  "app_path": "/srv/abel/releases/blue",
  "php_binary": "/usr/bin/php8.5",
  "config_files": ["/srv/abel/releases/blue/app/config/config.neon", "…/local.neon"],
  "hash": "sha256 hex nad kanonickým JSON polí config + crons",
  "config": {
    "enabled": true,
    "shadow": false,
    "environment": "production",
    "state_dir": "/srv/abel/shared/lqcron",
    "timezone": "Europe/Prague",
    "max_parallel": 3,
    "starvation_seconds": 300,
    "run_log_max_bytes": 52428800,
    "kill_grace_seconds": 30,
    "report_log_tail": true,
    "log_redactor": false,
    "remote_commands": ["cron.set_active", "cron.run_now", "run.cancel"],
    "resources": {"qi": 1, "product-write": 1},
    "services": {
      "productsDaemon": {"command": ["php", "vendor/…/products-daemon-watch.php"], "mode": "watchdog"}
    },
    "php_binary": "/usr/bin/php8.5",
    "bootstrap": "/srv/abel/releases/blue/bin/monitor-worker-bootstrap.php",
    "url": "https://lqdeck.example.org/api/connector",
    "api_key": "…",
    "verify_tls": true
  },
  "crons": [
    {
      "code": "mergadoPrices",
      "handler_class": "App\\Eshop\\Cron\\MergadoPricesHandler",
      "name": "Mergado ceny",
      "description": null,
      "schedule": "30 * * * *",
      "schedule_source": "schedules.production",
      "timeout": 600,
      "repeat_count": 1,
      "concurrency_mode": "exclusive",
      "max_queue_size": null,
      "resources": ["qi"],
      "after": null,
      "missed": "skip",
      "missed_grace_minutes": 5
    }
  ],
  "warnings": ["mergadoPrices: timeout 0 = bez limitu"]
}
```

- `schedule` je **efektivní** výraz pro `config.environment`: `schedules[environment]` ?? `schedule`;
  `null`, když `environments` prostředí nepovoluje. Cron s `schedule: null` jde dál zařadit
  z aplikace, CLI nebo LQDecku, jen se neplánuje.
- **Víc výrazů:** `schedule` může nést několik cron výrazů oddělených ` | ` (svislítko s mezerami,
  PHP `PullCron::EXPRESSION_SEPARATOR`), např. `"0 13 * * * | 15 23 * * *"` — pro crony, které běží
  v časech, jež jeden výraz nezapíše. Čtenář dělí podle `|`, ořízne mezery a prázdné části vynechá.
  Výskyty cronu jsou **sjednocení** výskytů všech výrazů (stejná minuta jen jednou; PHP
  `CronDefinition::isDue` = splatný kterýkoli výraz). Všechna pravidla plánu § 6.1 platí pro cron
  jako celek: nejvýš jeden job na cron a tick, jeden souhrn `run.missed`, `missed: once` zařadí
  jediný běh, DST se vyhodnocuje pro každý výraz zvlášť. Je-li nevalidní **kterýkoli** výraz, je
  nevalidní celý export (`lqcron` ho odmítne a platí poslední platná sada).
- `schedule_source`: `schedule` | `schedules.<env>` | `environments` (vypnuto prostředím).
- `after` je **code** rodiče (export převádí třídu na code).
- `verify_tls`: `true` | `false` | cesta k CA bundlu.
- `hash` počítá PHP; Rust ho bere jako neprůhledný řetězec.

## 5. Události

### 5.1 Obálka

V outboxu leží jen `payload`; obálku skládá `deliver` z řádku:

```json
{
  "event_id": "0192…",
  "seq": 1234,
  "type": "run.finished",
  "occurred_at": "2026-10-16T10:00:00.123+00:00",
  "payload": { … }
}
```

### 5.2 Payloady

Společné pro `run.*`: `run_uuid`, `job_uuid`, `origin_uuid`, `cron_code`, `attempt`, `max_attempts`,
`source`, `scheduled_for` (nebo `null`), `app_path`.

| Typ | Payload navíc |
|---|---|
| `definitions.sync` | `mode` (`local`/`shadow`), `hash`, `environment`, `timezone`, `connector_version`, `crons` (pole jako v exportu + `local_active`, `remote_active`). Posílá se při změně hashe nebo režimu exportu **a** po příkazu `definitions.resync` (aktuálně aplikovaná sada, stejný `hash`; ve stínovém režimu `mode: shadow`) |
| `definitions.failed` | `message`, `exit_code`, `stderr_tail` (max 4 kB). Posílá ho `sync` (selhaný PHP export) i tick (nečitelný `export.json` slotu → `exit_code: null`); stejná chyba jen jednou |
| `job.queued` | `job_uuid`, `origin_uuid`, `cron_code`, `source`, `attempt`, `available_at`, `has_arguments` (bool — argumenty se do LQDecku neposílají) |
| `job.rejected` | `cron_code`, `source`, `reason` (`queue_full`), `queue_size`, `max_queue_size` |
| `job.stale` | `job_uuid`, `cron_code`, `waited_seconds` |
| `run.started` | `started_at`, `pid`, `hostname` |
| `run.progress` | `data` (JSON z `progressJob()`, max 16 kB, jinak `{"truncated": true}`) |
| `run.finished` | `final` (vždy `true`; když chybí, LQDeck bere `true`), `started_at`, `finished_at`, `duration_ms`, `max_rss_kb`, `ram_mb` (peak PHP z markeru), `exit_code`, `result`, `result_truncated` |
| `run.failed` / `run.timeout` / `run.lost` / `run.cancelled` | `final` (bool, **povinné** — bez něj LQDeck událost odmítne `invalid`, `permanent: true`), `outcome` (`failed`/`timeout`/`lost`/`cancelled`), `started_at` (nebo `null`), `finished_at`, `duration_ms`, `max_rss_kb`, `ram_mb`, `exit_code`, `signal`, `error` (`{class, message, trace}` nebo `null`), `result`, `log_tail` (nebo `null`), `log_tail_truncated`. Když chybu nedodal handler, sestaví ji Rust s `class` `LqcronNoMarker` / `LqcronExitStatus` / `LqcronTimeout` / `LqcronLost` / `LqcronSpawnError` |
| `run.missed` | `cron_code`, `count`, `from`, `to` (ISO) |
| `run.shadow` | `cron_code`, `scheduled_for`, `would_start_at` |
| `service.started` / `service.exited` / `service.restarted` | `service`, `mode`, `pid`, `exit_code` (ve 4.0 vždy `null` — služba není potomek ticku), `app_path`, `restarts`. `watchdog` hlásí jen první `service.started`, ne každé spuštění |
| `command.applied` / `command.failed` | `command_id`, `command_type` (vč. `definitions.resync`, který se potvrzuje vždy `command.applied`), `reason` (jen failed: `expired`, `not_allowed`, `unknown_cron`, `run_not_active`, `queue_full` — `cron.run_now` při plné frontě, `invalid` — i nečitelné `expires_at`). `scheduler.resume` je povolený, když `remote_commands` obsahuje `scheduler.pause` nebo `scheduler.resume`; ve stínovém režimu jsou `cron.run_now` a `run.cancel` `not_allowed` |
| `scheduler.takeover` | `previous_hostname`, `hostname` (plán § 11.3) |
| `scheduler.pause_expired` | `since`, `ttl_until` (pauza s TTL se sama zrušila, plán § 11.2) |

`log_tail`: posledních 64 kB logu běhu (po redakci, plán § 7.4), jen u `failed` / `timeout` /
`lost` a jen s `report_log_tail`. V outboxu může být payload komprimovaný (`encoding = zstd`);
do LQDecku jde vždy jako čisté JSON.

### 5.3 Pravidlo `final`

`final = true`, když se po tomto výsledku už nic nezopakuje: `finished`, `cancelled`, nebo
`attempt >= max_attempts`, nebo cron už není definovaný. Opakovaný pokus má `attempt + 1`,
stejný `origin_uuid`, nový `job_uuid` i `run_uuid`.

LQDeck podle `final` rozhoduje o hlášení: alert i triage task vzniká jen z `final: true`
selhání (`failed` / `timeout` / `lost`), nikdy z mezipokusu ani z `cancelled` (to je vědomé
rozhodnutí člověka). Proto je `final` u selhání povinné — LQDeck ho nehádá.

### 5.4 `scheduler.heartbeat`

Není v outboxu; tick ho přepisuje v `meta.heartbeat` a `deliver` ho přikládá ke každé dávce.

```json
{
  "instance_id": "0192…",
  "hostname": "abel-prod-1",
  "version": "4.0.0",
  "schema_version": 1,
  "mode": "local",                    // local | shadow | disabled (scheduler.enabled: false, jen úklid)
  "generated_at": "…",
  "last_tick_at": "…",
  "queue_length": 3,
  "running": ["run_uuid", "…"],
  "outbox_size": 12,
  "oldest_outbox_at": "…|null",
  "rejected_count": 0,
  "poisoned_count": 0,
  "paused": {"deploy": {"since": "…", "ttl_until": "…|null", "drain_until": "…|null"}, "remote": null},
  "starving": [],
  "clock_skew_seconds": 0,
  "tick_refused": null,
  "export_error": null
}
```

Co z něj LQDeck používá (ukládá celý snímek do `local_schedulers.heartbeat`):

- `instance_id` (jinak `instance_id` z těla požadavku) — hlídání dvou plánovačů: alert, když se
  hlášení vrátí k předchozí instanci do 5 minut (jednorázové převzetí novou instancí se nehlásí),
- `generated_at` + `running` — smíření běhů: `Started` lokální log, který chybí ve třech po sobě
  jdoucích **nových** snímcích (podle `generated_at`; tentýž snímek přiložený k více dávkám se
  počítá jednou) a byl nahlášen před `generated_at`, LQDeck uzavře jako `failed` s outcome
  `lost_reconciled`. Bez klíče `running` se nesmiřuje nic (prázdné pole znamená „nic neběží“),
- `paused.deploy.since` / `paused.remote.since` — alert „Plánovač je pozastavený“ po 15 min,
- `hostname`, `version`, `mode`, `queue_length`, `outbox_size`, `rejected_count`,
  `poisoned_count` — zobrazení v adminu a MCP `get_project`.

## 6. HTTP API LQDecku

Base URL = `config.url` z exportu (`…/api/connector`). Hlavičky: `X-Connector-Version: <verze>`,
`X-Api-Key: <api_key>`, `Accept: application/json`, `Content-Type: application/json`,
`User-Agent: lqcron/<verze>`. Middleware `api.key` v LQDecku čte klíč z hlavičky i z query.

Společné pro oba endpointy:

- `X-Connector-Version`: rozhoduje jen počáteční číslo (`4.0.0-dev` → 4). LQDeck přijímá major
  2, 3 a 4 (`config/connector.php`); jiný major → `426`.
- Chybějící nebo neplatný klíč → `401`.
- Throttle po API klíči (`monitor.local_scheduler_requests_per_minute`, default 120 požadavků
  za minutu) → `429` s `Retry-After` v sekundách.
- `/api/connector/v4/*` **není kritický provoz** (`App\Support\Capacity\CriticalTraffic::EXCLUDED`,
  edge Caddy snippet): obsluhuje ho běžný Octane pool, kde má každý DB dotaz strop 10 s.
  Zpoždění nevadí — lokální crony na LQDecku nezávisí — a dohánění po výpadku nesmí vytlačit
  příjem chyb a push lifecycle.

### 6.1 `POST /v4/events`

Request:

```json
{
  "instance_id": "0192…",
  "sent_at": "2026-10-16T10:00:05.000+00:00",
  "heartbeat": { … } ,
  "events": [ {obálka § 5.1}, … ]
}
```

`events` je povinné pole a smí být prázdné (jen heartbeat). Max 200 událostí / 2 MB nebo
`max_batch` z poslední odpovědi. `sent_at` není povinné, ale bez něj LQDeck nepozná opožděné
události ani ujeté hodiny. Nevalidní tělo (např. `events` není pole) → `422`.

Response `200`:

```json
{
  "accepted": ["event_id", "…"],
  "rejected": [{"event_id": "…", "reason": "…", "permanent": true}],
  "max_batch": 200
}
```

- Tělo požadavku LQDeck nemění (bez ořezávání mezer a převodu `""` na `null`), `log_tail`,
  `trace` a `result` se ukládají tak, jak přišly (v mezích ořezu § 8.4 plánu).
- `accepted` je v pořadí událostí v požadavku. Každá událost se zpracuje ve vlastní transakci
  a potvrdí se až po commitu; heartbeat se zpracuje až po událostech a jeho selhání potvrzení
  neovlivní.
- Událost, kterou LQDeck už má (`connector_events.event_id`), je `accepted` a znovu se
  neaplikuje (idempotence). Retence `connector_events` je 90 dní — událost doručená znovu po
  delší době se aplikuje podruhé.
- Událost bez `event_id` (nebo prvek pole, který není objekt) LQDeck **přeskočí**: není ani
  `accepted`, ani `rejected`. Binárka takovou neposílá.
- Důvody v `rejected`:

  | `reason` | `permanent` | Kdy |
  |---|---|---|
  | `unknown_type` | `true` | neznámý `type` |
  | `invalid` | `true` | chybí `type` / `occurred_at` / objekt `payload`; u `run.*` chybí `run_uuid` nebo `cron_code`; u `run.failed` / `run.timeout` / `run.lost` / `run.cancelled` chybí `final` nebo `outcome` není jedno z `failed` / `timeout` / `lost` / `cancelled` (`handover` a `lost_reconciled` jsou interní stavy LQDecku); u `definitions.sync` neznámý `mode` nebo chybí `crons`; u `command.*` chybí `command_id` |
  | `run_uuid_conflict` | `true` | `run_uuid` už v LQDecku patří jinému cronu |
  | `error` | `false` | neošetřená výjimka při zpracování (např. DB) — pošle se znovu |
  | `conflict` | `false` | souběžný zápis téhož běhu (unikátní klíč) — pošle se znovu |

- `run.*` pro cron, který LQDeck nezná → cron se založí z payloadu (`code`, `name = code`,
  režim `local`, `active`) a událost se přijme. U projektu vráceného do pull
  (`local_blocked_at`) se založí v režimu `pull`.
- `definitions.sync` s `mode: local` založí / aktualizuje crony (metadata z kódu, `active` se
  nemění) a přepne je na `local` (plán § 13.4); u projektu vráceného do pull, nebo s
  `mode: shadow`, se jen uloží payload do `connector_events`. `schedule` smí obsahovat víc
  výrazů oddělených ` | ` (LQDeck ho drží jako TEXT); `description` a `schedule` LQDeck ořízne
  na 65 535 bajtů.
  - **Srovnání vypínače:** u každého cronu z aplikovaného exportu LQDeck porovná `remote_active`
    se svým `active`. Liší-li se a žádný `cron.set_active` pro ten cron ještě nečeká na potvrzení,
    vydá systémový `cron.set_active` (bez uživatele, auditovaný jako každý příkaz). Pokryje cron
    vypnutý ještě v režimu pull, nový `stateDir` (nová instance, `remote_active` zpět 1)
    i návrat do pull a zpět.
  - Cron, který byl v LQDecku smazaný a export ho obsahuje, se obnoví **vypnutý** (smazání
    znamenalo „nespouštět“) a srovnání vypínače to plánovači pošle. Lokální cron v LQDecku smazat
    nejde (admin ani MCP) — zdrojem pravdy je kód; zastavuje se vypínačem.
- **Opožděné události:** zpoždění = `sent_at − occurred_at` (obojí z hodin serveru projektu).
  Nad `monitor.local_cron_late_event_seconds` (default 900 s) se běh uloží s `delivered_late`
  a nespustí vlastní alert ani triage. Opožděná selhání se sčítají za projekt a jeden souhrn
  odejde, až 2 min (`monitor.local_scheduler_late_summary_quiet_seconds`) žádné další nepřišlo —
  jeden souhrn za celé dohánění, ne za dávku. Triage z opožděných: nejvýš jeden otevřený task na
  cron (plán § 8.2).
- `413` celé dávky — když má víc událostí než `max_batch`; tělo nese
  `{"message": "…", "max_batch": N}`, nic z dávky se nezpracuje. Deliver dávku rozpůlí
  (tělo 413 nečte). `429` + `Retry-After` → řízení toku.
- `426` → LQDeck ještě nepřijímá major 4 (opakuje se donekonečna, plán § 8.1).

### 6.2 `GET /v4/commands?instance_id=…`

Response `200`:

```json
{
  "commands": [
    {
      "command_id": "0192…",
      "seq": 17,
      "type": "cron.set_active",
      "payload": {"cron_code": "mergadoPrices", "active": false},
      "issued_at": "…",
      "expires_at": null
    }
  ]
}
```

Vrací všechny nevyřízené příkazy projektu (bez `command.applied` / `command.failed` potvrzení),
seřazené podle `seq` (roste per projekt) — **včetně prošlých**: plánovač je musí potvrdit jako
`command.failed {reason: expired}`, do té doby je LQDeck vrací. Časy jsou UTC s milisekundami,
`payload` je vždy JSON objekt (i prázdný `{}`). Parametr `instance_id` LQDeck zatím nevyužívá.
Potvrzení neznámého `command_id` LQDeck přijme a ignoruje. Payloady a platnost:

| Typ | Payload | `expires_at` | Kdo ho v LQDecku vydá |
|---|---|---|---|
| `cron.set_active` | `cron_code`, `active` (bool) | `null` | přepínač „Aktivní“ u lokálního cronu (admin, bulk, MCP `save_cron`), `Update:Cron` |
| `cron.run_now` | `cron_code` | +15 min | admin „Spustit teď“, MCP `run_cron_now`, `Run:Cron` |
| `run.cancel` | `run_uuid` | +15 min | admin „Zrušit běh“ (`job-logs.fail`), MCP `fail_job_log`, `Run:Cron` |
| `scheduler.pause` | `{}` | `null` | admin / MCP `set_local_scheduler_pause`, jen super-admin |
| `scheduler.resume` | `{}` | `null` | dtto |
| `definitions.resync` | `{}` | `null` | LQDeck při zrušení blokace „Vrátit crony do pull“ (`local_blocked_at`, plán § 13.4). Vždy povolený (není v `remoteCommands`, jen znovu pošle stav) |

LQDeck může nevyřízený příkaz uzavřít sám: při „Vrátit crony do pull“ označí všechny čekající příkazy projektu
(kromě `definitions.resync`) jako `superseded`. Takový příkaz už `GET /v4/commands` nevrací a pozdní
`command.applied` / `command.failed` k němu LQDeck ignoruje — zapomenutý plánovač tak po návratu nic nespustí.

## 7. Rozhraní procesů

### 7.1 `run-job` → PHP `execute`

```
<php_binary> vendor/bin/monitor-worker execute --local --run-id=<run_uuid>
    --cron-code=<code> --arguments-file=<stateDir>/runs/<uuid>.args.json
    --bootstrap=<config.bootstrap>
```

cwd = `app_path` slotu. Env: `LQCRON_RUN_ID`, `LQCRON_STATE_DIR`, `LQCRON_TIMEOUT` (s, `0` = bez
limitu), `LQCRON_LOG_FILE`. stdout/stderr = `runs/<uuid>.log` (`O_APPEND`). Nová session
(`setsid`). Exit 0 = handler doběhl bez výjimky.

### 7.2 Redakce logu

S `log_redactor: true` pustí Rust před uložením konce logu
`<php> vendor/bin/monitor-worker redact-log --bootstrap=…` (stdin = konec logu, stdout = redigovaný
text, timeout 30 s). Selže-li, jde místo konce logu text `[log tail withheld: redaction failed]`.

### 7.3 `enqueue-due` a `plan` (PHP, bez `lqcron`)

```
php vendor/bin/monitor-worker enqueue-due --bootstrap=… [--minute=2026-10-16T10:00]
php vendor/bin/monitor-worker plan --bootstrap=… [--environment=production] [--hours=24] [--json]
```

`--minute` jen pro testy a ruční dohnání; jinak se minuta zachytí při startu procesu.

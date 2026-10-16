# Přechod z connectoru 3 na 4

Návod pro libovolný Nette projekt, který dnes používá connector 3 (push crony, pull worker,
crunz). Connector 4 přináší:

- **rozvrh cronů jen v atributu `#[PullCron]`** — co v něm stojí, to běží (i bez lokálního plánovače),
- **lokální plánovač `lqcron`** (volitelný) — crony běží i při výpadku LQDecku,
- podporu **PHP 8.0**.

Přechod má dvě samostatné fáze. **Fáze A** (rozvrhy z atributu) je bezpečná a doporučená pro
všechny projekty s pull crony. **Fáze B** (lokální plánovač) přichází po ní, nejdřív na testu.
Push crony (`scheduleOrStartJob`, `url` u cronu) se nemění a mohou zůstat.

Návrh a pravidla: [`docs/v4-local-scheduler.md`](docs/v4-local-scheduler.md),
přesné tvary dat: [`docs/v4-contract.md`](docs/v4-contract.md). Stejný návod je v nápovědě
LQDecku (sekce „Přechod na connector 4“) a vrací ho i MCP.

## 0. Předpoklady

- **LQDeck přijímá connector 4.** Bez toho dostane projekt na `^4` odpověď 426 na *všechna*
  volání (i chyby a push crony). Ověření: odpověď LQDecku nese hlavičku
  `X-Connector-Supported-Versions` s `4`.
- PHP **≥ 8.0** (CLI i FPM).
- Laravel konektor (`liquid-monitor-connector-laravel`) verzi 4 zatím nemá — tento návod je pro Nette.

## 1. Composer a změny API

```bash
composer require liquiddesign/liquid-monitor-connector:^4.0
```

Do vydání 4.0 se `^4.0` vyřeší na větev `4.x` jen v projektu s `"minimum-stability": "dev"`
a `"prefer-stable": true`.

Najdi v projektu použití změněného API:

```bash
grep -rnE "ConcurrencyModeType|HealthCheckStatusEnum|getLastCronJobLog" app src --include=*.php
```

### `ConcurrencyModeType` už není enum

Třída se stringovými konstantami se stejnými jmény. Zápis v atributu se nemění:
`#[PullCron(schedule: '*/5 * * * *', concurrencyMode: ConcurrencyModeType::Independent)]`.

| Dřív | Teď |
|---|---|
| `ConcurrencyModeType::Exclusive` (enum case) | `'exclusive'` (string) |
| `$mode->value` | `$mode` |
| `ConcurrencyModeType::from($v)` → case | `from($v)` → string (`\ValueError` při neznámé hodnotě) |
| parametr `ConcurrencyModeType $mode` | `string $mode` |

Vlastnosti `PullCron` jsou `public` bez `readonly` (čtení beze změny).

### `HealthCheckStatusEnum` už není enum

Třída s int konstantami: `INFO = -1`, `OK = 0`, `WARNING = 1`, `ERROR = 2`, `CRITICAL = 3`.
JSON výstup health checku (`'status' => 'OK'`) se nemění.

| Dřív | Teď |
|---|---|
| `$status->value` | `$status` (pozor: na intu `->value` nespadne, jen varuje a vrátí `null`) |
| `$status->name` | `HealthCheckStatusEnum::name($status)` |
| `from($v)` / `tryFrom($v)` → case | → `int` / `?int` |
| `cases()` → list cases | → `array<string, int>` (jméno ⇒ hodnota) |
| `HealthCheckData(..., HealthCheckStatusEnum $status)`, `getStatus()` | `int` |

### Další

- `Cron::getLastCronJobLog()`: `cron_id`, `job_id`, `started_ts`, `finished_ts`, `timeout` mohou být `null`.
- `TelemetryConfig`, `DbQueryConfig`: vlastnosti bez `readonly`.
- `DbQueryApiPresenter::sendJsonPayload()` / `sendErrorResponse()` vrací `void` (`@return never`).
- Nové minimum závislostí: `nette/security ^3.1`, `liquiddesign/nette-log-viewer ^1.2.4`,
  `symfony/*` povoluje i `^5.4`.

## 2. Fáze A — rozvrh jen v atributu

### 2.1 Inventura: co dnes skutečně běží

Za pravdu se bere **skutečný běh**, ne to, co slibuje atribut. Pro každý handler s `#[PullCron]`
zjisti, jak ho dnes zařazuje crunz (nebo jiný spouštěč) v **každém prostředí**:

- výraz v crunz tasku (`->cron('…')`, `->everyFiveMinutes()`, …),
- pomocné funkce, které výraz počítají podle prostředí (např. `dailyAtForEnvironment()`),
- gaty, které běh v některém prostředí vypínají (`->skip(…)`, `->when(…)`),
- handlery, které crunz vůbec nezařazuje (dnes neběží nikde, nebo jen na vyžádání z aplikace).

Výsledek si zapiš do tabulky *handler × produkce × test* a každý rozdíl mezi atributem a skutečností
rozhodni vědomě (obvykle zachovat skutečnost). Poslední běhy v LQDecku (přehled cronů / `get_cron_runs`)
skutečnost potvrdí.

### 2.2 Zápis do atributu

```php
use LiquidMonitorConnector\Worker\PullCron;

#[PullCron(
    schedule: '30 * * * *',                        // produkce a každé prostředí bez přepsání
    schedules: [                                   // přepsání podle scheduler.environment
        'testing' => '15 */4 * * *',
        'local' => '15 */4 * * *',
    ],
    environments: ['production', 'testing'],       // null = všude, [] = nikde (jen na vyžádání)
)]
final class ExportFeedHandler implements CronJobHandler
```

- **`schedule` je produkční rozvrh.** Prostředí, které v `schedules` chybí (i překlep nebo
  neznámé), dostane produkční — chybně nastavené prostředí tak produkci nezpomalí.
- **Víc výrazů**, když jeden rozvrh nepopíše: `schedule: ['0 13 * * *', '15 23 * * *']`
  (cron je splatný, když je splatný kterýkoli, a zařadí se jednou).
- `environments: ['production']` = gate „jen produkce“; `[]` = cron se sám nikdy nespustí
  (např. handler, který dnes nikde neběží — zapnutí je vědomé rozhodnutí).
- Klíče prostředí jsou **hodnoty `scheduler.environment`** (viz níže), ne názvy hostů.

NEON:

```neon
liquidMonitorConnector:
    scheduler:
        environment: %appEnv%      # production / testing / local — jak projekt prostředí určuje
        timezone: Europe/Prague
```

Prostředí musí do NEONu dostat projekt sám (parametr z bootstrapu, `%env.APP_ENV%` apod.).
Ověř, že na každém serveru má správnou hodnotu — bez ní dostane každý cron produkční rozvrh
a crony s `environments: ['production']` neběží vůbec.

### 2.3 Kontrola rozvrhu

```bash
php vendor/bin/monitor-worker plan --environment=production --hours=168
php vendor/bin/monitor-worker plan --environment=testing --hours=168 --json
php vendor/bin/monitor-worker enqueue-due --dry-run --minute=2026-10-20T03:00
```

`plan` vypíše efektivní rozvrh každého cronu (a crony vypnuté prostředím). Porovnej ho s tabulkou
z 2.1 — rozdíl musí být nula, nebo vědomě schválený. Výstup `plan` patří do PR.

### 2.4 Spouštění: `enqueue-due` místo rozvrhů v crunzu

`enqueue-due` každou minutu zařadí crony, které jsou podle atributu splatné (minutu zachytí při
startu procesu; souběžné spuštění hlídá zámek, nic se nezdvojí ani nevynechá). Worker zůstává.

**S přístupem ke crontabu serveru:**

```cron
* * * * * cd /srv/app/current && php vendor/bin/monitor-worker enqueue-due
* * * * * cd /srv/app/current && php vendor/bin/monitor-worker run --bootstrap=… --monitor-url=… --api-key=… --max-runtime=55
```

**Bez přístupu ke crontabu (crunz zůstává spouštěčem):** všechny tasky, které zařazovaly pull crony
podle rozvrhu, nahraď jedním crunz taskem, který každou minutu spustí
`php vendor/bin/monitor-worker enqueue-due` v kořeni aplikace (stejným PHP a se stejným
`NETTE_BOOTSTRAP_CLASS` / `.env` jako worker). Tasky, které nejsou rozvrhy cronů (worker, watchdogy
démonů, údržba), nech být. Pomocné funkce rozvrhů pak smaž.

`crunz/MonitorWorkerTasks.php` z balíčku je ve 4.x **deprecated** — spouští jen worker.

### 2.5 Ověření po nasazení

- V LQDecku přicházejí běhy každého cronu v časech podle `plan` (první hodinu a přes noc).
- U cronu se jako časování zobrazuje rozvrh z kódu (pro dané prostředí).
- Chyby zařazení se logují (`enqueue-due` končí nenulovým kódem, ostatní crony to nezastaví).

## 3. Fáze B — lokální plánovač `lqcron` (volitelné)

O tom, co a kdy se spustí, rozhoduje binárka na serveru projektu se SQLite. LQDeck dostává
hlášení, ale běh cronů na něm nezávisí. Postup: **stínový režim na testu → zapnutý plánovač na
testu → produkce**.

Podmínky:

- PHP (CLI **i FPM**, které joby zařazuje) má `ext-pdo_sqlite` nebo `ext-sqlite3`, SQLite ≥ 3.24.
- `stateDir` mimo release slot, na lokálním disku (ne NFS), zapisovatelný pro uživatele crontabu
  i FPM (stejný uživatel, nebo sdílená skupina a `umask 0002`).
- Joby z aplikace jde zařazovat jen na hostu plánovače (víc webových nodů ve 4.0 nepodporuje).

### 3.1 Stínový režim

```neon
liquidMonitorConnector:
    scheduler:
        shadow: true
        stateDir: /srv/app/shared/lqcron
```

```cron
* * * * * cd /srv/app/current && vendor/bin/lqcron tick --php=/usr/bin/php8.3 >> /dev/null 2>&1
```

Ponech `enqueue-due` i worker — v3 dál spouští všechno, `lqcron` jen počítá, co by spustil.
Po několika dnech (vč. nocí) porovnej, co by `lqcron` spustil, se skutečnými běhy: správce LQDecku
pustí `php artisan crons:shadow-report <id projektu>` — report musí ukázat jen shody.

### 3.2 Zapnutý plánovač

```neon
liquidMonitorConnector:
    scheduler:
        enabled: true
        shadow: false
        stateDir: /srv/app/shared/lqcron
        maxParallel: 3
```

- Z crontabu (nebo crunzu) odstraň `enqueue-due` i worker; zůstane jediný řádek `lqcron tick`.
- Crony se v LQDecku samy přepnou do režimu `local` (karta „Lokální plánovač“ v tabu Konektor).
- Zařazení z aplikace (`$cron->schedulePullJob(...)`) jde do lokální fronty. Chyby jsou
  `SchedulingFailedException` (implementuje `GuzzleException` — stávající `catch` platí):
  `QueueFullException`, `SchedulerUnavailableException`, `UnknownCronHandlerException`.
- Registraci cronu ověřuj `$cron->isCronDefined($code)`, ne statistikami z LQDecku.
- Health: služba `LiquidMonitorConnector\Scheduler\SchedulerHealth::checks()` (čte SQLite read-only,
  funguje z FPM) místo kontrol heartbeatu workeru z logu.
- Hlídané procesy (démoni) přes `scheduler.services` (`watchdog` / `keepalive`).

Deploy (blue/green):

```bash
vendor/bin/lqcron wait-slot <cílový slot> --timeout=1800   # před zápisem nového releasu
vendor/bin/lqcron pause --drain=120 --ttl=1800
# přepnutí current
vendor/bin/lqcron sync; vendor/bin/lqcron resume            # resume vždy, i když sync selže
```

Provoz: `lqcron status`, `lqcron plan`, `lqcron logs <code|run>`, `lqcron run <code> --sync`,
`lqcron cancel <run>`, `lqcron cron disable|enable <code>`, `lqcron flush`.

### 3.3 Návrat na v3

1. `lqcron pause --drain`, `lqcron flush`.
2. Do crontabu vrátit `enqueue-due` + worker, odebrat `lqcron tick`.
3. V LQDecku na projektu akce „Vrátit crony do pull“ (zablokuje zpětné přepnutí; čekající příkazy
   zruší). Zrušením blokace se crony vrátí na lokální plánovač.

## 4. Kontrolní seznam

- [ ] LQDeck přijímá connector 4, projekt je na `^4.0`, změny API upravené.
- [ ] Tabulka *handler × prostředí* hotová, rozdíly rozhodnuté, atributy zapsané.
- [ ] `scheduler.environment` správně na každém serveru, `plan` sedí s tabulkou.
- [ ] Rozvrhy z crunz tasků pryč, `enqueue-due` běží každou minutu, worker běží.
- [ ] V LQDecku běhy v očekávaných časech (hodinu a přes noc).
- [ ] (Fáze B) stínový report bez rozdílů → zapnutý plánovač na testu → produkce.

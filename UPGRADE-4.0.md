# Upgrade na connector 4.0

Connector 4.0 přináší lokální plánovač cronů `lqcron` (LQDeck přestává být SPOF pro běh cronů),
rozvrh cronů jen v atributu `#[PullCron]` a podporu PHP 8.0. Návrh: [`docs/v4-local-scheduler.md`](docs/v4-local-scheduler.md),
kontrakt dat: [`docs/v4-contract.md`](docs/v4-contract.md).

## 0. Pořadí nasazení (tvrdé)

LQDeck musí přijímat major verzi 4 (**`supported_versions` obsahuje 4**) dřív, než jakýkoli projekt
přejde na `^4`. Jinak dostane `426 Upgrade Required` na *všechna* volání connectoru — hlášení chyb,
push crony i statistiky, i bez lokálního plánovače (plán § 13.1).

## 1. Požadavky

- PHP **>= 8.0** (dřív 8.1).
- `symfony/console|process|dotenv` `^5.4 || ^6.0 || ^7.0 || ^8.0`.
- Nová závislost `dragonmantank/cron-expression ^3.3` (tutéž používá crunz).
- Jen s `scheduler.enabled`: `ext-pdo_sqlite` **nebo** `ext-sqlite3` (i v PHP-FPM, které joby zařazuje),
  SQLite >= 3.24, `stateDir` na lokálním disku (ne NFS). Bez nich kompilace kontejneru selže.

## 2. Změny veřejného API (breaking)

### `ConcurrencyModeType` už není enum

Třída se stringovými konstantami se stejnými jmény. Zápis v atributu se nemění:

```php
#[PullCron(schedule: '*/5 * * * *', concurrencyMode: ConcurrencyModeType::Independent)]
```

| Dřív | Teď |
|---|---|
| `ConcurrencyModeType::Exclusive` (enum case) | `'exclusive'` (string) |
| `$mode->value` | `$mode` |
| `ConcurrencyModeType::from($v)` → case | `ConcurrencyModeType::from($v)` → string (`\ValueError` při neznámé hodnotě) |
| parametr typovaný `ConcurrencyModeType $mode` | `string $mode` |
| `PullCron::$concurrencyMode` typu `?ConcurrencyModeType` | `?string` |

Vlastnosti `PullCron` jsou `public` bez `readonly` (čtení beze změny).

### `HealthCheckStatusEnum` už není enum

Třída s int konstantami: `INFO = -1`, `OK = 0`, `WARNING = 1`, `ERROR = 2`, `CRITICAL = 3`.
JSON výstup health checku (`'status' => 'OK'`) se nemění.

| Dřív | Teď |
|---|---|
| `$status->value` | `$status` (pozor: na intu `->value` nespadne, jen varuje a vrátí `null`) |
| `$status->name` | `HealthCheckStatusEnum::name($status)` |
| `HealthCheckStatusEnum::from($v)` → case | `from($v)` → `int`, `tryFrom($v)` → `?int` |
| `HealthCheckStatusEnum::cases()` → list cases | `cases()` → `array<string, int>` (jméno ⇒ hodnota) |
| `HealthCheckData(..., HealthCheckStatusEnum $status)`, `getStatus(): HealthCheckStatusEnum` | `int $status`, `getStatus(): int` |
| `HealthCheckResponse::__construct(HealthCheckStatusEnum ...)`, `setStatus(...)` | `int` |

Hledej v projektu: `grep -rn "HealthCheckStatusEnum\|->value\|->name" app/ | grep -i health`.

### Další

- `TelemetryConfig`, `DbQueryConfig`: vlastnosti `public` bez `readonly` (čtení beze změny).
- `DbQueryApiPresenter::sendJsonPayload()` / `sendErrorResponse()` vrací `void` (`@return never`).
- `Cron::getLastCronJobLog()`: `cron_id`, `job_id`, `started_ts`, `finished_ts`, `timeout` mohou být `null`
  (lokální běhy nemají job v LQDecku).

## 3. Rozvrh jen v atributu (`enqueue-due`)

Platí i bez lokálního plánovače. Rozvrhy z crunz tasků, pomocných funkcí (`dailyAtForEnvironment()`
apod.) a skip gatů přepiš do atributu:

```php
#[PullCron(
    schedule: '*/30 * * * *',
    schedules: ['test' => '15 */4 * * *'],   // přepsání podle scheduler.environment
    environments: ['production', 'test'],    // jinde se neplánuje
)]
```

```neon
liquidMonitorConnector:
    scheduler:
        environment: %environment%     # nebo jak projekt prostředí určuje
        timezone: Europe/Prague
```

Crontab místo crunz tasků s rozvrhy:

```cron
* * * * * cd /srv/app/current && php vendor/bin/monitor-worker enqueue-due
* * * * * cd /srv/app/current && php vendor/bin/monitor-worker run --bootstrap=… --monitor-url=… --api-key=… --max-runtime=55
```

Než rozvrhy z crunzu smažeš, porovnej je: `php vendor/bin/monitor-worker plan --environment=production`
(a `test`). **Za pravdu se bere to, co dnes skutečně běží**, ne původní atribut — rozdíly rozhodne člověk.
`crunz/MonitorWorkerTasks.php` je **deprecated** (spouští jen worker).

## 4. Lokální plánovač (volitelné, doporučený postup)

1. `^4` + `enqueue-due` (bod 3), LQDeck s podporou v4.
2. Test: `scheduler.shadow: true` + `stateDir` + řádek `lqcron tick` v crontabu (vedle `enqueue-due`
   a workeru). Pár dní porovnávat (`php artisan crons:shadow-report <projekt>` v LQDecku).
3. Test: `scheduler.enabled: true`, `shadow: false`; z crontabu pryč `enqueue-due` i worker, zůstane jen
   `lqcron tick`. Ověřit souběh a deploy (`wait-slot` / `pause` / `resume`, README).
4. Produkce.

### Zařazování z aplikace

`Cron::schedulePullJob()` a `scheduleJob()` pro pull crony zapisují s `scheduler.enabled` do lokální
fronty. Chyby mají společného předka `SchedulingFailedException`, který **implementuje
`GuzzleException`** — stávající `catch (GuzzleException)` je chytí:

- `QueueFullException` (plná fronta, `maxQueueSize`, default 10),
- `SchedulerUnavailableException` (databáze neexistuje — jiný node než plánovač —, novější schéma, zámek),
- `UnknownCronHandlerException` (třída bez `#[PullCron]`).

Joby jde zařazovat **jen na hostu plánovače**; ostatní webové nody dostanou `SchedulerUnavailableException`.

### Místa v ABELu

- `QiOrderService` — chytá `GuzzleException`, beze změny; ověřit, že zařazuje na hostu plánovače.
- `ScriptsPresenter` — registraci cronu ověřuje přes `getCronJobLogsStats()` (při výpadku LQDecku skripty
  nejdou spustit) → přejít na `$cron->isCronDefined($code)`.
- `QiCronRunner` — zkontrolovat chytání výjimek zařazení.
- `CheckPullWorkerHeartbeat` (abel-base, čte log workeru) → `LiquidMonitorConnector\Scheduler\SchedulerHealth::checks()`.
- `tasks/`, `PullJobEnqueuer`, crunz závislost — smazat po přechodu (plán § 13.3 krok 5).

### Návrat na v3 (vypnutí plánovače)

`lqcron pause --drain`, `lqcron flush`, vrátit do crontabu `enqueue-due` + worker, v LQDecku akce
„Vrátit crony do pull“ na projektu (nastaví blokaci, aby zapomenutý `lqcron` crony nepřetáhl zpět).

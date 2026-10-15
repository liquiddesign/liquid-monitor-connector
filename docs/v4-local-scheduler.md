# Connector v4 — lokální plánovač cronů (`lqcron`)

> **Stav:** návrh specifikace, 2026-10-15. Nic z toho zatím není implementované. Vývoj na větvi `4.x`.
> **Vychází z:** connector 3.2.4 (větev `3.x`, `7796ed9`) a LQDeck `liquid-monitor-back` (`cfc619ce`).

**Rozhodnuto (2026-10-15):**

- PHP část v4 podporuje **PHP >= 8.0**, aby šla nasadit opravdu všude (§ 3.1).
- **Push crony zůstávají plně podporované**, nejsou deprecated (§ 9).
- **Statistiky a přehledy počítá jen LQDeck**, který je hlavním bodem sběru; lokální SQLite drží
  jen to, co plánovač potřebuje k rozhodování (§ 9, § 12).
- **Doručení do LQDecku je zaručené**: binárka posílá každou událost, dokud ji LQDeck nepotvrdí,
  nic se automaticky nezahazuje (§ 8.1).

## 1. Proč

V3 pull model dělá z LQDecku SPOF pro všechny crony projektu. Každý běh potřebuje tři úspěšná HTTP
volání na vzdálený server: `schedule-job` (zařazení), `claim-jobs` (převzetí) a `finish-job` / `fail-job`
(výsledek). Výpadek 2026-10-14 (LQDecku došlo místo na disku) ukázal, co se pak stane:

- zařazení skončí na `cURL error 28` a běh se vynechá (`PullJobEnqueuer`: „běh se vynechává“),
- worker nemá co převzít a stojí,
- výsledky rozběhnutých jobů se ztratí, lease vyprší a LQDeck běh označí jako `failed`
  (vznikly triage tasky „Cron failed: …“, ačkoli handler doběhl v pořádku).

Kolem cronů navíc každý projekt drží vlastní PHP: crunz tasky, `tasks/bootstrap.php` s pomocníky
`dailyAtForEnvironment()` / `hourlyCronForEnvironment()`, `PullJobEnqueuer`, správce workerových slotů,
správce dalších daemonů a health check heartbeatu z logu workeru.

**Cíl v4:** o tom, co a kdy se spustí, rozhoduje lokální binárka `lqcron` se SQLite. LQDeck zůstává
informační vrstvou — dostává hlášení, drží historii, alerty, triage i UI, ale nic neřídí.
Jeho výpadek přestane mít vliv na běh cronů. V projektu zůstanou jen handlery s atributem
a pár řádků NEON konfigurace.

Mimo rozsah: uptime / ping webu a cokoli, co má být nezávislé na serveru projektu, zůstává
ve vzdáleném LQDecku.

## 2. Přehled architektury

```
systémový cron (* * * * *)
   └─ lqcron tick                     ← běží milisekundy, nejvýš 1 instance (flock)
        ├─ načte definice cronů (cache v SQLite, obnova po deployi)
        ├─ zařadí splatné běhy do fronty
        ├─ vybere joby k převzetí podle pravidel souběhu
        ├─ pro každý odpojeně spustí  lqcron run-job <runId>   (hlídací proces)
        ├─ uklidí ztracené běhy (mrtvý hlídací proces, prošlý timeout)
        ├─ zajistí běh hlídaných služeb (products-daemon, telemetry agent, …)
        ├─ odešle outbox do LQDecku a stáhne příkazy (časově omezeno, nekritické)
        └─ zapíše heartbeat
lqcron run-job <runId>                ← 1 na běžící job, žije po dobu jobu
   └─ php vendor/bin/monitor-worker execute --run-id … (nová skupina procesů)
        └─ handler->execute($arguments) v čerstvém Nette kontejneru
```

Souběh mnoha dlouhých cronů tedy znamená N hlídacích procesů (jednotky MB RSS) + N PHP procesů;
plánovač sám mezi ticky neběží. Dnešní omezení `WorkerRunLoop` (po deadline přestane převzímat,
`waitForRunning` bez horní meze) odpadá.

## 3. Distribuce a balíček

- **Major verze 4.0 balíčku `liquiddesign/liquid-monitor-connector`**, žádné nové repo.
- Binárka v Rustu (stejný stack jako `abel-products-daemon`), staticky linkovaná (musl):
  `bin/lqcron-linux-x86_64`, `bin/lqcron-linux-aarch64`. Builduje CI connectoru při release a výsledek
  se commituje do tagu (Composer Rust při instalaci nesestaví). Zdroják v `scheduler/` v tomtéž repu.
- `bin/lqcron` je shellový wrapper uvedený v `composer.json` `bin`, vybere binárku podle `uname -m`.
- Verze binárky = verze balíčku. `lqcron --version` a schéma SQLite nesou verzi; tick odmítne běžet
  nad schématem novějším, než zná.
- PHP část v4: `WorkerExecuteCommand` (lokální režim), export definic, PHP API nad lokální frontou.
  `WorkerRunLoop`, `WorkerClient`, `MonitorWorkerLauncher`, `crunz/MonitorWorkerTasks.php` zůstávají
  pro projekty, které lokální plánovač nezapnou (vzdálený pull jako ve v3, viz § 13).

### 3.1 PHP >= 8.0

Binárka na verzi PHP nezávisí; podmínka se týká PHP kódu balíčku (`composer.json` `"php": ">=8.0"`).
Dnešní `3.x` vyžaduje 8.1 a ve `src/` používá konstrukce, které 8.0 nezná — na `4.x` se musí přepsat:

| Konstrukce (od) | Výskyt v `src/` (3.2.4) | Náhrada pro 8.0 |
|---|---|---|
| `enum` (8.1) | 2 soubory (`ConcurrencyModeType`, …) | třída s konstantami + `from()` / `tryFrom()` |
| `readonly` property (8.1) | 28 souborů | `private` property + getter, případně `@readonly` pro PHPStan |
| typované konstanty `const string` (8.3) | 6 souborů (např. `Orchestrator/Outbox.php`) | netypované konstanty |
| návratový typ `never` (8.1) | 1 soubor | `void` + `@return never` |
| first-class callable `f(...)` (8.1) | 2 soubory | `Closure::fromCallable()` |

Atributy (`#[PullCron]`) a constructor promotion jsou 8.0, ty zůstávají.

Závislosti: `symfony/console|process|dotenv` dnes `^6.3` (= PHP 8.1+) → rozšířit na
`^5.4 || ^6.0 || ^7.0 || ^8.0`; `nette/di ~3.0`, `guzzle ^7`, `carbon ^2` 8.0 podporují.
CI connectoru (dnes jen PHP 8.4, jen phpstan + phpcs, jen `main`) rozšířit na matici
8.0 / 8.1 / 8.3 / 8.5 se spuštěním testů (`nette/tester`) a na větev `4.x`.
PHPStan s `phpVersion: 80000`, aby novější konstrukce zachytil.

## 4. Konfigurace v projektu

```neon
liquidMonitorConnector:
	url: 'https://lqdeck.example.org/api/connector'
	apiKey: '...'
	workerHandlers: auto
	scheduler:
		enabled: true                 # false = v3 chování (vzdálený pull)
		environment: %environment%    # klíč pro schedules/environments v atributu
		stateDir: /srv/abel/shared/lqcron   # MIMO release slot, absolutní cesta, povinné
		timezone: Europe/Prague
		maxParallel: 3                # globální strop běžících jobů projektu (dnešní sloty)
		resources:                    # skupiny prostředků, viz § 6.3
			qi: 1
			product-write: 1
		phpBinary: /usr/bin/php8.5    # default: PHP_BINARY exportu
		bootstrap: %appDir%/../bin/monitor-worker-bootstrap.php   # default jako dnes
		services:                     # hlídané služby, viz § 10
			productsDaemon:
				command: [php, vendor/liquiddesign/eshop/products-daemon/bin/products-daemon-watch.php, ...]
				mode: watchdog          # spustit každý tick, služba si sama řeší „už běží“
```

Crontab serveru (jediný řádek místo crunz):

```
* * * * * cd /srv/abel/current && vendor/bin/lqcron tick >> /dev/null 2>&1
```

Tick si cestu k aplikaci odvozuje z `cwd` při každém spuštění, takže po přepnutí `current` symlinku
nové joby startují z nového slotu a běžící doběhnou ve starém.

## 5. Definice cronů

### 5.1 Atribut `#[PullCron]` — rozšíření

Stávající pole (`schedule`, `name`, `repeatCount`, `concurrencyMode`, `description`, `timeout`,
`maxQueueSize`) zůstávají. Nová:

| Pole | Typ | Význam |
|---|---|---|
| `schedules` | `array<string, string>` | přepsání `schedule` podle `scheduler.environment` (`['test' => '50 0 * * *']`); nahrazuje `dailyAtForEnvironment()` a spol. |
| `environments` | `list<string>\|null` | cron se plánuje jen v těchto prostředích; `null` = všude. Nahrazuje `->skip(isProductionEnvironment())`. Env-gate se pak píše sem i s `TODO(freelo …)`. |
| `resources` | `list<string>` | skupiny prostředků (§ 6.3) |
| `after` | `class-string\|null` | spustit po úspěšném doběhnutí jiného cronu (parita s `crons.cron_id` v LQDecku) |
| `missed` | `'skip'\|'once'` | co s během, který se nestihl naplánovat (server neběžel); default `skip` |
| `missedGraceMinutes` | `int` | jak pozdě se běh ještě spustí jako „včas“; default 5 |

Cron code se dál odvozuje z názvu třídy (`CronJobHandlerCode::fromClassName`), aby historie
v LQDecku navázala.

### 5.2 Jak se definice dostanou do binárky

Binárka PHP nečte. Definice exportuje PHP příkaz:

```
php vendor/bin/monitor-worker export-definitions --json
```

Nabootuje Nette kontejner, projde registry handlerů (`workerHandlers: auto`), vyhodnotí atributy
pro `scheduler.environment` a vypíše normalizovaný seznam (code, schedule, timeout, …) + hash.

Tick export spustí, když (a) se změnil cíl `current` symlinku / mtime `vendor/composer/installed.json`,
(b) uplynulo 15 minut od posledního exportu, nebo (c) příkazem `lqcron sync`. Selže-li export,
tick jede dál s poslední platnou sadou a hlásí chybu.

Projekt bez Nette (Laravel apod.) dodá vlastní export ve stejném JSON formátu — binárka je na
frameworku nezávislá.

## 6. Plánování a fronta

### 6.1 Vznik běhů

Tick spočítá pro každý aktivní cron výskyty výrazu v intervalu `(last_tick_at, now]` v `timezone`.

- výskyt mladší než `missedGraceMinutes` → zařadit,
- starší výskyty (server neběžel) → `missed: once` zařadí jeden běh, `skip` je zahodí a nahlásí
  `run.missed`,
- DST: neexistující čas (jarní posun) se spustí v první minutě po skoku, dvojitý čas (podzim) jen jednou.

Další zdroje jobů: `Cron::schedulePullJob()` / `scheduleJob()` z aplikace (§ 9), `lqcron enqueue`,
příkaz „spustit teď“ z LQDecku (§ 8.3), závislost `after`, opakování po selhání.

### 6.2 Fronta

Tabulka `jobs` (obdoba `cron_jobs`): `available_at` (zpoždění), `arguments`, `attempt`, `source`.

- `maxQueueSize`: `null` znamená **10** (jako LQDeck, `monitor.default_max_queue_size`). Počítají se
  i odložené joby. Při plné frontě se zařazení odmítne s chybou `QueueFull` a pošle se `job.rejected`.
- Opakování a `after` frontový limit obcházejí (jako dnes).
- Job čekající déle než 6 h **a už dostupný** (`available_at <= now`) se smaže s `job.stale`.
  Oproti LQDecku se nemažou odložené joby, kterým zpoždění ještě neuplynulo.

### 6.3 Pravidla souběhu (port `CanCronStartJob`)

„Aktivní běh“ = stav `starting` nebo `running` v rámci projektu (stateDir).

| Mód | Smí se spustit, když |
|---|---|
| `exclusive` (default) | neběží žádný jiný běh projektu kromě `independent`, ani vlastní instance |
| `parallel` | neběží žádný `exclusive` a žádná vlastní instance |
| `fully_parallel` | neběží žádný `exclusive`; vlastních instancí může běžet víc |
| `independent` | neběží vlastní instance; ostatní módy ho neblokují a on neblokuje je |

Navíc (nové ve v4):

- `maxParallel` — globální strop běžících jobů projektu,
- `resources` — každá skupina má kapacitu z NEONu (default 1); job se spustí, jen když je volné místo
  ve všech jeho skupinách.

Výběr: joby v pořadí `id` (FIFO), přeskočí se ty, které nesmí běžet; vyhodnocuje se postupně v rámci
jedné transakce, takže druhý job vidí první převzatý.

### 6.4 Opakování a závislosti

- `repeatCount = N` znamená **celkem N+1 pokusů**. LQDeck dnes při N ≥ 1 provede N+2 pokusů
  (`FailJob.php:49`, `>` místo `>=`); v4 to záměrně opravuje.
- Opakovaný běh dostane stejné `arguments` a zařadí se hned (backoff až ve 4.1, § 14).
- `after`: po `finished` rodiče se zařadí závislé crony (bez argumentů); po `failed` ne.

## 7. Spuštění, timeout, výsledek

### 7.1 Hlídací proces `lqcron run-job`

1. Přepne běh na `starting`, spustí PHP v **nové skupině procesů** (`setsid`):
   `php vendor/bin/monitor-worker execute --run-id=<id> --cron-code=… --arguments-file=…`
   (argumenty přes soubor, ne přes CLI ani env — ty jsou vidět v `ps` a mají limit délky).
2. Uloží `pid` + `pid_start_time` (z `/proc/<pid>/stat`), přepne na `running`.
3. stdout a stderr přesměruje do `stateDir/runs/<id>.log`.
4. Každých 30 s zapíše `heartbeat_at`.
5. Po skončení zapíše exit kód, dobu, peak RSS **potomka** (`wait4` rusage, ne rodiče jako dnes)
   a výsledek; běh přepne na `finished` / `failed`.

### 7.2 Timeout

- `timeout` z atributu; `null` znamená **1000 s** (parita s LQDeck: `crons.timeout ?: 1000`).
  Hodnota `0` znamená bez limitu a smí se použít jen vědomě.
- Po vypršení: `SIGTERM` celé skupině, po `killGraceSeconds` (default 30) `SIGKILL` celé skupině.
  Skončí tak i podprocesy handleru. Běh dostane stav `timeout`, do hlášení jde signál, doba a konec logu.
- `execute` v PHP zaregistruje `pcntl_signal(SIGTERM)` a dá handleru šanci uklidit
  (volitelné rozhraní `InterruptibleCronJobHandler::interrupt()`).

### 7.3 Ztracené běhy

Tick najde běh ve stavu `running` s `heartbeat_at` starším než 120 s (hlídací proces zemřel — OOM, restart):

- PHP proces podle `pid` + `pid_start_time` ještě žije a timeout neuplynul → hlídání převezme tick
  (zkontroluje znovu příští minutu),
- timeout uplynul → tick ukončí skupinu stejně jako v § 7.2,
- proces neexistuje → běh `lost`, uplatní se pravidla opakování.

Kontrola času startu brání zabití cizího procesu s recyklovaným PID.

### 7.4 Výsledek a průběh z handleru

- Výsledek: handler smí vrátit pole (`execute(): array|null` ve v4, `void` zůstává kompatibilní);
  `execute` ho zapíše do `stateDir/runs/<id>.result.json`. stdout už se jako výsledek neparsuje
  (dnes `echo` v handleru rozbije JSON a výsledek skončí jako `output`).
- Průběh: `Cron::progressJob($data)` v PHP potomkovi zapíše událost do SQLite (`LQCRON_RUN_ID` v env).
  Dnes je v pull potomkovi no-op.
- Chyba: do hlášení jde zpráva výjimky **i trace** a posledních 64 kB logu (dnes jen `getMessage()`).

## 8. LQDeck jako informační vrstva

### 8.1 Outbox — zaručené doručení

Každá událost (změna stavu běhu, fronty, definic, heartbeat) se nejdřív zapíše do tabulky `outbox`
**ve stejné transakci** jako změna stavu, kterou popisuje, s jedinečným `event_id` (UUID v7)
a pořadovým číslem `seq`. Teprve potom se odesílá. Událost tedy nemůže vzniknout „jen v paměti“
a ztratit se pádem procesu.

**Pravidlo: událost se z outboxu smaže jen tehdy, když ji LQDeck výslovně potvrdí.** Nic se
nezahazuje kvůli stáří, velikosti ani počtu pokusů. Po výpadku LQDecku (hodiny, dny) se do něj
dostane všechno, ve stejném pořadí, s původními časy.

- **Odesílání:** tick odesílá v pořadí `seq` dávky (max 200 událostí / 2 MB) v časovém rozpočtu 20 s,
  dokud fronta nedojde nebo rozpočet nevyprší; zbytek pošle příští tick. `lqcron flush` odesílá
  bez rozpočtu, dokud nic nezbývá (po delším výpadku). Odesílání nesmí blokovat plánování —
  tick nejdřív spustí joby, odesílá až potom.
- **Potvrzení po událostech:** LQDeck odpoví seznamem `accepted: [event_id…]` a
  `rejected: [{event_id, reason, permanent}]`. HTTP 2xx bez tohoto těla se nebere jako potvrzení.
  Mažou se jen `accepted`; vše ostatní zůstává a pošle se znovu.
- **Opakování:** přenosová chyba, timeout, 5xx, 408, 429, ale i 401 / 403 / 404 (výměna API klíče,
  rozbitý deploy LQDecku) se opakují donekonečna s backoffem 15 s → 30 s → 1 min → max 5 min;
  backoff se resetuje prvním úspěchem. 413 → dávka se rozpůlí.
- **Trvale odmítnutá událost** (`permanent: true`, např. validační chyba po změně schématu)
  se přesune do `outbox_rejected` — **nemaže se**. Hlásí se lokálně (Tracy log, `lqcron status`,
  health check WARNING) i v každém `scheduler.heartbeat` (`rejected_count`), aby o ní LQDeck věděl.
  `lqcron outbox retry-rejected` je pošle znovu (typicky po opravě LQDecku nebo upgradu connectoru).
- **Pořadí:** odmítnutá událost neblokuje ostatní; LQDeck musí unést, že např. `run.finished`
  přijde bez dříve odmítnutého `run.started` (založí běh z dostupných údajů).
- **Idempotence:** LQDeck deduplikuje podle `event_id` (unikátní index), takže opakované odeslání
  po ztracené odpovědi nic nezdvojí.
- **Velikost:** konec logu běhu (64 kB) se ukládá komprimovaně (zstd). Outbox se nehlídá stropem,
  ale volným místem: pod 500 MB volného místa ve `stateDir` health check hlásí CRITICAL a tick
  přestane ukládat progress události (stavové události ukládá vždy). Zahazování starých událostí
  není povolené.
- **Výpadek trvající dny** se tak projeví jen růstem outboxu; ABEL dělá řádově 1 500 běhů denně,
  tj. jednotky MB za den.

### 8.2 Ingest API v LQDecku (nové)

`POST /api/connector/v4/events` — dávka událostí. Odpověď vždy nese potvrzení po jednotlivých
událostech (`accepted` / `rejected`, § 8.1); LQDeck událost potvrdí až po jejím trvalém uložení
(commit transakce), aby výpadek jeho DB uprostřed zpracování nevedl ke ztrátě. Zaplněná DB
(incident 2026-10-14, `1114 The table is full`) tak vrátí chybu a binárka posílá dál, dokud se místo neuvolní.

| Typ | Obsah |
|---|---|
| `definitions.sync` | celý seznam definic + hash (nahrazuje sync metadat přes `schedule-job`) |
| `job.queued` / `job.rejected` / `job.stale` | fronta |
| `run.started` / `run.progress` / `run.finished` / `run.failed` / `run.timeout` / `run.lost` / `run.missed` | životní cyklus běhu, s `run_uuid`, `attempt`, časy, RAM, výsledkem, koncem logu |
| `scheduler.heartbeat` | každý tick: verze, délka fronty, běžící joby, velikost outboxu, `paused` |

LQDeck je zapisuje do stávajících tabulek (`crons` s `job_execution_mode='local'`, `cron_job_logs`
s novým sloupcem `external_uuid`), takže UI, Slack alerty, triage „Cron failed“ a statistiky fungují dál.
Časy se berou z události, ne z okamžiku přijetí.

Úpravy chování LQDecku pro `local` crony:

- alert a triage task jen při **finálním** selhání (dnes vzniká i pro mezipokusy, `CronFailureSource`
  bere každý `failed` log),
- `ExpireLeasedJobLogs` a `CleanupHangingJobLogs` se jich netýkají — o timeoutu rozhoduje binárka,
- nový alert „Plánovač mlčí“: žádný `scheduler.heartbeat` déle než 5 min (bez ohledu na frontu;
  LQDeck frontu nevidí). Nahrazuje „Pull worker silent“.

### 8.3 Příkazy z LQDecku

Tick stáhne `GET /api/connector/v4/commands` (limit 3 s) a potvrdí je v příští dávce událostí.

| Příkaz | Účinek |
|---|---|
| `cron.set_active` | lokální kill switch (dnešní admin `active`); stav se pamatuje i při výpadku LQDecku |
| `cron.run_now` | zařadí job (nová funkce, dnes v LQDecku neexistuje) |
| `run.cancel` | ukončí běh jako při timeoutu |
| `scheduler.pause` / `resume` | totéž jako CLI |

Výpadek LQDecku znamená jen to, že příkazy dorazí později.

## 9. PHP API v režimu lokálního plánovače

| Metoda (`Cron`) | v3 | v4 se `scheduler.enabled` |
|---|---|---|
| `schedulePullJob($class, $args, $delay)` | POST `schedule-job` | zápis do lokální fronty (SQLite přes PDO, transakce + `busy_timeout`); výjimka jen při plné frontě nebo neznámém handleru |
| `scheduleJob($code)` | POST `schedule-job` (push) | pro pull cron = `schedulePullJob`; push cron beze změny |
| `scheduleOrStartJob(...)` | push | beze změny — push crony zůstávají plně podporované |
| `progressJob` / `finishJob` / `failJob` | v pull potomkovi no-op | v potomkovi lokální událost; jinak (push) beze změny |
| `isCronRunning($code)` | GET LQDeck | lokální SQLite — je to stav, podle kterého plánovač rozhoduje |
| `getLastCronJobLog($code)` | GET LQDeck | GET LQDeck (jako dnes); při nedostupnosti fallback na poslední lokální běh |
| `getCronJobLogsStats` / `getCronOverview` | GET LQDeck | **GET LQDeck beze změny** — statistiky počítá jen server |
| `log()` | POST `/log` | beze změny |

Projekt se tedy při přechodu nemusí měnit v místech, kde joby zařazuje (v ABELu `QiOrderService`,
`ScriptsPresenter`, `QiCronRunner`).

**Push crony** (`url` u cronu, LQDeck je volá HTTP) se lokálním plánovačem nemění: plánuje je dál LQDeck
a v projektu mohou žít vedle lokálních. Ruší se jen pull přes vzdálenou frontu, a to jen v projektu
se zapnutým `scheduler.enabled`.

**Statistiky:** binárka ani PHP nic neagregují. Přehledy, statistiky běhů, trendy a dashboardy
se počítají v LQDecku z doručených událostí (§ 8.1); díky zaručenému doručení jsou po výpadku úplné.
Při výpadku LQDecku jsou statistiky dočasně nedostupné — to je přijatelné, běh cronů na nich nezávisí.

## 10. Hlídané služby

`scheduler.services` nahrazuje crunz tasky, které nejsou crony, ale hlídače procesů
(v ABELu `ProductsDaemonTasks`, job-worker watchdog ve `FrequentTasks`, telemetry agent v launcheru).

- `mode: watchdog` — příkaz se spustí každý tick a sám řeší „už běží → nic“ (dnešní vzor products-daemonu),
- `mode: keepalive` — `lqcron` drží proces naživu sám (pidfile ve stateDir, restart s backoffem).

Výsledek se hlásí jako `service.*` události. Škálování job-workeru podle délky fronty zůstává zatím
v aplikaci (4.1).

## 11. Provoz

### 11.1 CLI

| Příkaz | Co dělá |
|---|---|
| `lqcron tick` | jeden průchod plánovače (volá systémový cron) |
| `lqcron run <code> [--args=json] [--sync]` | zařadí job; `--sync` ho spustí hned v popředí (dev instance, testy) |
| `lqcron enqueue <code> [--args=json] [--delay=s]` | zařadí job |
| `lqcron plan [--hours=24]` | co a kdy poběží (po vyhodnocení `schedules` / `environments`) |
| `lqcron status [--json]` | poslední tick, fronta, běžící, outbox, pauza |
| `lqcron logs <code\|runId> [--tail]` | log běhu |
| `lqcron pause [--drain[=sec]]` / `resume` | deploy: nespouštět nové, volitelně počkat na doběhnutí |
| `lqcron sync` | znovu načíst definice |
| `lqcron flush` | odeslat outbox hned |

### 11.2 Deploy (blue/green)

1. `lqcron pause --drain=120` — nové joby se nespouštějí, běžící mají 2 min na doběhnutí
   (dlouhé běhy doběhnou ve starém slotu dál, nic se nezabíjí).
2. přepnutí `current`,
3. `lqcron sync && lqcron resume`.

Tím odpadá rozjezd jobů přes přepnutí slotu a joby spouštěné ze starého slotu
(ABEL test, monitor#1203).

### 11.3 Jeden plánovač na projekt

`stateDir` musí být sdílený mezi sloty a jen na jednom hostu. Tick při startu zapíše do SQLite
`host_id` (hostname + machine-id); běží-li stejný stateDir na jiném hostu, tick odmítne běžet
(`lqcron tick --takeover` jen ručně). Dva hosty s vlastním stateDir by naplánovaly všechno dvakrát —
to musí pohlídat konfigurace serveru (`scheduler.enabled` jen na jednom).

### 11.4 Health check

`HealthCheck` komponenta connectoru čte `lqcron status --json`: poslední tick starší než 3 min = CRITICAL,
nejstarší nepotvrzená událost v outboxu starší než 1 h = WARNING (LQDeck nepřijímá),
`outbox_rejected` neprázdný = WARNING, méně než 500 MB volného místa ve `stateDir` = CRITICAL. Nahrazuje
`CheckPullWorkerHeartbeat` (čtení řádků z `monitor-worker.log`).

## 12. Úložiště (SQLite, WAL)

`stateDir/lqcron.sqlite`, `journal_mode=WAL`, `busy_timeout=5000`, krátké transakce.
Zapisuje tick, hlídací procesy a PHP enqueue; řádově desítky zápisů za minutu.

| Tabulka | Obsah |
|---|---|
| `meta` | verze schématu, `host_id`, `last_tick_at`, `paused`, hash definic, čas exportu |
| `crons` | definice (code, schedule, timeout, concurrency, resources, after, missed, active, remote_active) |
| `jobs` | fronta (cron_code, arguments, available_at, attempt, source, created_at) |
| `runs` | běhy (uuid, cron_code, job, attempt, state, pid, pid_start_time, started/finished/heartbeat_at, exit_code, signal, max_rss, result, error) |
| `run_events` | progress události |
| `outbox`, `outbox_rejected` | události pro LQDeck (§ 8.1) |
| `services` | stav hlídaných služeb |

Lokální historie je jen pracovní, ne zdroj statistik: dokončený běh se smaže 7 dní po tom, co LQDeck
potvrdil všechny jeho události (dřív nikdy); logy běhů na disku 7 dní. Historii drží LQDeck.
Migrace schématu jen dopředu kompatibilní: běžící hlídací proces staré verze musí zvládnout
zapsat svůj výsledek do schématu nové verze.

## 13. Kompatibilita a přechod

- **Bez `scheduler.enabled`** se v4 chová jako v3 (vzdálený pull přes worker, push beze změny).
  Projekt může přejít na `^4` a plánovač zapnout později.
- **LQDeck** (`liquid-monitor-back`) dostane podporu v4 jako **nové funkce na hlavní větvi, bez nové
  branche a s plnou zpětnou kompatibilitou**: nové endpointy (`v4/events`, `v4/commands`), nový
  `job_execution_mode='local'` a sloupec `external_uuid`; stávající push i v3 pull API se nemění.
  Obsluhuje tak trvale všechny tři druhy klientů (push, v3 pull, v4 lokální) a rozlišuje je podle
  `X-Connector-Version` a režimu cronu. Úpravy chování z § 8.2 (alert jen při finálním selhání,
  „Plánovač mlčí“, timeout řeší binárka) platí jen pro crony v režimu `local`.
- **Stínový režim** (`scheduler.shadow: true`): tick počítá, co by spustil, nic nespouští a posílá
  `run.shadow` události; LQDeck je porovná s během řízeným v3. Slouží k ověření parity rozvrhů
  a pravidel souběhu na testu.

Postup pro projekt (ABEL jako první):

1. přepsat env-specifické rozvrhy a skip gaty z `tasks/*.php` do atributů (`schedules`, `environments`),
2. `^4` + `scheduler.shadow: true` na testu, porovnat se stávajícím během (několik dní, vč. nočních),
3. test: `scheduler.enabled`, crontab → `lqcron tick`, vypnout crunz,
4. RT test, pak produkce ABEL a RT (deploy skript s `pause` / `resume`),
5. smazat `tasks/`, `PullJobEnqueuer`, `CheckPullWorkerHeartbeat`, crunz závislost.

## 14. Rozsah verzí

| Verze | Obsah |
|---|---|
| **4.0** | tick + hlídací procesy, SQLite, port pravidel souběhu, `maxParallel`, `resources`, `schedules` / `environments`, `after`, `missed`, timeout přes skupinu procesů, ztracené běhy, outbox + ingest v LQDecku, příkazy z LQDecku, hlídané služby (watchdog/keepalive), CLI, pauza pro deploy, health check, stínový režim |
| **4.1** | opakování s backoffem (`retry`, `backoff`), škálování job-workeru jako služba, metriky běhů a alert na neobvykle dlouhý běh v LQDecku |
| **5.x** | sloučení s aplikační frontou jobů (`JobRepository` / `bin/job-worker.php`) do jednoho vykonavatele |

## 15. Záměrné odchylky od dnešního LQDecku

| LQDeck dnes | v4 | Proč |
|---|---|---|
| `repeatCount` N ≥ 1 → N+2 pokusů | N+1 pokusů | off-by-one |
| stale cleanup maže i odložené joby nad 6 h | maže jen dostupné | `delaySeconds` až 24 h jinak nefunguje |
| triage / alert i pro mezipokus | jen finální selhání | šum |
| `schedule-job` na smazaný cron ho obnoví s `active=true` | definice z kódu nemění admin `active` | kill switch nesmí přepsat deploy |
| `max_queue_size = NULL` popsané jako „unlimited“, chová se jako 10 | 10, zdokumentováno | jen popis |
| `ram` = peak rodiče (workeru) | peak potomka | dnes měří špatný proces |

## 16. Otevřené otázky

1. `branch-alias` v `composer.json` na `4.x` (`"4.x-dev"`) a `X-Connector-Version` 4.0 — kdy LQDeck
   začne v4 klienty rozlišovat (ingest endpoint musí být nasazený dřív než první projekt na v4).

Uzavřeno 2026-10-15: větev `4.x` z `3.x`; PHP >= 8.0; push crony zůstávají podporované;
statistiky jen v LQDecku; zaručené doručení událostí; `missed` default `skip`; LQDeck bez nové
branche, jen zpětně kompatibilní rozšíření.

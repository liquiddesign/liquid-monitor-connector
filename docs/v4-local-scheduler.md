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

**Revize 2026-10-16** (review proti kódu LQDecku `cfc619ce` a connectoru `7796ed9`):

- **Pořadí nasazení je tvrdé:** LQDeck musí přijímat major verzi 4 dřív, než jakýkoli projekt přejde
  na `^4` — jinak dostane 426 na *všechna* volání connectoru, i bez plánovače (§ 13.1).
- Kompatibilita schématu SQLite přes `min_reader_version`, aby rollback deploye nezastavil plánovač (§ 12.1).
- Provozní podmínky `stateDir` — práva, `pdo_sqlite`, žádné NFS, zápis z hostu bez plánovače (§ 4.1).
- Politika alertů pro opožděně doručené události, aby výpadek LQDecku neskončil lavinou alertů (§ 8.2).
- **Rozvrh má jediný zdroj — atribut** — i bez `lqcron` (`enqueue-due` místo crunz tasků); crunz
  ve 4.x jen deprecated spouštěč workeru, v 5.0 pryč (§ 5.0).
- Bariéra proti vyhladovění `exclusive` jobů (§ 6.3), stropy na log běhu, výsledek a velikost
  události (§ 7.1, § 7.4, § 8.1), heartbeat mimo outbox (§ 8.1), hlídání slotu při deployi (§ 11.2).

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
   └─ lqcron tick                     ← běží milisekundy, nejvýš 1 instance (flock), NIKDY nesahá na síť
        ├─ použije příkazy z LQDecku, které už leží v SQLite (stáhl je deliver)
        ├─ načte definice cronů (cache v SQLite, obnova po deployi)
        ├─ zařadí splatné běhy do fronty
        ├─ vybere joby k převzetí podle pravidel souběhu
        ├─ pro každý odpojeně spustí  lqcron run-job <runId>   (hlídací proces)
        ├─ uklidí ztracené běhy (mrtvý hlídací proces, prošlý timeout)
        ├─ zajistí běh hlídaných služeb (products-daemon, telemetry agent, …)
        ├─ zapíše heartbeat (jeden řádek v `meta`, ne do outboxu — § 8.1)
        ├─ po deployi / každých 15 min odpojeně spustí  lqcron sync   (export definic, § 5.2)
        └─ když neběží, odpojeně spustí  lqcron deliver
lqcron run-job <runId>                ← 1 na běžící job, žije po dobu jobu
   └─ php vendor/bin/monitor-worker execute --run-id … (nová skupina procesů)
        └─ handler->execute($arguments) v čerstvém Nette kontejneru
lqcron deliver                        ← jediný proces, který mluví s LQDeckem; nejvýš 1 instance (vlastní flock)
   ├─ stáhne příkazy z LQDecku a zapíše je do SQLite
   └─ odesílá outbox, dokud není prázdný (backoff při výpadku), pak skončí
```

Souběh mnoha dlouhých cronů tedy znamená N hlídacích procesů (jednotky MB RSS) + N PHP procesů;
plánovač sám mezi ticky neběží. Dnešní omezení `WorkerRunLoop` (po deadline přestane převzímat,
`waitForRunning` bez horní meze) odpadá.

**Síť je oddělená od plánování.** Tick nečeká na LQDeck ani s časovým limitem: všechno, co jde přes
síť, dělá samostatný proces `lqcron deliver` a s tickem si vyměňuje data jen přes SQLite (outbox
tam, příkazy zpět). Pomalý nebo nedostupný LQDeck tak nedrží zámek ticku a nemůže zdržet ani jeden
běh; ovlivní jen to, kdy LQDeck uvidí události a kdy dorazí příkazy. Stejně tak export definic
(§ 5.2), který bootuje PHP a trvá sekundy: běží v odpojeném `lqcron sync`, ne pod zámkem ticku.

## 3. Distribuce a balíček

- **Major verze 4.0 balíčku `liquiddesign/liquid-monitor-connector`**, žádné nové repo.
- Binárka v Rustu (stejný stack jako `abel-products-daemon`), staticky linkovaná (musl):
  `bin/lqcron-linux-x86_64`, `bin/lqcron-linux-aarch64`. Builduje CI connectoru při release a výsledek
  se commituje do tagu (Composer Rust při instalaci nesestaví). Zdroják v `scheduler/` v tomtéž repu.
- **Release flow** (tag se nikdy nezakládá ručně): ruční spuštění workflow `release` s číslem verze
  na `4.x` → CI sestaví obě binárky (`--release`, `strip`, `opt-level = "z"`), vedle nich zapíše
  `bin/lqcron-*.sha256`, vytvoří attestation původu (`actions/attest-build-provenance`), commitne
  `chore(release): vX.Y.Z` (vč. `Version::CURRENT`) a teprve ten commit otaguje. Cílová velikost
  binárky do 5 MB; každý release ji přidá do historie repa natrvalo, takže při překročení 10 MB
  přejít na release assety.
- `bin/lqcron` je shellový wrapper uvedený v `composer.json` `bin`, vybere binárku podle `uname -m`.
  Chybí-li binárce exec bit, zkusí `chmod +x`; když to nejde (`noexec` mount `vendor/`, cizí vlastník),
  skončí srozumitelnou chybou s návodem, ne `Permission denied`. `lqcron verify` porovná binárku
  s `.sha256`.
- Verze binárky = verze balíčku. `lqcron --version` vypisuje i verzi schématu SQLite; kdy smí starší
  binárka běžet nad novějším schématem, určuje § 12.1.
- PHP část v4: `WorkerExecuteCommand` (lokální režim), export definic, PHP API nad lokální frontou,
  `monitor-worker enqueue-due` (§ 5.0). `WorkerRunLoop`, `WorkerClient` a `MonitorWorkerLauncher`
  zůstávají pro projekty, které lokální plánovač nezapnou (vzdálený pull jako ve v3, viz § 13).
  `crunz/MonitorWorkerTasks.php` je ve 4.x **deprecated** (spouští jen worker, žádné rozvrhy) a v 5.0
  se odstraní; doporučený spouštěč je řádek v crontabu (§ 5.0).

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
| funkce `array_is_list()` (8.1) | 1 soubor (`Telemetry/Agent/MonitorSink.php`) | `symfony/polyfill-php81` nebo vlastní helper |

Atributy (`#[PullCron]`) a constructor promotion jsou 8.0, ty zůstávají.

**Změna veřejného API:** `PullCron::$concurrencyMode` je dnes typovaný enumem `ConcurrencyModeType`.
Po převodu na třídu s konstantami (stejná jména jako dnešní případy) zůstane zápis
`concurrencyMode: ConcurrencyModeType::Exclusive` v atributu beze změny, ale hodnota bude `string`.
Kód projektu, který volá `->value`, `::from()` na enumu nebo typuje parametr `ConcurrencyModeType`,
se rozbije — patří to do upgrade notes 4.0. `public readonly` vlastnosti atributu se změní na `public`
(čtení zůstává kompatibilní).

Testy (`tests/`) musí jít spustit na 8.0 také — dnes v nich je tucet výskytů 8.1+ konstrukcí.

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
		starvationSeconds: 300        # bariéra proti vyhladovění čekajícího jobu, viz § 6.3
		runLogMaxBytes: 52428800      # strop logu jednoho běhu na disku, viz § 7.1
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

### 4.1 Provozní podmínky `stateDir`

Do `lqcron.sqlite` zapisuje tick (uživatel crontabu), hlídací procesy, PHP potomci jobů **a PHP
z webových requestů** (`schedulePullJob` z FPM, § 9). Z toho plynou podmínky, které ověřuje DI
extension při kompilaci kontejneru (s `scheduler.enabled`) a `lqcron status` / health check za běhu:

- **Jeden uživatel, nebo sdílená skupina.** Doporučeno: crontab pod uživatelem, pod kterým běží
  PHP-FPM. Jinak `stateDir` se setgid skupinou sdílenou oběma a `umask 0002` u všech zapisovatelů.
  WAL potřebuje právo zápisu do **adresáře** (soubory `-wal` a `-shm`), ne jen do databáze.
  Tick při každém průchodu ověří, že zapsat jde; když ne, health check hlásí CRITICAL.
- **`ext-pdo_sqlite` v PHP, které joby zařazuje** — typicky FPM, nejen CLI. Balíček ho uvádí
  v `suggest` (projekty bez plánovače ho nepotřebují); s `scheduler.enabled` bez něj kompilace
  kontejneru selže.
- **Žádné NFS ani jiný síťový FS.** WAL vyžaduje sdílenou paměť na jednom stroji; na síťovém FS se
  databáze tiše poškodí. `stateDir` je lokální disk hostu s plánovačem.
- **PHP databázi nikdy nezakládá.** Zakládá a migruje ji jen `lqcron`. Chybí-li soubor (např. enqueue
  na webovém nodu, kde plánovač neběží), PHP vyhodí `SchedulerUnavailable` — nevytvoří prázdnou
  databázi, kterou by nikdo nečetl. PHP 8.0 nemá `PDO::SQLITE_ATTR_OPEN_FLAGS`, proto se existence
  souboru kontroluje před otevřením.
- **Zápis z hostu bez plánovače selže nahlas.** Enqueue porovná `meta.host_id` s hostem, na kterém
  běží; při neshodě vyhodí `SchedulerNotOnThisHost`. Aplikace na víc webových nodech tak musí
  joby zařazovat jen na hostu plánovače (nebo přes HTTP endpoint aplikace na něm) — tiché ztráty
  jobů nejsou přípustné.

## 5. Definice cronů

### 5.0 Rozvrh má jediný zdroj: atribut

**Kdy a v kterém prostředí cron běží, je zapsané jen v atributu `#[PullCron]` jeho handleru**
(`schedule`, `schedules`, `environments`). Nikde jinde — ne v crunz tasku, ne v pomocné funkci
`*CronForEnvironment()`, ne v crontabu po cronech, ne v adminu LQDecku. Atribut je **výkonný**,
ne popisný: co v něm stojí, to běží.

Proč: dnes atribut jen posílá `cronTiming` do LQDecku jako popis a skutečný běh řídí crunz, takže
se ty dva zdroje rozjedou a nikdo to nevidí. Ověřeno v ABELu (`abel-monorepo`, 2026-10-16):
`MergadoPricesHandler` má `schedule: '*/30 * * * *'`, crunz ho ale spouští přes
`mergadoCronForEnvironment()` (`abel/tasks/bootstrap.php:112`) — produkce `30 * * * *`, test
`15 */4 * * *`. Komentář v `FrequentTasks.php` i atribut slibují 30 minut, běží se jednou za hodinu,
a LQDeck ukazuje rozvrh, který neplatí.

Platí v obou režimech 4.x:

- **S `scheduler.enabled`** plánuje `lqcron tick` podle exportu atributů (§ 5.2).
- **Bez plánovače (v3 režim)** nahrazuje crunz tasky po cronech příkaz
  `php vendor/bin/monitor-worker enqueue-due`, spouštěný každou minutu jedním řádkem v crontabu.
  Vyhodnotí atributy pro `scheduler.environment` a splatné crony zařadí přes `schedulePullJob()`.
  Sémantika je stejná jako u crunzu (`isDue(now)`, bez doplňování zmeškaných běhů a bez `missed`);
  zmeškané běhy, DST a `after` umí až `lqcron`. Výrazy vyhodnocuje `dragonmantank/cron-expression`
  (tutéž knihovnu používá crunz, verze 3.x běží na PHP 8.0). Crunzové `preventOverlapping` není
  potřeba: zařazení je okamžité a o souběhu běhů rozhoduje LQDeck podle `concurrencyMode`
  a `maxQueueSize`.

Kontrola: `monitor-worker plan [--environment=production] [--hours=24]` (PHP, oba režimy)
a `lqcron plan` vypíšou efektivní rozvrh po vyhodnocení `schedules` / `environments`. Výstup patří
do PR, který rozvrh mění. LQDeck u `pull` i `local` cronů zobrazuje `timing` jen pro čtení a s
poznámkou „z kódu“.

`enqueue-due` je čistě aditivní a nezávisí na zbytku v4, proto **vyjde už v 3.3** na větvi `3.x`
(rozhodnuto 2026-10-16) a do `4.x` se převezme mergem. ABEL tak srovná rozvrhy dřív, než bude
`lqcron` hotový. Ve 3.3 musí kód dodržet PHP 8.1 (požadavek `3.x`), ve `4.x` pak projde přepisem
na 8.0 s ostatními (§ 3.1).

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

Tick spustí odpojeně `lqcron sync` (vlastní zámek `stateDir/sync.lock`, nejvýš 1 instance), když
(a) se změnil cíl `current` symlinku / mtime `vendor/composer/installed.json`, nebo (b) uplynulo
15 minut od posledního exportu. Ručně jde `lqcron sync` pustit kdykoli (v popředí). Tick na export
nečeká: plánuje podle poslední platné sady a novou použije v příštím průchodu. Selže-li export,
zůstává poslední platná sada a chyba se hlásí (`definitions.failed`, health check WARNING).

Projekt bez Nette (Laravel apod.) dodá vlastní export ve stejném JSON formátu — binárka je na
frameworku nezávislá.

## 6. Plánování a fronta

### 6.1 Vznik běhů

Tick spočítá pro každý aktivní cron výskyty výrazu v intervalu `(last_tick_at, now]` v `timezone`.

- výskyt mladší než `missedGraceMinutes` → zařadit,
- starší výskyty (server neběžel) → `missed: once` zařadí jeden běh, `skip` je zahodí a nahlásí
  `run.missed`,
- DST: neexistující čas (jarní posun) se spustí v první minutě po skoku, dvojitý čas (podzim) jen jednou.
- **První tick** (`last_tick_at` není nastavené — nová instalace, nový `stateDir`): nic se zpětně
  nedoplňuje, `last_tick_at = now`. Zabrání to dávce „zmeškaných“ běhů při zapnutí plánovače.
- **Hodiny jdou pozpátku** (`now < last_tick_at`, korekce NTP): nic se nezařadí, `last_tick_at`
  se nepřepisuje, dokud ho čas nepředběhne; heartbeat nese `clock_skew_seconds`.
  Výskyty v překrytém intervalu se tak nespustí dvakrát.

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

**Bariéra proti vyhladovění (nové ve v4).** Samotné FIFO s přeskakováním (tak to dělá LQDeck
v `ClaimJobsForWorker`) nechá `exclusive` job čekat donekonečna, když se neustále rozbíhají
`parallel` / `fully_parallel` joby; `maxParallel` a `resources` to zhoršují. Proto: čeká-li dostupný
(`available_at <= now`) job déle než `scheduler.starvationSeconds` (default 300 s) a nesmí se spustit,
tick dočasně nespouští žádné joby, které by mu bránily (pro `exclusive` všechny kromě `independent`;
pro job čekající na skupinu prostředků ostatní joby té skupiny; pro `maxParallel` všechny), dokud
nedoběhnou běžící a on se nespustí. Bariéra se hlásí v heartbeatu (`starving: [cron_code…]`).

### 6.4 Opakování a závislosti

- `repeatCount = N` znamená **celkem N+1 pokusů**. LQDeck dnes při N ≥ 1 provede N+2 pokusů
  (`FailJob.php:49`, `>` místo `>=`); v4 to záměrně opravuje.
- Opakovaný běh dostane stejné `arguments` a zařadí se hned (backoff až ve 4.1, § 14).
- `after`: po `finished` rodiče se zařadí závislé crony (bez argumentů); po `failed` ne. Uspěje-li
  rodič až v opakovaném pokusu, závislé crony se zařadí po tomto úspěchu — jednou za původní job,
  ne za každý pokus.

## 7. Spuštění, timeout, výsledek

### 7.1 Hlídací proces `lqcron run-job`

1. Přepne běh na `starting`, spustí PHP v **nové skupině procesů** (`setsid`):
   `php vendor/bin/monitor-worker execute --run-id=<id> --cron-code=… --arguments-file=…`
   (argumenty přes soubor, ne přes CLI ani env — ty jsou vidět v `ps` a mají limit délky).
2. Uloží `pid` + `pid_start_time` (z `/proc/<pid>/stat`) a `app_path` (realpath slotu, ze kterého
   job startuje — § 11.2), přepne na `running`.
3. stdout a stderr čte z rour a zapisuje do `stateDir/runs/<id>.log`, nejvýš `scheduler.runLogMaxBytes`
   (default 50 MB). Po překročení do souboru dál nepíše, drží jen posledních 64 kB v paměti a na konci
   je připojí za značku `[… log zkrácen, vynecháno N B …]`. Upovídaný handler tak nezaplní disk serveru.
4. Každých 30 s zapíše `heartbeat_at`.
5. Po skončení zapíše exit kód, dobu, peak RSS **potomka** (`wait4` rusage, ne rodiče jako dnes)
   a výsledek; běh přepne na `finished` / `failed`.

### 7.2 Timeout

- `timeout` z atributu; `null` znamená **1000 s** (parita s LQDeck: `crons.timeout ?: 1000`).
  Hodnota `0` znamená bez limitu a smí se použít jen vědomě — **pozor, odchylka:** v LQDecku dnes
  `0` kvůli `?:` znamená 1000 s (§ 15). Export definic proto `0` vypíše varováním.
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

`lost` se opakuje stejně jako `failed` (parita s dnešní expirací lease). Job zabitý uprostřed práce
(OOM, restart serveru) tak poběží znovu od začátku — handler, který není idempotentní, musí mít
`repeatCount: 0`.

### 7.4 Výsledek a průběh z handleru

- Výsledek: handler smí vrátit pole (`execute(): array|null` ve v4, `void` zůstává kompatibilní);
  `execute` ho zapíše do `stateDir/runs/<id>.result.json`. stdout už se jako výsledek neparsuje
  (dnes `echo` v handleru rozbije JSON a výsledek skončí jako `output`). Výsledek má strop 256 kB
  JSON; větší se nahradí `{"truncated": true, "bytes": N, "head": "<prvních 4 kB>"}`.
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

- **Odesílání dělá jen `lqcron deliver`**, nikdy tick. Tick ho na konci každého průchodu odpojeně
  spustí, pokud neběží (zámek `stateDir/deliver.lock`) a je co dělat (neprázdný outbox, nebo
  příkazy stažené před víc než 60 s). Deliver posílá v pořadí `seq` dávky (max 200 událostí / 2 MB),
  dokud outbox nevyprázdní, a pak skončí. Při chybě čeká podle backoffu níže a zkouší znovu ve stejném
  procesu; po 10 minutách běhu skončí sám a příští tick ho spustí znovu (ochrana proti zaseknutému
  procesu). Timeouty HTTP: navázání spojení 3 s, celý request 30 s. `lqcron flush` spustí totéž
  v popředí a čeká, dokud není outbox prázdný (po delším výpadku, při deployi).
- **Potvrzení po událostech:** LQDeck odpoví seznamem `accepted: [event_id…]` a
  `rejected: [{event_id, reason, permanent}]`. HTTP 2xx bez tohoto těla se nebere jako potvrzení.
  Mažou se jen `accepted`; vše ostatní zůstává a pošle se znovu.
- **Opakování:** přenosová chyba, timeout, 5xx, 408, 429, ale i 401 / 403 / 404 (výměna API klíče,
  rozbitý deploy LQDecku) a **426** (LQDeck ještě nepřijímá major 4, § 13.1) se opakují donekonečna
  s backoffem 15 s → 30 s → 1 min → max 5 min; backoff se resetuje prvním úspěchem.
  413 → dávka se rozpůlí; vrátí-li 413 i dávka s jedinou událostí, jde událost do `outbox_rejected`
  (nemaže se, viz níže) a ostatní se posílají dál.
- **Strop velikosti události: 1 MB** (po kompresi). Hlídá se už při zápisu do outboxu — výsledek
  (§ 7.4) a konec logu se ořežou dřív, takže událost nad stropem vzniknout nemá; kdyby přesto,
  ořízne se `result` / `log_tail` s příznakem `truncated`.
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
- **Heartbeat není v outboxu.** Je to stav, ne událost: tick ho přepisuje v jediném řádku `meta`
  a `deliver` k **každé** dávce (a nejméně jednou za minutu i s prázdným outboxem) přidá aktuální
  snímek `scheduler.heartbeat`. Pravidlo „nic se nezahazuje“ se na něj nevztahuje — 1 440 starých
  heartbeatů denně po výpadku by jen zdržovalo doručení skutečných událostí. LQDeck tak i během
  dohánění zpožděné fronty vidí čerstvý stav plánovače.

### 8.2 Ingest API v LQDecku (nové)

`POST /api/connector/v4/events` — dávka událostí. Odpověď vždy nese potvrzení po jednotlivých
událostech (`accepted` / `rejected`, § 8.1); LQDeck událost potvrdí až po jejím trvalém uložení
(commit transakce), aby výpadek jeho DB uprostřed zpracování nevedl ke ztrátě. Zaplněná DB
(incident 2026-10-14, `1114 The table is full`) tak vrátí chybu a binárka posílá dál, dokud se místo neuvolní.

| Typ | Obsah |
|---|---|
| `definitions.sync` / `definitions.failed` | celý seznam definic + hash (nahrazuje sync metadat přes `schedule-job`); selhaný export |
| `job.queued` / `job.rejected` / `job.stale` | fronta |
| `run.started` / `run.progress` / `run.finished` / `run.failed` / `run.timeout` / `run.lost` / `run.missed` | životní cyklus běhu, s `run_uuid`, `attempt`, `final` (poslední pokus), časy, RAM, výsledkem, koncem logu |
| `run.shadow` | stínový režim (§ 13.2): co by tick spustil a kdy |
| `service.started` / `service.exited` / `service.restarted` | hlídané služby (§ 10) |
| `command.applied` / `command.failed` | potvrzení příkazu z LQDecku (§ 8.3), s `command_id` |
| `scheduler.heartbeat` | snímek stavu, mimo outbox (§ 8.1): verze, verze schématu, délka fronty, běžící joby, velikost outboxu, `rejected_count`, `paused`, `starving`, `clock_skew_seconds` |

LQDeck je zapisuje do stávajících tabulek (`crons` s `job_execution_mode='local'`, `cron_job_logs`
s novým sloupcem `external_uuid`), takže UI, Slack alerty, triage „Cron failed“ a statistiky fungují dál.
Časy se berou z události, ne z okamžiku přijetí.

Úpravy chování LQDecku pro `local` crony:

- alert a triage task jen při **finálním** selhání (`final: true`; dnes vzniká i pro mezipokusy,
  `CronFailureSource` bere každý `failed` log),
- `ExpireLeasedJobLogs` a `CleanupHangingJobLogs` se jich netýkají — o timeoutu rozhoduje binárka,
- nový alert „Plánovač se nehlásí“: žádný `scheduler.heartbeat` déle než 5 min (bez ohledu na frontu;
  LQDeck frontu nevidí). Nahrazuje „Pull worker silent“. Text alertu nesmí tvrdit, že crony stojí —
  stejně se projeví i výpadek sítě mezi serverem projektu a LQDeckem, zatímco plánovač jede.

**Opožděné události nespouštějí alerty jednotlivě.** Zaručené doručení znamená, že po výpadku
LQDecku dorazí najednou všechna selhání za celou dobu výpadku; dispatcher by z nich udělal desítky
zpráv do Slacku / LQD Chatu a triage tasky na dávno vyřešené problémy. Proto:

- událost, jejíž čas (`occurred_at`) je při přijetí starší než **15 min**, se normálně uloží
  (historie, statistiky, UI), ale **nespustí** alert přes `ProjectAlertDispatcher` ani triage task,
- po zpracování dávky s opožděnými selháními pošle LQDeck za projekt **jeden souhrn**
  („Po výpadku doručeno N selhání M cronů za období od–do“ + odkaz na přehled),
- triage „Cron failed“ vznikne nejvýš jeden na cron, a to z posledního finálního selhání v dohnaném
  období — a jen pokud po něm ten cron už neproběhl úspěšně.

Práh 15 min je konfigurovatelný (`monitor.local_cron_late_event_seconds`); doladit podle stínového
provozu.

### 8.3 Příkazy z LQDecku

`lqcron deliver` při každém spuštění nejdřív stáhne `GET /api/connector/v4/commands` a uloží je do
tabulky `commands`. Tick je na začátku příštího průchodu použije (ještě před plánováním), označí jako
provedené a zapíše potvrzení (`command.applied` / `command.failed`) do outboxu; deliver ho pošle
v další dávce. LQDeck příkaz považuje za
vyřízený až po tomto potvrzení a do té doby ho vrací znovu, takže se neztratí ani při pádu mezi
stažením a provedením (tick provede každý příkaz podle `command_id` jen jednou).

| Příkaz | Účinek |
|---|---|
| `cron.set_active` | lokální kill switch (dnešní admin `active`); stav se pamatuje i při výpadku LQDecku |
| `cron.run_now` | zařadí job (nová funkce, dnes v LQDecku neexistuje) |
| `run.cancel` | ukončí běh jako při timeoutu |
| `scheduler.pause` / `resume` | totéž jako CLI |

Výpadek LQDecku znamená jen to, že příkazy dorazí později. Běžná prodleva příkazu je do 1–2 minut
(deliver je stáhne, tick je použije při dalším průchodu).

## 9. PHP API v režimu lokálního plánovače

| Metoda (`Cron`) | v3 | v4 se `scheduler.enabled` |
|---|---|---|
| `schedulePullJob($class, $args, $delay)` | POST `schedule-job` | zápis do lokální fronty (SQLite přes PDO, transakce + `busy_timeout`) **spolu s událostí `job.queued` do outboxu v téže transakci**; výjimka při plné frontě, neznámém handleru a při porušení § 4.1 (`SchedulerUnavailable`, `SchedulerNotOnThisHost`) |
| `scheduleJob($code)` | POST `schedule-job` (push) | pro pull cron = `schedulePullJob`; push cron beze změny |
| `scheduleOrStartJob(...)` | push | beze změny — push crony zůstávají plně podporované |
| `progressJob` / `finishJob` / `failJob` | v pull potomkovi no-op | v potomkovi lokální událost; jinak (push) beze změny |
| `isCronRunning($code)` | GET LQDeck | lokální SQLite — je to stav, podle kterého plánovač rozhoduje |
| `getLastCronJobLog($code)` | GET LQDeck | GET LQDeck (jako dnes); při nedostupnosti fallback na poslední lokální běh |
| `getCronJobLogsStats` / `getCronOverview` | GET LQDeck | **GET LQDeck beze změny** — statistiky počítá jen server |
| `log()` | POST `/log` | beze změny |

Projekt se tedy při přechodu nemusí měnit v místech, kde joby zařazuje (v ABELu `QiOrderService`,
`ScriptsPresenter`, `QiCronRunner`) — pokud je zařazuje na hostu plánovače (§ 4.1).

PHP tak zapisuje přímo do tabulek `jobs` a `outbox`, jejichž schéma vlastní binárka. Schéma těchto
dvou tabulek je proto **kontrakt mezi Rustem a PHP** a platí pro něj stejná pravidla kompatibility
jako pro hlídací procesy (§ 12.1): PHP ze starého slotu musí umět zapsat do schématu nové binárky.

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
| `lqcron wait-slot <path> [--timeout=sec]` | deploy: počkat, až v daném slotu nepoběží žádný job (exit ≠ 0 po timeoutu) |
| `lqcron verify` | ověří binárku proti `.sha256` (§ 3) |
| `lqcron sync` | znovu načíst definice |
| `lqcron deliver` | odeslání outboxu a stažení příkazů (spouští ho tick odpojeně, nejvýš 1 instance) |
| `lqcron flush` | totéž v popředí, čeká na prázdný outbox |

### 11.2 Deploy (blue/green)

1. `lqcron wait-slot <cílový slot> --timeout=…` — **před** zápisem nového releasu do cílového slotu.
   Blue/green má jen dva sloty, takže deploy přepisuje slot, ve kterém může z minulého deploye pořád
   běžet dlouhý job; přepsat mu kód pod rukama nesmí. Po timeoutu deploy skončí chybou (nebo vědomě
   `run.cancel` přes `lqcron`), nikdy tiše nepokračuje. Běžící joby a jejich slot ukazuje
   `lqcron status` (`runs.app_path`).
2. `lqcron pause --drain=120` — nové joby se nespouštějí, běžící mají 2 min na doběhnutí
   (dlouhé běhy doběhnou ve starém slotu dál, nic se nezabíjí).
3. přepnutí `current`,
4. `lqcron sync && lqcron resume`.

Tím odpadá rozjezd jobů přes přepnutí slotu a joby spouštěné ze starého slotu
(ABEL test, monitor#1203).

**Rollback** (přepnutí `current` zpět) je běžná operace: starší binárka pak běží nad schématem,
které už zmigrovala novější. Smí, pokud splní `min_reader_version` (§ 12.1); jinak tick odmítne běžet
a health check hlásí CRITICAL s pokynem „rollback přes nekompatibilní migraci schématu“.

### 11.3 Jeden plánovač na projekt

`stateDir` musí být sdílený mezi sloty a jen na jednom hostu. Tick při startu zapíše do SQLite
`host_id` (hostname + machine-id); běží-li stejný stateDir na jiném hostu, tick odmítne běžet
(`lqcron tick --takeover` jen ručně). Dva hosty s vlastním stateDir by naplánovaly všechno dvakrát —
to musí pohlídat konfigurace serveru (`scheduler.enabled` jen na jednom). Webové nody bez plánovače
smějí běžet se stejnou konfigurací; zařazení jobu z nich selže výjimkou (§ 4.1), ne tiše.

### 11.4 Health check

`HealthCheck` komponenta connectoru čte `lqcron status --json`: poslední tick starší než 3 min = CRITICAL,
`stateDir` nejde zapsat (§ 4.1) = CRITICAL, tick odmítá běžet kvůli schématu (§ 12.1) = CRITICAL,
selhaný poslední export definic = WARNING,
nejstarší nepotvrzená událost v outboxu starší než 1 h = WARNING (LQDeck nepřijímá),
poslední úspěšné spojení `lqcron deliver` s LQDeckem starší než 1 h = WARNING,
`outbox_rejected` neprázdný = WARNING, méně než 500 MB volného místa ve `stateDir` = CRITICAL. Nahrazuje
`CheckPullWorkerHeartbeat` (čtení řádků z `monitor-worker.log`).

## 12. Úložiště (SQLite, WAL)

`stateDir/lqcron.sqlite`, `journal_mode=WAL`, `busy_timeout=5000`, krátké transakce.
Zapisuje tick, hlídací procesy a PHP enqueue; řádově desítky zápisů za minutu.

| Tabulka | Obsah |
|---|---|
| `meta` | `schema_version`, `min_reader_version`, `host_id`, `last_tick_at`, `paused`, hash definic, čas exportu, heartbeat (jeden řádek, § 8.1) |
| `crons` | definice (code, schedule, timeout, concurrency, resources, after, missed, active, remote_active) |
| `jobs` | fronta (cron_code, arguments, available_at, attempt, source, created_at) |
| `runs` | běhy (uuid, cron_code, job, attempt, state, `app_path`, pid, pid_start_time, started/finished/heartbeat_at, exit_code, signal, max_rss, result, error) |
| `run_events` | progress události |
| `outbox`, `outbox_rejected` | události pro LQDeck (§ 8.1) |
| `commands` | příkazy stažené z LQDecku (`command_id`, payload, `fetched_at`, `applied_at`) |
| `services` | stav hlídaných služeb |

Lokální historie je jen pracovní, ne zdroj statistik: dokončený běh se smaže 7 dní po tom, co LQDeck
potvrdil všechny jeho události (dřív nikdy); logy běhů na disku 7 dní. Historii drží LQDeck.

### 12.1 Kompatibilita schématu

Na jednom `stateDir` současně běží různé verze: hlídací procesy a PHP potomci ze starého slotu,
tick a PHP z nového, a po rollbacku stará binárka nad novým schématem. Proto:

- Schéma migruje **jen `lqcron`** (tick nebo `lqcron sync`), nikdy PHP. Migrace běží v transakci
  a nastaví `schema_version`.
- Migrace jsou **aditivní**: nové tabulky, nové sloupce s defaultem, nové indexy. Nemění význam
  existujících sloupců a nic nemažou. Taková migrace `min_reader_version` nemění.
- `min_reader_version` = nejnižší verze schématu, kterou musí znát čtenář/zapisovatel, aby nad
  databází pracoval správně. Zvedá ji jen migrace, která aditivní být nemůže; ta smí přijít jen
  s minor/major verzí balíčku a v release notes musí stát, že rollback přes ni nejde.
- Každý zapisovatel (tick, `run-job`, `deliver`, `sync`, PHP enqueue/progress) při otevření ověří
  `schema_version_který_znám >= min_reader_version`. Když ne, nezapíše nic: tick odmítne běžet
  (health CRITICAL), PHP vyhodí `SchedulerUnavailable`, hlídací proces zapíše výsledek do
  `stateDir/runs/<id>.result.json` a ztracený běh (§ 7.3) dořeší novější tick.
- Rollback mezi verzemi se stejnou `min_reader_version` tedy plánovač nezastaví.

## 13. Kompatibilita a přechod

### 13.1 Pořadí nasazení (tvrdé)

LQDeck odmítá každou neznámou major verzi connectoru: middleware `TrackConnectorVersion` vrací
**426 Upgrade Required** a `config/connector.php` má dnes `supported_versions => [2, 3]`. Platí to
pro celé `/api/connector/*` — i pro `/log` (chyby) a push lifecycle. Projekt na `^4` by tedy přišel
o hlášení chyb i o push crony, i **bez** `scheduler.enabled`. Proto:

1. **LQDeck:** `supported_versions => [2, 3, 4]` — samostatná změna, nezávislá na ingestu,
   nasazená na produkci jako první,
2. **LQDeck:** ingest `v4/events`, `v4/commands`, režim `local`, `external_uuid`, politika
   opožděných alertů (§ 8.2) a porovnání stínového režimu (§ 13.2),
3. teprve potom první projekt na `^4`.

Release connectoru 4.0 se nevydá, dokud není bod 1 na produkci LQDecku. Postup bumpu drží skill
`connector-versioning` (v obou repech).

### 13.2 Zpětná kompatibilita

- **Bez `scheduler.enabled`** se v4 chová jako v3 (vzdálený pull přes worker, push beze změny) —
  za předpokladu § 13.1. Projekt může přejít na `^4` a plánovač zapnout později. Rozvrhy ale
  i v tomto režimu bere z atributů přes `enqueue-due` (§ 5.0), ne z crunzu.
- **LQDeck** (`liquid-monitor-back`) dostane podporu v4 jako **nové funkce na hlavní větvi, bez nové
  branche a s plnou zpětnou kompatibilitou**: nové endpointy (`v4/events`, `v4/commands`), nový
  `job_execution_mode='local'` a sloupec `external_uuid`; stávající push i v3 pull API se nemění.
  Obsluhuje tak trvale všechny tři druhy klientů (push, v3 pull, v4 lokální) a rozlišuje je podle
  `X-Connector-Version` a režimu cronu. Úpravy chování z § 8.2 (alert jen při finálním selhání,
  „Plánovač mlčí“, timeout řeší binárka) platí jen pro crony v režimu `local`.
- **Stínový režim** (`scheduler.shadow: true`): tick počítá, co by spustil, nic nespouští a posílá
  `run.shadow` události; LQDeck je porovná s během řízeným v3. Slouží k ověření parity rozvrhů
  a pravidel souběhu na testu. Porovnání je **práce v LQDecku** (spárovat `run.shadow` s `cron_job_logs`
  stejného cronu v okně ±2 min, ukázat chybějící / navíc / posunuté běhy) a patří do 4.0 — bez něj
  krok 2 postupu níže nejde vyhodnotit.

### 13.3 Postup pro projekt

ABEL jako první:

1. **srovnat rozvrhy do atributů** (`schedules`, `environments`) a přepnout spouštění na
   `enqueue-due` (§ 5.0; lze už na 3.3). Za pravdu se **nebere atribut, ale to, co dnes skutečně
   běží** (crunz výraz po vyhodnocení `*CronForEnvironment()` a skip gatů pro každé prostředí).
   Pro všech 66 handlerů s `#[PullCron]` v ABELu (+ `rajtiskaren/`) vznikne tabulka *atribut ×
   crunz produkce × crunz test*; každý rozdíl rozhodne člověk (např. `MergadoPricesHandler`:
   atribut `*/30 * * * *` vs. produkce `30 * * * *` — buď opravit atribut na skutečnost, nebo
   vědomě zrychlit běh). Teprve pak smazat rozvrhy z `tasks/*.php`,
2. `^4` + `scheduler.shadow: true` na testu, porovnat se stávajícím během (několik dní, vč. nočních),
3. test: `scheduler.enabled`, crontab → `lqcron tick`, vypnout crunz,
4. RT test, pak produkce ABEL a RT (deploy skript s `pause` / `resume`),
5. smazat `tasks/`, `PullJobEnqueuer`, `CheckPullWorkerHeartbeat`, crunz závislost.

## 14. Rozsah verzí

| Verze | Obsah |
|---|---|
| **4.0** | tick + hlídací procesy, SQLite, port pravidel souběhu, `maxParallel`, `resources`, `schedules` / `environments`, `after`, `missed`, timeout přes skupinu procesů, ztracené běhy, outbox s odesíláním v samostatném `lqcron deliver` + ingest v LQDecku, příkazy z LQDecku, hlídané služby (watchdog/keepalive), CLI, pauza a hlídání slotu pro deploy, health check, stínový režim, bariéra proti vyhladovění, kompatibilita schématu (`min_reader_version`). V LQDecku: `supported_versions` 4 (napřed, § 13.1), ingest, příkazy, politika opožděných alertů, porovnání stínového režimu |
| **3.3** (předstih na `3.x`) | `monitor-worker enqueue-due` + `monitor-worker plan`, `schedules` / `environments` v atributu — jediný zdroj rozvrhu ještě před `lqcron` (§ 5.0) |
| **4.1** | opakování s backoffem (`retry`, `backoff`), škálování job-workeru jako služba, metriky běhů a alert na neobvykle dlouhý běh v LQDecku; v LQDecku alert „rozvrh nesedí“ (skutečné běhy neodpovídají `timing` — zobecněné porovnání ze stínového režimu) |
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
| `timeout = 0` → 1000 s (`timeout ?: 1000`) | `0` = bez limitu | jediný způsob, jak vědomě vypnout limit; export na `0` varuje |
| FIFO s přeskakováním, `exclusive` může čekat donekonečna | FIFO + bariéra po `starvationSeconds` | vyhladovění exclusive jobů |
| alerty z každého doručeného selhání | opožděné události (> 15 min) bez jednotlivých alertů, jeden souhrn | lavina alertů po výpadku |

## 16. Otevřené otázky

1. `branch-alias` v `composer.json` na `4.x` (`"4.x-dev"`).
2. Prahy k doladění ve stínovém provozu ABELu: `starvationSeconds` (300 s), práh opožděné události
   (15 min), `runLogMaxBytes` (50 MB).

Uzavřeno 2026-10-15: větev `4.x` z `3.x`; PHP >= 8.0; push crony zůstávají podporované;
statistiky jen v LQDecku; zaručené doručení událostí; `missed` default `skip`; LQDeck bez nové
branche, jen zpětně kompatibilní rozšíření.

Uzavřeno 2026-10-16 (review): LQDeck přidá major 4 do `supported_versions` jako první krok, nezávisle
na ingestu (§ 13.1); kompatibilita schématu přes `min_reader_version` (§ 12.1); heartbeat mimo outbox;
opožděné události bez jednotlivých alertů; PHP databázi nezakládá a zápis z hostu bez plánovače selže;
rozvrh má jediný zdroj v atributu a `enqueue-due` + `schedules` / `environments` vyjdou už v 3.3
(§ 5.0); crunz ve 4.x jen deprecated spouštěč workeru, v 5.0 pryč.

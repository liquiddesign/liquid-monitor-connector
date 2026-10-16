# Connector v4 — lokální plánovač cronů (`lqcron`)

> **Stav:** návrh specifikace, 2026-10-15, revize a audit 2026-10-16. Nic z toho zatím není
> implementované. Vývoj na větvi `4.x`.
> **Vychází z:** connector 3.2.4 (větev `3.x`, `7796ed9`) a LQDeck `liquid-monitor-back` (`cfc619ce`).

**Rozhodnuto (2026-10-15):**

- **Push crony zůstávají plně podporované**, nejsou deprecated (§ 9).
- **Statistiky a přehledy počítá jen LQDeck**, který je hlavním bodem sběru; lokální SQLite drží
  jen to, co plánovač potřebuje k rozhodování (§ 9, § 12).
- **Doručení do LQDecku je zaručené**: binárka posílá každou událost, dokud ji LQDeck nepotvrdí,
  nic se automaticky nezahazuje (§ 8.1).

**Revize 2026-10-16** (review proti kódu LQDecku `cfc619ce` a connectoru `7796ed9`):

- **Pořadí nasazení je tvrdé:** LQDeck musí přijímat major verzi 4 dřív, než jakýkoli projekt přejde
  na `^4` — jinak dostane 426 na *všechna* volání connectoru, i bez plánovače (§ 13.1).
- Kompatibilita schématu SQLite přes `min_reader_version`, aby rollback deploye nezastavil plánovač (§ 12.1).
- Provozní podmínky `stateDir` — práva, `pdo_sqlite`, žádné NFS, PHP databázi nezakládá (§ 4.1).
- Politika alertů pro opožděně doručené události, aby výpadek LQDecku neskončil lavinou alertů (§ 8.2).
- **Rozvrh má jediný zdroj — atribut** — i bez `lqcron` (`enqueue-due` místo crunz tasků, na `4.x` jako první);
  crunz ve 4.x jen deprecated spouštěč workeru (§ 5.0).
- Bariéra proti vyhladovění `exclusive` jobů (§ 6.3), stropy na log běhu, výsledek a velikost
  události (§ 7.1, § 7.4, § 8.1), heartbeat mimo outbox (§ 8.1), hlídání slotu při deployi (§ 11.2).

**Audit 2026-10-16** (nezávislý, druhé čtení):

- **PHP 8.0 vychází zvlášť** (rozhodnuto): 4.0 drží `php >= 8.1` jako `3.x`; port na 8.0 mění
  veřejné API (enumy), takže je to samostatná major verze — Příloha A.
- Doplněn **stavový automat běhu** (§ 7.0), **mapování událostí na tabulky LQDecku** (§ 8.4)
  a **přepnutí cronu na `local` a zpět** (§ 13.4) — bez nich hrozily dvojí nebo zablokované běhy,
  crony vrácené do `pull` a tichá ztráta triage.
- Konfigurace binárky (§ 4.2), výjimky při zařazení kompatibilní s dnešními volajícími (§ 9),
  `instance_id` místo `host_id` (§ 11.3), otrávená událost neblokuje outbox (§ 8.1), zpoždění
  bez závislosti na hodinách dvou strojů (§ 8.2), řízení toku při dohánění (§ 8.1), oprávnění
  a expirace vzdálených příkazů (§ 8.3), pauza s pojistkou a lokální vypnutí cronu (§ 11),
  konec logu jen u selhání a s redakcí (§ 7.4).

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
        ├─ načte konfiguraci a definice (export ze slotu, § 4.2 a § 5.2)
        ├─ zařadí splatné běhy do fronty
        ├─ vybere joby k převzetí podle pravidel souběhu (stav `claimed`, § 7.0)
        ├─ pro každý odpojeně spustí  lqcron run-job <runId>   (hlídací proces)
        ├─ uklidí ztracené a zaseknuté běhy (§ 7.3)
        ├─ zajistí běh hlídaných služeb (products-daemon, telemetry agent, …)
        ├─ zapíše heartbeat (jeden řádek v `meta`, ne do outboxu — § 8.1)
        ├─ po deployi / každých 15 min odpojeně spustí  lqcron sync   (export, § 5.2)
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

**Procesy `lqcron` běží z verzované kopie binárky** `stateDir/bin/lqcron-<verze>` (tick ji při prvním
spuštění nové verze zkopíruje ze slotu). Deploy, který přepíše slot, tak nesahá pod běžící
hlídací procesy, `deliver` ani `sync` (§ 11.2). Kopie starší než 7 dní bez běžícího procesu se mažou.

## 3. Distribuce a balíček

- **Major verze 4.0 balíčku `liquiddesign/liquid-monitor-connector`**, žádné nové repo.
- **PHP >= 8.1**, stejně jako `3.x`. Port na PHP 8.0 je samostatné vydání (Příloha A).
  Typované konstanty (`const string`, PHP 8.3) dnes ve `src/Orchestrator/` požadavek 8.1 porušují
  — opravuje se na `4.x` bez ohledu na Přílohu A (`3.x` se neřeší, § 16). CI pro `4.x`: matice 8.1 / 8.3 / 8.5
  se spuštěním testů (`nette/tester`), PHPStan s `phpVersion: 80100`.
- Binárka v Rustu (stejný stack jako `abel-products-daemon`), staticky linkovaná (musl):
  `bin/lqcron-linux-x86_64`, `bin/lqcron-linux-aarch64`. Builduje CI connectoru při release a výsledek
  se commituje do tagu (Composer Rust při instalaci nesestaví). Zdroják v `scheduler/` v tomtéž repu.
  SQLite je v binárce zabudovaná (`rusqlite`, feature `bundled`).
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
- PHP část v4: `WorkerExecuteCommand` (lokální režim), export konfigurace a definic, PHP API nad
  lokální frontou, `SchedulerHealth` (§ 11.4), `monitor-worker enqueue-due` a `plan` (§ 5.0).
  `WorkerRunLoop`, `WorkerClient` a `MonitorWorkerLauncher` zůstávají pro projekty, které lokální
  plánovač nezapnou (vzdálený pull jako ve v3, viz § 13). `crunz/MonitorWorkerTasks.php` je ve 4.x
  **deprecated** (spouští jen worker, žádné rozvrhy) a s další major verzí se odstraní; doporučený
  spouštěč je řádek v crontabu (§ 5.0).
- Schéma SQLite a formát exportu jsou kontrakt nejen mezi Rustem a PHP (§ 9), ale i pro Laravel
  konektor (`liquid-monitor-connector-laravel`), který bude dodávat vlastní export a enqueue.

## 4. Konfigurace v projektu

```neon
liquidMonitorConnector:
	url: 'https://lqdeck.example.org/api/connector'
	apiKey: '...'
	workerHandlers: auto
	scheduler:
		enabled: true                 # false = v3 chování (vzdálený pull)
		shadow: false                 # true = stínový režim (§ 13.2), nic nespouští
		environment: %environment%    # klíč pro schedules/environments v atributu
		stateDir: /srv/abel/shared/lqcron   # MIMO release slot, absolutní cesta, povinné
		timezone: Europe/Prague
		maxParallel: 3                # globální strop běžících jobů projektu (dnešní sloty)
		starvationSeconds: 300        # bariéra proti vyhladovění čekajícího jobu, viz § 6.3
		runLogMaxBytes: 52428800      # strop logu jednoho běhu na disku, viz § 7.1
		reportLogTail: true           # posílat konec logu selhaných běhů do LQDecku, viz § 7.4
		logRedactor: null             # služba implementující LogRedactor, viz § 7.4
		remoteCommands: [cron.set_active, cron.run_now, run.cancel]   # povolené příkazy z LQDecku, § 8.3
		resources:                    # skupiny prostředků, viz § 6.3
			qi: 1
			product-write: 1
		phpBinary: /usr/bin/php8.5    # PHP pro joby a export; default: PHP_BINARY exportu
		bootstrap: %appDir%/../bin/monitor-worker-bootstrap.php   # default jako dnes
		services:                     # hlídané služby, viz § 10
			productsDaemon:
				command: [php, vendor/liquiddesign/eshop/products-daemon/bin/products-daemon-watch.php, ...]
				mode: watchdog          # spustit každý tick, služba si sama řeší „už běží“
```

Crontab serveru (jediný řádek místo crunz):

```
* * * * * cd /srv/abel/current && vendor/bin/lqcron tick --php=/usr/bin/php8.5 >> /dev/null 2>&1
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
  kontejneru selže. Stejně tak selže, je-li systémová `sqlite_version()` nižší než **3.24**
  (minimum kontraktu, § 12.1).
- **Žádné NFS ani jiný síťový FS.** WAL vyžaduje sdílenou paměť na jednom stroji; na síťovém FS se
  databáze tiše poškodí. `stateDir` je lokální disk hostu s plánovačem.
- **PHP databázi nikdy nezakládá.** Zakládá a migruje ji jen `lqcron`. Chybí-li soubor (např. enqueue
  na webovém nodu, kde plánovač neběží), PHP vyhodí `SchedulerUnavailable` — nevytvoří prázdnou
  databázi, kterou by nikdo nečetl. Existence souboru se kontroluje před otevřením.
- **Víc webových nodů ve 4.0 podporované není.** Joby z aplikace se smí zařazovat jen na hostu
  plánovače; na ostatních nodech zařazení selže výjimkou (`SchedulerUnavailable`), ne tiše.
  Zařazování přes síť z ostatních nodů je otevřená otázka (§ 16).

### 4.2 Jak binárka dostane konfiguraci

Binárka NEON nečte a zdroj pravdy je jediný: NEON projektu. `lqcron sync` (§ 5.2) spustí PHP export,
který zapíše **konfiguraci i definice** do `./temp/lqcron/export.json` ve slotu (práva `0640`,
obsahuje `url` a `apiKey` — stejné citlivosti jako `local.neon`, který ve slotu už leží):

- `scheduler.*` z NEONu včetně `stateDir`, `timezone`, `maxParallel`, `resources`, `services`,
  `shadow`, `phpBinary`, `remoteCommands`, `url`, `apiKey`,
- definice cronů (§ 5.2),
- hash obsahu.

Tick čte `export.json` ze svého `cwd`. Jediné, co musí znát dřív, je PHP pro spuštění exportu —
proto `--php` v crontabu (default `php` z `PATH`, který v cronu bývá minimální). Chybí-li export
(první spuštění, nový slot), tick odpojeně spustí `sync` a skončí; plánovat začne příští minutu.

Změna NEONu platí po příštím `sync`: hned po `lqcron sync`, jinak po deployi nebo nejpozději za
15 minut. `stateDir` z exportu se při každém ticku porovná s posledním známým; změna `stateDir`
bez `lqcron tick --move-state` je chyba (CRITICAL), aby přepsání cesty v NEONu nezaložilo druhý
plánovač s prázdnou frontou.

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
  - **Minuta se zachytí při startu procesu** (před bootem Nette kontejneru) a vyhodnocuje se
    `isDue(<zachycená minuta>)`, ne `isDue(now)` — boot ani pomalé `schedule-job` tak běh
    nepřesunou do další minuty.
  - **Zámek** (`flock` na `temp/lqcron/enqueue-due.lock`): další minuta počká na doběhnutí
    předchozí až 50 s a pak vyhodnotí **svou** zachycenou minutu. Nic se nevynechá ani nezdvojí;
    když zámek do 50 s nedostane, minutu vynechá a zaloguje to.
  - Selhání zařazení jednoho cronu (např. 429 plná fronta, výpadek LQDecku) nezastaví ostatní.
  - Sémantika je jinak stejná jako u crunzu (bez doplňování zmeškaných běhů, bez `missed`);
    zmeškané běhy, DST a `after` umí až `lqcron`. Výrazy vyhodnocuje `dragonmantank/cron-expression`
    (tutéž knihovnu používá crunz). Crunzové `preventOverlapping` není potřeba: zařazení je
    okamžité a o souběhu běhů rozhoduje LQDeck podle `concurrencyMode` a `maxQueueSize`.

Kontrola: `monitor-worker plan [--environment=production] [--hours=24]` (PHP, oba režimy)
a `lqcron plan` vypíšou efektivní rozvrh po vyhodnocení `schedules` / `environments`. Výstup patří
do PR, který rozvrh mění. LQDeck u `pull` i `local` cronů zobrazuje `timing` jen pro čtení a s
poznámkou „z kódu“.

`enqueue-due` nezávisí na zbytku v4, proto se na `4.x` **implementuje jako první** a ABEL na něj
může přejít dřív, než bude `lqcron` hotový — jakmile LQDeck přijímá connector 4 (§ 13.1 bod 1).
Na `3.x` se nic z toho nedělá (rozhodnuto 2026-10-16: všechny změny jen na `4.x`).

### 5.1 Atribut `#[PullCron]` — rozšíření

Stávající pole (`schedule`, `name`, `repeatCount`, `concurrencyMode`, `description`, `timeout`,
`maxQueueSize`) zůstávají. Nová:

| Pole | Typ | Význam |
|---|---|---|
| `schedules` | `array<string, string>` | přepsání `schedule` podle `scheduler.environment` (`['test' => '50 0 * * *']`); nahrazuje `dailyAtForEnvironment()` a spol. |
| `environments` | `list<string>\|null` | cron se plánuje jen v těchto prostředích; `null` = všude. Nahrazuje `->skip(isProductionEnvironment())`. Env-gate se pak píše sem i s `TODO(freelo …)`. |
| `resources` | `list<string>` | skupiny prostředků (§ 6.3) |
| `after` | `class-string\|null` | spustit po úspěšném doběhnutí jiného cronu (náhrada `crons.cron_id` z adminu LQDecku, § 8.4) |
| `missed` | `'skip'\|'once'` | co s během, který se nestihl naplánovat (server neběžel); default `skip` |
| `missedGraceMinutes` | `int` | jak pozdě se běh ještě spustí jako „včas“; default 5 |

Cron code se dál odvozuje z názvu třídy (`CronJobHandlerCode::fromClassName`), aby historie
v LQDecku navázala.

### 5.2 Jak se definice dostanou do binárky

Binárka PHP nečte. Konfiguraci (§ 4.2) a definice exportuje PHP příkaz:

```
php vendor/bin/monitor-worker export-definitions --json
```

Nabootuje Nette kontejner, projde registry handlerů (`workerHandlers: auto`), vyhodnotí atributy
pro `scheduler.environment` a vypíše normalizovaný seznam (code, schedule, timeout, …) + hash.

Tick spustí odpojeně `lqcron sync` (vlastní zámek `stateDir/sync.lock`, nejvýš 1 instance), když
(a) se změnil cíl `current` symlinku / mtime `vendor/composer/installed.json` / mtime NEON souborů
z `export.json` (`configFiles`), nebo (b) uplynulo 15 minut od posledního exportu. Ručně jde
`lqcron sync` pustit kdykoli (v popředí). Tick na export nečeká: plánuje podle poslední platné sady
a novou použije v příštím průchodu. Selže-li export, zůstává poslední platná sada a chyba se hlásí
(`definitions.failed`, health check WARNING).

Projekt bez Nette (Laravel apod.) dodá vlastní export ve stejném JSON formátu — binárka je na
frameworku nezávislá.

## 6. Plánování a fronta

### 6.1 Vznik běhů

Tick spočítá pro každý aktivní cron výskyty výrazu v intervalu `(last_tick_at, now]` v `timezone`.

- výskyt mladší než `missedGraceMinutes` → zařadit, ale **nejvýš jeden job na cron a tick** (poslední
  výskyt v okně). Mezera mezi ticky (zátěž, restart cronu) tak nezařadí čtyři běhy `* * * * *` naráz.
- starší výskyty (server neběžel) → `missed: once` zařadí jeden běh, `skip` je zahodí a nahlásí
  `run.missed` (jedna událost s počtem a rozsahem, ne jedna na výskyt),
- **DST:** výraz s pevnou hodinou (`30 2 * * *`) se vyhodnocuje v místním čase — neexistující čas
  (jarní posun) se spustí v první minutě po skoku, dvojitý čas (podzim) jen jednou. Výraz s `*`
  nebo krokem v poli hodin (`0 * * * *`, `*/15 * * * *`) se vyhodnocuje po skutečných okamžicích
  (UTC), takže v opakované hodině běží znovu a hodinový cron o běh nepřijde.
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
  i odložené joby. Při plné frontě se zařazení odmítne výjimkou `QueueFull` (§ 9) a pošle se `job.rejected`.
- Opakování a `after` frontový limit obcházejí (jako dnes).
- Job čekající déle než 6 h **a už dostupný** (`available_at <= now`) se smaže s `job.stale`.
  Oproti LQDecku se nemažou odložené joby, kterým zpoždění ještě neuplynulo. **Doba pauzy (§ 11.2)
  se nezapočítává** — stáří se měří od `max(available_at, posledni_resume_at)`, takže víkendová
  pauza nesmaže joby zařazené z webu.

### 6.3 Pravidla souběhu (port `CanCronStartJob`)

„Aktivní běh“ = stav `claimed`, `starting`, `running` nebo `stopping` v rámci projektu (stateDir), § 7.0.

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

**Push crony a lokální crony se navzájem neblokují.** Lokální plánovač push běhy nevidí a LQDeck
při rozhodování o push cronu lokální běhy ignoruje (§ 8.4). Výlučnost (`exclusive`) mezi push
a lokálními crony téhož projektu proto **neplatí** — dnes ji push a pull sdílejí. Projekt, který ji
potřebuje, musí oba crony převést na lokální. Uvedeno i v § 15.

### 6.4 Opakování a závislosti

- `repeatCount = N` znamená **celkem N+1 pokusů**. LQDeck dnes při N ≥ 1 provede N+2 pokusů
  (`FailJob.php:49`, `>` místo `>=`); v4 to záměrně opravuje.
- Opakuje se po `failed`, `timeout` a `lost`, ne po `cancelled` (§ 7.0).
- Opakovaný běh dostane stejné `arguments` a zařadí se hned (backoff až ve 4.1, § 14).
- `after`: po `finished` rodiče se zařadí závislé crony (bez argumentů); po `failed` ne. Uspěje-li
  rodič až v opakovaném pokusu, závislé crony se zařadí po tomto úspěchu — jednou za původní job,
  ne za každý pokus.

## 7. Spuštění, timeout, výsledek

### 7.0 Stavový automat běhu

Každý přechod je compare-and-set (`UPDATE runs SET state = ? WHERE id = ? AND state = ?`); kdo
přechod nevyhraje, nic nedělá. Tak se nemůže stát, že finální stav zapíšou dva procesy.

| Stav | Kdo ho zapíše | Aktivní (§ 6.3) | Retry | Událost |
|---|---|---|---|---|
| `claimed` | tick v transakci výběru (job z fronty → řádek v `runs`, `claimed_at`) | ano | — | — |
| `starting` | `run-job` (CAS z `claimed`, uloží svůj `supervisor_pid` + start time) | ano | — | — |
| `running` | `execute` v PHP sám, jako první akce po startu (CAS ze `starting`, uloží svůj `pid` + start time) | ano | — | `run.started` |
| `stopping` | `run-job` nebo tick při timeoutu / zrušení, než doběhne celá skupina procesů | ano | — | — |
| `finished` | `run-job`, nebo tick podle markeru (níže) | ne | — | `run.finished` |
| `failed` | dtto | ne | ano | `run.failed` |
| `timeout` | dtto, po `stopping` | ne | ano | `run.timeout` |
| `cancelled` | dtto, po `stopping` (`run.cancel`, `lqcron cancel`) | ne | **ne** | `run.cancelled` |
| `lost` | tick (§ 7.3) | ne | ano | `run.lost` |

Pravidla:

- **Pid zapisuje proces sám o sobě.** `run-job` svůj, PHP `execute` svůj (dostane `LQCRON_RUN_ID`
  v env). Pád hlídacího procesu mezi spuštěním PHP a zápisem pidu tak nenechá PHP bez evidence.
- **Zaseknutý `claimed` / `starting`:** běh, který je v `claimed` déle než 60 s, nebo ve `starting`
  déle než 60 s s mrtvým `supervisor_pid` a bez `pid`, tick vrátí do fronty **bez započtení pokusu**
  (handler nezačal) a řádek v `runs` smaže. Nedrží tak `exclusive` navždy a nepoběží dvakrát.
- **Konec běhu je marker na disku.** `execute` na konci atomicky (zápis do dočasného souboru
  + `rename`) zapíše `stateDir/runs/<id>.exit.json`: výsledek (exit status, chyba, výsledek
  handleru). Stav v SQLite se odvozuje z markeru; když hlídací proces umře po doběhnutí PHP,
  tick podle markeru běh uzavře správně a handler znovu nespustí.
- **Skupina procesů musí být prázdná.** Po skončení hlavního PHP procesu pošle `run-job` celé
  skupině `SIGTERM` a po `killGraceSeconds` `SIGKILL`; běh se počítá jako aktivní (`stopping`),
  dokud ve skupině něco žije. Vnoučata handleru tak neporuší pravidla souběhu.

### 7.1 Hlídací proces `lqcron run-job`

1. CAS `claimed → starting`, uloží `supervisor_pid` + start time. Spustí PHP v **nové skupině
   procesů** (`setsid`): `php vendor/bin/monitor-worker execute --run-id=<id> --cron-code=…
   --arguments-file=…` (argumenty přes soubor, ne přes CLI ani env — ty jsou vidět v `ps` a mají limit
   délky). `execute` sám přepne běh na `running` (§ 7.0) a uloží `app_path` (realpath slotu, § 11.2).
2. **stdout a stderr jdou přímo do souboru** `stateDir/runs/<id>.log` (otevřený v režimu
   `O_APPEND` a předaný potomkovi), ne přes rouru — smrt hlídacího procesu tak potomka neshodí
   na `SIGPIPE`. Hlídací proces každých 5 s kontroluje velikost; nad `scheduler.runLogMaxBytes`
   (default 50 MB) odloží posledních 64 kB, soubor zkrátí a zapíše značku
   `[… log zkrácen, vynecháno N B …]`. Upovídaný handler tak nezaplní disk serveru.
3. Každých 30 s zapíše `heartbeat_at` (monotónní čas `CLOCK_BOOTTIME`, ne wall clock — posun
   hodin NTP heartbeat nezestárne).
4. Po skončení PHP vyprázdní skupinu procesů (§ 7.0), přečte marker, zapíše exit kód, dobu,
   peak RSS **potomka** (`wait4` rusage, ne rodiče jako dnes) a přepne běh do finálního stavu.

### 7.2 Timeout

- `timeout` z atributu; `null` znamená **1000 s** (parita s LQDeck: `crons.timeout ?: 1000`).
  Hodnota `0` znamená bez limitu a smí se použít jen vědomě — **pozor, odchylka:** v LQDecku dnes
  `0` kvůli `?:` znamená 1000 s (§ 15). Export definic proto `0` vypíše varováním.
- Po vypršení: běh přejde do `stopping`, `SIGTERM` celé skupině, po `killGraceSeconds` (default 30)
  `SIGKILL` celé skupině. Skončí tak i podprocesy handleru. Po vyprázdnění skupiny stav `timeout`,
  do hlášení jde signál, doba a konec logu.
- `execute` v PHP zaregistruje `pcntl_signal(SIGTERM)` a dá handleru šanci uklidit
  (volitelné rozhraní `InterruptibleCronJobHandler::interrupt()`).

### 7.3 Ztracené a zaseknuté běhy

Živost se určuje **podle procesu**, ne podle stáří heartbeatu: tick ověří `supervisor_pid`
+ start time (`/proc/<pid>/stat`). Kontrola času startu brání záměně s recyklovaným PID.

- **Hlídací proces žije, heartbeat čerstvý** → nic.
- **Hlídací proces žije, ale heartbeat je starší než 120 s** (zaseknutý — SQLite busy, `SIGSTOP`):
  tick ho zabije `SIGKILL` a pokračuje jako u mrtvého. Nikdy nepracují dva hlídači nad jedním během.
- **Hlídací proces je mrtvý:**
  - existuje marker `<id>.exit.json` → běh se uzavře podle markeru (skupina se předtím vyprázdní),
  - PHP podle `pid` ještě žije → tick skupinu ukončí jako v § 7.2 a běh dostane `lost`
    (žádné „převzetí hlídání“ — tick není rodič, exit kód ani RSS by neznal),
  - PHP neexistuje a marker chybí → `lost`.

`lost` se opakuje stejně jako `failed` (parita s dnešní expirací lease). Job zabitý uprostřed práce
(OOM, restart serveru) tak poběží znovu od začátku — handler, který není idempotentní, musí mít
`repeatCount: 0`.

### 7.4 Výsledek, průběh a chyba z handleru

- **Rozhraní handleru se nemění:** `CronJobHandler::execute(?array $arguments): void`. Návratový
  typ `array|null` by rozbil všechny stávající implementace (`void` nejde zúžit ani rozšířit
  kompatibilně), proto výsledek handler předává přes `Cron::finishJob($data)` — v potomkovi
  lokálního běhu to jen zapíše data do markeru. stdout se jako výsledek neparsuje (dnes `echo`
  v handleru rozbije JSON a výsledek skončí jako `output`). Výsledek má strop 256 kB JSON; větší
  se nahradí `{"truncated": true, "bytes": N, "head": "<prvních 4 kB>"}`.
- **Kdo rozhoduje o výsledku:** neošetřená výjimka nebo nenulový exit → `failed`. Explicitní
  `Cron::failJob($data)` v potomkovi → `failed` i při exit 0 (vědomé rozhodnutí handleru vyhrává).
  Jinak exit 0 → `finished` s daty z posledního `finishJob()`.
- **Průběh:** `Cron::progressJob($data)` v potomkovi zapíše událost do SQLite. Dnes je v pull
  potomkovi no-op a ABEL ho volá ve smyčkách (`ImportPresenter`) — proto se **slučuje**: nejvýš
  jedna událost `run.progress` za 10 s na běh, poslední hodnota vyhrává.
- **Chyba:** do hlášení jde zpráva výjimky **i trace** (dnes jen `getMessage()`). `execute`
  nastaví `zend.exception_ignore_args=On`, takže trace nenese argumenty volání (hesla, osobní
  údaje).
- **Konec logu (64 kB)** jde do LQDecku **jen u `failed` / `timeout` / `lost`** a jen při
  `scheduler.reportLogTail: true` (default). Před uložením do outboxu projde volitelnou službou
  `scheduler.logRedactor` (rozhraní `LogRedactor::redact(string): string`). Konec logu i trace
  totiž v LQDecku tečou do popisu triage tasku a tím do kontextu LLM agenta.

## 8. LQDeck jako informační vrstva

### 8.1 Outbox — zaručené doručení

Každá událost (změna stavu běhu, fronty, definic) se nejdřív zapíše do tabulky `outbox`
**ve stejné transakci** jako změna stavu, kterou popisuje, s jedinečným `event_id` (UUID v7)
a pořadovým číslem `seq`. Teprve potom se odesílá. Událost tedy nemůže vzniknout „jen v paměti“
a ztratit se pádem procesu.

**Pravidlo: událost se z outboxu smaže jen tehdy, když ji LQDeck výslovně potvrdí.** Nic se
nezahazuje kvůli stáří, velikosti ani počtu pokusů. Po výpadku LQDecku (hodiny, dny) se do něj
dostane všechno, ve stejném pořadí, s původními časy.

- **Odesílání dělá jen `lqcron deliver`**, nikdy tick. Tick ho na konci každého průchodu odpojeně
  spustí, pokud neběží (zámek `stateDir/deliver.lock`) a je co dělat (neprázdný outbox, nebo
  příkazy stažené před víc než 60 s). Deliver posílá v pořadí `seq` dávky (max 200 událostí / 2 MB,
  nebo méně podle `max_batch` z poslední odpovědi LQDecku), dokud outbox nevyprázdní, a pak skončí.
  Při chybě čeká podle backoffu níže a zkouší znovu ve stejném procesu; po 10 minutách běhu skončí
  sám a příští tick ho spustí znovu (ochrana proti zaseknutému procesu). Timeouty HTTP: navázání
  spojení 3 s, celý request 30 s. `lqcron flush` spustí totéž v popředí a čeká, dokud není outbox
  prázdný (po delším výpadku, při deployi).
- **Dávka nese `sent_at`** (lokální hodiny serveru projektu) — slouží k výpočtu zpoždění (§ 8.2).
- **Potvrzení po událostech:** LQDeck odpoví seznamem `accepted: [event_id…]` a
  `rejected: [{event_id, reason, permanent}]`. HTTP 2xx bez tohoto těla se nebere jako potvrzení.
  Mažou se jen `accepted`; vše ostatní zůstává a pošle se znovu.
- **Opakování:** přenosová chyba, timeout, 5xx, 408, 429, ale i 401 / 403 / 404 (výměna API klíče,
  rozbitý deploy LQDecku) a **426** (LQDeck ještě nepřijímá major 4, § 13.1) se opakují donekonečna
  s backoffem 15 s → 30 s → 1 min → max 5 min a **jitterem ±20 %**; backoff se resetuje prvním
  úspěchem. 429 s hlavičkou `Retry-After` se řídí jí (řízení toku ze serveru při dohánění backlogu
  všech projektů najednou). 413 → dávka se rozpůlí; vrátí-li 413 i dávka s jedinou událostí, jde
  událost do `outbox_rejected` (nemaže se, viz níže) a ostatní se posílají dál.
- **Otrávená událost neblokuje frontu.** Vrátí-li LQDeck 5xx pětkrát po sobě na dávku se stejným
  prvním `seq`, deliver dávku půlí jako u 413, až najde jedinou událost, na které server padá.
  Ta dostane `poisoned_at`, běžné dávky ji přeskakují a zkouší se samostatně jednou za hodinu.
  Nemaže se; hlásí se v heartbeatu (`poisoned_count`) a jako health WARNING.
- **Strop velikosti události: 1 MB** (po kompresi). Hlídá se už při zápisu do outboxu — výsledek
  (§ 7.4) a konec logu se ořežou dřív, takže událost nad stropem vzniknout nemá; kdyby přesto,
  ořízne se `result` / `log_tail` s příznakem `truncated`.
- **Trvale odmítnutá událost** (`permanent: true`, např. validační chyba po změně schématu)
  se přesune do `outbox_rejected` — **nemaže se**. Hlásí se lokálně (Tracy log, `lqcron status`,
  health check WARNING) i v každém `scheduler.heartbeat` (`rejected_count`), aby o ní LQDeck věděl.
  `lqcron outbox retry-rejected` je pošle znovu (typicky po opravě LQDecku nebo upgradu connectoru).
- **Pořadí:** odmítnutá událost neblokuje ostatní; LQDeck musí unést, že např. `run.finished`
  přijde bez dříve odmítnutého `run.started` (založí běh z dostupných údajů).
- **Idempotence:** LQDeck deduplikuje podle `event_id` (§ 8.4), takže opakované odeslání
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
událostech (`accepted` / `rejected`, § 8.1) a `max_batch`. **Každá událost se zpracuje ve vlastní
transakci** (savepoint): neošetřená výjimka u jedné události se převede na
`rejected {permanent: false}` a ostatní v dávce projdou. LQDeck událost potvrdí až po jejím trvalém
uložení (commit), aby výpadek jeho DB uprostřed zpracování nevedl ke ztrátě. Zaplněná DB (incident
2026-10-14, `1114 The table is full`) tak vrátí chybu a binárka posílá dál, dokud se místo neuvolní.

`v4/events` a `v4/commands` **nepatří do kritického Octane poolu** (`CriticalTraffic`): běh lokálních
cronů na nich nezávisí, takže zpoždění nevadí, a dohánění backlogu všech projektů po výpadku nesmí
vytlačit příjem chyb a push lifecycle. Při zátěži LQDeck vrací 429 s `Retry-After`.

| Typ | Obsah |
|---|---|
| `definitions.sync` / `definitions.failed` | celý seznam definic + hash + `mode` (`local` / `shadow`); selhaný export |
| `job.queued` / `job.rejected` / `job.stale` | fronta (ukládá se jen do `connector_events`, § 8.4) |
| `run.started` / `run.progress` / `run.finished` / `run.failed` / `run.timeout` / `run.cancelled` / `run.lost` | životní cyklus běhu, s `run_uuid`, `attempt`, `final` (poslední pokus), `occurred_at`, RAM, výsledkem, u selhání koncem logu |
| `run.missed` | vynechané výskyty (počet, rozsah) |
| `run.shadow` | stínový režim (§ 13.2): co by tick spustil a kdy |
| `service.started` / `service.exited` / `service.restarted` | hlídané služby (§ 10) |
| `command.applied` / `command.failed` | potvrzení příkazu z LQDecku (§ 8.3), s `command_id` |
| `scheduler.heartbeat` | snímek stavu, mimo outbox (§ 8.1): `instance_id`, verze, verze schématu, délka fronty, běžící `run_uuid`, velikost outboxu, `rejected_count`, `poisoned_count`, `paused` (+ zdroj a od kdy), `starving`, `clock_skew_seconds` |

Úpravy chování LQDecku pro `local` crony:

- **alert ani triage task nevzniká pro mezipokusy** — jen pro finální selhání (`final: true`).
  Dnes alert chodí už jen při finálním selhání (`FailJob.php:49`), ale triage task
  (`CronFailureSource`) vzniká z každého `failed` logu, tedy i z mezipokusu,
- `ExpireLeasedJobLogs` a `CleanupHangingJobLogs` se jich netýkají — o timeoutu rozhoduje binárka,
- **smíření běžících běhů:** heartbeat nese seznam běžících `run_uuid`; `Started` log lokálního
  cronu, který chybí ve třech po sobě jdoucích heartbeatech, LQDeck uzavře jako `failed`
  s `outcome = lost_reconciled`, bez alertu. Přijde-li později skutečná událost běhu, má přednost.
  Ztracená nebo odmítnutá `run.finished` tak nenechá v LQDecku běh „navždy běžící“,
- nový alert **„Plánovač se nehlásí“**: žádný `scheduler.heartbeat` déle než 5 min (bez ohledu na
  frontu; LQDeck frontu nevidí). Nahrazuje „Pull worker silent“. Text alertu nesmí tvrdit, že crony
  stojí — stejně se projeví i výpadek sítě mezi serverem projektu a LQDeckem, zatímco plánovač jede,
- alert **„Plánovač je pozastavený“**: heartbeat hlásí `paused` déle než 15 min (§ 11.2),
- alert **„Dva plánovače pro jeden projekt“**: během 5 min přijdou heartbeaty se dvěma různými
  `instance_id` (§ 11.3),
- informace **„Hodiny serveru ujíždějí“**: rozdíl `received_at − sent_at` nad 60 s.

**Opožděné události nespouštějí alerty jednotlivě.** Zaručené doručení znamená, že po výpadku
LQDecku dorazí najednou všechna selhání za celou dobu výpadku; dispatcher by z nich udělal desítky
zpráv do Slacku / LQD Chatu a triage tasky na dávno vyřešené problémy. Proto:

- **zpoždění = `sent_at − occurred_at`**, obojí z hodin serveru projektu — nezávisí na rozdílu
  hodin dvou strojů, takže ujeté hodiny serveru alerty tiše nepotlačí,
- událost se zpožděním nad **15 min** se normálně uloží (historie, statistiky, UI) s příznakem
  `delivered_late`, ale **nespustí** alert přes `ProjectAlertDispatcher` ani triage task,
- po zpracování dávky s opožděnými selháními pošle LQDeck za projekt **jeden souhrn**
  („Po výpadku doručeno N selhání M cronů za období od–do“ + odkaz na přehled),
- triage „Cron failed“ vznikne nejvýš jeden na cron, a to z posledního finálního selhání v dohnaném
  období — a jen pokud po něm ten cron už neproběhl úspěšně.

Práh 15 min je konfigurovatelný (`monitor.local_cron_late_event_seconds`); doladit podle stínového
provozu.

### 8.3 Příkazy z LQDecku

`lqcron deliver` při každém spuštění nejdřív stáhne `GET /api/connector/v4/commands` a uloží je do
tabulky `commands`. Tick je na začátku příštího průchodu použije (ještě před plánováním) v pořadí
`seq`, označí jako provedené a zapíše potvrzení (`command.applied` / `command.failed`) do outboxu;
deliver ho pošle v další dávce. LQDeck příkaz považuje za vyřízený až po tomto potvrzení a do té
doby ho vrací znovu, takže se neztratí ani při pádu mezi stažením a provedením (tick provede každý
příkaz podle `command_id` jen jednou; řádek v `commands` drží 30 dní po provedení).

Příkaz nese `command_id`, `seq` (pořadí v rámci projektu — `set_active false` a `true` se tak
nepřehodí), `issued_at` a `expires_at`. Prošlý příkaz se neprovede a potvrdí se jako
`command.failed {reason: expired}` — `run_now` doručený po dvoudenním výpadku sítě se tak nespustí
o dva dny později.

| Příkaz | Účinek | Platnost | Oprávnění v LQDecku |
|---|---|---|---|
| `cron.set_active` | vzdálený kill switch (`remote_active`); pamatuje se i při výpadku LQDecku | bez expirace | `Update:Cron` |
| `cron.run_now` | zařadí job; **ve 4.0 bez argumentů** | 15 min | `Run:Cron` (nová) |
| `run.cancel` | ukončí běh jako při timeoutu, stav `cancelled`, bez opakování | 15 min | `Run:Cron` |
| `scheduler.pause` / `resume` | vzdálená pauza (§ 11.2) | bez expirace | jen super-admin |

- **Projekt rozhoduje, co z LQDecku přijme:** `scheduler.remoteCommands` v NEONu (default bez
  `scheduler.pause`). Nepovolený příkaz se potvrdí jako `command.failed {reason: not_allowed}`.
- Každý příkaz se v LQDecku auditně loguje (kdo, kdy, co).
- Každá nová admin routa pro příkazy musí mít MCP tool (`AdminMcpParityTest`).

Výpadek LQDecku znamená jen to, že příkazy dorazí později. Běžná prodleva příkazu je do 1–2 minut
(deliver je stáhne, tick je použije při dalším průchodu).

### 8.4 Mapování na tabulky LQDecku

Lokální crony žijí v existujících tabulkách, aby UI, MCP, statistiky a alerty fungovaly dál.
Změny schématu (`liquid-monitor-back`, aditivní migrace):

| Tabulka | Změna |
|---|---|
| `crons` | `job_execution_mode` nově i `local` (jen per cron, **nikdy jako režim projektu** — výběr režimu projektu v `ProjectWriteSchema` ho nenabízí); `local_blocked_at` na projektu (§ 13.4) |
| `cron_job_logs` | `job_id` → **nullable** (u `local` vždy NULL); nové `external_uuid` (UNIQUE, nullable), `attempt`, `is_final` (nullable — NULL u push/pull = dnešní chování), `outcome` (nullable: `timeout`, `lost`, `lost_reconciled`, `cancelled`, `handover`), `occurred_at`, `delivered_late` |
| `connector_events` (nová) | `event_id` (PK), `project_id`, `type`, `received_at`, `payload` (jen u `job.*`, `run.missed`, `service.*`) — deduplikace a záznam událostí bez vlastní tabulky; retence 90 dní |

Mapování událostí:

| Událost | Operace |
|---|---|
| `definitions.sync` (`mode: local`) | upsert `crons` (metadata z kódu, `active` se nemění) + přepnutí na `local` podle § 13.4 |
| `definitions.sync` (`mode: shadow`) | jen uložit pro porovnání, **režim cronu nemění** |
| `run.started` | insert `cron_job_logs`: `type=started`, `external_uuid`, `attempt`, `started_ts = occurred_at`; `created_at` = **čas přijetí** |
| `run.progress` | `cron_job_log_items` typu progress |
| `run.finished` | update logu podle `external_uuid` (nebo insert, když `started` chybí): `type=finished`, `finished_ts`, RAM, výsledek |
| `run.failed` / `run.timeout` / `run.lost` / `run.cancelled` | `type=failed` + `outcome`, `is_final`, `delivered_late`; konec logu do `cron_job_log_items` s **limitem 16 kB** |
| `run.missed`, `job.*`, `service.*` | jen `connector_events`; do `cron_jobs` se nic nezrcadlí (fronta je lokální, délku nese heartbeat) |
| `command.*` | označí příkaz jako vyřízený |

Pravidla, která z toho plynou:

- **`created_at` je čas přijetí, ne události.** `CronFailureSource` čte selhání od kurzoru
  `last_scanned_at` podle `created_at`; zpětně datovaný řádek by spadl před kurzor a triage by
  z něj nikdy nevznikl. Časy události nesou `started_ts` / `finished_ts` / `occurred_at`; UI
  zobrazuje je.
- **Triage** (`CronFailureSource`) u `local` cronů filtruje `is_final = true AND delivered_late = false`
  (souhrn po výpadku § 8.2 řeší zvlášť). Push/pull beze změny.
- **Connector API v3 lokální logy nevidí.** `start-job`, `progress-job`, `finish-job`, `fail-job`,
  `heartbeat-job` hledají log podle `job_id`; u `local` je NULL, a navíc se dotazy omezí na crony
  s režimem jiným než `local`. Push `finish-job` se stejným číslem tak lokální běh nikdy neuzavře.
- **`CanCronStartJob` ignoruje logy `local` cronů** (§ 6.3) — jinak by lokální `Started` log blokoval
  push crony projektu, a při ztracené `run.finished` natrvalo.
- **`FailJob` se pro `local` nevolá nikdy** — sám zakládá retry `Job`, a o opakování rozhoduje
  binárka. Ruční „fail“ z adminu (`job-logs.fail`) a MCP `fail_job_log` nad lokálním logem se
  přeloží na příkaz `run.cancel`; je-li běh už ukončený, akce se odmítne.
- **Řetězení `crons.cron_id`** z adminu se na `local` crony nevztahuje (`FinishJobController` je
  nezakládá); jediné řetězení lokálních cronů je `after` v atributu. Řetězení `local → push` ani
  `push → local` ve 4.0 není; admin u `local` cronu pole skryje.
- `Cron::isCodeManaged()` vrací true pro `pull` i `local`; `CronController` a `CronWriteSchema`
  u nich drží `timing` a metadata jen pro čtení.
- **Retence** `cron_job_logs` beze změny (30 dní podle `created_at`).

## 9. PHP API v režimu lokálního plánovače

| Metoda (`Cron`) | v3 | v4 se `scheduler.enabled` |
|---|---|---|
| `schedulePullJob($class, $args, $delay)` | POST `schedule-job` | zápis do lokální fronty (SQLite přes PDO, transakce + `busy_timeout`) **spolu s událostí `job.queued` do outboxu v téže transakci**; chyby jako `SchedulingFailedException` (níže) |
| `scheduleJob($code)` | POST `schedule-job` (push) | pro pull cron = `schedulePullJob`; push cron beze změny |
| `scheduleOrStartJob(...)` | push | beze změny — push crony zůstávají plně podporované |
| `progressJob` / `finishJob` / `failJob` | v pull potomkovi no-op | v potomkovi lokálního běhu zápis do SQLite / markeru (§ 7.4); jinak (push) beze změny |
| `isCronRunning($code)` | GET LQDeck (true i pro job čekající ve frontě) | lokální SQLite — stejná sémantika: aktivní běh **nebo** job ve frontě. Kódy push cronů dál přes HTTP |
| `isCronDefined($code)` (nová) | — | lokální definice — náhrada kontroly „je registrovaný“ přes statistiky z LQDecku |
| `getLastCronJobLog($code)` | GET LQDeck | GET LQDeck (jako dnes); při nedostupnosti fallback na poslední lokální běh |
| `getCronJobLogsStats` / `getCronOverview` | GET LQDeck | **GET LQDeck beze změny** — statistiky počítá jen server |
| `log()` | POST `/log` | beze změny |

**Chyby při zařazení.** Všechny výjimky lokálního zařazení (`QueueFull`, `SchedulerUnavailable`,
neznámý handler, `SQLITE_BUSY` po vypršení `busy_timeout`) mají společného předka
`SchedulingFailedException`, který **implementuje `GuzzleHttp\Exception\GuzzleException`**. Dnešní
volající, kteří chytají `GuzzleException` kvůli 429 / výpadku LQDecku (ABEL `QiOrderService`),
tak chytí i lokální selhání a objednávka nespadne na 500. Z FPM se používá `busy_timeout` **1 s**
(z CLI 5 s), aby request nečekal na zámek SQLite dlouho.

Projekt se tedy při přechodu nemusí měnit v místech, kde joby zařazuje a chytá `GuzzleException`
nebo `LiquidMonitorDisabledException` — pokud je zařazuje na hostu plánovače (§ 4.1). Ostatní
volající musí doplnit `SchedulingFailedException`; upgrade notes 4.0 vyjmenují místa v ABELu
(`QiOrderService`, `ScriptsPresenter` — ten navíc ověřuje registraci přes `getCronJobLogsStats`,
takže při výpadku LQDecku skripty nejdou spustit → přejít na `isCronDefined()`, `QiCronRunner`).

PHP tak zapisuje přímo do tabulek `jobs`, `runs` (stav `running`, § 7.0), `run_events` a `outbox`,
jejichž schéma vlastní binárka. Schéma těchto tabulek je proto **kontrakt mezi Rustem a PHP**
a platí pro něj stejná pravidla kompatibility jako pro hlídací procesy (§ 12.1): PHP ze starého slotu
musí umět zapsat do schématu nové binárky.

**Push crony** (`url` u cronu, LQDeck je volá HTTP) se lokálním plánovačem nemění: plánuje je dál LQDeck
a v projektu mohou žít vedle lokálních (s výhradou výlučnosti, § 6.3). Ruší se jen pull přes vzdálenou
frontu, a to jen v projektu se zapnutým `scheduler.enabled`.

**Statistiky:** binárka ani PHP nic neagregují. Přehledy, statistiky běhů, trendy a dashboardy
se počítají v LQDecku z doručených událostí (§ 8.1); díky zaručenému doručení jsou po výpadku úplné.
Při výpadku LQDecku jsou statistiky dočasně nedostupné — to je přijatelné, běh cronů na nich nezávisí.

## 10. Hlídané služby

`scheduler.services` nahrazuje crunz tasky, které nejsou crony, ale hlídače procesů
(v ABELu `ProductsDaemonTasks`, job-worker watchdog ve `FrequentTasks`, telemetry agent v launcheru).

- `mode: watchdog` — příkaz se spustí každý tick a sám řeší „už běží → nic“ (dnešní vzor products-daemonu),
- `mode: keepalive` — `lqcron` drží proces naživu sám (pidfile ve stateDir, restart s backoffem).

Služba si ukládá `app_path` (slot, ze kterého běží). Když se `realpath(cwd)` ticku změní (přepnutý
`current`), keepalive službu ze starého slotu ukončí a spustí z nového; `wait-slot` (§ 11.2) čeká
i na služby běžící z cílového slotu.

Výsledek se hlásí jako `service.*` události. Škálování job-workeru podle délky fronty zůstává zatím
v aplikaci (4.1).

## 11. Provoz

### 11.1 CLI

| Příkaz | Co dělá |
|---|---|
| `lqcron tick [--php=…]` | jeden průchod plánovače (volá systémový cron) |
| `lqcron run <code> [--args=json] [--sync]` | zařadí job; `--sync` ho spustí hned v popředí (dev instance, testy) |
| `lqcron enqueue <code> [--args=json] [--delay=s]` | zařadí job |
| `lqcron cancel <runId>` | ukončí běh (stav `cancelled`, bez opakování) |
| `lqcron cron disable\|enable <code>` | lokální vypnutí cronu (`local_active`), funguje i bez LQDecku |
| `lqcron plan [--hours=24]` | co a kdy poběží (po vyhodnocení `schedules` / `environments`) |
| `lqcron status [--json]` | poslední tick, fronta, běžící (vč. slotu), outbox, pauza, služby |
| `lqcron logs <code\|runId> [--tail]` | log běhu |
| `lqcron pause [--drain[=sec]] [--ttl=sec]` / `resume [--all]` | deploy: nespouštět nové, volitelně počkat na doběhnutí |
| `lqcron wait-slot <path> [--timeout=sec]` | deploy: počkat, až z daného slotu nepoběží žádný job ani služba (exit ≠ 0 po timeoutu) |
| `lqcron verify` | ověří binárku proti `.sha256` (§ 3) |
| `lqcron sync` | znovu načíst konfiguraci a definice |
| `lqcron deliver` | odeslání outboxu a stažení příkazů (spouští ho tick odpojeně, nejvýš 1 instance) |
| `lqcron flush` | totéž v popředí, čeká na prázdný outbox |
| `lqcron outbox retry-rejected` | znovu odeslat trvale odmítnuté události |

**Vypnutí cronu má dva nezávislé zdroje:** `local_active` (CLI) a `remote_active` (příkaz z LQDecku).
Cron se plánuje jen tehdy, když jsou zapnuté oba; každý zdroj zapíná jen sám sebe. Vypnout cron
lze tedy i při výpadku LQDecku, a vzdálené zapnutí nepřebije lokální vypnutí.

### 11.2 Deploy (blue/green)

1. `lqcron wait-slot <cílový slot> --timeout=…` — **před** zápisem nového releasu do cílového slotu.
   Blue/green má jen dva sloty, takže deploy přepisuje slot, ve kterém může z minulého deploye pořád
   běžet dlouhý job nebo keepalive služba; přepsat jim kód pod rukama nesmí. Po timeoutu deploy skončí
   chybou (nebo vědomě `lqcron cancel`), nikdy tiše nepokračuje. Běžící joby a jejich slot ukazuje
   `lqcron status` (`runs.app_path`). Samotné procesy `lqcron` slot neblokují — běží z kopie
   binárky ve `stateDir/bin` (§ 2).
2. `lqcron pause --drain=120 --ttl=1800` — nové joby se nespouštějí, běžící mají 2 min na doběhnutí
   (dlouhé běhy doběhnou ve starém slotu dál, nic se nezabíjí). TTL je pojistka: když deploy
   uprostřed spadne, plánovač se po 30 min sám rozjede (s událostí a WARNINGem).
3. přepnutí `current`,
4. `lqcron sync; lqcron resume` — **`resume` vždy**, i když `sync` selže: stará sada definic platí
   dál a pozastavený plánovač je horší než plánovač se starými definicemi.

**Pauza má dva nezávislé zdroje:** deploy (CLI) a vzdálená (`scheduler.pause` z LQDecku). Plánovač
běží, jen když nejsou aktivní ani jedna; `lqcron resume` ruší jen deploy pauzu (`--all` obě), vzdálený
`resume` jen vzdálenou. Pauza delší než 15 min = health WARNING a alert v LQDecku (§ 8.2).

Tím odpadá rozjezd jobů přes přepnutí slotu a joby spouštěné ze starého slotu
(ABEL test, monitor#1203).

**Rollback** (přepnutí `current` zpět) je běžná operace: starší binárka pak běží nad schématem,
které už zmigrovala novější. Smí, pokud splní `min_reader_version` (§ 12.1); jinak tick odmítne běžet
a health check hlásí CRITICAL s pokynem „rollback přes nekompatibilní migraci schématu“.

### 11.3 Jeden plánovač na projekt

`stateDir` musí být sdílený mezi sloty a jen na jednom hostu (lokální disk, § 4.1).

- Při založení databáze vznikne **`instance_id`** (náhodné UUID uložené ve `stateDir`). Identifikuje
  plánovač, ne stroj — na rozdíl od hostname + machine-id se nemění s novým kontejnerem.
  Heartbeat ho nese a LQDeck alertuje, když se za projekt hlásí dvě různé instance (§ 8.2).
  Tím se odhalí i případ, který lokálně odhalit nejde: dva hosty, každý s vlastním `stateDir`.
- Tick si do `meta` zapisuje `hostname` posledního ticku. Hlásí-li se jiný host a poslední tick
  je mladší než 5 min, tick odmítne běžet (dva hosty nad jedním `stateDir`); je-li starší, převezme
  plánovač automaticky (přesun stroje, nový kontejner) a pošle o tom událost.
- PHP enqueue host nekontroluje — stačí existence a zapisovatelnost databáze (§ 4.1). Kontejnery
  FPM a cronu nad sdíleným svazkem tak fungují.

### 11.4 Health check

Connector dodá službu **`SchedulerHealth`**, která čte SQLite přímo (read-only, bez `proc_open`,
které bývá ve FPM zakázané). Projekt ji použije ve své health akci — dnešní `CheckPullWorkerHeartbeat`
(čtení řádků z `monitor-worker.log`) žije v `abel-base`, ne v connectoru, a touto službou se nahradí.

| Stav | Úroveň |
|---|---|
| poslední tick starší než 3 min | CRITICAL |
| `stateDir` nejde zapsat (§ 4.1) | CRITICAL |
| tick odmítá běžet kvůli schématu (§ 12.1) nebo změně `stateDir` (§ 4.2) | CRITICAL |
| méně než 500 MB volného místa ve `stateDir` | CRITICAL |
| plánovač pozastavený déle než 15 min | WARNING |
| selhaný poslední export definic | WARNING |
| nejstarší nepotvrzená událost v outboxu starší než 1 h (LQDeck nepřijímá) | WARNING |
| poslední úspěšné spojení `lqcron deliver` s LQDeckem starší než 1 h | WARNING |
| `outbox_rejected` neprázdný nebo otrávená událost (§ 8.1) | WARNING |

## 12. Úložiště (SQLite, WAL)

`stateDir/lqcron.sqlite`, `journal_mode=WAL`, `busy_timeout` 5000 ms (FPM 1000 ms), krátké transakce.
Zapisuje tick, hlídací procesy, `deliver`, `sync`, PHP enqueue i PHP potomci (`running`, progress,
výsledek); řádově desítky zápisů za minutu.

| Tabulka | Obsah |
|---|---|
| `meta` | `schema_version`, `min_reader_version`, `instance_id`, `hostname`, `last_tick_at`, pauza (zdroje, od kdy, TTL), hash exportu, čas exportu, `state_dir`, heartbeat (jeden řádek, § 8.1) |
| `crons` | definice (code, schedule, timeout, concurrency, resources, after, missed, `local_active`, `remote_active`) |
| `jobs` | fronta (cron_code, arguments, available_at, attempt, source, created_at) |
| `runs` | běhy (uuid, cron_code, job, attempt, state, `claimed_at`, `supervisor_pid` + start, `pid` + start, `app_path`, started/finished/heartbeat_at, exit_code, signal, max_rss, result, error) |
| `run_events` | progress události |
| `outbox`, `outbox_rejected` | události pro LQDeck (§ 8.1), `poisoned_at` |
| `commands` | příkazy stažené z LQDecku (`command_id`, `seq`, payload, `issued_at`, `expires_at`, `fetched_at`, `applied_at`) |
| `services` | stav hlídaných služeb (pid, `app_path`, restarty) |

**Retence.** Lokální historie je jen pracovní, ne zdroj statistik:

- dokončený běh (`runs` + `run_events` + marker + soubor s argumenty) se smaže 7 dní poté, co je
  každá jeho událost potvrzená **nebo** v `outbox_rejected` — odmítnutá událost mazání běhu neblokuje,
- logy běhů na disku 7 dní,
- `commands` 30 dní po provedení,
- `outbox_rejected` se automaticky nemaže nikdy (jen `retry-rejected` nebo ručně),
- soubor s argumenty (`--arguments-file`) se smaže hned po skončení běhu.

Historii drží LQDeck.

### 12.1 Kompatibilita schématu

Na jednom `stateDir` současně běží různé verze: hlídací procesy a PHP potomci ze starého slotu,
tick a PHP z nového, a po rollbacku stará binárka nad novým schématem. Proto:

- Schéma migruje **jen `lqcron`** (tick nebo `lqcron sync`), nikdy PHP. Migrace běží v transakci
  a nastaví `schema_version`.
- Migrace jsou **aditivní**: nové tabulky, nové sloupce s defaultem, nové indexy. Nemění význam
  existujících sloupců a nic nemažou. Taková migrace `min_reader_version` nemění.
- **Jen SQL, které umí SQLite 3.24.** Binárka má SQLite zabudovanou a novější, ale PHP používá
  systémovou knihovnu. Zakázané jsou proto `STRICT` tabulky, `DROP COLUMN`, `RETURNING`
  a generované sloupce; DI extension kontroluje `sqlite_version() >= 3.24` (§ 4.1).
- `min_reader_version` = nejnižší verze schématu, kterou musí znát čtenář/zapisovatel, aby nad
  databází pracoval správně. Zvedá ji jen migrace, která aditivní být nemůže; ta smí přijít jen
  s minor/major verzí balíčku a v release notes musí stát, že rollback přes ni nejde.
- Každý zapisovatel (tick, `run-job`, `deliver`, `sync`, PHP enqueue/progress/`execute`) při otevření
  ověří `schema_version_který_znám >= min_reader_version`. Když ne, nezapíše nic: tick odmítne běžet
  (health CRITICAL), PHP enqueue vyhodí `SchedulerUnavailable`, `execute` zapíše jen marker
  (§ 7.0) a běh dořeší novější tick.
- Rollback mezi verzemi se stejnou `min_reader_version` tedy plánovač nezastaví.

## 13. Kompatibilita a přechod

### 13.1 Pořadí nasazení (tvrdé)

LQDeck odmítá každou neznámou major verzi connectoru: middleware `TrackConnectorVersion` vrací
**426 Upgrade Required** a `config/connector.php` má dnes `supported_versions => [2, 3]`. Platí to
pro všechny routy s middlewarem `connector.version` — celé `/api/connector/*` (i `/log` a push
lifecycle), ale i `/api/front/*` (`getCronOverview`, `getCronJobLogsStats`), `/api/context/*`
a `/api/orchestrator/*` (`RouteServiceProvider`). Projekt na `^4` by tedy přišel o hlášení chyb,
push crony i statistiky, i **bez** `scheduler.enabled`. Proto:

1. **LQDeck:** `supported_versions => [2, 3, 4]` — samostatná změna, nezávislá na ingestu,
   nasazená na produkci jako první,
2. **LQDeck:** ingest `v4/events`, `v4/commands`, režim `local` a schéma z § 8.4, přepnutí a návrat
   (§ 13.4), politika opožděných alertů (§ 8.2) a report stínového režimu (§ 13.2),
3. teprve potom první projekt na `^4`.

Release connectoru 4.0 se nevydá, dokud není bod 1 na produkci LQDecku. Postup bumpu drží skill
`connector-versioning` (v obou repech).

### 13.2 Zpětná kompatibilita a stínový režim

- **Bez `scheduler.enabled`** se v4 chová jako v3 (vzdálený pull přes worker, push beze změny) —
  za předpokladu § 13.1. Projekt může přejít na `^4` a plánovač zapnout později. Rozvrhy ale
  i v tomto režimu bere z atributů přes `enqueue-due` (§ 5.0), ne z crunzu.
- **LQDeck** (`liquid-monitor-back`) dostane podporu v4 jako **nové funkce na hlavní větvi, bez nové
  branche a s plnou zpětnou kompatibilitou**: nové endpointy (`v4/events`, `v4/commands`), nový
  `job_execution_mode='local'` a schéma z § 8.4; stávající push i v3 pull API se nemění.
  Obsluhuje tak trvale všechny tři druhy klientů (push, v3 pull, v4 lokální) a rozlišuje je podle
  `X-Connector-Version` a režimu cronu. Úpravy chování z § 8.2 (triage jen z finálního selhání,
  „Plánovač se nehlásí“, timeout řeší binárka) platí jen pro crony v režimu `local`.
- **Stínový režim** (`scheduler.shadow: true`): tick počítá, co by zařadil, nic nespouští, posílá
  `run.shadow` události a `definitions.sync` s `mode: shadow`, který **režim cronů v LQDecku
  nemění** (§ 13.4). LQDeck je porovná s během řízeným v3: spáruje `run.shadow` s `cron_job_logs`
  stejného cronu v okně ±2 min a vypíše chybějící / navíc / posunuté běhy (ve 4.0 jako report
  příkazem `crons:shadow-report`, ne UI). **Ověřuje jen rozvrhy** — pravidla souběhu stínový režim
  ověřit neumí, protože lokálně nic neběží; ta se ověřují až na testu se zapnutým plánovačem
  (krok 3 v § 13.3).

### 13.3 Postup pro projekt

ABEL jako první:

1. **srovnat rozvrhy do atributů** (`schedules`, `environments`) a přepnout spouštění na
   `enqueue-due` (§ 5.0; `^4` bez plánovače, po § 13.1 bod 1). Za pravdu se **nebere atribut, ale to, co dnes skutečně
   běží** (crunz výraz po vyhodnocení `*CronForEnvironment()` a skip gatů pro každé prostředí).
   Pro všech 66 handlerů s `#[PullCron]` v ABELu (+ `rajtiskaren/`) vznikne tabulka *atribut ×
   crunz produkce × crunz test*; každý rozdíl rozhodne člověk (např. `MergadoPricesHandler`:
   atribut `*/30 * * * *` vs. produkce `30 * * * *` — buď opravit atribut na skutečnost, nebo
   vědomě zrychlit běh). Teprve pak smazat rozvrhy z `tasks/*.php`,
2. `^4` + `scheduler.shadow: true` na testu, porovnat se stávajícím během (několik dní, vč. nočních),
3. test: `scheduler.enabled`, crontab → `lqcron tick`, vypnout `enqueue-due` a worker; ověřit souběh
   a deploy s `wait-slot` / `pause` / `resume`,
4. RT test, pak produkce ABEL a RT,
5. smazat `tasks/`, `PullJobEnqueuer`, `CheckPullWorkerHeartbeat` (→ `SchedulerHealth`), crunz závislost.

### 13.4 Přepnutí cronu na `local` a návrat

**Kdy se přepíná.** Cron přejde do `local` až s prvním `definitions.sync` s `mode: local`, tedy od
plánovače se `scheduler.enabled: true` mimo stínový režim. Stínový režim režim cronů nemění nikdy —
jinak by `ClaimJobsForWorker` (`whereEffectivePull`) přestal lokální crony vydávat v3 workeru
a na testu by se zastavily všechny crony. Přepíná se jen cron, který je v exportu.

**Co se stane v okamžiku přepnutí** (jedna transakce v LQDecku na projekt):

- `job_execution_mode = local` u crony z exportu,
- joby lokálních cronů, které zbyly v `cron_jobs` (zařadil je ještě v3), se smažou a LQDeck pošle
  **jedno** souhrnné hlášení („předáno lokálnímu plánovači, N čekajících jobů zrušeno“); plánovač
  si splatné běhy naplánuje sám,
- rozběhnuté pull běhy (`Started`) se nechají doběhnout — `finish-job` / `fail-job` od v3 workeru
  pořád projde. Když jim vyprší lease, `ExpireLeasedJobLogs` je uzavře jako `failed` s
  `outcome = handover`, **bez retry, bez alertu a bez triage**.

**Ochrana před návratem do `pull` omylem.** `schedule-job` nad `local` cronem odpoví
**409 `cron_is_local`**: žádný sync metadat, žádná změna režimu, žádný `Job`. Dnes by jeden v3 slot
při deployi, rollback na v3 nebo webový node se starým kódem cron přepsal zpátky na `pull`
(`ScheduleJobController` přepisuje režim při každém pull volání). 409 se v aplikaci projeví jako
výjimka — špatně nastavený node je tak vidět.

**Návrat z v4 na v3** (vypnutí plánovače, ne rollback deploye):

1. `lqcron pause --drain`, `lqcron flush` (dodat zbylé události),
2. v crontabu vrátit `enqueue-due` + worker (crontab je mimo slot — přepnutí `current` ho nevrátí),
3. v LQDecku akce **„Vrátit crony do pull“** na projektu (admin + MCP tool, parita
   `AdminMcpParityTest`): přepne `local` crony projektu na `pull` a nastaví `local_blocked_at`.
   Dokud je blokace nastavená, `definitions.sync` s `mode: local` se uloží, ale **režim nepřepne**
   — zapomenutý `lqcron` tak crony zpátky nepřetáhne. Blokaci ruší člověk v adminu.

## 14. Rozsah verzí

| Verze | Obsah |
|---|---|
| **4.0** (PHP >= 8.1) | **jako první** `monitor-worker enqueue-due` + `monitor-worker plan`, `schedules` / `environments` v atributu — jediný zdroj rozvrhu i bez `lqcron` (§ 5.0); dále tick + hlídací procesy se stavovým automatem, SQLite, port pravidel souběhu, `maxParallel`, `resources`, `schedules` / `environments`, `after`, `missed`, timeout přes skupinu procesů, ztracené běhy, outbox s odesíláním v samostatném `lqcron deliver`, příkazy z LQDecku, hlídané služby (watchdog/keepalive), CLI, pauza s TTL a hlídání slotu pro deploy, `SchedulerHealth`, stínový režim, bariéra proti vyhladovění, kompatibilita schématu (`min_reader_version`). V LQDecku: `supported_versions` 4 (napřed, § 13.1), ingest a schéma (§ 8.4), přepnutí a návrat (§ 13.4), příkazy s oprávněními, politika opožděných alertů, report stínového režimu |
| **4.1** | opakování s backoffem (`retry`, `backoff`), škálování job-workeru jako služba, metriky běhů a alert na neobvykle dlouhý běh v LQDecku; v LQDecku alert „rozvrh nesedí“ (skutečné běhy neodpovídají `timing`), UI pro vynechané běhy a stínový režim |
| **Port na PHP 8.0** | samostatná major verze po 4.0 — Příloha A; s ní se odstraní `crunz/MonitorWorkerTasks.php` |
| **později** | sloučení s aplikační frontou jobů (`JobRepository` / `bin/job-worker.php`) do jednoho vykonavatele |

## 15. Záměrné odchylky od dnešního LQDecku

| LQDeck dnes | v4 | Proč |
|---|---|---|
| `repeatCount` N ≥ 1 → N+2 pokusů | N+1 pokusů | off-by-one |
| stale cleanup maže i odložené joby nad 6 h | maže jen dostupné, pauza se nepočítá | `delaySeconds` až 24 h jinak nefunguje |
| alert jen při finálním selhání, ale triage task i z mezipokusu | triage také jen z finálního selhání | šum |
| `schedule-job` na smazaný cron ho obnoví s `active=true` | definice z kódu nemění admin `active` | kill switch nesmí přepsat deploy |
| `max_queue_size = NULL` popsané jako „unlimited“, chová se jako 10 | 10, zdokumentováno | jen popis |
| `ram` = peak rodiče (workeru) | peak potomka | dnes měří špatný proces |
| `timeout = 0` → 1000 s (`timeout ?: 1000`) | `0` = bez limitu | jediný způsob, jak vědomě vypnout limit; export na `0` varuje |
| FIFO s přeskakováním, `exclusive` může čekat donekonečna | FIFO + bariéra po `starvationSeconds` | vyhladovění exclusive jobů |
| alerty z každého doručeného selhání | opožděné události (> 15 min) bez jednotlivých alertů, jeden souhrn | lavina alertů po výpadku |
| `exclusive` platí napříč push a pull crony projektu | mezi push a lokálními crony neplatí | lokální plánovač push běhy nevidí (§ 6.3) |

## 16. Otevřené otázky

1. `branch-alias` v `composer.json` na `4.x` (`"4.x-dev"`).
2. Prahy k doladění ve stínovém provozu ABELu: `starvationSeconds` (300 s), práh opožděné události
   (15 min), `runLogMaxBytes` (50 MB), smíření běhů po 3 heartbeatech.
3. **Zúžení rozsahu 4.0** (návrh auditu, nerozhodnuto): přesunout do 4.1 `keepalive` služby
   (watchdog stačí), `resources`, vzdálené příkazy kromě `cron.set_active`, `missed: once`,
   `lqcron run --sync` a attestation / `verify`.
4. Zařazování jobů z webových nodů bez plánovače (§ 4.1) — HTTP endpoint aplikace, nebo zařazení
   přes LQDeck jako relé? Ve 4.0 nepodporované.
5. Číslo verze portu na PHP 8.0 (Příloha A): vlastní major hned po 4.0, nebo až spolu s další
   velkou změnou.
6. Vydat `enqueue-due` dřív (samostatná 4.0, `lqcron` jako 4.1), aby ABEL nečekal na celý
   plánovač, nebo všechno najednou v 4.0?

Uzavřeno 2026-10-15: větev `4.x` z `3.x`; push crony zůstávají podporované; statistiky jen
v LQDecku; zaručené doručení událostí; `missed` default `skip`; LQDeck bez nové branche, jen
zpětně kompatibilní rozšíření.

Uzavřeno 2026-10-16 (review): LQDeck přidá major 4 do `supported_versions` jako první krok, nezávisle
na ingestu (§ 13.1); kompatibilita schématu přes `min_reader_version` (§ 12.1); heartbeat mimo outbox;
opožděné události bez jednotlivých alertů; PHP databázi nezakládá; rozvrh má jediný zdroj v atributu
(`enqueue-due` + `schedules` / `environments`, § 5.0); **všechny změny jen na `4.x`, `3.x` se
neřeší** (žádné backporty ani vydání 3.3).

Uzavřeno 2026-10-16 (audit): **4.0 zůstává na PHP >= 8.1, port na PHP 8.0 vychází zvlášť**
(Příloha A); rozhraní `CronJobHandler` se nemění, výsledek jde přes `finishJob()` (§ 7.4);
`created_at` v `cron_job_logs` = čas přijetí (§ 8.4); stínový režim režim cronů nemění (§ 13.4);
`instance_id` místo `host_id` (§ 11.3).

---

## Příloha A — Port na PHP 8.0 (samostatné vydání)

Původně součást 4.0, oddělené 2026-10-16: s odstraněním SPOF nesouvisí, je drahé (Orchestrator
a Telemetry jsou psané pro 8.4) a mění veřejné API, takže potřebuje vlastní major verzi.
Cíl: aby šel connector nasadit i na projekty, které zůstaly na PHP 8.0.

Konstrukce ve `src/` (stav 3.2.4), které 8.0 nezná:

| Konstrukce (od) | Výskyt v `src/` | Náhrada pro 8.0 |
|---|---|---|
| `enum` (8.1) | 2 soubory: `Worker/ConcurrencyModeType`, `HealthCheck/HealthCheckStatusEnum` | třída s konstantami + `from()` / `tryFrom()` |
| `readonly` property (8.1) | 28 souborů | `private` property + getter, případně `@readonly` pro PHPStan |
| typované konstanty `const string` (8.3) | 6 souborů (`Orchestrator/*`) | netypované konstanty (nutné už pro 8.1, § 3) |
| návratový typ `never` (8.1) | 1 soubor (`DbQuery/DbQueryApiPresenter`) | `void` + `@return never` |
| first-class callable `f(...)` (8.1) | 2 soubory | `Closure::fromCallable()` |
| funkce `array_is_list()` (8.1) | 1 soubor (`Telemetry/Agent/MonitorSink.php`) | `symfony/polyfill-php81` nebo vlastní helper |

Atributy (`#[PullCron]`) a constructor promotion jsou 8.0, ty zůstávají. Testy (`tests/`) musí jít
spustit na 8.0 také — je v nich tucet výskytů 8.1+ konstrukcí.

**Změny veřejného API (proto major):**

- `PullCron::$concurrencyMode` je typovaný enumem `ConcurrencyModeType`. Po převodu na třídu
  s konstantami (stejná jména jako dnešní případy) zůstane zápis `concurrencyMode:
  ConcurrencyModeType::Exclusive` v atributu beze změny, ale hodnota bude `string`. Kód, který volá
  `->value`, `::from()` na enumu nebo typuje parametr `ConcurrencyModeType`, se rozbije.
  `public readonly` vlastnosti atributu se změní na `public` (čtení zůstává kompatibilní).
- `HealthCheckStatusEnum` (int enum) je veřejné API health checku (`HealthCheckData`,
  `HealthCheckResponse`, projekty používají `->value` / `->name`). Převod rozbije health checky
  **všech** projektů, i těch bez plánovače — upgrade notes musí vyjmenovat náhrady.

**Závislosti:**

- `liquiddesign/nette-log-viewer` (`^1.2`) deklaruje `"php": "^8.0"`, ale v
  `LogViewerApiPresenter.php` používá návratový typ `never` → na 8.0 fatální chyba při načtení.
  Nutné opravit v tom balíčku (nová verze) a zúžit požadavek connectoru.
- `symfony/console|process|dotenv` dnes `^6.3` (= PHP 8.1+) → rozšířit na `^5.4 || ^6.0 || ^7.0 || ^8.0`.
- `nette/application`, `nette/http` (`^3.0`): nejnovější 3.2 vyžaduje 8.3, Composer na 8.0 vybere
  starší 3.1 / 3.0 — ověřit v CI s nejnižšími verzemi. `nette/di ~3.0`, `guzzle ^7.0 || ^8.0`,
  `carbon ^2.0 || ^3.0` (na 8.0 se vybere 2.x) 8.0 podporují.
- PHP 8.0 nemá `PDO::SQLITE_ATTR_OPEN_FLAGS` — kontrola existence databáze před otevřením (§ 4.1)
  to už řeší.

**CI:** matice 8.0 / 8.1 / 8.3 / 8.5 se spuštěním testů, vč. běhu s `--prefer-lowest`;
PHPStan s `phpVersion: 80000`.

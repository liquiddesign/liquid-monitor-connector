# Liquid Monitor Connector

Connector mezi webem a Liquid Monitor.

## Components

- **`Cron`** (`src/Cron.php`) — Nette DI integrace pro produkční reporting (schedule-job, error logging, health check).
- **`orchestrator:run`** (`bin/orchestrator-run`) — autonomous programming worker. Pollne `/api/orchestrator/worker/poll`, v repo-mode pracuje přímo v repu (volitelně git worktree), spustí **tmux + interaktivní Claude Code REPL** (`send-keys`, `--resume`), doručí brief, parsuje JSON milníky, spustí `composer test` a zapíše `triage_result` zpět na monitor.
- **`orchestrator-init`** (`bin/orchestrator-init`) — jednorázový setup hostu: vygeneruje `<repo>/.orchestrator/.env`, doplní `.orchestrator/` do `.gitignore` a ověří kredity proti monitoru.
- **`LiquidMonitorLogViewerDI`** (`src/Bridges/LiquidMonitorLogViewerDI.php`) — DI extension, která vystaví read-only JSON API pro Tracy logy přímo z connectoru. Bundluje balíček [`liquiddesign/nette-log-viewer`](https://github.com/liquiddesign/nette-log-viewer) a registruje jeho routy/presentery, takže hostová aplikace nemusí balíček instalovat ani registrovat zvlášť. Viz [Log viewer](#log-viewer).
- **`LiquidMonitorDbQueryDI`** (`src/Bridges/LiquidMonitorDbQueryDI.php`) — DI extension pro read-only SQL dotazy proti databázi host aplikace (PDO proxy pro monitor orchestrátor). Viz [DB query proxy](#db-query-proxy).

Starý `bin/triage-pull` (read-only `claude -p`) je nahrazen orchestrátorem — nepoužívat.

## Log viewer

Connector umí vystavit identické API jako `liquiddesign/nette-log-viewer` — read-only přístup k Tracy logům (`Debugger::$logDirectory`) přes JSON endpointy pod `/<urlPrefix>/api/<action>` (`list`, `stat`, `view`, `search`, `download`). Slouží monitoru/orchestrátoru pro čtení logů aplikace.

Registrace v host aplikaci:

```neon
extensions:
    liquidMonitorLogViewer: LiquidMonitorConnector\Bridges\LiquidMonitorLogViewerDI

# volitelné (výchozí hodnoty níže):
liquidMonitorLogViewer:
    urlPrefix: log-viewer
    presenter: LogViewer:LogViewer
    apiPresenter: LogViewer:LogViewerApi
    registerRoutes: true            # false = routy si spravuje host sám
    registerPresenterMapping: true  # false = host má vlastní presenter mapping
```

**Přístup je gatovaný přes Tracy debug mode** (`Debugger::isEnabled()`) ve startupu presenterů — stejně jako v samotném balíčku. Žádná další autentizace se nepřidává; produkční přístup monitoru je tedy nutné řešit přes Tracy debug allowlist (IP), případně vlastními subclassy presenterů v aplikaci.

## DB query proxy

Connector umí vystavit read-only JSON API pro SQL dotazy proti databázi host aplikace. Monitor (ne agent) posílá `sql` + `connection` credentials v HTTP body; connector se připojí přes PDO, vynutí SELECT-only guardy, LIMIT wrap a statement timeout a vrátí flat JSON `{columns, rows, row_count, limit, truncated}`.

Registrace v host aplikaci:

```neon
extensions:
    liquidMonitorDbQuery: LiquidMonitorConnector\Bridges\LiquidMonitorDbQueryDI

liquidMonitorDbQuery:
    urlPrefix: db-query
    apiPresenter: DbQuery:DbQueryApi
    registerRoutes: true
    registerPresenterMapping: true
    apiToken: '…'   # doporučeno na produkci — vyžaduje X-Api-Key header
```

**Endpoint:** `POST /{urlPrefix}/api/query`

**Request body:**

```json
{
  "sql": "SELECT 1 AS connected",
  "connection": {
    "driver": "mariadb",
    "host": "127.0.0.1",
    "port": 3306,
    "database": "my_app",
    "username": "readonly_user",
    "password": "…"
  },
  "row_limit": 100,
  "statement_timeout_seconds": 5
}
```

**Bezpečnostní model (dvojitý gate):**

1. **Trusted IP / Tracy debug mode** — `Debugger::isEnabled()` ve startupu presenteru (stejně jako log-viewer). Na produkci přidej IP monitoru do `access.debug` v host NEON.
2. **Credentials v HTTP body** — connector se k DB připojí jen s `connection` objektem z requestu. Bez správných údajů SELECT neproběhne.
3. **Volitelný `apiToken`** — pokud je nastaven v NEON, vyžaduje shodný `X-Api-Key` header (`hash_equals`).

**Odpovědi:** úspěch `200` s flat JSON (bez vnořeného `data`); chyby `{ "error": "…", "code": 400|403|422|500 }`.

## Orchestrator worker setup

### 1. Projekt na monitoru

```bash
php artisan triage:provision-project <id> --json --repo-path=/opt/autonomy/my-app
```

Vytvoří `triage_api_key`, zapne `orchestrator_enabled` a založí git context source pro daný repo path.

### 2. Host (z kořene repa)

```bash
/path/to/liquid-monitor-connector/bin/orchestrator-init
```

Vypíše `.env` do `<repo>/.orchestrator/.env`. Potřeba jsou jen dvě hodnoty:

```dotenv
ORCHESTRATOR_MONITOR_URL=https://monitor.lqd.cz
ORCHESTRATOR_API_KEY=trk_…
```

Kapacita, `claude_binary` a turn timeout přicházejí z monitoru (`orchestrator_settings`); odpovídající env proměnné jsou jen volitelný debug override. Alias env vars: `TRIAGE_MONITOR_URL`, `TRIAGE_API_KEY`, …

### 3. Cron

```cron
* * * * * /path/to/liquid-monitor-connector/bin/orchestrator-run --env-file=/opt/autonomy/my-app/.orchestrator/.env >> /var/log/orchestrator-run.log 2>&1
```

### Pre-flight

- `git`, `tmux`, `claude` v PATH (kontroluje i `orchestrator-init`)
- `orchestrator_repo_path` na projektu ukazuje na existující clone
- Repo-mode (default): čistý pracovní strom (modifikované tracked soubory blokují běh; untracked se ignorují)

## Development

```bash
composer install
composer check-code
```

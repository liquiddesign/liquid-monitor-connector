# Liquid Monitor Connector

Connector mezi webem a Liquid Monitor.

## Components

- **`Cron`** (`src/Cron.php`) — Nette DI integrace pro produkční reporting (schedule-job, error logging, health check).
- **`triage:pull`** (`bin/triage-pull`) — self-contained CLI worker pro Triage systém. Standalone Symfony Console aplikace bez vazby na Nette/Laravel hostitelské aplikace. Spouští se z cronu, pollne monitor backend, spustí lokální AI agent CLI (`claude` nebo `cursor-agent`) a zapíše výsledek zpět.

## Triage worker setup

CLI pollne monitor, spustí agenta s parametry, které dostane v `context_sources` (env vars, allowed tools, add_dirs), parse výsledek a postne ho zpět. **Konektor sám nevytváří ani nemodifikuje žádný filesystem state** — přístup k souborovému systému je řízen výhradně přes `context_sources.add_dirs` ze serveru. Žádné workspace adresáře, žádné SKILL.md zápisy.

### Konfigurace

`.env` v adresáři, ze kterého CLI spouštíš (skutečné env vars mají přednost před `.env`):

```dotenv
TRIAGE_MONITOR_URL=https://monitor.lqd.cz
TRIAGE_API_KEY=trk_…
# volitelné — všechny mají rozumný default
TRIAGE_WORKER_ID=abel-prod-1
TRIAGE_MAX_CONCURRENT=1
TRIAGE_AGENT_TIMEOUT=600
TRIAGE_CLAUDE_BINARY=claude
TRIAGE_CURSOR_BINARY=cursor-agent
TRIAGE_CURSOR_MODEL=sonnet-4
```

### Cron

```cron
* * * * * cd /var/www/myproject && vendor/bin/triage-pull >> /var/log/triage-pull.log 2>&1
```

`.env` se načte automaticky z CWD. API klíč vygeneruje monitor backend (`php artisan triage:provision-project <name> --regenerate`) — jeden per project, žádný read/write split.

### CLI options

Všechny mají odpovídající env var (viz tabulka v `.env` výše). Příkladově: `--monitor-url`, `--api-key`, `--worker-id`, `--max-concurrent`, `--agent-timeout`, `--claude-binary`, `--cursor-binary`, `--cursor-model`. `--monitor-url` a `--api-key` jsou jediné povinné.

### Agent abstrakce

`Triage\Agent\AgentInterface` — kontrakt pro CLI agenty. Implementace:

- `ClaudeAgent` (`name(): 'anthropic'`) — `claude -p` s `--output-format=stream-json`, `--allowed-tools`, `--add-dir`.
- `CursorAgent` (`name(): 'cursor'`) — `cursor-agent -p --print --output-format=stream-json`. Cursor CLI nezná `--allowed-tools` ani `--add-dir`; MCP/skills se konfigurují per-host přes `~/.cursor/`.

Přidání nového agenta: nová třída implementující `AgentInterface`, zaregistrovat do `AgentRegistry` v `bin/triage-pull`. `name()` musí odpovídat klíči v serverové `App\Services\Triage\AgentRegistry` (liquid-monitor-back).

### Pre-flight

- `claude --version` ≥ 2.x **nebo** `cursor-agent --version` (záleží který provider má projekt zvolený ve Filamentu)
- Pokud Cursor: `cursor-agent status` musí ukazovat přihlášení (`cursor-agent login` jednorázově ručně)
- Žádný temp/lock — collision-handling řeší monitor backend přes DB lease (`LeaseTasksForWorker`)

## Development

```bash
composer install
composer check-code   # phpcs + phpstan
```

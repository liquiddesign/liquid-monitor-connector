# Liquid Monitor Connector

Connector mezi webem a Liquid Monitor.

## Components

- **`Cron`** (`src/Cron.php`) — Nette DI integrace pro produkční reporting (schedule-job, error logging, health check).
- **`orchestrator:run`** (`bin/orchestrator-run`) — autonomous programming worker. Pollne `/api/orchestrator/worker/poll`, vytvoří git worktree, spustí **tmux + interaktivní Claude Code REPL** (`send-keys`, `--resume`), doručí brief, parsuje JSON milníky, spustí `composer test` a zapíše `triage_result` zpět na monitor.

Starý `bin/triage-pull` (read-only `claude -p`) je nahrazen orchestrátorem — nepoužívat.

## Orchestrator worker setup

### Konfigurace

`.env` v adresáři, ze kterého CLI spouštíš:

```dotenv
ORCHESTRATOR_MONITOR_URL=https://monitor.lqd.cz
ORCHESTRATOR_API_KEY=trk_…
ORCHESTRATOR_WORKER_ID=host-1
ORCHESTRATOR_MAX_CONCURRENT=1
ORCHESTRATOR_CLAUDE_BINARY=claude
ORCHESTRATOR_TURN_TIMEOUT=900
```

Alias env vars: `TRIAGE_MONITOR_URL`, `TRIAGE_API_KEY`, …

Projekt na monitoru: `php artisan triage:provision-project <id> --json --repo-path=/opt/autonomy/my-app/repo`

### Cron

```cron
* * * * * cd /opt/autonomy && vendor/bin/orchestrator-run >> /var/log/orchestrator-run.log 2>&1
```

### Pre-flight

- `git`, `tmux`, `claude` v PATH
- `orchestrator_repo_path` na projektu ukazuje na existující clone (`…/repo`)
- Worktrees: `{parent}/worktrees/task-{id}/` vedle `repo/`

## Development

```bash
composer install
composer check-code
```

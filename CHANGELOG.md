# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [3.1.0] — 2026-09-30

Performance telemetry in the spirit of Laravel Nightwatch — built so that it cannot slow the host down.
Off by default; nothing changes for a host until it sets `liquidMonitorTelemetry: enabled: true`.

### Added
- `LiquidMonitorTelemetryDI` — hooks `Nette\Application` events (route, status, phases `boot` / `startup` /
  `presenter` / `send`, peak memory) and, when the host has StORM 2.1+, `StORM\Connection::setQueryObserver()`
  (SQL count, time, N+1, slow queries with backtraces). Older StORM is skipped silently.
- `Telemetry\Recorder` — writes only to capped in-memory arrays (`hrtime()` + array append) and sends one
  fire-and-forget UDP datagram at the end of the request; transport errors are swallowed. Cold requests are
  detected from the opcache `misses` delta (userland state does not survive FastCGI requests).
  Public API for host-specific operations: `measure()`, `begin()` / `end()`, `record()`, `tag()`.
- `Telemetry\GuzzleMiddleware` for outgoing HTTP (key = host).
- `bin/monitor-telemetry-agent` — local agent aggregating datagrams into minute buckets with mergeable log
  histograms; writes daily JSONL (200 MB/day cap) for now. Lives ~65 s and binds with `SO_REUSEPORT`, so the
  agent spawned every minute by `MonitorWorkerLauncher` overlaps the previous one and never runs stale code.
- `tests/bench/telemetry-overhead.php` — measured on PHP 8.5: ~0.16 ms per request with 200 SQL, ~0.38 ms with
  1000 SQL (including the UDP send); inactive recorder ~50 ns per call.

## [3.0.7] — 2026-09-25

Connector 3.x installs on older Nette stacks (StORM 1.x, nette/utils 3.x, Symfony 6.x — e.g. Levior B2B)
without upgrading anything else in the host.

### Removed
- Dependency on `liquiddesign/base`. It was used only for `BaseAction::getLocalCachedOutput()` in
  `GetCronService`, but `base ^2.0.33` requires `liquiddesign/storm ^2`, which blocked every host on StORM 1.x.
  `GetCronService` keeps its API (`execute()`, `__invoke()`) and caching (found service is remembered, `null` is not).
  **Upgrade note:** a host that used `Base\…` classes without requiring `liquiddesign/base` itself (getting it only
  transitively through the connector) must now require it explicitly.

### Changed
- `symfony/console`, `symfony/process`, `symfony/dotenv` widened to `^6.3 || ^7.0 || ^8.0`.
- `bin/monitor-worker` and `bin/orchestrator-run` register commands through `LiquidMonitorConnector\Console\ConsoleCompat`:
  `Application::addCommand()` exists only since symfony/console 7.4 and `add()` was removed in 8.0, so the previous
  direct `addCommand()` call crashed on 6.x and 7.0–7.3 even though `^7.0` was allowed.
- Works with `liquiddesign/nette-log-viewer` 1.2.3+, which accepts `nette/utils ^3.2 || ^4.0`.

### Fixed
- nette/utils 3.x (allowed by `^3.0 || ^4.0` all along, but never tested): `Json::decode()` is
  `decode(string, int $flags)` there, so `forceArrays: true` (named parameter exists only in 4.x) threw
  `Unknown named parameter` and a positional `true` threw `TypeError` under `strict_types`. Hit
  `Cron::getArguments()`, `getLastCronJobLog()`, `getCronOverview()`, `getCronJobLogsStats()`,
  `monitor-worker execute` (job arguments) and the worker run loop (child result). All use `Json::FORCE_ARRAY`
  now; `tests/NetteUtils3Compat.phpt` guards against the pattern coming back.

## [Unreleased] — 3.0.0-alpha

### Added
- `#[PullCron]` attribute (`LiquidMonitorConnector\Worker\PullCron`) for declaring code-managed pull-model crons on handler classes — schedule (cron expression), name, description, repeatCount, concurrencyMode, timeout and maxQueueSize live in code and are synced to the monitor on every schedule call.
- `Cron::schedulePullJob(string $handlerClass, ?array $arguments = null)` — schedules a pull-model job from a handler class carrying `#[PullCron]`; sends `executionMode=pull` + `cronTiming`, no `cronUrl` and no client-side execution timeout. Throws when the handler class lacks the attribute.
- `ConcurrencyModeType` enum (`exclusive` / `parallel` / `fully_parallel` / `independent`) mirroring the monitor's cron concurrency modes.

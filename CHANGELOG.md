# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased] — 3.0.0-alpha

### Added
- `#[PullCron]` attribute (`LiquidMonitorConnector\Worker\PullCron`) for declaring code-managed pull-model crons on handler classes — schedule (cron expression), name, description, repeatCount, concurrencyMode, timeout and maxQueueSize live in code and are synced to the monitor on every schedule call.
- `Cron::schedulePullJob(string $handlerClass, ?array $arguments = null)` — schedules a pull-model job from a handler class carrying `#[PullCron]`; sends `executionMode=pull` + `cronTiming`, no `cronUrl` and no client-side execution timeout. Throws when the handler class lacks the attribute.
- `ConcurrencyModeType` enum (`exclusive` / `parallel` / `fully_parallel` / `independent`) mirroring the monitor's cron concurrency modes.

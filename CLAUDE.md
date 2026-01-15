# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Liquid Monitor Connector je PHP knihovna pro propojení webových aplikací se systémem Liquid Monitor. Umožňuje monitorování cron jobů, logování chyb a health checky.

## Příkazy pro vývoj

```bash
# Kontrola kódu (phpcs + phpstan)
composer check-code

# Oprava formátování kódu
composer fix-code

# Pouze PHPStan analýza
composer phpstan

# Pouze PHP_CodeSniffer
composer phpcs

# Release (vytvoří novou verzi s changelogem a commitem)
composer release:patch   # patch verze (1.0.x)
composer release:minor   # minor verze (1.x.0)
composer release:major   # major verze (x.0.0)
```

## Architektura

### Hlavní komponenty

**Cron** (`src/Cron.php`) - Hlavní třída pro správu cron jobů:
- `scheduleOrStartJob()` - Naplánuje nebo spustí job podle toho, zda je volán z monitoru
- `scheduleJob()` / `startJob()` / `finishJob()` / `failJob()` - Životní cyklus jobu
- `progressJob()` - Reportuje průběh dlouho běžících jobů
- `log()` - Posílá logy do Liquid Monitoru

**LiquidMonitorLogger** (`src/LiquidMonitorLogger.php`) - Rozšíření Tracy loggeru:
- Automaticky posílá logy do Liquid Monitoru podle nastavených úrovní
- Podporuje `WeakException` pro méně důležité chyby (neposílají se na Slack)

**HealthCheck** (`src/HealthCheck/`) - Komponenty pro health check endpoint:
- `HealthCheckResponse` - Agreguje jednotlivé kontroly a vrací JSON odpověď
- `HealthCheckData` - Jednotlivá položka health checku
- `HealthCheckStatusEnum` - Stavy: INFO, OK, WARNING, ERROR, CRITICAL

### Nette DI Extensions

Knihovna poskytuje dvě DI extension pro Nette:

```neon
# Základní connector (povinné)
extensions:
    liquidMonitorConnector: LiquidMonitorConnector\Bridges\LiquidMonitorConnectorDI

liquidMonitorConnector:
    url: 'https://monitor.example.com/connector'
    apiKey: 'xxx'
    enabled: true

# Logger extension (volitelné, nahrazuje Tracy logger)
extensions:
    liquidMonitorLogger: LiquidMonitorConnector\Bridges\LiquidMonitorLoggerDI

liquidMonitorLogger:
    title: 'Název projektu'
    levels: [error, exception, critical]  # volitelné
```

## Kódové konvence

- PHP 8.1+
- PHPStan level 8
- Coding standard: liquiddesign/codestyle
- Namespace: `LiquidMonitorConnector\`

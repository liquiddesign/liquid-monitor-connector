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
    url: 'https://monitor.example.com/connector'   # sdílený fallback (povinné)
    apiKey: 'xxx'
    enabled: true
    # Volitelné: crony a logy/chyby na různé instance monitoru. Každý kanál
    # nese vlastní url i apiKey; co se vynechá, dědí z top-level url/apiKey.
    # Typický scénář migrace: crony nech na staré stabilní instanci (top-level
    # url) a chyby napoj na novou instanci s AI orchestrátorem.
    log:
        url: 'https://new-monitor.example.com/connector'
        apiKey: 'yyy'
    # cron: lze přepsat stejným způsobem (např. když je hlavní url pro logy)

# Logger extension (volitelné, nahrazuje Tracy logger)
extensions:
    liquidMonitorLogger: LiquidMonitorConnector\Bridges\LiquidMonitorLoggerDI

liquidMonitorLogger:
    title: 'Název projektu'
    levels: [error, exception, critical]  # volitelné
```

Knihovna dále nabízí read-only API extension pro monitor/AI agenta: `liquidMonitorLogViewer` (Tracy logy) a `liquidMonitorDbQuery` (SQL dotazy nad DB, `src/DbQuery/`). Detaily v README.

**Přístupový gate (LogViewer i DbQuery):** oba endpointy servírují jen v Tracy debug módu (`Debugger::$productionMode === false`), což je per-IP whitelist `parameters.access.debug` v host NEONu (`Configurator::setDebugMode`). **Volající IP (monitor) musí být v `access.debug`, NE v `access.trusted`** — `access.trusted` se pro tyhle endpointy nevyhodnocuje, takže IP jen tam → `403 Access denied`. Gate je fail-closed; nepoužívej `Debugger::isEnabled()` (zůstává `true` i v produkci).

## Kódové konvence

- PHP 8.1+
- PHPStan level 8
- Coding standard: liquiddesign/codestyle
- Namespace: `LiquidMonitorConnector\`

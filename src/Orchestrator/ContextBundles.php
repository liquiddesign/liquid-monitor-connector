<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

/**
 * Merges the per-source context bundles from the monitor poll response
 * (`context_sources`: type, mode, skill_md, env, allowed_tools_patterns, add_dirs)
 * into the flat env / add-dir / allowed-tools sets the Claude launch needs.
 *
 * The monitor base URL and triage API key are injected as MONITOR_API_URL /
 * MONITOR_TRIAGE_API_KEY into every agent env (independent of which context
 * sources are enabled) so the agent can always reach /api/orchestrator/capabilities
 * and the monitor proxy endpoints via curl.
 */
final class ContextBundles
{
	public function __construct(private readonly ?string $monitorUrl = null, private readonly ?string $apiKey = null,)
	{
	}

	/**
	 * @param array<int, array<string, mixed>> $sources
	 * @return array{env: array<string, string>, add_dirs: array<int, string>, allowed_tools: array<int, string>}
	 */
	public function merge(array $sources): array
	{
		$env = [];
		$addDirs = [];
		$allowedTools = [];

		if ($this->monitorUrl !== null && $this->monitorUrl !== '') {
			$env['MONITOR_API_URL'] = \rtrim($this->monitorUrl, '/');
		}

		if ($this->apiKey !== null && $this->apiKey !== '') {
			$env['MONITOR_TRIAGE_API_KEY'] = $this->apiKey;
		}

		foreach ($sources as $source) {
			foreach (\is_array($source['env'] ?? null) ? $source['env'] : [] as $name => $value) {
				if (\is_string($name) && \is_string($value) && $value !== '') {
					$env[$name] = $value;
				}
			}

			foreach (\is_array($source['add_dirs'] ?? null) ? $source['add_dirs'] : [] as $dir) {
				if (\is_string($dir) && $dir !== '') {
					$addDirs[] = $dir;
				}
			}

			foreach (\is_array($source['allowed_tools_patterns'] ?? null) ? $source['allowed_tools_patterns'] : [] as $pattern) {
				if (\is_string($pattern) && $pattern !== '') {
					$allowedTools[] = $pattern;
				}
			}
		}

		return [
			'env' => $env,
			'add_dirs' => \array_values(\array_unique($addDirs)),
			'allowed_tools' => \array_values(\array_unique($allowedTools)),
		];
	}
}

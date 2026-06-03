<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

/**
 * Merges the per-source context bundles from the monitor poll response
 * (`context_sources`: type, mode, skill_md, env, allowed_tools_patterns, add_dirs)
 * into the flat env / add-dir / allowed-tools sets the Claude launch needs.
 */
final class ContextBundles
{
	/**
	 * @param array<int, array<string, mixed>> $sources
	 * @return array{env: array<string, string>, add_dirs: array<int, string>, allowed_tools: array<int, string>}
	 */
	public function merge(array $sources): array
	{
		$env = [];
		$addDirs = [];
		$allowedTools = [];

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

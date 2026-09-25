<?php

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

// Connector allows nette/utils ^3.0 || ^4.0, but CI and local runs install 4.x only. Json::decode()
// differs between them: 3.x is decode(string $json, int $flags), 4.x is decode(string $json, bool|int $forceArrays).
// `forceArrays: true` (named param exists only in 4.x) and a positional bool (TypeError under strict_types
// on 3.x) both break hosts pinned to nette/utils 3 — use Json::FORCE_ARRAY, which works on both.
$offenders = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../src', FilesystemIterator::SKIP_DOTS));
$paths = \array_merge(\array_map(static fn (SplFileInfo $file): string => $file->getPathname(), \iterator_to_array($files, false)), \glob(__DIR__ . '/../bin/*') ?: []);

foreach ($paths as $path) {
	if (!\is_file($path)) {
		continue;
	}

	foreach (\file($path) ?: [] as $number => $line) {
		if (\preg_match('~Json::decode\([^;]*(forceArrays\s*:|,\s*(true|false)\s*\))~', $line)) {
			$offenders[] = \basename($path) . ':' . ($number + 1) . ' ' . \trim($line);
		}
	}
}

Assert::same([], $offenders);

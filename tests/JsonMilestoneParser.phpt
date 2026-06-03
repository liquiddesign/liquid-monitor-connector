<?php

declare(strict_types=1);

use LiquidMonitorConnector\Orchestrator\JsonMilestoneParser;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

$parser = new JsonMilestoneParser();

Assert::same(
	'hello world',
	$parser->collectTextFromOutput("\"hello world\"\n"),
);

Assert::notNull($parser->extract(<<<'OUT'
Some prose
```json
{"phase":"assess","category":"answer","confidence":"high"}
```
OUT));

Assert::null($parser->extract('no json here'));

// extractFromFile: primary milestone channel (raw JSON object in a file)
$file = \tempnam(\sys_get_temp_dir(), 'milestone');
\assert($file !== false);

\file_put_contents($file, '{"phase":"assess","category":"answer","confidence":"high"}');
$milestone = $parser->extractFromFile($file);
Assert::notNull($milestone);
Assert::same('answer', $milestone['category']);

// Agent may wrap the file content in a fence anyway — still accepted.
\file_put_contents($file, "```json\n{\"phase\":\"work\",\"category\":\"implemented\"}\n```\n");
$milestone = $parser->extractFromFile($file);
Assert::notNull($milestone);
Assert::same('implemented', $milestone['category']);

// Invalid / empty / scalar content is rejected.
\file_put_contents($file, '{"phase": broken');
Assert::null($parser->extractFromFile($file));

\file_put_contents($file, '');
Assert::null($parser->extractFromFile($file));

\file_put_contents($file, '"just a string"');
Assert::null($parser->extractFromFile($file));

\unlink($file);
Assert::null($parser->extractFromFile($file));

echo "\nOK " . __FILE__ . "\n";

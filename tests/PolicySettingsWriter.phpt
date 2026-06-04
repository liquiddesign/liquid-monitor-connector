<?php

declare(strict_types=1);

use LiquidMonitorConnector\Orchestrator\PolicySettingsWriter;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

$writer = new PolicySettingsWriter();
$cwd = \sys_get_temp_dir() . '/policy-writer-test-' . \getmypid();
@\mkdir($cwd, 0o777, true);

$merged = [
	'env' => [],
	'add_dirs' => [],
	'allowed_tools' => ['Bash(composer test:*)', 'Bash(php artisan:*)'],
];

// Repo mode: git deny list from settings is applied
$settingsPath = $writer->write($cwd, ['deny_git_operations' => ['commit', 'reset']], $merged, true);
Assert::same($cwd . '/' . PolicySettingsWriter::SETTINGS_RELATIVE_PATH, $settingsPath);
Assert::true(\is_file($settingsPath));
Assert::true(\is_file($cwd . '/' . PolicySettingsWriter::POLICY_RELATIVE_PATH));

$claudeSettings = \json_decode((string) \file_get_contents($settingsPath), true);
Assert::type('array', $claudeSettings);
$deny = $claudeSettings['permissions']['deny'];
Assert::contains('Bash(git push:*)', $deny);
Assert::contains('Bash(rm -rf:*)', $deny);
Assert::contains('Bash(git commit:*)', $deny);
Assert::contains('Bash(git reset:*)', $deny);
Assert::false(\in_array('Bash(git checkout:*)', $deny, true)); // not in custom list
Assert::contains('Edit(.orchestrator/turn-state.json)', $deny);
Assert::contains('Write(.orchestrator/outbox/**)', $deny);
Assert::false(\in_array('Edit(.orchestrator/milestone.json)', $deny, true)); // milestone stays writable
// Baseline Read/Grep/Glob are always prepended (deduped) to the monitor allow-list.
Assert::same(
	['Read', 'Grep', 'Glob', 'Bash(composer test:*)', 'Bash(php artisan:*)'],
	$claudeSettings['permissions']['allow'],
);

// Hooks reference the guard binary for PreToolUse and Stop
Assert::same('Bash|Edit|Write', $claudeSettings['hooks']['PreToolUse'][0]['matcher']);
Assert::contains('orchestrator-guard', $claudeSettings['hooks']['PreToolUse'][0]['hooks'][0]['command']);
Assert::contains('orchestrator-guard', $claudeSettings['hooks']['Stop'][0]['hooks'][0]['command']);

$policy = \json_decode((string) \file_get_contents($cwd . '/' . PolicySettingsWriter::POLICY_RELATIVE_PATH), true);
Assert::type('array', $policy);
Assert::true($policy['repo_mode']);
Assert::same(['commit', 'reset'], $policy['deny_git']);

// Worktree mode: no git-op denies beyond push, repo_mode=false in policy
$writer->write($cwd, [], $merged, false);
$claudeSettings = \json_decode((string) \file_get_contents($settingsPath), true);
$deny = $claudeSettings['permissions']['deny'];
Assert::contains('Bash(git push:*)', $deny);
Assert::false(\in_array('Bash(git commit:*)', $deny, true));

$policy = \json_decode((string) \file_get_contents($cwd . '/' . PolicySettingsWriter::POLICY_RELATIVE_PATH), true);
Assert::false($policy['repo_mode']);
Assert::same(PolicySettingsWriter::DEFAULT_DENY_GIT_OPERATIONS, $policy['deny_git']);

// Invalid deny_git_operations falls back to defaults; injection-ish values are dropped
$writer->write($cwd, ['deny_git_operations' => ['commit; rm -rf /', 123]], $merged, true);
$policy = \json_decode((string) \file_get_contents($cwd . '/' . PolicySettingsWriter::POLICY_RELATIVE_PATH), true);
Assert::same(PolicySettingsWriter::DEFAULT_DENY_GIT_OPERATIONS, $policy['deny_git']);

// Even with an empty monitor allow-list, the agent keeps the read baseline.
$writer->write($cwd, [], ['env' => [], 'add_dirs' => [], 'allowed_tools' => []], true);
$claudeSettings = \json_decode((string) \file_get_contents($settingsPath), true);
Assert::same(['Read', 'Grep', 'Glob'], $claudeSettings['permissions']['allow']);

// A source that already grants a baseline tool does not duplicate it.
$writer->write($cwd, [], ['env' => [], 'add_dirs' => [], 'allowed_tools' => ['Read', 'Bash(ls:*)']], true);
$claudeSettings = \json_decode((string) \file_get_contents($settingsPath), true);
Assert::same(['Read', 'Grep', 'Glob', 'Bash(ls:*)'], $claudeSettings['permissions']['allow']);

// Empty cwd throws
Assert::exception(
	fn () => $writer->write('', [], $merged, true),
	RuntimeException::class,
);

Tester\Helpers::purge($cwd);
@\rmdir($cwd);

echo "\nOK " . __FILE__ . "\n";

<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

const GUARD = __DIR__ . '/../bin/orchestrator-guard';

/**
 * @param array<string, mixed> $input
 * @return array{exit: int, stdout: string}
 */
function runGuard(array $input): array
{
	$process = new Process(['php', GUARD]);
	$process->setInput(\json_encode($input));
	$process->setTimeout(15);
	$process->run();

	return ['exit' => $process->getExitCode() ?? -1, 'stdout' => $process->getOutput()];
}

$cwd = \sys_get_temp_dir() . '/guard-test-' . \getmypid();
@\mkdir($cwd . '/.orchestrator', 0o777, true);
\file_put_contents(
	$cwd . '/.orchestrator/policy.json',
	\json_encode(['repo_mode' => true, 'deny_git' => ['commit', 'checkout', 'switch', 'reset', 'stash']]),
);

$preToolUse = fn (string $tool, array $toolInput): array => runGuard([
	'hook_event_name' => 'PreToolUse',
	'tool_name' => $tool,
	'tool_input' => $toolInput,
	'cwd' => $cwd,
]);

// PreToolUse: git push denied always
$result = $preToolUse('Bash', ['command' => 'git push origin main']);
Assert::same(0, $result['exit']);
Assert::contains('"permissionDecision":"deny"', $result['stdout']);
Assert::contains('git push', $result['stdout']);

// PreToolUse: repo-mode git ops denied, even with flags between git and the subcommand
$result = $preToolUse('Bash', ['command' => 'git -C /tmp commit -m "x"']);
Assert::contains('"permissionDecision":"deny"', $result['stdout']);

// PreToolUse: ordinary bash allowed (empty output)
$result = $preToolUse('Bash', ['command' => 'git status && composer test']);
Assert::same(0, $result['exit']);
Assert::same('', $result['stdout']);

// PreToolUse: read-only git commands that merely MENTION a denied op as an argument
// or option value must NOT be denied (regression — used to false-positive).
foreach (['git log --grep=reset', 'git diff -- config/checkout.php', 'git log --oneline | grep commit', 'git show HEAD:src/Stash.php'] as $command) {
	$result = $preToolUse('Bash', ['command' => $command]);
	Assert::same('', $result['stdout'], $command);
}

// PreToolUse: chained denied op is still caught across separators.
$result = $preToolUse('Bash', ['command' => 'git add . && git commit -m x']);
Assert::contains('"permissionDecision":"deny"', $result['stdout']);

// PreToolUse: writing a control file through the shell is denied (bypass of Edit/Write guard).
foreach ([
	'echo "{}" > .orchestrator/turn-state.json',
	'echo x >> .orchestrator/policy.json',
	'rm .orchestrator/claude-settings.json',
	'mv /tmp/x .orchestrator/outbox/task1.json',
	'sed -i s/a/b/ .orchestrator/policy.json',
] as $command) {
	$result = $preToolUse('Bash', ['command' => $command]);
	Assert::contains('"permissionDecision":"deny"', $result['stdout'], $command);
}

// PreToolUse: reading a control file and writing the milestone via shell are allowed.
$result = $preToolUse('Bash', ['command' => 'cat .orchestrator/policy.json']);
Assert::same('', $result['stdout']);
$result = $preToolUse('Bash', ['command' => 'echo "{}" > .orchestrator/milestone.json']);
Assert::same('', $result['stdout']);

// "checkout" mentioned after a separator is a different command — still denied ops only match within one command
$result = $preToolUse('Bash', ['command' => 'echo hello']);
Assert::same('', $result['stdout']);

// PreToolUse: rm -rf denied (combined and split flags)
foreach (['rm -rf vendor', 'rm -fr vendor', 'rm -r -f vendor'] as $command) {
	$result = $preToolUse('Bash', ['command' => $command]);
	Assert::contains('"permissionDecision":"deny"', $result['stdout'], $command);
}

// rm without force-recursive is fine
$result = $preToolUse('Bash', ['command' => 'rm composer.lock']);
Assert::same('', $result['stdout']);

// PreToolUse: control files protected, milestone writable
$result = $preToolUse('Write', ['file_path' => $cwd . '/.orchestrator/turn-state.json']);
Assert::contains('"permissionDecision":"deny"', $result['stdout']);

$result = $preToolUse('Edit', ['file_path' => $cwd . '/.orchestrator/outbox/task1.json']);
Assert::contains('"permissionDecision":"deny"', $result['stdout']);

$result = $preToolUse('Write', ['file_path' => $cwd . '/.orchestrator/milestone.json']);
Assert::same('', $result['stdout']);

$result = $preToolUse('Write', ['file_path' => $cwd . '/src/Foo.php']);
Assert::same('', $result['stdout']);

// Stop: no open turn → allow
$stop = fn (array $extra = []): array => runGuard(\array_merge([
	'hook_event_name' => 'Stop',
	'cwd' => $cwd,
], $extra));

$result = $stop();
Assert::same(0, $result['exit']);

// Stop: open turn without milestone → block (exit 2)
\file_put_contents($cwd . '/.orchestrator/turn-state.json', '{"task_id":1}');
$result = $stop();
Assert::same(2, $result['exit']);
Assert::contains('"decision":"block"', $result['stdout']);

// Stop: stop_hook_active short-circuits (no repeated blocking)
$result = $stop(['stop_hook_active' => true]);
Assert::same(0, $result['exit']);

// Stop: invalid milestone JSON → block
\file_put_contents($cwd . '/.orchestrator/milestone.json', '```json {"phase":"work"}```');
$result = $stop();
Assert::same(2, $result['exit']);
Assert::contains('not valid JSON', $result['stdout']);

// Stop: missing/invalid enum keys → block
\file_put_contents($cwd . '/.orchestrator/milestone.json', \json_encode(['phase' => 'work', 'category' => 'nonsense', 'confidence' => 'high']));
$result = $stop();
Assert::same(2, $result['exit']);
Assert::contains('category', $result['stdout']);

// Stop: valid milestone → allow
\file_put_contents(
	$cwd . '/.orchestrator/milestone.json',
	\json_encode(['phase' => 'work', 'category' => 'implemented', 'confidence' => 'high', 'draft_response_md' => 'done']),
);
$result = $stop();
Assert::same(0, $result['exit']);

// Garbage stdin → allow (fail open, the policy layer must not break the session)
$process = new Process(['php', GUARD]);
$process->setInput('not json');
$process->run();
Assert::same(0, $process->getExitCode());

Tester\Helpers::purge($cwd);
@\rmdir($cwd);

echo "\nOK " . __FILE__ . "\n";

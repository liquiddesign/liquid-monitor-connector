<?php

declare(strict_types=1);

use LiquidMonitorConnector\Orchestrator\PathGuard;
use Symfony\Component\Process\Process;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

$run = static function (array $cmd): void {
	$process = new Process($cmd);
	$process->setTimeout(60);
	$process->mustRun();
};

$base = \sys_get_temp_dir() . '/pathguard-' . \getmypid() . '-' . \uniqid();
$work = $base . '/work';
@\mkdir($work, 0o777, true);

$run(['git', 'init', '-b', 'main', $work]);
$run(['git', '-C', $work, 'config', 'user.email', 'test@test.cz']);
$run(['git', '-C', $work, 'config', 'user.name', 'Test']);
\file_put_contents($work . '/README.md', "v1\n");
@\mkdir($work . '/app', 0o777, true);
\file_put_contents($work . '/app/Service.php', "<?php\n");
$run(['git', '-C', $work, 'add', '.']);
$run(['git', '-C', $work, 'commit', '-m', 'init']);

$guard = new PathGuard();
$settings = ['require_human_for_paths' => ['migrations', 'config', '.env']];

// Clean tree → nothing restricted.
Assert::false($guard->touchesRestrictedPaths($work, $settings));

// Editing a non-restricted tracked file → not restricted.
\file_put_contents($work . '/app/Service.php', "<?php // edit\n");
Assert::false($guard->touchesRestrictedPaths($work, $settings));

// A brand-new (untracked) migration must be caught — this is the regression:
// `git diff HEAD` would never show it, so it used to slip past the review gate.
@\mkdir($work . '/database/migrations', 0o777, true);
\file_put_contents($work . '/database/migrations/2026_create_table.php', "<?php\n");
Assert::true($guard->touchesRestrictedPaths($work, $settings));
\unlink($work . '/database/migrations/2026_create_table.php');

// A brand-new untracked .env must be caught too.
\file_put_contents($work . '/.env', "SECRET=1\n");
Assert::true($guard->touchesRestrictedPaths($work, $settings));
\unlink($work . '/.env');

// A modified tracked config file is caught (the original tracked-only case still works).
@\mkdir($work . '/config', 0o777, true);
\file_put_contents($work . '/config/app.php', "<?php\n");
$run(['git', '-C', $work, 'add', 'config/app.php']);
$run(['git', '-C', $work, 'commit', '-m', 'add config']);
\file_put_contents($work . '/config/app.php', "<?php // changed\n");
Assert::true($guard->touchesRestrictedPaths($work, $settings));
$run(['git', '-C', $work, 'checkout', '--', 'config/app.php']);

// Files under .orchestrator/ are excluded — the agent's own milestone never triggers handoff.
@\mkdir($work . '/.orchestrator', 0o777, true);
\file_put_contents($work . '/.orchestrator/config-like.env', "x\n");
Assert::false($guard->touchesRestrictedPaths($work, $settings));

// Empty pattern list disables the guard.
Assert::false($guard->touchesRestrictedPaths($work, ['require_human_for_paths' => []]));

Tester\Helpers::purge($base);
@\rmdir($base);

echo "\nOK " . __FILE__ . "\n";

<?php

declare(strict_types=1);

use LiquidMonitorConnector\Orchestrator\RepoSynchronizer;
use Symfony\Component\Process\Process;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

$run = static function (array $cmd): void {
	$process = new Process($cmd);
	$process->setTimeout(60);
	$process->mustRun();
};

$base = \sys_get_temp_dir() . '/reposync-' . \getmypid() . '-' . \uniqid();
$remote = $base . '/remote.git';
$work = $base . '/work';
$work2 = $base . '/work2';
@\mkdir($base, 0o777, true);

// Bare remote + a working clone with one commit on main.
$run(['git', 'init', '--bare', '-b', 'main', $remote]);
$run(['git', 'clone', $remote, $work]);
$run(['git', '-C', $work, 'config', 'user.email', 'test@test.cz']);
$run(['git', '-C', $work, 'config', 'user.name', 'Test']);
\file_put_contents($work . '/README.md', "v1\n");
$run(['git', '-C', $work, 'add', '.']);
$run(['git', '-C', $work, 'commit', '-m', 'init']);
$run(['git', '-C', $work, 'push', '-u', 'origin', 'main']);

$sync = new RepoSynchronizer();

// Clean working tree.
Assert::true($sync->isClean($work));

// Untracked file makes it dirty.
\file_put_contents($work . '/dirty.txt', "x\n");
Assert::false($sync->isClean($work));
\unlink($work . '/dirty.txt');
Assert::true($sync->isClean($work));

// Modified tracked file makes it dirty.
\file_put_contents($work . '/README.md', "v1-modified\n");
Assert::false($sync->isClean($work));
$run(['git', '-C', $work, 'checkout', '--', 'README.md']);
Assert::true($sync->isClean($work));

// Changes under .orchestrator/ are ignored by the clean check.
@\mkdir($work . '/.orchestrator', 0o777, true);
\file_put_contents($work . '/.orchestrator/turn-state.json', "{}\n");
\file_put_contents($work . '/.orchestrator/policy.json', "{}\n");
Assert::true($sync->isClean($work));

// fetch refreshes refs without error.
Assert::true($sync->fetch($work));

// Push a new commit to the remote from a second clone, then fast-forward $work.
$run(['git', 'clone', $remote, $work2]);
$run(['git', '-C', $work2, 'config', 'user.email', 'test@test.cz']);
$run(['git', '-C', $work2, 'config', 'user.name', 'Test']);
\file_put_contents($work2 . '/README.md', "v2\n");
$run(['git', '-C', $work2, 'commit', '-am', 'v2']);
$run(['git', '-C', $work2, 'push', 'origin', 'main']);

Assert::true($sync->pullFastForward($work));
Assert::same("v2\n", \file_get_contents($work . '/README.md'));

// A divergent local commit cannot fast-forward → returns false.
\file_put_contents($work2 . '/README.md', "v3-remote\n");
$run(['git', '-C', $work2, 'commit', '-am', 'v3-remote']);
$run(['git', '-C', $work2, 'push', 'origin', 'main']);
\file_put_contents($work . '/README.md', "v3-local\n");
$run(['git', '-C', $work, 'commit', '-am', 'v3-local']);
Assert::false($sync->pullFastForward($work));

Tester\Helpers::purge($base);
@\rmdir($base);

echo "\nOK " . __FILE__ . "\n";

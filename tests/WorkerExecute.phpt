<?php

declare(strict_types=1);

use LiquidMonitorConnector\Worker\CronJobHandler;
use LiquidMonitorConnector\Worker\CronJobHandlerRegistry;
use LiquidMonitorConnector\Worker\WorkerExecuteCommand;
use Nette\DI\Container;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

$tempDir = \sys_get_temp_dir() . '/monitor-worker-exec-' . \getmypid();
@\mkdir($tempDir, 0777, true);

$okBootstrap = $tempDir . '/bootstrap-ok.php';
$failBootstrap = $tempDir . '/bootstrap-fail.php';

\file_put_contents($okBootstrap, <<<'PHP'
<?php

declare(strict_types=1);

use LiquidMonitorConnector\Worker\CronJobHandler;
use LiquidMonitorConnector\Worker\CronJobHandlerRegistry;
use Nette\DI\Container;

return new class extends Container {
	public function getByType(string $type, bool $throw = true): ?object
	{
		if ($type === CronJobHandlerRegistry::class) {
			return new CronJobHandlerRegistry(['import' => new class implements CronJobHandler {
				public function execute(?array $arguments): void
				{
				}
			}]);
		}

		if ($throw) {
			throw new \RuntimeException('unknown type');
		}

		return null;
	}
};
PHP);

\file_put_contents($failBootstrap, <<<'PHP'
<?php

declare(strict_types=1);

use LiquidMonitorConnector\Worker\CronJobHandler;
use LiquidMonitorConnector\Worker\CronJobHandlerRegistry;
use Nette\DI\Container;

return new class extends Container {
	public function getByType(string $type, bool $throw = true): ?object
	{
		if ($type === CronJobHandlerRegistry::class) {
			return new CronJobHandlerRegistry(['import' => new class implements CronJobHandler {
				public function execute(?array $arguments): void
				{
					throw new \RuntimeException('handler boom');
				}
			}]);
		}

		if ($throw) {
			throw new \RuntimeException('unknown type');
		}

		return null;
	}
};
PHP);

$app = new Application();
$app->setAutoExit(false);
$app->addCommand(new WorkerExecuteCommand());

$output = new BufferedOutput();
$exitCode = $app->run(new ArrayInput([
	'command' => 'execute',
	'--bootstrap' => $okBootstrap,
	'--job-id' => '42',
	'--job-log-id' => '84',
	'--cron-code' => 'import',
	'--arguments' => '{"userId":5}',
]), $output);

Assert::same(0, $exitCode);
Assert::true(\str_contains(\str_replace(' ', '', $output->fetch()), '"status":"ok"'));

\putenv(WorkerExecuteCommand::ENV_JOB_ID . '=99');
\putenv(WorkerExecuteCommand::ENV_JOB_LOG_ID . '=199');
\putenv(WorkerExecuteCommand::ENV_CRON_CODE . '=import');
\putenv(WorkerExecuteCommand::ENV_ARGUMENTS . '{"mode":"env"}');

$envOutput = new BufferedOutput();
$envExitCode = $app->run(new ArrayInput([
	'command' => 'execute',
	'--bootstrap' => $okBootstrap,
]), $envOutput);

Assert::same(0, $envExitCode);

$failOutput = new BufferedOutput();
$failExitCode = $app->run(new ArrayInput([
	'command' => 'execute',
	'--bootstrap' => $failBootstrap,
	'--job-id' => '1',
	'--job-log-id' => '2',
	'--cron-code' => 'import',
]), $failOutput);

Assert::notSame(0, $failExitCode);

@\unlink($okBootstrap);
@\unlink($failBootstrap);
@\rmdir($tempDir);

echo "\nOK " . __FILE__ . "\n";

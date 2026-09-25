<?php

declare(strict_types=1);

use LiquidMonitorConnector\Console\ConsoleCompat;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

// bin/monitor-worker + bin/orchestrator-run register commands through ConsoleCompat, because
// Application::addCommand() exists only since symfony/console 7.4 and add() is gone in 8.0.
$command = new class ('compat:ping') extends Command {
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$output->write('pong');

		return self::SUCCESS;
	}
};

$app = new Application();
$app->setAutoExit(false);
ConsoleCompat::addCommand($app, $command);

Assert::true($app->has('compat:ping'));
Assert::same($command, $app->find('compat:ping'));

$output = new BufferedOutput();
Assert::same(0, $app->run(new ArrayInput(['command' => 'compat:ping']), $output));
Assert::same('pong', $output->fetch());

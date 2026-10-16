<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

use LiquidMonitorConnector\Scheduler\CronDefinitionCatalog;
use LiquidMonitorConnector\Scheduler\SchedulerConfig;
use Nette\Utils\Json;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the effective schedule after `schedules` / `environments` are applied
 * (docs/v4-local-scheduler.md § 5.0). Its output belongs in a PR that changes a schedule.
 *
 *     php vendor/bin/monitor-worker plan --environment=production --hours=24
 */
final class PlanCommand extends Command
{
	public const NAME = 'plan';

	/** Upper bound of listed occurrences per cron (a `* * * * *` cron over a week). */
	public const MAX_OCCURRENCES = 10080;

	/**
	 * @param \Closure(string|null): \Nette\DI\Container|null $containerFactory
	 */
	public function __construct(private \Closure|null $containerFactory = null)
	{
		parent::__construct(self::NAME);
	}

	protected function configure(): void
	{
		$this
			->setDescription('Show when each #[PullCron] cron runs in an environment.')
			->addOption('bootstrap', null, InputOption::VALUE_REQUIRED, 'PHP file returning a Nette DI Container (default: the package bootstrap)')
			->addOption('environment', null, InputOption::VALUE_REQUIRED, 'Environment to evaluate (default: scheduler.environment)')
			->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Length of the window', '24')
			->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start of the window (Y-m-d\TH:i, scheduler timezone; default: now)')
			->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output with every occurrence');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$bootstrap = $input->getOption('bootstrap');
		$container = $this->containerFactory !== null
			? ($this->containerFactory)(\is_string($bootstrap) ? $bootstrap : null)
			: ContainerBootstrap::load(\is_string($bootstrap) ? $bootstrap : null);

		$config = $container->getByType(SchedulerConfig::class);
		$catalog = $container->getByType(CronDefinitionCatalog::class);

		$environmentOption = $input->getOption('environment');
		$environment = \is_string($environmentOption) && $environmentOption !== '' ? $environmentOption : $config->environment;
		$timezone = $config->timezone();
		$hours = \max(1, (int) $input->getOption('hours'));

		$fromOption = $input->getOption('from');
		$from = \is_string($fromOption) && $fromOption !== ''
			? \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $fromOption, $timezone)
			: new \DateTimeImmutable('now', $timezone);

		if ($from === false) {
			$output->writeln('<error>--from must be in Y-m-d\TH:i format.</error>');

			return self::FAILURE;
		}

		$from = $from->setTime((int) $from->format('H'), (int) $from->format('i'), 0);
		$to = $from->modify('+' . $hours . ' hours');
		$plan = [];

		foreach ($catalog->all($environment) as $definition) {
			$occurrences = [];
			$error = null;

			try {
				foreach ($definition->expressions() as $expression) {
					$next = $from->modify('-1 minute');

					while (\count($occurrences) < self::MAX_OCCURRENCES) {
						$next = \DateTimeImmutable::createFromMutable($expression->getNextRunDate($next, 0, false, $timezone->getName()));

						if ($next >= $to) {
							break;
						}

						$occurrences[$next->getTimestamp()] = $next;
					}
				}

				// Several expressions may hit the same minute; it runs once.
				\ksort($occurrences);
				$occurrences = \array_values($occurrences);
			} catch (\Throwable $e) {
				$error = $e->getMessage();
			}

			$plan[] = [
				'code' => $definition->code,
				'handler_class' => $definition->handlerClass,
				'schedule' => $definition->schedule(),
				'schedule_source' => $definition->scheduleSource(),
				'error' => $error,
				'occurrences' => $occurrences,
			];
		}

		if ((bool) $input->getOption('json')) {
			$output->writeln(Json::encode([
				'environment' => $environment,
				'timezone' => $timezone->getName(),
				'from' => $from->format(\DATE_ATOM),
				'to' => $to->format(\DATE_ATOM),
				'crons' => \array_map(static fn (array $row): array => [
					'code' => $row['code'],
					'handler_class' => $row['handler_class'],
					'schedule' => $row['schedule'],
					'schedule_source' => $row['schedule_source'],
					'error' => $row['error'],
					'runs' => \count($row['occurrences']),
					'occurrences' => \array_map(static fn (\DateTimeImmutable $at): string => $at->format(\DATE_ATOM), $row['occurrences']),
				], $plan),
			], Json::PRETTY));

			return $this->hasErrors($plan) ? self::FAILURE : self::SUCCESS;
		}

		$output->writeln(\sprintf(
			'Plan for environment <info>%s</info>, %s – %s (%s)',
			$environment ?? '(none)',
			$from->format('Y-m-d H:i'),
			$to->format('Y-m-d H:i'),
			$timezone->getName(),
		));

		foreach ($plan as $row) {
			if ($row['error'] !== null) {
				$output->writeln(\sprintf('  <error>%-40s %-18s %s</error>', $row['code'], $row['schedule'], $row['error']));

				continue;
			}

			if ($row['schedule'] === null) {
				$output->writeln(\sprintf('  %-40s %-18s off in this environment', $row['code'], '—'));

				continue;
			}

			$next = \array_map(static fn (\DateTimeImmutable $at): string => $at->format('D H:i'), \array_slice($row['occurrences'], 0, 3));
			$output->writeln(\sprintf(
				'  %-40s %-18s %-22s %5d× %s',
				$row['code'],
				$row['schedule'],
				$row['schedule_source'],
				\count($row['occurrences']),
				$next === [] ? '' : 'next: ' . \implode(', ', $next),
			));
		}

		return $this->hasErrors($plan) ? self::FAILURE : self::SUCCESS;
	}

	/**
	 * @param list<array{error: string|null}> $plan
	 */
	private function hasErrors(array $plan): bool
	{
		foreach ($plan as $row) {
			if ($row['error'] !== null) {
				return true;
			}
		}

		return false;
	}
}

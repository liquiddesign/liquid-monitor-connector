<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class TmuxClaudeDriver
{
	public function __construct(private readonly string $claudeBinary = 'claude')
	{
	}

	public function sessionExists(string $tmuxSession): bool
	{
		$process = new Process(['tmux', 'has-session', '-t', $tmuxSession]);
		$process->run();

		return $process->isSuccessful();
	}

	public function startNew(string $tmuxSession, string $cwd, string $claudeSessionId): void
	{
		if ($this->sessionExists($tmuxSession)) {
			$this->kill($tmuxSession);
		}

		$cmd = \sprintf(
			'%s --session-id %s',
			\escapeshellarg($this->claudeBinary),
			\escapeshellarg($claudeSessionId),
		);

		$process = new Process([
			'tmux', 'new-session', '-d', '-s', $tmuxSession, '-c', $cwd, 'bash', '-lc', $cmd,
		]);
		$process->setTimeout(30);
		$process->run();

		if (!$process->isSuccessful()) {
			throw new ProcessFailedException($process);
		}
	}

	public function resume(string $tmuxSession, string $cwd, string $claudeSessionId): void
	{
		if ($this->sessionExists($tmuxSession)) {
			$this->kill($tmuxSession);
		}

		$cmd = \sprintf(
			'%s --resume %s',
			\escapeshellarg($this->claudeBinary),
			\escapeshellarg($claudeSessionId),
		);

		$process = new Process([
			'tmux', 'new-session', '-d', '-s', $tmuxSession, '-c', $cwd, 'bash', '-lc', $cmd,
		]);
		$process->setTimeout(30);
		$process->run();

		if (!$process->isSuccessful()) {
			throw new ProcessFailedException($process);
		}
	}

	public function sendKeys(string $tmuxSession, string $text, bool $enter = true): void
	{
		$args = ['tmux', 'send-keys', '-t', $tmuxSession, $text];

		if ($enter) {
			$args[] = 'Enter';
		}

		$process = new Process($args);
		$process->setTimeout(15);
		$process->run();

		if (!$process->isSuccessful()) {
			throw new ProcessFailedException($process);
		}
	}

	public function capturePane(string $tmuxSession): string
	{
		$process = new Process(['tmux', 'capture-pane', '-p', '-t', $tmuxSession, '-S', '-3000']);
		$process->setTimeout(15);
		$process->run();

		if (!$process->isSuccessful()) {
			throw new ProcessFailedException($process);
		}

		return $process->getOutput();
	}

	public function kill(string $tmuxSession): void
	{
		if (!$this->sessionExists($tmuxSession)) {
			return;
		}

		$process = new Process(['tmux', 'kill-session', '-t', $tmuxSession]);
		$process->run();
	}
}

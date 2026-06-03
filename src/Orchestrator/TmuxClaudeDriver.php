<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use Nette\Utils\Strings;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class TmuxClaudeDriver
{
	/** Microseconds to wait after pasting/typing before sending Enter (Claude REPL must absorb input). */
	private const int ENTER_DELAY_US = 300000;

	/** Use tmux paste-buffer for multiline or long payloads (send-keys + Enter is unreliable). */
	private const int PASTE_BUFFER_MIN_LENGTH = 400;

	public function __construct(private readonly string $claudeBinary = 'claude')
	{
	}

	public function sessionExists(string $tmuxSession): bool
	{
		$process = new Process(['tmux', 'has-session', '-t', $tmuxSession]);
		$process->run();

		return $process->isSuccessful();
	}

	/**
	 * @param array<string, string> $env
	 * @param array<int, string> $addDirs
	 * @param array<int, string> $allowedTools
	 */
	public function startNew(
		string $tmuxSession,
		string $cwd,
		string $claudeSessionId,
		array $env = [],
		array $addDirs = [],
		array $allowedTools = [],
	): void {
		$this->launch($tmuxSession, $cwd, '--session-id', $claudeSessionId, $env, $addDirs, $allowedTools);
	}

	/**
	 * @param array<string, string> $env
	 * @param array<int, string> $addDirs
	 * @param array<int, string> $allowedTools
	 */
	public function resume(
		string $tmuxSession,
		string $cwd,
		string $claudeSessionId,
		array $env = [],
		array $addDirs = [],
		array $allowedTools = [],
	): void {
		$this->launch($tmuxSession, $cwd, '--resume', $claudeSessionId, $env, $addDirs, $allowedTools);
	}

	/**
	 * Deliver text to the tmux pane, then submit with a separate Enter.
	 *
	 * A single `tmux send-keys … text Enter` call often types multiline briefs but never
	 * submits them — Enter must be its own send-keys invocation (after a short delay).
	 */
	public function sendKeys(string $tmuxSession, string $text, bool $enter = true): void
	{
		if ($text !== '') {
			if ($this->shouldPasteFromBuffer($text)) {
				$this->pasteFromBuffer($tmuxSession, $text);
			} else {
				$this->runProcess(['tmux', 'send-keys', '-t', $tmuxSession, '-l', $text]);
			}
		}

		if (!$enter) {
			return;
		}

		\usleep(self::ENTER_DELAY_US);
		$this->sendEnter($tmuxSession);
	}

	public function sendEnter(string $tmuxSession): void
	{
		$this->runProcess(['tmux', 'send-keys', '-t', $tmuxSession, 'Enter']);
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

	/**
	 * @param array<string, string> $env
	 * @param array<int, string> $addDirs
	 * @param array<int, string> $allowedTools
	 */
	private function launch(
		string $tmuxSession,
		string $cwd,
		string $sessionFlag,
		string $claudeSessionId,
		array $env,
		array $addDirs,
		array $allowedTools,
	): void {
		if ($this->sessionExists($tmuxSession)) {
			$this->kill($tmuxSession);
		}

		$cmd = '';

		foreach ($env as $name => $value) {
			if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1 || $value === '') {
				continue;
			}

			$cmd .= $name . '=' . \escapeshellarg($value) . ' ';
		}

		$cmd .= \sprintf(
			'%s %s %s',
			\escapeshellarg($this->claudeBinary),
			$sessionFlag,
			\escapeshellarg($claudeSessionId),
		);

		foreach ($addDirs as $dir) {
			if ($dir === '') {
				continue;
			}

			$cmd .= ' --add-dir ' . \escapeshellarg($dir);
		}

		$tools = \array_values(\array_filter($allowedTools, static fn (string $t): bool => $t !== ''));

		if ($tools !== []) {
			$cmd .= ' --allowedTools ' . \implode(' ', \array_map('escapeshellarg', $tools));
		}

		$process = new Process([
			'tmux', 'new-session', '-d', '-s', $tmuxSession, '-c', $cwd, 'bash', '-lc', $cmd,
		]);
		$process->setTimeout(30);
		$process->run();

		if (!$process->isSuccessful()) {
			throw new ProcessFailedException($process);
		}
	}

	private function shouldPasteFromBuffer(string $text): bool
	{
		return \str_contains($text, "\n") || Strings::length($text) >= self::PASTE_BUFFER_MIN_LENGTH;
	}

	private function pasteFromBuffer(string $tmuxSession, string $text): void
	{
		$load = new Process(['tmux', 'load-buffer', '-']);
		$load->setInput($text);
		$load->setTimeout(15);
		$load->run();

		if (!$load->isSuccessful()) {
			throw new ProcessFailedException($load);
		}

		$this->runProcess(['tmux', 'paste-buffer', '-t', $tmuxSession]);
	}

	/**
	 * @param array<int, string> $command
	 */
	private function runProcess(array $command): void
	{
		$process = new Process($command);
		$process->setTimeout(15);
		$process->run();

		if (!$process->isSuccessful()) {
			throw new ProcessFailedException($process);
		}
	}
}

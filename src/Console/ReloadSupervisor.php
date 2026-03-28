<?php

declare(strict_types=1);

namespace PhpDevRuntime\Console;

use PhpDevRuntime\Runtime\ReloadConfiguration;

final class ReloadSupervisor
{
    private const CHILD_ENV_FLAG = 'PHP_DEV_RUNTIME_SUPERVISED_CHILD';

    public function shouldSupervise(ServeOptions $options): bool
    {
        return $options->reload->enabled && getenv(self::CHILD_ENV_FLAG) !== '1';
    }

    public function run(array $argv, ReloadConfiguration $reload): int
    {
        $script = $this->resolveScriptPath($argv[0] ?? 'bin/dev-server');
        $command = [PHP_BINARY, $script, ...array_slice($argv, 1)];
        $watcher = new ReloadWatcher($reload);

        $process = $this->startChild($command);
        $this->installSignalHandlers($process);
        $lastFingerprint = $watcher->fingerprint();

        while (true) {
            usleep($reload->intervalMilliseconds * 1000);

            $status = proc_get_status($process);
            if (!$status['running']) {
                return $status['exitcode'];
            }

            $nextFingerprint = $watcher->fingerprint();
            if ($nextFingerprint === $lastFingerprint) {
                continue;
            }

            $lastFingerprint = $nextFingerprint;
            fwrite(STDERR, "Reloading development server...\n");
            $this->stopChild($process);
            $process = $this->startChild($command);
            $this->installSignalHandlers($process);
        }
    }

    private function startChild(array $command)
    {
        $env = $_ENV;
        $env[self::CHILD_ENV_FLAG] = '1';

        $process = proc_open(
            $command,
            [
                0 => ['file', 'php://stdin', 'r'],
                1 => ['file', 'php://stdout', 'w'],
                2 => ['file', 'php://stderr', 'w'],
            ],
            $pipes,
            getcwd(),
            $env,
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start supervised server process.');
        }

        return $process;
    }

    private function stopChild($process): void
    {
        $status = proc_get_status($process);

        if ($status['running']) {
            proc_terminate($process, defined('SIGTERM') ? SIGTERM : 15);
            usleep(200000);
        }

        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process, defined('SIGKILL') ? SIGKILL : 9);
        }

        proc_close($process);
    }

    private function installSignalHandlers($process): void
    {
        if (!function_exists('pcntl_signal') || !defined('SIGINT')) {
            return;
        }

        pcntl_async_signals(true);

        $forward = function (int $signal) use ($process): void {
            $this->stopChild($process);
            exit(128 + $signal);
        };

        pcntl_signal(SIGINT, $forward);

        if (defined('SIGTERM')) {
            pcntl_signal(SIGTERM, $forward);
        }
    }

    private function resolveScriptPath(string $argv0): string
    {
        if (str_starts_with($argv0, '/')) {
            return $argv0;
        }

        return getcwd() . '/' . $argv0;
    }
}

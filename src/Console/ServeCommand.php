<?php

declare(strict_types=1);

namespace PhpDevRuntime\Console;

use PhpDevRuntime\Http\RuntimeServer;
use PhpDevRuntime\Runtime\AppFactory;
use PhpDevRuntime\Runtime\RuntimeContext;
use Throwable;

final class ServeCommand
{
    public function __construct(private AppFactory $appFactory = new AppFactory())
    {
    }

    public function run(array $argv): int
    {
        $args = $argv;
        array_shift($args);
        $command = array_shift($args);

        if ($command !== 'serve') {
            $this->printUsage();

            return $command === null ? 0 : 1;
        }

        try {
            [$appFile, $host, $port, $publicPath, $environment, $debug] = $this->parseServeArguments($args);

            $application = $this->appFactory->createFromFile($appFile);
            $context = new RuntimeContext(
                $environment,
                dirname(realpath($appFile) ?: $appFile),
                $publicPath,
                $host,
                $port,
                $debug,
            );

            (new RuntimeServer($application, $context))->run();

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, sprintf("Failed to start server: %s\n", $exception->getMessage()));

            return 1;
        }
    }

    private function parseServeArguments(array $args): array
    {
        $host = '127.0.0.1';
        $port = 8080;
        $environment = 'dev';
        $debug = true;
        $appFile = null;
        $publicPath = null;

        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--')) {
                $appFile ??= $arg;

                continue;
            }

            if ($arg === '--no-debug') {
                $debug = false;

                continue;
            }

            [$name, $value] = array_pad(explode('=', $arg, 2), 2, null);

            match ($name) {
                '--host' => $host = $value ?? $host,
                '--port' => $port = (int) ($value ?? $port),
                '--public' => $publicPath = $this->normalizePath($value),
                '--env' => $environment = $value ?? $environment,
                default => throw new \InvalidArgumentException(sprintf('Unknown option "%s".', $name)),
            };
        }

        if ($appFile === null) {
            throw new \InvalidArgumentException('Missing application file.');
        }

        $publicPath ??= $this->appFactory->inferPublicPath($appFile);

        return [
            $this->normalizePath($appFile),
            $host,
            $port,
            $publicPath,
            $environment,
            $debug,
        ];
    }

    private function normalizePath(?string $path): string
    {
        if ($path === null) {
            throw new \InvalidArgumentException('Expected a path value.');
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return getcwd() . '/' . $path;
    }

    private function printUsage(): void
    {
        $usage = <<<TXT
Usage:
  bin/dev-server serve <app.php> [--host=127.0.0.1] [--port=8080] [--public=path] [--env=dev] [--no-debug]

Example:
  bin/dev-server serve examples/hello-app/app.php --port=8080
TXT;

        fwrite(STDERR, $usage . "\n");
    }
}

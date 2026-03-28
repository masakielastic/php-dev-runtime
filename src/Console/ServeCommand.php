<?php

declare(strict_types=1);

namespace PhpDevRuntime\Console;

use PhpDevRuntime\Http\RuntimeServer;
use PhpDevRuntime\Runtime\AppFactory;
use PhpDevRuntime\Runtime\RuntimeContext;
use PhpDevRuntime\Runtime\TlsConfiguration;
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
            [$appFile, $host, $port, $publicPath, $environment, $debug, $tls] = $this->parseServeArguments($args);

            $application = $this->appFactory->createFromFile($appFile);
            $context = new RuntimeContext(
                $environment,
                dirname(realpath($appFile) ?: $appFile),
                $publicPath,
                $host,
                $port,
                $debug,
                $tls,
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
        $environment = 'dev';
        $debug = true;
        $publicPath = null;
        $tlsEnabled = true;
        $passphrase = getenv('PHP_DEV_RUNTIME_TLS_PASSPHRASE') ?: null;
        $positionals = [];

        while ($args !== []) {
            $arg = array_shift($args);

            if ($arg === null) {
                break;
            }

            if (!str_starts_with($arg, '-')) {
                $positionals[] = $arg;

                continue;
            }

            $value = null;
            if (str_contains($arg, '=')) {
                [$arg, $value] = explode('=', $arg, 2);
            }

            match ($arg) {
                '-a', '--address', '--host' => $host = $this->optionValue($arg, $value, $args),
                '-d', '--htdocs', '--public' => $publicPath = $this->normalizePath($this->optionValue($arg, $value, $args)),
                '--env' => $environment = $this->optionValue($arg, $value, $args),
                '--tls-passphrase' => $passphrase = $this->optionValue($arg, $value, $args),
                '--no-debug' => $debug = false,
                '--no-tls' => $tlsEnabled = false,
                default => throw new \InvalidArgumentException(sprintf('Unknown option "%s".', $arg)),
            };
        }

        if ($positionals === []) {
            throw new \InvalidArgumentException('Missing application file.');
        }

        $appFile = $this->normalizePath(array_shift($positionals));

        if ($positionals === []) {
            throw new \InvalidArgumentException('Missing listen port.');
        }

        $port = $this->parsePort(array_shift($positionals));
        $publicPath ??= $this->appFactory->inferPublicPath($appFile);
        $tls = $this->parseTlsConfiguration($tlsEnabled, $positionals, $passphrase);

        return [
            $appFile,
            $host,
            $port,
            $publicPath,
            $environment,
            $debug,
            $tls,
        ];
    }

    private function parseTlsConfiguration(bool $tlsEnabled, array $positionals, ?string $passphrase): TlsConfiguration
    {
        if (!$tlsEnabled) {
            if ($positionals !== []) {
                throw new \InvalidArgumentException('Do not pass <PRIVATE_KEY> or <CERT> when --no-tls is set.');
            }

            return new TlsConfiguration(false);
        }

        if (count($positionals) !== 2) {
            throw new \InvalidArgumentException('TLS mode requires <PRIVATE_KEY> and <CERT>.');
        }

        $privateKeyFile = $this->normalizePath($positionals[0]);
        $certificateFile = $this->normalizePath($positionals[1]);

        $this->assertReadableFile($privateKeyFile, 'TLS private key');
        $this->assertReadableFile($certificateFile, 'TLS certificate');

        return new TlsConfiguration(
            true,
            $privateKeyFile,
            $certificateFile,
            $passphrase,
        );
    }

    private function parsePort(string $port): int
    {
        if (!ctype_digit($port)) {
            throw new \InvalidArgumentException(sprintf('Invalid listen port "%s".', $port));
        }

        $value = (int) $port;

        if ($value < 1 || $value > 65535) {
            throw new \InvalidArgumentException(sprintf('Listen port "%s" is out of range.', $port));
        }

        return $value;
    }

    private function optionValue(string $option, ?string $value, array &$args): string
    {
        if ($value !== null && $value !== '') {
            return $value;
        }

        $next = array_shift($args);

        if ($next === null || str_starts_with($next, '-')) {
            throw new \InvalidArgumentException(sprintf('Option "%s" requires a value.', $option));
        }

        return $next;
    }

    private function assertReadableFile(string $path, string $label): void
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException(sprintf('%s file "%s" was not found.', $label, $path));
        }

        if (!is_readable($path)) {
            throw new \InvalidArgumentException(sprintf('%s file "%s" is not readable.', $label, $path));
        }
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
  bin/dev-server serve [OPTION]... <APP> <PORT> [<PRIVATE_KEY> <CERT>]

Example:
  bin/dev-server serve --no-tls -a 127.0.0.1 examples/hello-app/app.php 8080
  bin/dev-server serve -a 127.0.0.1 -d examples/hello-app/public examples/hello-app/app.php 8443 localhost-key.pem localhost.pem

Options:
  -a, --address=<ADDR>       Bind to the given address. Default: 127.0.0.1
  -d, --htdocs=<PATH>        Serve static files from the given directory
      --env=<ENV>            Runtime environment name. Default: dev
      --no-debug             Disable detailed development error pages
      --no-tls               Disable TLS and serve plain HTTP
      --tls-passphrase=<PW>  Passphrase for the encrypted private key
TXT;

        fwrite(STDERR, $usage . "\n");
    }
}

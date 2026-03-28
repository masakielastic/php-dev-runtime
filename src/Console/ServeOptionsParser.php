<?php

declare(strict_types=1);

namespace PhpDevRuntime\Console;

use InvalidArgumentException;
use PhpDevRuntime\Runtime\AppFactory;
use PhpDevRuntime\Runtime\ProtocolConfiguration;
use PhpDevRuntime\Runtime\ReloadConfiguration;
use PhpDevRuntime\Runtime\TlsConfiguration;

final class ServeOptionsParser
{
    public function __construct(private AppFactory $appFactory = new AppFactory())
    {
    }

    public function parse(array $args): ServeOptions
    {
        $host = '127.0.0.1';
        $environment = 'dev';
        $debug = true;
        $publicPath = null;
        $tlsEnabled = true;
        $http2Enabled = false;
        $reloadEnabled = false;
        $reloadInterval = 500;
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
                '--http2' => $http2Enabled = true,
                '--reload' => $reloadEnabled = true,
                '--reload-interval' => $reloadInterval = $this->parseReloadInterval($this->optionValue($arg, $value, $args)),
                '--tls-passphrase' => $passphrase = $this->optionValue($arg, $value, $args),
                '--no-debug' => $debug = false,
                '--no-tls' => $tlsEnabled = false,
                default => throw new InvalidArgumentException(sprintf('Unknown option "%s".', $arg)),
            };
        }

        if ($positionals === []) {
            throw new InvalidArgumentException('Missing application file.');
        }

        $appFile = $this->normalizePath(array_shift($positionals));

        if ($positionals === []) {
            throw new InvalidArgumentException('Missing listen port.');
        }

        $port = $this->parsePort(array_shift($positionals));
        $publicPath ??= $this->appFactory->inferPublicPath($appFile);
        $tls = $this->parseTlsConfiguration($tlsEnabled, $positionals, $passphrase);
        $protocol = new ProtocolConfiguration($http2Enabled);
        $reload = new ReloadConfiguration(
            $reloadEnabled,
            $reloadInterval,
            $this->buildWatchPaths($appFile, $publicPath),
        );

        return new ServeOptions(
            $appFile,
            $host,
            $port,
            $publicPath,
            $environment,
            $debug,
            $tls,
            $protocol,
            $reload,
        );
    }

    private function buildWatchPaths(string $appFile, string $publicPath): array
    {
        $paths = [
            dirname($appFile),
        ];

        if ($publicPath !== dirname($appFile) && !in_array($publicPath, $paths, true)) {
            $paths[] = $publicPath;
        }

        return $paths;
    }

    private function parseTlsConfiguration(bool $tlsEnabled, array $positionals, ?string $passphrase): TlsConfiguration
    {
        if (!$tlsEnabled) {
            if ($positionals !== []) {
                throw new InvalidArgumentException('Do not pass <PRIVATE_KEY> or <CERT> when --no-tls is set.');
            }

            return new TlsConfiguration(false);
        }

        if (count($positionals) !== 2) {
            throw new InvalidArgumentException('TLS mode requires <PRIVATE_KEY> and <CERT>.');
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
            throw new InvalidArgumentException(sprintf('Invalid listen port "%s".', $port));
        }

        $value = (int) $port;

        if ($value < 1 || $value > 65535) {
            throw new InvalidArgumentException(sprintf('Listen port "%s" is out of range.', $port));
        }

        return $value;
    }

    private function parseReloadInterval(string $interval): int
    {
        if (!ctype_digit($interval)) {
            throw new InvalidArgumentException(sprintf('Invalid reload interval "%s".', $interval));
        }

        $value = (int) $interval;

        if ($value < 50) {
            throw new InvalidArgumentException('Reload interval must be at least 50 milliseconds.');
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
            throw new InvalidArgumentException(sprintf('Option "%s" requires a value.', $option));
        }

        return $next;
    }

    private function assertReadableFile(string $path, string $label): void
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('%s file "%s" was not found.', $label, $path));
        }

        if (!is_readable($path)) {
            throw new InvalidArgumentException(sprintf('%s file "%s" is not readable.', $label, $path));
        }
    }

    private function normalizePath(?string $path): string
    {
        if ($path === null) {
            throw new InvalidArgumentException('Expected a path value.');
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return getcwd() . '/' . $path;
    }
}

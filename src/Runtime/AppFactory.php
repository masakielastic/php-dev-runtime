<?php

declare(strict_types=1);

namespace PhpDevRuntime\Runtime;

use InvalidArgumentException;
use PhpDevRuntime\Contract\ApplicationInterface;

final class AppFactory
{
    public function createFromFile(string $file): ApplicationInterface
    {
        $path = $this->normalizeFile($file);
        $loaded = require $path;

        if ($loaded instanceof ApplicationInterface) {
            return $loaded;
        }

        if (is_callable($loaded)) {
            $application = $loaded();

            if ($application instanceof ApplicationInterface) {
                return $application;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Application file "%s" must return %s or callable(): %s.',
            $path,
            ApplicationInterface::class,
            ApplicationInterface::class,
        ));
    }

    public function inferPublicPath(string $appFile): string
    {
        return dirname($this->normalizeFile($appFile)) . '/public';
    }

    private function normalizeFile(string $file): string
    {
        $path = str_starts_with($file, '/')
            ? $file
            : getcwd() . '/' . $file;

        $resolved = realpath($path);

        if ($resolved === false || !is_file($resolved)) {
            throw new InvalidArgumentException(sprintf('Application file "%s" was not found.', $file));
        }

        return $resolved;
    }
}

<?php

declare(strict_types=1);

namespace PhpDevRuntime\Console;

use PhpDevRuntime\Runtime\ReloadConfiguration;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ReloadWatcher
{
    public function __construct(private ReloadConfiguration $configuration)
    {
    }

    public function fingerprint(): string
    {
        $entries = [];

        foreach ($this->configuration->watchPaths as $path) {
            $resolved = realpath($path);

            if ($resolved === false) {
                continue;
            }

            if (is_file($resolved)) {
                $entries[] = $this->describeFile($resolved);
                continue;
            }

            if (!is_dir($resolved)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($resolved, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $pathname = $file->getPathname();
                if ($this->shouldIgnore($pathname)) {
                    continue;
                }

                $entries[] = $this->describeFile($pathname);
            }
        }

        sort($entries);

        return sha1(implode("\n", $entries));
    }

    private function describeFile(string $path): string
    {
        return sprintf('%s:%d:%d', $path, filemtime($path) ?: 0, filesize($path) ?: 0);
    }

    private function shouldIgnore(string $path): bool
    {
        return str_contains($path, '/vendor/')
            || str_contains($path, '/.git/')
            || str_contains($path, '/var/cache/')
            || str_contains($path, '/node_modules/');
    }
}

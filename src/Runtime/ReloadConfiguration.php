<?php

declare(strict_types=1);

namespace PhpDevRuntime\Runtime;

final readonly class ReloadConfiguration
{
    /**
     * @param list<string> $watchPaths
     */
    public function __construct(
        public bool $enabled = false,
        public int $intervalMilliseconds = 500,
        public array $watchPaths = [],
    ) {
    }
}

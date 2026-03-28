<?php

declare(strict_types=1);

namespace PhpDevRuntime\Runtime;

final readonly class RuntimeContext
{
    public function __construct(
        public string $environment,
        public string $appRoot,
        public string $publicPath,
        public string $host,
        public int $port,
        public bool $debug,
        public TlsConfiguration $tls,
    ) {
    }
}

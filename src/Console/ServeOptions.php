<?php

declare(strict_types=1);

namespace PhpDevRuntime\Console;

use PhpDevRuntime\Runtime\ProtocolConfiguration;
use PhpDevRuntime\Runtime\TlsConfiguration;

final readonly class ServeOptions
{
    public function __construct(
        public string $appFile,
        public string $host,
        public int $port,
        public string $publicPath,
        public string $environment,
        public bool $debug,
        public TlsConfiguration $tls,
        public ProtocolConfiguration $protocol,
    ) {
    }
}

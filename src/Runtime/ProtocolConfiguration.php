<?php

declare(strict_types=1);

namespace PhpDevRuntime\Runtime;

final readonly class ProtocolConfiguration
{
    public function __construct(
        public bool $http2Enabled = false,
    ) {
    }

    public function displayName(): string
    {
        return $this->http2Enabled ? 'HTTP/2' : 'HTTP/1.1';
    }
}

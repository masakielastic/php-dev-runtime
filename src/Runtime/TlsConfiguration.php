<?php

declare(strict_types=1);

namespace PhpDevRuntime\Runtime;

final readonly class TlsConfiguration
{
    public function __construct(
        public bool $enabled,
        public ?string $privateKeyFile = null,
        public ?string $certificateFile = null,
        public ?string $passphrase = null,
    ) {
    }

    public function scheme(): string
    {
        return $this->enabled ? 'https' : 'http';
    }
}

<?php

declare(strict_types=1);

namespace PhpDevRuntime\Http;

use PhpDevRuntime\Runtime\RuntimeContext;

final class RuntimeMetadataPrinter
{
    public function __construct(private RuntimeContext $context)
    {
    }

    public function printStartupInfo(): void
    {
        fwrite(STDERR, sprintf(
            "Development server listening on %s://%s:%d\n",
            $this->context->tls->scheme(),
            $this->context->host,
            $this->context->port,
        ));
        fwrite(STDERR, sprintf("Protocol: %s\n", $this->context->protocol->displayName()));
        fwrite(STDERR, sprintf("Application root: %s\n", $this->context->appRoot));
        fwrite(STDERR, sprintf("Public path: %s\n", $this->context->publicPath));
        fwrite(STDERR, sprintf("TLS: %s\n", $this->context->tls->enabled ? 'enabled' : 'disabled'));
    }
}

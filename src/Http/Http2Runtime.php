<?php

declare(strict_types=1);

namespace PhpDevRuntime\Http;

use PhpDevRuntime\Runtime\RuntimeContext;

final class Http2Runtime
{
    public function __construct(
        private RuntimeContext $context,
        private ApplicationGateway $gateway,
        private RuntimeLifecycle $lifecycle,
        private RuntimeMetadataPrinter $printer,
    ) {
    }

    public function run(): void
    {
        $server = new Http2Server($this->context, $this->gateway);

        $this->lifecycle->startup();
        $this->registerSignalHandlers($server);
        $this->printer->printStartupInfo();

        try {
            $server->run();
        } finally {
            $this->lifecycle->shutdown();
        }
    }

    private function registerSignalHandlers(Http2Server $server): void
    {
        if (!function_exists('pcntl_signal') || !defined('SIGINT')) {
            return;
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGINT, function () use ($server): void {
            fwrite(STDERR, "Stopping development server...\n");
            $server->stop();
        });

        if (defined('SIGTERM')) {
            pcntl_signal(SIGTERM, function () use ($server): void {
                fwrite(STDERR, "Stopping development server...\n");
                $server->stop();
            });
        }
    }
}

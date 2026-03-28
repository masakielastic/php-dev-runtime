<?php

declare(strict_types=1);

namespace PhpDevRuntime\Http;

use PhpDevRuntime\Contract\ApplicationInterface;
use PhpDevRuntime\Runtime\RuntimeContext;

final class RuntimeServer
{
    public function __construct(
        private ApplicationInterface $application,
        private RuntimeContext $context,
    ) {
    }

    public function run(): void
    {
        $staticFiles = new StaticFileMiddleware($this->context->publicPath);
        $errorHandler = new ErrorHandler();
        $gateway = new ApplicationGateway(
            $this->application,
            $staticFiles,
            $errorHandler,
            $this->context->debug,
        );
        $lifecycle = new RuntimeLifecycle($this->application, $this->context);
        $printer = new RuntimeMetadataPrinter($this->context);

        if ($this->context->protocol->http2Enabled) {
            (new Http2Runtime($this->context, $gateway, $lifecycle, $printer))->run();

            return;
        }

        (new Http1Runtime($this->application, $this->context, $lifecycle, $printer))->run();
    }
}

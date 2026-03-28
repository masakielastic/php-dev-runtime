<?php

declare(strict_types=1);

namespace PhpDevRuntime\Http;

use PhpDevRuntime\Contract\ApplicationInterface;
use PhpDevRuntime\Runtime\RuntimeContext;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Socket\SocketServer;

final class Http1Runtime
{
    private bool $stopping = false;

    public function __construct(
        private ApplicationInterface $application,
        private RuntimeContext $context,
        private RuntimeLifecycle $lifecycle,
        private RuntimeMetadataPrinter $printer,
    ) {
    }

    public function run(): void
    {
        $staticFiles = new StaticFileMiddleware($this->context->publicPath);
        $errorHandler = new ErrorHandler();
        $adapter = new RequestHandlerAdapter(
            $this->application,
            $staticFiles,
            $errorHandler,
            $this->context->debug,
            $this->context->tls->scheme(),
        );

        $server = new HttpServer($adapter);
        $socket = new SocketServer(
            $this->buildListenUri(),
            $this->buildSocketContext(),
        );
        $server->listen($socket);

        $this->lifecycle->startup();
        $this->registerSignalHandlers($socket);
        $this->printer->printStartupInfo();

        try {
            Loop::run();
        } finally {
            $this->lifecycle->shutdown();
            $socket->close();
        }
    }

    private function buildListenUri(): string
    {
        return sprintf(
            '%s://%s:%d',
            $this->context->tls->enabled ? 'tls' : 'tcp',
            $this->context->host,
            $this->context->port,
        );
    }

    private function buildSocketContext(): array
    {
        if (!$this->context->tls->enabled) {
            return [];
        }

        return [
            'tls' => [
                'local_cert' => $this->context->tls->certificateFile,
                'local_pk' => $this->context->tls->privateKeyFile,
                'passphrase' => $this->context->tls->passphrase ?? '',
            ],
        ];
    }

    private function registerSignalHandlers(SocketServer $socket): void
    {
        if (!defined('SIGINT') || !method_exists(Loop::class, 'addSignal')) {
            return;
        }

        Loop::addSignal(SIGINT, fn (): bool => $this->stop($socket));

        if (defined('SIGTERM')) {
            Loop::addSignal(SIGTERM, fn (): bool => $this->stop($socket));
        }
    }

    private function stop(SocketServer $socket): bool
    {
        if ($this->stopping) {
            return false;
        }

        $this->stopping = true;
        fwrite(STDERR, "Stopping development server...\n");
        $socket->close();
        Loop::stop();

        return true;
    }
}

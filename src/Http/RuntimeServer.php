<?php

declare(strict_types=1);

namespace PhpDevRuntime\Http;

use PhpDevRuntime\Contract\ApplicationInterface;
use PhpDevRuntime\Contract\LifespanInterface;
use PhpDevRuntime\Runtime\RuntimeContext;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Socket\SocketServer;
use Throwable;

final class RuntimeServer
{
    private bool $stopping = false;

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

        if ($this->context->protocol->http2Enabled) {
            $this->runHttp2($gateway);

            return;
        }

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

        $this->bootLifecycle();
        $this->registerSignalHandlers($socket);
        $this->printStartupInfo();

        try {
            Loop::run();
        } finally {
            $this->shutdownLifecycle();
            $socket->close();
        }
    }

    private function bootLifecycle(): void
    {
        if (!$this->application instanceof LifespanInterface) {
            return;
        }

        $this->application->startup($this->context);
    }

    private function shutdownLifecycle(): void
    {
        if (!$this->application instanceof LifespanInterface) {
            return;
        }

        try {
            $this->application->shutdown($this->context);
        } catch (Throwable $exception) {
            $this->stderr(sprintf("Shutdown error: %s\n", $exception->getMessage()));
        }
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
        $this->stderr("Stopping development server...\n");
        $socket->close();
        Loop::stop();

        return true;
    }

    private function runHttp2(ApplicationGateway $gateway): void
    {
        $server = new Http2Server($this->context, $gateway);

        $this->bootLifecycle();
        $this->registerHttp2SignalHandlers($server);
        $this->printStartupInfo();

        try {
            $server->run();
        } finally {
            $this->shutdownLifecycle();
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

        $tls = [
            'local_cert' => $this->context->tls->certificateFile,
            'local_pk' => $this->context->tls->privateKeyFile,
            'passphrase' => $this->context->tls->passphrase ?? '',
        ];

        return ['tls' => $tls];
    }

    private function registerHttp2SignalHandlers(Http2Server $server): void
    {
        if (!function_exists('pcntl_signal') || !defined('SIGINT')) {
            return;
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGINT, function () use ($server): void {
            if ($this->stopping) {
                return;
            }

            $this->stopping = true;
            $this->stderr("Stopping development server...\n");
            $server->stop();
        });

        if (defined('SIGTERM')) {
            pcntl_signal(SIGTERM, function () use ($server): void {
                if ($this->stopping) {
                    return;
                }

                $this->stopping = true;
                $this->stderr("Stopping development server...\n");
                $server->stop();
            });
        }
    }

    private function printStartupInfo(): void
    {
        $this->stderr(sprintf(
            "Development server listening on %s://%s:%d\n",
            $this->context->tls->scheme(),
            $this->context->host,
            $this->context->port,
        ));
        $this->stderr(sprintf("Protocol: %s\n", $this->context->protocol->displayName()));
        $this->stderr(sprintf("Application root: %s\n", $this->context->appRoot));
        $this->stderr(sprintf("Public path: %s\n", $this->context->publicPath));
        $this->stderr(sprintf("TLS: %s\n", $this->context->tls->enabled ? 'enabled' : 'disabled'));
    }

    private function stderr(string $message): void
    {
        fwrite(STDERR, $message);
    }
}

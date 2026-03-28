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
        $adapter = new RequestHandlerAdapter(
            $this->application,
            $staticFiles,
            $errorHandler,
            $this->context->debug,
        );

        $server = new HttpServer($adapter);
        $socket = new SocketServer(sprintf('%s:%d', $this->context->host, $this->context->port));
        $server->listen($socket);

        $this->bootLifecycle();
        $this->registerSignalHandlers($server, $socket);

        $this->stderr(sprintf(
            "Development server listening on http://%s:%d\n",
            $this->context->host,
            $this->context->port,
        ));
        $this->stderr(sprintf("Application root: %s\n", $this->context->appRoot));
        $this->stderr(sprintf("Public path: %s\n", $this->context->publicPath));

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

    private function registerSignalHandlers(HttpServer $server, SocketServer $socket): void
    {
        if (!defined('SIGINT') || !method_exists(Loop::class, 'addSignal')) {
            return;
        }

        Loop::addSignal(SIGINT, fn (): bool => $this->stop($server, $socket));

        if (defined('SIGTERM')) {
            Loop::addSignal(SIGTERM, fn (): bool => $this->stop($server, $socket));
        }
    }

    private function stop(HttpServer $server, SocketServer $socket): bool
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

    private function stderr(string $message): void
    {
        fwrite(STDERR, $message);
    }
}

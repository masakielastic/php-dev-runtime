<?php

declare(strict_types=1);

namespace PhpDevRuntime\Http;

use InvalidArgumentException;
use PhpDevRuntime\Contract\ApplicationInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Promise\PromiseInterface;
use Throwable;

use function React\Promise\resolve;

final class ApplicationGateway
{
    public function __construct(
        private ApplicationInterface $application,
        private StaticFileMiddleware $staticFiles,
        private ErrorHandler $errorHandler,
        private bool $debug,
    ) {
    }

    public function handleSync(ServerRequestInterface $request): ResponseInterface
    {
        $staticResponse = $this->staticFiles->serve($request);

        if ($staticResponse instanceof ResponseInterface) {
            return $staticResponse;
        }

        try {
            $result = $this->application->handle($request);
        } catch (Throwable $exception) {
            return $this->errorHandler->render($exception, $request, $this->debug);
        }

        if ($result instanceof ResponseInterface) {
            return $result;
        }

        if (!$result instanceof PromiseInterface) {
            return $this->errorHandler->render(
                new InvalidArgumentException(sprintf(
                    'Application must return %s or PromiseInterface<%s>.',
                    ResponseInterface::class,
                    ResponseInterface::class,
                )),
                $request,
                $this->debug,
            );
        }

        $resolved = null;
        $rejected = null;

        resolve($result)->then(
            function (mixed $response) use (&$resolved): void {
                $resolved = $response;
            },
            function (mixed $reason) use (&$rejected): void {
                $rejected = $reason;
            },
        );

        if ($resolved instanceof ResponseInterface) {
            return $resolved;
        }

        $throwable = $rejected instanceof Throwable
            ? $rejected
            : new InvalidArgumentException('HTTP/2 mode requires an immediately resolvable response promise.');

        return $this->errorHandler->render($throwable, $request, $this->debug);
    }
}

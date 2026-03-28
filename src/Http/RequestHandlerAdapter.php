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

final class RequestHandlerAdapter
{
    public function __construct(
        private ApplicationInterface $application,
        private StaticFileMiddleware $staticFiles,
        private ErrorHandler $errorHandler,
        private bool $debug,
        private string $scheme,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface|PromiseInterface
    {
        $request = $request->withUri($request->getUri()->withScheme($this->scheme));

        $staticResponse = $this->staticFiles->serve($request);

        if ($staticResponse instanceof ResponseInterface) {
            return $staticResponse;
        }

        try {
            $result = $this->application->handle($request);
        } catch (Throwable $exception) {
            return $this->errorHandler->render($exception, $request, $this->debug);
        }

        return resolve($result)->then(
            fn (mixed $response): ResponseInterface => $this->normalizeResponse($response),
            fn (mixed $reason): ResponseInterface => $this->errorHandler->render($this->normalizeThrowable($reason), $request, $this->debug),
        );
    }

    private function normalizeResponse(mixed $response): ResponseInterface
    {
        if ($response instanceof ResponseInterface) {
            return $response;
        }

        throw new InvalidArgumentException(sprintf(
            'Application must return %s or PromiseInterface<%s>.',
            ResponseInterface::class,
            ResponseInterface::class,
        ));
    }

    private function normalizeThrowable(mixed $reason): Throwable
    {
        if ($reason instanceof Throwable) {
            return $reason;
        }

        return new InvalidArgumentException('Promise was rejected with a non-throwable reason.');
    }
}

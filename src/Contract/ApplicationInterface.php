<?php

declare(strict_types=1);

namespace PhpDevRuntime\Contract;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Promise\PromiseInterface;

interface ApplicationInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface|PromiseInterface;
}

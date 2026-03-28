<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PhpDevRuntime\Contract\ApplicationInterface;
use PhpDevRuntime\Contract\LifespanInterface;
use PhpDevRuntime\Runtime\RuntimeContext;
use Psr\Http\Message\ServerRequestInterface;

return new class () implements ApplicationInterface, LifespanInterface {
    public function startup(RuntimeContext $context): void
    {
        fwrite(STDERR, sprintf("[%s] startup %s:%d\n", $context->environment, $context->host, $context->port));
    }

    public function shutdown(RuntimeContext $context): void
    {
        fwrite(STDERR, sprintf("[%s] shutdown\n", $context->environment));
    }

    public function handle(ServerRequestInterface $request): Response
    {
        if ($request->getUri()->getPath() === '/boom') {
            throw new RuntimeException('Example exception for development error page.');
        }

        $body = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Hello App</title>
</head>
<body>
  <h1>Hello from php-dev-runtime</h1>
  <p>Request path: {$request->getUri()->getPath()}</p>
  <p><a href="/index.html">Static file</a></p>
  <p><a href="/boom">Trigger error page</a></p>
</body>
</html>
HTML;

        return new Response(
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
            Stream::create($body),
        );
    }
};

<?php

declare(strict_types=1);

namespace PhpDevRuntime\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class ErrorHandler
{
    public function render(Throwable $exception, ServerRequestInterface $request, bool $debug): ResponseInterface
    {
        $accept = strtolower($request->getHeaderLine('Accept'));
        $wantsHtml = str_contains($accept, 'text/html') || str_contains($accept, '*/*');

        return $wantsHtml
            ? $this->htmlResponse($exception, $debug)
            : $this->textResponse($exception, $debug);
    }

    private function htmlResponse(Throwable $exception, bool $debug): ResponseInterface
    {
        $title = $debug ? 'Application Error' : 'Internal Server Error';
        $detail = $debug ? $this->formatDebugText($exception) : 'An unexpected error occurred.';
        $body = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>{$this->escape($title)}</title>
  <style>
    body { font-family: sans-serif; margin: 2rem; line-height: 1.5; color: #222; background: #f7f7f7; }
    main { max-width: 960px; margin: 0 auto; background: #fff; padding: 2rem; border: 1px solid #ddd; }
    h1 { margin-top: 0; }
    pre { overflow-x: auto; background: #111; color: #eee; padding: 1rem; }
  </style>
</head>
<body>
  <main>
    <h1>{$this->escape($title)}</h1>
    <pre>{$this->escape($detail)}</pre>
  </main>
</body>
</html>
HTML;

        return new Response(
            500,
            ['Content-Type' => 'text/html; charset=utf-8'],
            Stream::create($body),
        );
    }

    private function textResponse(Throwable $exception, bool $debug): ResponseInterface
    {
        $body = $debug
            ? $this->formatDebugText($exception)
            : 'Internal Server Error';

        return new Response(
            500,
            ['Content-Type' => 'text/plain; charset=utf-8'],
            Stream::create($body),
        );
    }

    private function formatDebugText(Throwable $exception): string
    {
        return sprintf(
            "%s: %s\n\n%s:%d\n\n%s",
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString(),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

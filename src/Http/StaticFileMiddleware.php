<?php

declare(strict_types=1);

namespace PhpDevRuntime\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class StaticFileMiddleware
{
    public function __construct(private string $publicPath)
    {
    }

    public function serve(ServerRequestInterface $request): ?ResponseInterface
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }

        if (!is_dir($this->publicPath)) {
            return null;
        }

        $requestedPath = rawurldecode($request->getUri()->getPath() ?: '/');
        $relativePath = ltrim($requestedPath, '/');
        $candidate = $relativePath === '' ? $this->publicPath : $this->publicPath . '/' . $relativePath;

        if (is_dir($candidate)) {
            $candidate = rtrim($candidate, '/') . '/index.html';
        }

        $resolved = realpath($candidate);

        if ($resolved === false || !is_file($resolved)) {
            return null;
        }

        $publicRoot = realpath($this->publicPath);

        if ($publicRoot === false || !str_starts_with($resolved, $publicRoot . '/')
            && $resolved !== $publicRoot . '/index.html'
        ) {
            return null;
        }

        $headers = [
            'Content-Type' => $this->detectContentType($resolved),
            'Content-Length' => (string) filesize($resolved),
            'Cache-Control' => 'no-store',
        ];

        if ($request->getMethod() === 'HEAD') {
            return new Response(200, $headers, Stream::create(''));
        }

        $resource = fopen($resolved, 'rb');

        if ($resource === false) {
            return new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], Stream::create('Failed to open static file.'));
        }

        return new Response(200, $headers, Stream::create($resource));
    }

    private function detectContentType(string $file): string
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return match ($extension) {
            'css' => 'text/css; charset=utf-8',
            'gif' => 'image/gif',
            'html' => 'text/html; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'txt' => 'text/plain; charset=utf-8',
            default => $this->detectWithFileInfo($file),
        };
    }

    private function detectWithFileInfo(string $file): string
    {
        if (!class_exists(\finfo::class)) {
            return 'application/octet-stream';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($file);

        return is_string($detected) && $detected !== ''
            ? $detected
            : 'application/octet-stream';
    }
}

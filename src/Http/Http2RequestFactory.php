<?php

declare(strict_types=1);

namespace PhpDevRuntime\Http;

use Amp\Http\HPack;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\Uri;
use PhpDevRuntime\Runtime\RuntimeContext;

final class Http2RequestFactory
{
    public function __construct(private RuntimeContext $context)
    {
    }

    public function decodeHeaderBlock(string $block): array
    {
        $headers = [];
        $method = null;
        $path = null;
        $authority = null;
        $scheme = null;

        $decoded = (new HPack())->decode($block, 65535);

        if (!is_array($decoded)) {
            return [
                'method' => null,
                'path' => null,
                'authority' => null,
                'scheme' => null,
                'headers' => [],
            ];
        }

        foreach ($decoded as [$name, $value]) {
            if ($name === ':method') {
                $method = $value;
                continue;
            }

            if ($name === ':path') {
                $path = $value;
                continue;
            }

            if ($name === ':authority') {
                $authority = $value;
                continue;
            }

            if ($name === ':scheme') {
                $scheme = $value;
                continue;
            }

            if ($name !== '' && $name[0] !== ':') {
                $headers[$name] = $value;
            }
        }

        return [
            'method' => $method,
            'path' => $path,
            'authority' => $authority,
            'scheme' => $scheme,
            'headers' => $headers,
        ];
    }

    public function buildRequest(array $state): ServerRequest
    {
        $target = $state['requestPath'] !== '' ? $state['requestPath'] : '/';
        $scheme = $state['requestScheme'];
        $authority = $state['requestAuthority'];
        $authorityUri = sprintf('%s://%s', $scheme, $authority);
        $host = (string) parse_url($authorityUri, PHP_URL_HOST);
        $port = parse_url($authorityUri, PHP_URL_PORT);
        $path = (string) parse_url($target, PHP_URL_PATH);
        $query = (string) parse_url($target, PHP_URL_QUERY);

        $uri = (new Uri())
            ->withScheme($scheme)
            ->withHost($host)
            ->withPath($path !== '' ? $path : '/');

        if ($query !== '') {
            $uri = $uri->withQuery($query);
        }

        if (is_int($port)) {
            $uri = $uri->withPort($port);
        }

        if (!isset($state['requestHeaders']['host'])) {
            $state['requestHeaders']['host'] = $authority;
        }

        return new ServerRequest(
            $state['requestMethod'],
            $uri,
            $state['requestHeaders'],
            Stream::create($state['requestBody']),
            '2',
        );
    }
}

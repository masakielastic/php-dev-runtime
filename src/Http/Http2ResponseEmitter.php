<?php

declare(strict_types=1);

namespace PhpDevRuntime\Http;

use Amp\Http\HPack;
use Psr\Http\Message\ResponseInterface;

final class Http2ResponseEmitter
{
    public function buildResponseHeaderBlock(array $headers): string
    {
        $encoded = (new HPack())->encode($headers);

        return is_string($encoded) ? $encoded : '';
    }

    public function buildResponseHeaders(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        $headers = [[':status', (string) $response->getStatusCode()]];

        foreach ($response->getHeaders() as $name => $values) {
            $name = strtolower($name);

            if (in_array($name, ['connection', 'keep-alive', 'proxy-connection', 'transfer-encoding', 'upgrade'], true)) {
                continue;
            }

            foreach ($values as $value) {
                $headers[] = [$name, $value];
            }
        }

        if (!$response->hasHeader('Content-Length')) {
            $headers[] = ['content-length', (string) strlen($body)];
        }

        return $headers;
    }
}

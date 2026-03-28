<?php

declare(strict_types=1);

namespace PhpDevRuntime\Http;

use PhpDevRuntime\Runtime\RuntimeContext;
use RuntimeException;

final class Http2Server
{
    private const CLIENT_PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";

    private const FRAME_DATA = 0x00;
    private const FRAME_HEADERS = 0x01;
    private const FRAME_SETTINGS = 0x04;
    private const FRAME_PING = 0x06;
    private const FRAME_GOAWAY = 0x07;
    private const FRAME_WINDOW_UPDATE = 0x08;
    private const FRAME_CONTINUATION = 0x09;

    private const FLAG_ACK = 0x01;
    private const FLAG_END_STREAM = 0x01;
    private const FLAG_END_HEADERS = 0x04;
    private const FLAG_PADDED = 0x08;
    private const FLAG_PRIORITY = 0x20;

    private const H2_ERR_PROTOCOL = 0x01;
    private const H2_ERR_FRAME_SIZE = 0x06;

    private bool $stopping = false;
    private Http2FrameCodec $frameCodec;
    private Http2RequestFactory $requestFactory;
    private Http2ResponseEmitter $responseEmitter;

    public function __construct(
        private readonly RuntimeContext $context,
        private readonly ApplicationGateway $gateway,
    ) {
        $this->frameCodec = new Http2FrameCodec();
        $this->requestFactory = new Http2RequestFactory($this->context);
        $this->responseEmitter = new Http2ResponseEmitter();
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    public function run(): void
    {
        $server = $this->createServer();

        try {
            while (!$this->stopping) {
                $connection = @stream_socket_accept($server, 1);

                if ($connection === false) {
                    continue;
                }

                try {
                    $this->handleConnection($connection);
                } finally {
                    fclose($connection);
                }
            }
        } finally {
            fclose($server);
        }
    }

    private function handleConnection($connection): void
    {
        stream_set_timeout($connection, 5);

        $preface = $this->readBytes($connection, strlen(self::CLIENT_PREFACE));
        if ($preface !== self::CLIENT_PREFACE) {
            return;
        }

        fwrite($connection, $this->frameCodec->packFrame(self::FRAME_SETTINGS, 0x00, 0, ''));

        $state = [
            'gotClientSettings' => false,
            'headerBlock' => '',
            'requestStreamId' => null,
            'requestEnded' => false,
            'headersEnded' => false,
            'responseSent' => false,
            'lastClientStreamId' => 0,
            'requestMethod' => 'GET',
            'requestPath' => '/',
            'requestScheme' => $this->context->tls->scheme(),
            'requestAuthority' => sprintf('%s:%d', $this->context->host, $this->context->port),
            'requestHeaders' => [],
            'requestBody' => '',
        ];

        $buffer = '';

        while (!feof($connection) && !$this->stopping) {
            $chunk = fread($connection, 8192);

            if ($chunk === '' || $chunk === false) {
                break;
            }

            $buffer .= $chunk;

            while (($frame = $this->frameCodec->parseNextFrame($buffer)) !== null) {
                $action = $this->handleFrame($frame, $state);

                foreach ($action['writes'] as $write) {
                    fwrite($connection, $write);
                }

                if ($action['close']) {
                    $this->gracefulClose($connection);

                    return;
                }
            }
        }
    }

    private function handleFrame(array $frame, array &$state): array
    {
        $type = $frame['type'];
        $flags = $frame['flags'];
        $streamId = $frame['streamId'];
        $action = ['writes' => [], 'close' => false];

        if (!$state['gotClientSettings']) {
            if ($type !== self::FRAME_SETTINGS) {
                $action['writes'][] = $this->buildGoAwayFrame($state['lastClientStreamId'], self::H2_ERR_PROTOCOL);
                $action['close'] = true;

                return $action;
            }

            $state['gotClientSettings'] = true;
        }

        if ($type === self::FRAME_SETTINGS) {
            if ($streamId !== 0) {
                $action['writes'][] = $this->buildGoAwayFrame($state['lastClientStreamId'], self::H2_ERR_PROTOCOL);
                $action['close'] = true;

                return $action;
            }

            if (($flags & self::FLAG_ACK) === 0) {
                $action['writes'][] = $this->frameCodec->packFrame(self::FRAME_SETTINGS, self::FLAG_ACK, 0, '');
            }

            return $action;
        }

        if ($type === self::FRAME_PING) {
            if ($streamId === 0 && strlen($frame['payload']) === 8 && ($flags & self::FLAG_ACK) === 0) {
                $action['writes'][] = $this->frameCodec->packFrame(self::FRAME_PING, self::FLAG_ACK, 0, $frame['payload']);
            }

            return $action;
        }

        if ($type === self::FRAME_WINDOW_UPDATE) {
            return $action;
        }

        if ($type === self::FRAME_HEADERS || $type === self::FRAME_CONTINUATION) {
            if ($streamId <= 0) {
                $action['writes'][] = $this->buildGoAwayFrame($state['lastClientStreamId'], self::H2_ERR_PROTOCOL);
                $action['close'] = true;

                return $action;
            }

            $headerBlock = $this->frameCodec->extractHeaderBlockFragment($frame, self::FLAG_PADDED, self::FLAG_PRIORITY);
            if ($headerBlock === null) {
                $action['writes'][] = $this->buildGoAwayFrame($state['lastClientStreamId'], self::H2_ERR_FRAME_SIZE);
                $action['close'] = true;

                return $action;
            }

            if ($state['requestStreamId'] === null) {
                $state['requestStreamId'] = $streamId;
                $state['lastClientStreamId'] = $streamId;
            }

            $state['headerBlock'] .= $headerBlock;

            if (($flags & self::FLAG_END_HEADERS) !== 0) {
                $state['headersEnded'] = true;
                $decoded = $this->requestFactory->decodeHeaderBlock($state['headerBlock']);
                $state['requestMethod'] = $decoded['method'] ?: 'GET';
                $state['requestPath'] = $decoded['path'] ?: '/';
                $state['requestScheme'] = $decoded['scheme'] ?: $this->context->tls->scheme();
                $state['requestAuthority'] = $decoded['authority'] ?: sprintf('%s:%d', $this->context->host, $this->context->port);
                $state['requestHeaders'] = $decoded['headers'];
            }

            if (($flags & self::FLAG_END_STREAM) !== 0) {
                $state['requestEnded'] = true;
            }
        }

        if ($type === self::FRAME_DATA) {
            if ($streamId !== $state['requestStreamId']) {
                $action['writes'][] = $this->buildGoAwayFrame($state['lastClientStreamId'], self::H2_ERR_PROTOCOL);
                $action['close'] = true;

                return $action;
            }

            $payload = $frame['payload'];
            if (($flags & self::FLAG_PADDED) !== 0) {
                if ($payload === '') {
                    $action['writes'][] = $this->buildGoAwayFrame($state['lastClientStreamId'], self::H2_ERR_FRAME_SIZE);
                    $action['close'] = true;

                    return $action;
                }

                $padLength = ord($payload[0]);
                $payload = substr($payload, 1);
                if ($padLength > strlen($payload)) {
                    $action['writes'][] = $this->buildGoAwayFrame($state['lastClientStreamId'], self::H2_ERR_FRAME_SIZE);
                    $action['close'] = true;

                    return $action;
                }

                $payload = substr($payload, 0, strlen($payload) - $padLength);
            }

            $state['requestBody'] .= $payload;

            if (($flags & self::FLAG_END_STREAM) !== 0) {
                $state['requestEnded'] = true;
            }
        }

        if ($state['headersEnded'] && $state['requestEnded'] && !$state['responseSent'] && $state['requestStreamId'] !== null) {
            $request = $this->requestFactory->buildRequest($state);
            $response = $this->gateway->handleSync($request);
            $action['writes'] = [...$action['writes'], ...$this->buildResponseFrames($state['requestStreamId'], $response)];
            $action['close'] = true;
            $state['responseSent'] = true;
        }

        return $action;
    }

    private function buildResponseFrames(int $streamId, \Psr\Http\Message\ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        $headers = $this->responseEmitter->buildResponseHeaders($response);
        $headerBlock = $this->responseEmitter->buildResponseHeaderBlock($headers);

        return [
            $this->frameCodec->packFrame(self::FRAME_HEADERS, self::FLAG_END_HEADERS, $streamId, $headerBlock),
            $this->frameCodec->packFrame(self::FRAME_DATA, self::FLAG_END_STREAM, $streamId, $body),
            $this->buildGoAwayFrame($streamId, 0),
        ];
    }

    private function buildGoAwayFrame(int $lastStreamId, int $errorCode): string
    {
        $payload = pack('N', $lastStreamId & 0x7fffffff) . pack('N', $errorCode);

        return $this->frameCodec->packFrame(self::FRAME_GOAWAY, 0x00, 0, $payload);
    }

    private function gracefulClose($connection): void
    {
        @stream_socket_shutdown($connection, STREAM_SHUT_WR);
        stream_set_timeout($connection, 1);

        while (!feof($connection)) {
            $chunk = fread($connection, 8192);

            if ($chunk === '' || $chunk === false) {
                break;
            }
        }
    }

    private function readBytes($connection, int $length): string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = fread($connection, $length - strlen($buffer));

            if ($chunk === '' || $chunk === false) {
                break;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function createServer()
    {
        $scheme = $this->context->tls->enabled ? 'tls' : 'tcp';
        $address = sprintf('%s://%s:%d', $scheme, $this->context->host, $this->context->port);
        $errorCode = 0;
        $errorMessage = '';
        $context = stream_context_create($this->buildStreamContextOptions());
        $server = @stream_socket_server($address, $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

        if ($server === false) {
            throw new RuntimeException(sprintf('Cannot bind %s: (%d) %s', $address, $errorCode, $errorMessage));
        }

        return $server;
    }

    private function buildStreamContextOptions(): array
    {
        if (!$this->context->tls->enabled) {
            return [];
        }

        return [
            'ssl' => [
                'local_cert' => $this->context->tls->certificateFile,
                'local_pk' => $this->context->tls->privateKeyFile,
                'passphrase' => $this->context->tls->passphrase ?? '',
                'crypto_method' => STREAM_CRYPTO_METHOD_TLS_SERVER,
                'alpn_protocols' => 'h2',
            ],
        ];
    }
}

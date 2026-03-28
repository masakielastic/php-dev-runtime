<?php

declare(strict_types=1);

namespace PhpDevRuntime\Http;

final class Http2FrameCodec
{
    public function parseNextFrame(string &$buffer): ?array
    {
        if (strlen($buffer) < 9) {
            return null;
        }

        $length = (ord($buffer[0]) << 16) | (ord($buffer[1]) << 8) | ord($buffer[2]);
        $required = 9 + $length;

        if (strlen($buffer) < $required) {
            return null;
        }

        $frame = [
            'length' => $length,
            'type' => ord($buffer[3]),
            'flags' => ord($buffer[4]),
            'streamId' => unpack('N', substr($buffer, 5, 4))[1] & 0x7fffffff,
            'payload' => substr($buffer, 9, $length),
        ];

        $buffer = (string) substr($buffer, $required);

        return $frame;
    }

    public function packFrame(int $type, int $flags, int $streamId, string $payload): string
    {
        $length = strlen($payload);

        return chr(($length >> 16) & 0xff)
            . chr(($length >> 8) & 0xff)
            . chr($length & 0xff)
            . chr($type & 0xff)
            . chr($flags & 0xff)
            . pack('N', $streamId & 0x7fffffff)
            . $payload;
    }

    public function extractHeaderBlockFragment(array $frame, int $flagPadded, int $flagPriority): ?string
    {
        $payload = $frame['payload'];
        $flags = $frame['flags'];

        if (($flags & $flagPadded) !== 0) {
            if ($payload === '') {
                return null;
            }

            $padLength = ord($payload[0]);
            $payload = substr($payload, 1);
        } else {
            $padLength = 0;
        }

        if (($flags & $flagPriority) !== 0) {
            if (strlen($payload) < 5) {
                return null;
            }

            $payload = substr($payload, 5);
        }

        if ($padLength > strlen($payload)) {
            return null;
        }

        if ($padLength > 0) {
            $payload = substr($payload, 0, strlen($payload) - $padLength);
        }

        return $payload;
    }
}

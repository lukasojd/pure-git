<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Transport;

use Lukasojd\PureGit\Domain\Exception\PureGitException;

final class PktLine
{
    public static function encode(string $line): string
    {
        return sprintf('%04x', strlen($line) + 4) . $line;
    }

    public static function flush(): string
    {
        return '0000';
    }

    /**
     * Read a single pkt-line from a stream resource.
     *
     * @param resource $stream
     * @return string|null payload (without length prefix), null on flush-pkt
     */
    public static function read($stream): ?string
    {
        $lenHex = fread($stream, 4);
        if ($lenHex === false || strlen($lenHex) < 4) {
            throw new PureGitException('Unexpected end of pkt-line stream');
        }

        $len = intval($lenHex, 16);

        if ($len === 0) {
            return null;
        }

        if ($len < 4) {
            throw new PureGitException(sprintf('Invalid pkt-line length: %d', $len));
        }

        $payloadLen = $len - 4;
        if ($payloadLen === 0) {
            return '';
        }

        $payload = fread($stream, $payloadLen);
        if ($payload === false || strlen($payload) !== $payloadLen) {
            throw new PureGitException('Truncated pkt-line payload');
        }

        return $payload;
    }

    /**
     * Read pkt-lines until flush-pkt ("0000").
     *
     * @param resource $stream
     * @return list<string>
     */
    public static function readAll($stream): array
    {
        $lines = [];
        while (($line = self::read($stream)) !== null) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Parse a pkt-line from a string buffer, returning the payload and bytes consumed.
     *
     * @return array{payload: string|null, consumed: int} null payload = flush-pkt
     */
    public static function parseFromBuffer(string $buffer, int $offset = 0): array
    {
        if (strlen($buffer) - $offset < 4) {
            throw new PureGitException('Buffer too short for pkt-line length');
        }

        $lenHex = substr($buffer, $offset, 4);
        $len = intval($lenHex, 16);

        if ($len === 0) {
            return [
                'payload' => null,
                'consumed' => 4,
            ];
        }

        if ($len < 4) {
            throw new PureGitException(sprintf('Invalid pkt-line length: %d', $len));
        }

        $payloadLen = $len - 4;
        if (strlen($buffer) - $offset < $len) {
            throw new PureGitException('Buffer too short for pkt-line payload');
        }

        return [
            'payload' => substr($buffer, $offset + 4, $payloadLen),
            'consumed' => $len,
        ];
    }
}

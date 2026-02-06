<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Object;

use Lukasojd\PureGit\Domain\Exception\InvalidObjectException;

final class DeltaDecoder
{
    public static function apply(string $baseData, string $deltaData): string
    {
        $offset = 0;
        $deltaLen = strlen($deltaData);

        // Read base size (variable-length)
        self::readVarint($deltaData, $offset, $deltaLen);

        // Read result size (variable-length)
        $resultSize = self::readVarint($deltaData, $offset, $deltaLen);

        $result = self::processInstructions($baseData, $deltaData, $offset, $deltaLen);

        if (strlen($result) !== $resultSize) {
            throw new InvalidObjectException(sprintf(
                'Delta result size mismatch: expected %d, got %d',
                $resultSize,
                strlen($result),
            ));
        }

        return $result;
    }

    private static function processInstructions(string $baseData, string $deltaData, int $offset, int $deltaLen): string
    {
        $chunks = [];

        while ($offset < $deltaLen) {
            $cmd = ord($deltaData[$offset]);
            $offset++;

            if (($cmd & 0x80) !== 0) {
                $chunks[] = self::processCopyInstruction($baseData, $deltaData, $cmd, $offset);
            } elseif ($cmd > 0) {
                $chunks[] = substr($deltaData, $offset, $cmd);
                $offset += $cmd;
            } else {
                throw new InvalidObjectException('Invalid delta instruction: zero byte');
            }
        }

        return implode('', $chunks);
    }

    private static function processCopyInstruction(string $baseData, string $deltaData, int $cmd, int &$offset): string
    {
        $copyOffset = self::decodeCopyOffset($deltaData, $cmd, $offset);
        $copySize = self::decodeCopySize($deltaData, $cmd, $offset);

        if ($copySize === 0) {
            $copySize = 0x10000;
        }

        return substr($baseData, $copyOffset, $copySize);
    }

    private static function decodeCopyOffset(string $deltaData, int $cmd, int &$offset): int
    {
        $copyOffset = 0;

        if (($cmd & 0x01) !== 0) {
            $copyOffset = ord($deltaData[$offset++]);
        }
        if (($cmd & 0x02) !== 0) {
            $copyOffset |= ord($deltaData[$offset++]) << 8;
        }
        if (($cmd & 0x04) !== 0) {
            $copyOffset |= ord($deltaData[$offset++]) << 16;
        }
        if (($cmd & 0x08) !== 0) {
            $copyOffset |= ord($deltaData[$offset++]) << 24;
        }

        return $copyOffset;
    }

    private static function decodeCopySize(string $deltaData, int $cmd, int &$offset): int
    {
        $copySize = 0;

        if (($cmd & 0x10) !== 0) {
            $copySize = ord($deltaData[$offset++]);
        }
        if (($cmd & 0x20) !== 0) {
            $copySize |= ord($deltaData[$offset++]) << 8;
        }
        if (($cmd & 0x40) !== 0) {
            $copySize |= ord($deltaData[$offset++]) << 16;
        }

        return $copySize;
    }

    private static function readVarint(string $data, int &$offset, int $length): int
    {
        $result = 0;
        $shift = 0;

        do {
            if ($offset >= $length) {
                throw new InvalidObjectException('Truncated varint in delta');
            }
            $byte = ord($data[$offset]);
            $offset++;
            $result |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while (($byte & 0x80) !== 0);

        return $result;
    }
}

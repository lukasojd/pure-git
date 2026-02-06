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

        $result = '';

        while ($offset < $deltaLen) {
            $cmd = ord($deltaData[$offset]);
            $offset++;

            if (($cmd & 0x80) !== 0) {
                // Copy from base
                $copyOffset = 0;
                $copySize = 0;

                if (($cmd & 0x01) !== 0) {
                    $copyOffset = ord($deltaData[$offset]);
                    $offset++;
                }
                if (($cmd & 0x02) !== 0) {
                    $copyOffset |= ord($deltaData[$offset]) << 8;
                    $offset++;
                }
                if (($cmd & 0x04) !== 0) {
                    $copyOffset |= ord($deltaData[$offset]) << 16;
                    $offset++;
                }
                if (($cmd & 0x08) !== 0) {
                    $copyOffset |= ord($deltaData[$offset]) << 24;
                    $offset++;
                }

                if (($cmd & 0x10) !== 0) {
                    $copySize = ord($deltaData[$offset]);
                    $offset++;
                }
                if (($cmd & 0x20) !== 0) {
                    $copySize |= ord($deltaData[$offset]) << 8;
                    $offset++;
                }
                if (($cmd & 0x40) !== 0) {
                    $copySize |= ord($deltaData[$offset]) << 16;
                    $offset++;
                }

                if ($copySize === 0) {
                    $copySize = 0x10000;
                }

                $result .= substr($baseData, $copyOffset, $copySize);
            } elseif ($cmd > 0) {
                // Insert new data
                $result .= substr($deltaData, $offset, $cmd);
                $offset += $cmd;
            } else {
                throw new InvalidObjectException('Invalid delta instruction: zero byte');
            }
        }

        if (strlen($result) !== $resultSize) {
            throw new InvalidObjectException(sprintf(
                'Delta result size mismatch: expected %d, got %d',
                $resultSize,
                strlen($result),
            ));
        }

        return $result;
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

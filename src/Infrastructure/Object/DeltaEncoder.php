<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Object;

/**
 * Produces Git-format delta instructions between a base object and target object.
 *
 * Algorithm: build a hash index of 16-byte blocks in the base data, then scan the
 * target to find matching blocks (copy instructions) and emit insert instructions
 * for non-matching regions.
 *
 * Output format (Git delta):
 *   - varint(base_size)
 *   - varint(target_size)
 *   - instructions: copy (0x80 | flags) or insert (1..127 length bytes)
 */
final class DeltaEncoder
{
    private const int BLOCK_SIZE = 16;

    private const int MAX_INSERT_SIZE = 127;

    /**
     * Compute a delta that transforms baseData into targetData.
     * Returns null if the delta is not beneficial (larger than target).
     */
    public static function encode(string $baseData, string $targetData): ?string
    {
        $baseSize = strlen($baseData);
        $targetSize = strlen($targetData);

        $index = self::buildIndex($baseData, $baseSize);
        $instructions = self::computeInstructions($baseData, $baseSize, $targetData, $targetSize, $index);

        $delta = self::writeVarint($baseSize) . self::writeVarint($targetSize) . $instructions;

        if (strlen($delta) >= $targetSize) {
            return null;
        }

        return $delta;
    }

    /**
     * Build a hash-to-offset index of BLOCK_SIZE-byte blocks in the base data.
     *
     * @return array<int, list<int>> hash => list of offsets
     */
    private static function buildIndex(string $baseData, int $baseSize): array
    {
        $index = [];
        $limit = $baseSize - self::BLOCK_SIZE + 1;

        for ($i = 0; $i < $limit; $i += self::BLOCK_SIZE) {
            $hash = self::blockHash($baseData, $i);
            $index[$hash][] = $i;
        }

        return $index;
    }

    /**
     * Scan target data and produce delta instructions by matching against base index.
     *
     * @param array<int, list<int>> $index
     */
    private static function computeInstructions(
        string $baseData,
        int $baseSize,
        string $targetData,
        int $targetSize,
        array $index,
    ): string {
        $chunks = [];
        $insertBuf = '';
        $targetOffset = 0;

        while ($targetOffset < $targetSize) {
            $match = self::findBestMatch($baseData, $baseSize, $targetData, $targetSize, $targetOffset, $index);

            if ($match !== null) {
                if ($insertBuf !== '') {
                    $chunks[] = self::encodeInserts($insertBuf);
                    $insertBuf = '';
                }
                $chunks[] = self::encodeCopy($match['offset'], $match['size']);
                $targetOffset += $match['size'];
            } else {
                $insertBuf .= $targetData[$targetOffset];
                $targetOffset++;
            }
        }

        if ($insertBuf !== '') {
            $chunks[] = self::encodeInserts($insertBuf);
        }

        return implode('', $chunks);
    }

    /**
     * Try to find a matching block in the base at the current target position.
     *
     * @param array<int, list<int>> $index
     * @return array{offset: int, size: int}|null
     */
    private static function findBestMatch(
        string $baseData,
        int $baseSize,
        string $targetData,
        int $targetSize,
        int $targetOffset,
        array $index,
    ): ?array {
        $remaining = $targetSize - $targetOffset;
        if ($remaining < self::BLOCK_SIZE) {
            return null;
        }

        $hash = self::blockHash($targetData, $targetOffset);
        if (! isset($index[$hash])) {
            return null;
        }

        $bestOffset = -1;
        $bestSize = 0;

        foreach ($index[$hash] as $baseOffset) {
            if (substr($baseData, $baseOffset, self::BLOCK_SIZE) !== substr($targetData, $targetOffset, self::BLOCK_SIZE)) {
                continue;
            }

            $matchSize = self::extendMatch($baseData, $baseSize, $targetData, $targetSize, $baseOffset, $targetOffset);

            if ($matchSize > $bestSize) {
                $bestSize = $matchSize;
                $bestOffset = $baseOffset;
            }
        }

        if ($bestSize < self::BLOCK_SIZE) {
            return null;
        }

        return [
            'offset' => $bestOffset,
            'size' => $bestSize,
        ];
    }

    /**
     * Extend a match forward from the initial BLOCK_SIZE match.
     */
    private static function extendMatch(
        string $baseData,
        int $baseSize,
        string $targetData,
        int $targetSize,
        int $baseOffset,
        int $targetOffset,
    ): int {
        $maxLen = min($baseSize - $baseOffset, $targetSize - $targetOffset);
        $maxLen = min($maxLen, 0xFFFFFF);
        $len = 0;

        while ($len < $maxLen && $baseData[$baseOffset + $len] === $targetData[$targetOffset + $len]) {
            $len++;
        }

        return $len;
    }

    /**
     * Simple rolling hash of a BLOCK_SIZE chunk.
     */
    private static function blockHash(string $data, int $offset): int
    {
        $h = 0x9E3779B9;
        $end = $offset + self::BLOCK_SIZE;
        for ($i = $offset; $i < $end; $i++) {
            $h = (($h << 5) + $h + ord($data[$i])) & 0x7FFFFFFF;
        }

        return $h;
    }

    /**
     * Encode insert instructions. Splits data into MAX_INSERT_SIZE chunks.
     */
    private static function encodeInserts(string $data): string
    {
        $result = '';
        $len = strlen($data);
        $offset = 0;

        while ($offset < $len) {
            $chunkSize = min(self::MAX_INSERT_SIZE, $len - $offset);
            $result .= chr($chunkSize) . substr($data, $offset, $chunkSize);
            $offset += $chunkSize;
        }

        return $result;
    }

    /**
     * Encode a copy instruction in Git delta format.
     * Byte format: 0x80 | offset_flags | size_flags, then offset/size bytes.
     */
    private static function encodeCopy(int $offset, int $size): string
    {
        $encodedSize = $size === 0x10000 ? 0 : $size;

        [$cmd, $bytes] = self::encodeCopyOffsetBytes($offset);
        [$cmd, $bytes] = self::encodeCopySizeBytes($encodedSize, $cmd, $bytes);

        if ($cmd === 0x80) {
            $cmd |= 0x10;
            $bytes .= chr($encodedSize & 0xFF);
        }

        return chr($cmd) . $bytes;
    }

    /**
     * @return array{int, string}
     */
    private static function encodeCopyOffsetBytes(int $offset): array
    {
        $cmd = 0x80;
        $bytes = '';

        if (($offset & 0xFF) !== 0) {
            $cmd |= 0x01;
            $bytes .= chr($offset & 0xFF);
        }
        if (($offset & 0xFF00) !== 0) {
            $cmd |= 0x02;
            $bytes .= chr(($offset >> 8) & 0xFF);
        }
        if (($offset & 0xFF0000) !== 0) {
            $cmd |= 0x04;
            $bytes .= chr(($offset >> 16) & 0xFF);
        }
        if (($offset & 0x7F000000) !== 0) {
            $cmd |= 0x08;
            $bytes .= chr(($offset >> 24) & 0xFF);
        }

        return [$cmd, $bytes];
    }

    /**
     * @return array{int, string}
     */
    private static function encodeCopySizeBytes(int $size, int $cmd, string $bytes): array
    {
        if (($size & 0xFF) !== 0) {
            $cmd |= 0x10;
            $bytes .= chr($size & 0xFF);
        }
        if (($size & 0xFF00) !== 0) {
            $cmd |= 0x20;
            $bytes .= chr(($size >> 8) & 0xFF);
        }
        if (($size & 0xFF0000) !== 0) {
            $cmd |= 0x40;
            $bytes .= chr(($size >> 16) & 0xFF);
        }

        return [$cmd, $bytes];
    }

    /**
     * Encode a variable-length integer (varint) in Git delta format.
     */
    private static function writeVarint(int $value): string
    {
        $result = '';

        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            if ($value > 0) {
                $byte |= 0x80;
            }
            $result .= chr($byte);
        } while ($value > 0);

        return $result;
    }
}

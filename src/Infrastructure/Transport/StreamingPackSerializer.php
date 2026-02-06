<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Transport;

use Generator;
use Lukasojd\PureGit\Domain\Object\ObjectType;
use Lukasojd\PureGit\Domain\Repository\RawObject;
use Lukasojd\PureGit\Infrastructure\Object\DeltaEncoder;

/**
 * Streaming pack serializer with delta compression.
 *
 * Writes objects to a packfile one at a time using a sliding window
 * for OFS_DELTA encoding. Memory usage is proportional to window size,
 * not total object count.
 */
final class StreamingPackSerializer
{
    private const int OBJ_OFS_DELTA = 6;

    private const int DELTA_WINDOW_SIZE = 5;

    private const int OBJ_BLOB = 3;

    private const int MIN_DELTA_SIZE = 256;

    /**
     * Serialize objects into a pack file with optional streaming delta compression.
     *
     * @param Generator<int, RawObject> $objects
     */
    public function serialize(Generator $objects, int $count, string $outputPath, bool $enableDelta = false): void
    {
        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $tmpPath = $outputPath . '.tmp.' . getmypid();
        $fh = fopen($tmpPath, 'wb');
        if ($fh === false) {
            return;
        }

        $hashCtx = hash_init('sha1');
        $this->writeAndHash($fh, $hashCtx, 'PACK' . pack('N', 2) . pack('N', $count));

        /** @var list<array{type: int, data: string, offset: int}> $window */
        $window = [];

        foreach ($objects as $raw) {
            $this->writePackObject($fh, $hashCtx, $raw, $window, $enableDelta);
        }

        fwrite($fh, hash_final($hashCtx, true));
        fclose($fh);

        rename($tmpPath, $outputPath);
    }

    /**
     * Write a single object, attempting delta compression against the window.
     *
     * @param resource $fh
     * @param list<array{type: int, data: string, offset: int}> $window
     */
    private function writePackObject($fh, \HashContext $hashCtx, RawObject $raw, array &$window, bool $enableDelta): void
    {
        $type = $this->rawTypeToPackType($raw->type);
        $offset = (int) ftell($fh);

        $compressed = gzcompress($raw->data);
        $fullCompressed = $compressed !== false ? $compressed : '';

        $tryDelta = $enableDelta && $type === self::OBJ_BLOB && strlen($raw->data) >= self::MIN_DELTA_SIZE;
        $delta = $tryDelta ? $this->findStreamingDelta($raw->data, $type, strlen($fullCompressed), $window) : null;

        if ($delta !== null) {
            $this->writeOfsDelta($fh, $hashCtx, $delta['data'], $offset - $delta['baseOffset']);
        } else {
            $this->writeFullObject($fh, $hashCtx, $type, $raw->data, $fullCompressed);
        }

        $this->updateWindow($window, $type, $raw->data, $offset);
    }

    /**
     * @param resource $fh
     */
    private function writeFullObject($fh, \HashContext $hashCtx, int $type, string $data, string $compressed): void
    {
        $this->writeAndHash($fh, $hashCtx, $this->encodeObjectHeader($type, strlen($data)));
        $this->writeAndHash($fh, $hashCtx, $compressed);
    }

    /**
     * @param list<array{type: int, data: string, offset: int}> $window
     */
    private function updateWindow(array &$window, int $type, string $data, int $offset): void
    {
        if ($type !== self::OBJ_BLOB) {
            return;
        }

        $window[] = [
            'type' => $type,
            'data' => $data,
            'offset' => $offset,
        ];
        if (count($window) > self::DELTA_WINDOW_SIZE) {
            array_shift($window);
        }
    }

    /**
     * Find the best delta against same-type entries in the window.
     *
     * @param list<array{type: int, data: string, offset: int}> $window
     * @return array{data: string, baseOffset: int}|null
     */
    private function findStreamingDelta(string $data, int $type, int $fullSize, array $window): ?array
    {
        $best = null;
        $bestSize = $fullSize;

        foreach (array_reverse($window) as $entry) {
            if ($entry['type'] !== $type) {
                continue;
            }

            $delta = DeltaEncoder::encode($entry['data'], $data);
            if ($delta === null) {
                continue;
            }

            $compressed = gzcompress($delta);
            if ($compressed === false || strlen($compressed) >= $bestSize) {
                continue;
            }

            $bestSize = strlen($compressed);
            $best = [
                'data' => $compressed,
                'baseOffset' => $entry['offset'],
            ];
        }

        return $best;
    }

    /**
     * Write an OFS_DELTA entry: header + negative offset + compressed delta.
     *
     * @param resource $fh
     */
    private function writeOfsDelta($fh, \HashContext $hashCtx, string $compressedDelta, int $negativeOffset): void
    {
        $rawDelta = gzuncompress($compressedDelta);
        $rawSize = $rawDelta !== false ? strlen($rawDelta) : strlen($compressedDelta);

        $this->writeAndHash($fh, $hashCtx, $this->encodeObjectHeader(self::OBJ_OFS_DELTA, $rawSize));
        $this->writeAndHash($fh, $hashCtx, $this->encodeNegativeOffset($negativeOffset));
        $this->writeAndHash($fh, $hashCtx, $compressedDelta);
    }

    private function encodeNegativeOffset(int $offset): string
    {
        $bytes = [chr($offset & 0x7F)];
        $offset >>= 7;

        while ($offset > 0) {
            $offset--;
            array_unshift($bytes, chr(0x80 | ($offset & 0x7F)));
            $offset >>= 7;
        }

        return implode('', $bytes);
    }

    /**
     * @param resource $fh
     */
    private function writeAndHash($fh, \HashContext $hashCtx, string $data): void
    {
        fwrite($fh, $data);
        hash_update($hashCtx, $data);
    }

    private function encodeObjectHeader(int $type, int $size): string
    {
        $byte = ($type << 4) | ($size & 0x0F);
        $size >>= 4;
        $header = '';

        while ($size > 0) {
            $header .= chr($byte | 0x80);
            $byte = $size & 0x7F;
            $size >>= 7;
        }

        return $header . chr($byte);
    }

    private function rawTypeToPackType(ObjectType $type): int
    {
        return match ($type) {
            ObjectType::Commit => 1,
            ObjectType::Tree => 2,
            ObjectType::Blob => 3,
            ObjectType::Tag => 4,
        };
    }
}

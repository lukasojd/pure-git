<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Object;

use Lukasojd\PureGit\Domain\Exception\InvalidObjectException;
use Lukasojd\PureGit\Domain\Exception\ObjectNotFoundException;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\ObjectType;
use Lukasojd\PureGit\Domain\Repository\RawObject;

final class PackfileReader
{
    private const int PACK_SIGNATURE = 0x5041434B; // 'PACK'

    private const int OBJ_COMMIT = 1;

    private const int OBJ_TREE = 2;

    private const int OBJ_BLOB = 3;

    private const int OBJ_TAG = 4;

    private const int OBJ_OFS_DELTA = 6;

    private const int OBJ_REF_DELTA = 7;

    /**
     * @var resource|null
     */
    private $handle;

    public function __construct(
        private readonly string $packPath,
        private readonly PackIndexReader $indexReader,
    ) {
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            fclose($this->handle);
        }
    }

    /**
     * Get delta reuse info for an object, if it is stored as OFS_DELTA.
     *
     * Returns the base object ID and uncompressed delta data, allowing
     * a pack writer to reuse the delta without running DeltaEncoder.
     * Returns null for whole objects or if the base cannot be identified.
     */
    public function getDeltaReuse(ObjectId $id): ?DeltaReuseInfo
    {
        $offset = $this->indexReader->findOffset($id);
        if ($offset === null) {
            return null;
        }

        $handle = $this->getHandle();
        fseek($handle, $offset);

        $buf = fread($handle, 512);
        if ($buf === false || $buf === '') {
            return null;
        }

        $byte = ord($buf[0]);
        $type = ($byte >> 4) & 0x07;
        $size = $byte & 0x0F;
        $shift = 4;
        $pos = 1;

        while (($byte & 0x80) !== 0) {
            $byte = ord($buf[$pos++]);
            $size |= ($byte & 0x7F) << $shift;
            $shift += 7;
        }

        if ($type !== self::OBJ_OFS_DELTA) {
            return null;
        }

        return $this->readDeltaReuseInfo($handle, substr($buf, $pos), $offset, $size);
    }

    public function readObject(ObjectId $id): RawObject
    {
        $offset = $this->indexReader->findOffset($id);
        if ($offset === null) {
            throw ObjectNotFoundException::withId($id->hash);
        }

        return $this->readAtOffset($offset);
    }

    public function tryReadObject(ObjectId $id): ?RawObject
    {
        $offset = $this->indexReader->findOffset($id);
        if ($offset === null) {
            return null;
        }

        return $this->readAtOffset($offset);
    }

    public function tryReadObjectHeader(ObjectId $id): ?RawObject
    {
        return $this->tryReadObjectHeaderByBinary($id->toBinary());
    }

    /**
     * @param string $binHash 20-byte raw hash
     */
    public function tryReadObjectHeaderByBinary(string $binHash): ?RawObject
    {
        $offset = $this->indexReader->findOffsetByBinary($binHash);
        if ($offset === null) {
            return null;
        }

        return $this->readAtOffsetHeaderOnly($offset);
    }

    public function hasObject(ObjectId $id): bool
    {
        return $this->indexReader->hasObject($id);
    }

    public function readAtOffset(int $offset): RawObject
    {
        $handle = $this->getHandle();
        fseek($handle, $offset);

        $buf = fread($handle, 512);
        if ($buf === false || $buf === '') {
            throw new InvalidObjectException('Unexpected end of pack data');
        }

        $byte = ord($buf[0]);
        $type = ($byte >> 4) & 0x07;
        $size = $byte & 0x0F;
        $shift = 4;
        $pos = 1;

        while (($byte & 0x80) !== 0) {
            $byte = ord($buf[$pos++]);
            $size |= ($byte & 0x7F) << $shift;
            $shift += 7;
        }

        // Remaining bytes in buffer after varint = start of compressed/delta data
        $remainingBuf = substr($buf, $pos);

        if ($type === self::OBJ_OFS_DELTA) {
            return $this->readOfsDeltaFromBuffer($handle, $remainingBuf, $size, $offset);
        }

        return match ($type) {
            self::OBJ_COMMIT, self::OBJ_TREE, self::OBJ_BLOB, self::OBJ_TAG => $this->readWholeObject($handle, $type, $size, $remainingBuf),
            self::OBJ_REF_DELTA => $this->readRefDelta($handle, $size, $remainingBuf),
            default => throw new InvalidObjectException(sprintf('Unknown pack object type: %d', $type)),
        };
    }

    /**
     * @param resource $handle positioned at end of initial read buffer
     * @param string $remainingBuf buffer bytes after object header (OFS varint + compressed data)
     */
    private function readDeltaReuseInfo($handle, string $remainingBuf, int $currentOffset, int $deltaSize): ?DeltaReuseInfo
    {
        if ($remainingBuf === '') {
            return null;
        }

        $pos = 0;
        $byte = ord($remainingBuf[$pos++]);
        $negativeOffset = $byte & 0x7F;

        while (($byte & 0x80) !== 0) {
            $byte = ord($remainingBuf[$pos++]);
            $negativeOffset = (($negativeOffset + 1) << 7) | ($byte & 0x7F);
        }

        $baseOffset = $currentOffset - $negativeOffset;
        $baseId = $this->indexReader->findObjectAtOffset($baseOffset);
        if (! $baseId instanceof ObjectId) {
            return null;
        }

        $compressedBuf = substr($remainingBuf, $pos);
        $deltaData = $this->readCompressedData($handle, $deltaSize, $compressedBuf);

        return new DeltaReuseInfo(baseId: $baseId, deltaData: $deltaData);
    }

    /**
     * @param resource $handle
     */
    private function readWholeObject($handle, int $type, int $size, string $initialBuffer): RawObject
    {
        $objectType = $this->packTypeToObjectType($type);
        $data = $this->readCompressedData($handle, $size, $initialBuffer);

        return new RawObject($objectType, $size, $data);
    }

    /**
     * @param resource $handle positioned at offset + 32 (end of initial buffer)
     * @param string $remainingBuf buffer bytes after object header varint (OFS varint + compressed data)
     * @param int $currentOffset absolute file offset of this object
     */
    private function readOfsDeltaFromBuffer(
        $handle,
        string $remainingBuf,
        int $deltaSize,
        int $currentOffset,
    ): RawObject {
        $pos = 0;
        $byte = ord($remainingBuf[$pos++]);
        $negativeOffset = $byte & 0x7F;

        while (($byte & 0x80) !== 0) {
            $byte = ord($remainingBuf[$pos++]);
            $negativeOffset = (($negativeOffset + 1) << 7) | ($byte & 0x7F);
        }

        // Pass remaining buffer (after OFS varint) as initial inflate data
        $compressedBuf = substr($remainingBuf, $pos);

        $baseOffset = $currentOffset - $negativeOffset;
        $deltaData = $this->readCompressedData($handle, $deltaSize, $compressedBuf);

        $baseObject = $this->readAtOffset($baseOffset);

        $result = DeltaDecoder::apply($baseObject->data, $deltaData);

        return new RawObject($baseObject->type, strlen($result), $result);
    }

    /**
     * @param resource $handle
     */
    private function readRefDelta($handle, int $deltaSize, string $remainingBuf): RawObject
    {
        // Buffer has 20-byte base hash + start of compressed data
        if (strlen($remainingBuf) >= 20) {
            $baseHash = substr($remainingBuf, 0, 20);
            $compressedBuf = substr($remainingBuf, 20);
        } else {
            // Buffer too short (unlikely) — fall back to reading from handle
            $need = 20 - strlen($remainingBuf);
            $extra = fread($handle, $need);
            if ($extra === false) {
                throw new InvalidObjectException('Failed to read ref delta base hash');
            }
            $baseHash = $remainingBuf . $extra;
            $compressedBuf = '';
        }

        if (strlen($baseHash) !== 20) {
            throw new InvalidObjectException('Failed to read ref delta base hash');
        }

        $baseId = ObjectId::fromBinary($baseHash);
        $deltaData = $this->readCompressedData($handle, $deltaSize, $compressedBuf);

        $baseObject = $this->readObject($baseId);

        $result = DeltaDecoder::apply($baseObject->data, $deltaData);

        return new RawObject($baseObject->type, strlen($result), $result);
    }

    private function readAtOffsetHeaderOnly(int $offset): RawObject
    {
        $handle = $this->getHandle();
        fseek($handle, $offset);

        $buf = fread($handle, 512);
        if ($buf === false || $buf === '') {
            throw new InvalidObjectException('Unexpected end of pack data');
        }

        $byte = ord($buf[0]);
        $type = ($byte >> 4) & 0x07;
        $size = $byte & 0x0F;
        $shift = 4;
        $pos = 1;

        while (($byte & 0x80) !== 0) {
            $byte = ord($buf[$pos++]);
            $size |= ($byte & 0x7F) << $shift;
            $shift += 7;
        }

        $remainingBuf = substr($buf, $pos);

        // Only partial inflate for whole commit/tag objects
        if ($type === self::OBJ_COMMIT || $type === self::OBJ_TAG) {
            return $this->readHeaderOnly($handle, $type, $size, $remainingBuf);
        }

        // Delta and other types: fall back to full decompression
        if ($type === self::OBJ_OFS_DELTA) {
            return $this->readOfsDeltaFromBuffer($handle, $remainingBuf, $size, $offset);
        }

        return match ($type) {
            self::OBJ_TREE, self::OBJ_BLOB => $this->readWholeObject($handle, $type, $size, $remainingBuf),
            self::OBJ_REF_DELTA => $this->readRefDelta($handle, $size, $remainingBuf),
            default => throw new InvalidObjectException(sprintf('Unknown pack object type: %d', $type)),
        };
    }

    /**
     * @param resource $handle
     */
    private function readHeaderOnly($handle, int $type, int $size, string $initialBuffer): RawObject
    {
        $context = inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($context === false) {
            throw new InvalidObjectException('Failed to init zlib inflate');
        }

        $objectType = $this->packTypeToObjectType($type);
        $data = $this->inflateUntilHeaderEnd($handle, $size, $initialBuffer, $context);

        return new RawObject($objectType, $size, $data);
    }

    /**
     * Inflate data until "\n\n" header boundary is found or full data is decompressed.
     *
     * @param resource $handle
     */
    private function inflateUntilHeaderEnd($handle, int $expectedSize, string $initialBuffer, \InflateContext $context): string
    {
        $output = '';

        if ($initialBuffer !== '') {
            $output = $this->inflateChunk($context, $initialBuffer);
            $truncated = $this->truncateAtHeaderEnd($output);
            if ($truncated !== null || strlen($output) >= $expectedSize) {
                return $truncated ?? $output;
            }
        }

        while (strlen($output) < $expectedSize) {
            $compressed = fread($handle, 256);
            if ($compressed === false || $compressed === '') {
                return $output;
            }

            $decompressed = $this->inflateChunk($context, $compressed);
            $output .= $decompressed;
            $truncated = $this->truncateAtHeaderEnd($output);
            if ($truncated !== null) {
                return $truncated;
            }
        }

        return $output;
    }

    private function inflateChunk(\InflateContext $context, string $compressed): string
    {
        $decompressed = inflate_add($context, $compressed, ZLIB_SYNC_FLUSH);
        if ($decompressed === false) {
            throw new InvalidObjectException('Failed to decompress pack data');
        }

        return $decompressed;
    }

    private function truncateAtHeaderEnd(string $output): ?string
    {
        $headerEnd = strpos($output, "\n\n");

        return $headerEnd !== false ? substr($output, 0, $headerEnd + 2) : null;
    }

    private function packTypeToObjectType(int $type): ObjectType
    {
        return match ($type) {
            self::OBJ_COMMIT => ObjectType::Commit,
            self::OBJ_TREE => ObjectType::Tree,
            self::OBJ_BLOB => ObjectType::Blob,
            self::OBJ_TAG => ObjectType::Tag,
            default => throw new InvalidObjectException(sprintf('Unknown pack type: %d', $type)),
        };
    }

    /**
     * @param resource $handle
     */
    private function readCompressedData($handle, int $expectedSize, string $initialBuffer = ''): string
    {
        $context = inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($context === false) {
            throw new InvalidObjectException('Failed to init zlib inflate');
        }

        $chunks = [];
        $totalSize = 0;

        if ($initialBuffer !== '') {
            $decompressed = inflate_add($context, $initialBuffer, ZLIB_SYNC_FLUSH);
            if ($decompressed === false) {
                throw new InvalidObjectException('Failed to decompress pack data');
            }

            $chunks[] = $decompressed;
            $totalSize += strlen($decompressed);
        }

        while ($totalSize < $expectedSize) {
            $chunkSize = max(1, min(65536, $expectedSize - $totalSize + 512));
            $compressed = fread($handle, $chunkSize);
            if ($compressed === false || $compressed === '') {
                throw new InvalidObjectException('Unexpected end of compressed data');
            }

            $decompressed = inflate_add($context, $compressed, ZLIB_SYNC_FLUSH);
            if ($decompressed === false) {
                throw new InvalidObjectException('Failed to decompress pack data');
            }

            $chunks[] = $decompressed;
            $totalSize += strlen($decompressed);
        }

        return implode('', $chunks);
    }

    /**
     * @return resource
     */
    private function getHandle()
    {
        if ($this->handle !== null) {
            return $this->handle;
        }

        $handle = fopen($this->packPath, 'rb');
        if ($handle === false) {
            throw new InvalidObjectException(sprintf('Cannot open packfile: %s', $this->packPath));
        }

        // Verify header
        $signature = fread($handle, 4);
        if ($signature === false) {
            throw new InvalidObjectException('Cannot read pack signature');
        }
        /** @var array{value: int} $unpacked */
        $unpacked = unpack('Nvalue', $signature);
        if ($unpacked['value'] !== self::PACK_SIGNATURE) {
            throw new InvalidObjectException('Invalid pack signature');
        }

        $versionData = fread($handle, 4);
        if ($versionData === false) {
            throw new InvalidObjectException('Cannot read pack version');
        }
        /** @var array{value: int} $unpacked */
        $unpacked = unpack('Nvalue', $versionData);
        if ($unpacked['value'] !== 2) {
            throw new InvalidObjectException(sprintf('Unsupported pack version: %d', $unpacked['value']));
        }

        // Skip object count (4 bytes)
        fread($handle, 4);

        $this->handle = $handle;

        return $handle;
    }
}

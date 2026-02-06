<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Transport;

use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Object\ObjectType;
use Lukasojd\PureGit\Infrastructure\Object\DeltaDecoder;

/**
 * Receives pack data from HTTP/TCP transport and produces .pack + .idx.
 *
 * During streaming: demuxes side-band-64k frames, writes raw pack bytes to disk.
 * On finish: re-reads the completed pack file to build index entries, writes .idx.
 */
final class StreamingPackReceiver
{
    private const DATA_CACHE_LIMIT = 32 * 1024 * 1024;

    /**
     * @var resource|null
     */
    private $packHandle;

    /**
     * @var resource|null
     */
    private $indexHandle;

    private readonly SideBandDemuxer $demuxer;

    /**
     * @var array<int, int> offset → resolved type (1-4)
     */
    private array $typeByOffset = [];

    /**
     * @var array<string, int> hex hash → offset
     */
    private array $hashToOffset = [];

    /**
     * @var array<int, string> offset → decompressed data (bounded FIFO)
     */
    private array $dataCache = [];

    private int $dataCacheSize = 0;

    public function __construct(
        private readonly string $packPath
    ) {
        $this->demuxer = new SideBandDemuxer();

        $handle = fopen($this->packPath, 'wb');
        if ($handle === false) {
            throw new PureGitException(sprintf('Cannot open pack file for writing: %s', $this->packPath));
        }
        $this->packHandle = $handle;
    }

    /**
     * Feed a chunk of raw HTTP response data (side-band-64k framed).
     */
    public function feedChunk(string $data): void
    {
        $packData = $this->demuxer->feed($data);

        if ($packData !== '') {
            $this->writePackData($packData);
        }
    }

    /**
     * Feed raw pack data directly (no side-band demux).
     */
    public function feedPackData(string $data): void
    {
        $this->writePackData($data);
    }

    /**
     * Finalize: close pack, index it, write .idx, return pack path.
     */
    public function finish(): string
    {
        if ($this->packHandle === null) {
            throw new PureGitException('Pack file already closed');
        }

        fclose($this->packHandle);
        $this->packHandle = null;

        $this->buildIndex();

        return $this->packPath;
    }

    private function writePackData(string $data): void
    {
        if ($this->packHandle === null) {
            throw new PureGitException('Pack file handle is closed');
        }

        fwrite($this->packHandle, $data);
    }

    private function buildIndex(): void
    {
        $handle = fopen($this->packPath, 'rb');
        if ($handle === false) {
            throw new PureGitException('Cannot reopen pack file for indexing');
        }

        $this->indexHandle = $handle;

        $objectCount = $this->readPackHeader($handle);
        $entries = $this->indexAllObjects($handle, $objectCount);

        fclose($handle);
        $this->indexHandle = null;
        $this->writeIndex($entries);
        $this->clearIndexState();
    }

    /**
     * @param resource $handle
     */
    private function readPackHeader($handle): int
    {
        $header = fread($handle, 12);
        if ($header === false || strlen($header) < 12) {
            throw new PureGitException('Truncated pack header');
        }

        if (! str_starts_with($header, 'PACK')) {
            throw new PureGitException('Invalid pack header magic');
        }

        /** @var array{v: int, c: int} $parsed */
        $parsed = unpack('Nv/Nc', $header, 4);
        if ($parsed['v'] !== 2) {
            throw new PureGitException(sprintf('Unsupported pack version: %d', $parsed['v']));
        }

        return $parsed['c'];
    }

    /**
     * @param resource $handle
     * @return list<IndexEntry>
     */
    private function indexAllObjects($handle, int $objectCount): array
    {
        $entries = [];

        for ($i = 0; $i < $objectCount; $i++) {
            $offset = (int) ftell($handle);
            $entries[] = $this->indexSingleObject($handle, $offset);
        }

        return $entries;
    }

    /**
     * @param resource $handle
     */
    private function indexSingleObject(
        $handle,
        int $offset,
    ): IndexEntry {
        [$type, $size] = $this->readTypeSize($handle);

        return $this->indexByType($handle, $type, $size, $offset);
    }

    /**
     * @param resource $handle
     */
    private function indexByType(
        $handle,
        int $type,
        int $size,
        int $offset,
    ): IndexEntry {
        return match ($type) {
            1, 2, 3, 4 => $this->indexWholeObject($handle, $type, $size, $offset),
            6 => $this->indexOfsDelta($handle, $size, $offset),
            7 => $this->indexRefDelta($handle, $size, $offset),
            default => throw new PureGitException(sprintf('Unknown pack type: %d', $type)),
        };
    }

    /**
     * @param resource $handle
     */
    private function indexWholeObject(
        $handle,
        int $type,
        int $size,
        int $offset,
    ): IndexEntry {
        $data = $this->inflateData($handle, $size);
        $hash = $this->computeHash($type, $data);
        $crc = $this->readCrc32($handle, $offset);

        $this->typeByOffset[$offset] = $type;
        $this->hashToOffset[$hash] = $offset;
        $this->cacheData($offset, $data);

        return new IndexEntry(hash: $hash, offset: $offset, crc32: $crc);
    }

    /**
     * @param resource $handle
     */
    private function indexOfsDelta(
        $handle,
        int $size,
        int $offset,
    ): IndexEntry {
        $negativeOffset = $this->readNegativeOffset($handle);

        $deltaData = $this->inflateData($handle, $size);
        $crc = $this->readCrc32($handle, $offset);

        $baseOffset = $offset - $negativeOffset;
        $baseType = $this->getBaseType($baseOffset);
        $baseData = $this->getBaseData($baseOffset);

        $result = DeltaDecoder::apply($baseData, $deltaData);
        $hash = $this->computeHash($baseType, $result);

        $this->typeByOffset[$offset] = $baseType;
        $this->hashToOffset[$hash] = $offset;
        $this->cacheData($offset, $result);

        return new IndexEntry(hash: $hash, offset: $offset, crc32: $crc);
    }

    /**
     * @param resource $handle
     */
    private function indexRefDelta(
        $handle,
        int $size,
        int $offset,
    ): IndexEntry {
        $baseHash = fread($handle, 20);
        if ($baseHash === false || strlen($baseHash) < 20) {
            throw new PureGitException('Truncated REF_DELTA base hash');
        }

        $deltaData = $this->inflateData($handle, $size);
        $crc = $this->readCrc32($handle, $offset);
        $baseHex = bin2hex($baseHash);

        if (! isset($this->hashToOffset[$baseHex])) {
            throw new PureGitException(sprintf('REF_DELTA base %s not found', $baseHex));
        }

        $baseOffset = $this->hashToOffset[$baseHex];
        $baseType = $this->getBaseType($baseOffset);
        $baseData = $this->getBaseData($baseOffset);

        $result = DeltaDecoder::apply($baseData, $deltaData);
        $hash = $this->computeHash($baseType, $result);

        $this->typeByOffset[$offset] = $baseType;
        $this->hashToOffset[$hash] = $offset;
        $this->cacheData($offset, $result);

        return new IndexEntry(hash: $hash, offset: $offset, crc32: $crc);
    }

    /**
     * Read pack object type (3 bits) and size (variable-length) from handle.
     *
     * @param resource $handle
     * @return array{int, int} [type, size]
     */
    private function readTypeSize($handle): array
    {
        $byte = $this->readByte($handle);
        $type = ($byte >> 4) & 0x07;
        $size = $byte & 0x0F;
        $shift = 4;

        while (($byte & 0x80) !== 0) {
            $byte = $this->readByte($handle);
            $size |= ($byte & 0x7F) << $shift;
            $shift += 7;
        }

        return [$type, $size];
    }

    /**
     * Read OFS_DELTA negative offset encoding from handle.
     *
     * @param resource $handle
     */
    private function readNegativeOffset($handle): int
    {
        $byte = $this->readByte($handle);
        $negativeOffset = $byte & 0x7F;
        while (($byte & 0x80) !== 0) {
            $byte = $this->readByte($handle);
            $negativeOffset = (($negativeOffset + 1) << 7) | ($byte & 0x7F);
        }

        return $negativeOffset;
    }

    private function getBaseType(int $offset): int
    {
        if (! isset($this->typeByOffset[$offset])) {
            throw new PureGitException(sprintf('Base type at offset %d not found', $offset));
        }

        return $this->typeByOffset[$offset];
    }

    private function cacheData(int $offset, string $data): void
    {
        $len = strlen($data);
        $this->dataCache[$offset] = $data;
        $this->dataCacheSize += $len;

        while ($this->dataCacheSize > self::DATA_CACHE_LIMIT && $this->dataCache !== []) {
            $evictOffset = array_key_first($this->dataCache);
            $this->dataCacheSize -= strlen($this->dataCache[$evictOffset]);
            unset($this->dataCache[$evictOffset]);
        }
    }

    private function getBaseData(int $offset): string
    {
        return $this->dataCache[$offset] ?? $this->resolveAtOffset($offset);
    }

    /**
     * Re-read and decompress object at given offset from the pack file.
     * Handles delta chains recursively. Saves/restores file position.
     */
    private function resolveAtOffset(int $offset): string
    {
        if ($this->indexHandle === null) {
            throw new PureGitException('Index handle not available for re-read');
        }

        $savedPos = (int) ftell($this->indexHandle);
        fseek($this->indexHandle, $offset);

        [$type, $size] = $this->readTypeSize($this->indexHandle);

        $data = match ($type) {
            1, 2, 3, 4 => $this->inflateData($this->indexHandle, $size),
            6 => $this->resolveOfsDeltaData($this->indexHandle, $offset, $size),
            7 => $this->resolveRefDeltaData($this->indexHandle, $size),
            default => throw new PureGitException(sprintf('Unknown pack type during resolve: %d', $type)),
        };

        fseek($this->indexHandle, $savedPos);
        $this->cacheData($offset, $data);

        return $data;
    }

    /**
     * Resolve OFS_DELTA data only (for re-read path).
     *
     * @param resource $handle
     */
    private function resolveOfsDeltaData($handle, int $offset, int $size): string
    {
        $negativeOffset = $this->readNegativeOffset($handle);
        $deltaData = $this->inflateData($handle, $size);
        $baseOffset = $offset - $negativeOffset;

        return DeltaDecoder::apply($this->getBaseData($baseOffset), $deltaData);
    }

    /**
     * Resolve REF_DELTA data only (for re-read path).
     *
     * @param resource $handle
     */
    private function resolveRefDeltaData($handle, int $size): string
    {
        $baseHash = fread($handle, 20);
        if ($baseHash === false || strlen($baseHash) < 20) {
            throw new PureGitException('Truncated REF_DELTA base hash during resolve');
        }

        $deltaData = $this->inflateData($handle, $size);
        $baseHex = bin2hex($baseHash);

        if (! isset($this->hashToOffset[$baseHex])) {
            throw new PureGitException(sprintf('REF_DELTA base %s not found during resolve', $baseHex));
        }

        return DeltaDecoder::apply($this->getBaseData($this->hashToOffset[$baseHex]), $deltaData);
    }

    private function clearIndexState(): void
    {
        $this->typeByOffset = [];
        $this->hashToOffset = [];
        $this->dataCache = [];
        $this->dataCacheSize = 0;
    }

    /**
     * Decompress data from file handle, using inflate_get_read_len()
     * to determine exact compressed byte count and seek precisely.
     *
     * @param resource $handle positioned at start of compressed data
     */
    private function inflateData($handle, int $expectedSize): string
    {
        $context = inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($context === false) {
            throw new PureGitException('Failed to init zlib inflate');
        }

        $startPos = (int) ftell($handle);
        $chunks = [];
        $totalSize = 0;

        do {
            $readSize = max(1, min(65536, max(1, $expectedSize - $totalSize) + 512));
            $compressed = fread($handle, $readSize);
            if ($compressed === false || $compressed === '') {
                throw new PureGitException('Unexpected end of compressed data');
            }

            $decompressed = inflate_add($context, $compressed, ZLIB_SYNC_FLUSH);
            if ($decompressed === false) {
                throw new PureGitException('Failed to decompress pack data');
            }

            $chunks[] = $decompressed;
            $totalSize += strlen($decompressed);
        } while ($totalSize < $expectedSize);

        // Seek to exact end of compressed stream
        $consumed = inflate_get_read_len($context);
        fseek($handle, $startPos + $consumed);

        return implode('', $chunks);
    }

    /**
     * @param resource $handle
     */
    private function readByte($handle): int
    {
        $byte = fread($handle, 1);
        if ($byte === false || $byte === '') {
            throw new PureGitException('Unexpected end of pack data');
        }

        return ord($byte);
    }

    /**
     * CRC32 of raw pack bytes from object start to current file position.
     *
     * @param resource $handle positioned after the object's compressed data
     */
    private function readCrc32($handle, int $objectOffset): int
    {
        $endPos = (int) ftell($handle);
        $length = $endPos - $objectOffset;
        if ($length < 1) {
            return 0;
        }

        fseek($handle, $objectOffset);
        $raw = fread($handle, $length);
        fseek($handle, $endPos);

        return crc32($raw !== false ? $raw : '');
    }

    private function computeHash(int $packType, string $data): string
    {
        $typeName = match ($packType) {
            1 => ObjectType::Commit->value,
            2 => ObjectType::Tree->value,
            3 => ObjectType::Blob->value,
            4 => ObjectType::Tag->value,
            default => throw new PureGitException(sprintf('Unknown type: %d', $packType)),
        };

        $ctx = hash_init('sha1');
        hash_update($ctx, $typeName . ' ' . strlen($data) . "\0");
        hash_update($ctx, $data);

        return hash_final($ctx);
    }

    /**
     * @param list<IndexEntry> $entries
     */
    private function writeIndex(array $entries): void
    {
        $idxPath = substr($this->packPath, 0, -5) . '.idx';

        usort($entries, fn (IndexEntry $a, IndexEntry $b): int => strcmp($a->hash, $b->hash));

        $fh = fopen($idxPath, 'w+b');
        if ($fh === false) {
            throw new PureGitException(sprintf('Cannot open index file: %s', $idxPath));
        }

        fwrite($fh, "\xfftOc");
        fwrite($fh, pack('N', 2));

        $this->writeFanout($fh, $entries);
        $this->writeHashTable($fh, $entries);
        $this->writeCrcTable($fh, $entries);
        $this->writeOffsetTable($fh, $entries);
        $this->writeChecksums($fh);

        fclose($fh);
    }

    /**
     * @param resource $fh
     */
    private function writeChecksums($fh): void
    {
        // Pack's embedded checksum = last 20 bytes of the pack file
        $packFh = fopen($this->packPath, 'rb');
        if ($packFh !== false) {
            fseek($packFh, -20, SEEK_END);
            $packChecksum = fread($packFh, 20);
            fclose($packFh);
            if ($packChecksum !== false) {
                fwrite($fh, $packChecksum);
            }
        }

        fseek($fh, 0);
        $content = stream_get_contents($fh);
        if ($content !== false) {
            fseek($fh, 0, SEEK_END);
            fwrite($fh, hash('sha1', $content, true));
        }
    }

    /**
     * @param resource $fh
     * @param list<IndexEntry> $entries
     */
    private function writeFanout($fh, array $entries): void
    {
        $fanout = array_fill(0, 256, 0);
        foreach ($entries as $entry) {
            $firstByte = intval(substr($entry->hash, 0, 2), 16);
            for ($j = $firstByte; $j < 256; $j++) {
                $fanout[$j]++;
            }
        }
        foreach ($fanout as $count) {
            fwrite($fh, pack('N', $count));
        }
    }

    /**
     * @param resource $fh
     * @param list<IndexEntry> $entries
     */
    private function writeHashTable($fh, array $entries): void
    {
        foreach ($entries as $entry) {
            $binHash = hex2bin($entry->hash);
            if ($binHash !== false) {
                fwrite($fh, $binHash);
            }
        }
    }

    /**
     * @param resource $fh
     * @param list<IndexEntry> $entries
     */
    private function writeCrcTable($fh, array $entries): void
    {
        foreach ($entries as $entry) {
            fwrite($fh, pack('N', $entry->crc32));
        }
    }

    /**
     * @param resource $fh
     * @param list<IndexEntry> $entries
     */
    private function writeOffsetTable($fh, array $entries): void
    {
        foreach ($entries as $entry) {
            fwrite($fh, pack('N', $entry->offset));
        }
    }
}

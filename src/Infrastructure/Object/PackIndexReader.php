<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Object;

use Lukasojd\PureGit\Domain\Exception\InvalidObjectException;
use Lukasojd\PureGit\Domain\Object\ObjectId;

/**
 * Lazy pack index v2 reader.
 *
 * Only the fanout table (1 KB) is kept in memory. Individual lookups
 * use binary search with fseek into the on-disk hash table, avoiding
 * the need to load the entire index (24 MB+ for large repos).
 */
final class PackIndexReader
{
    private const int HEADER_SIZE = 8;

    private const int FANOUT_SIZE = 1024;

    private const int HASH_SIZE = 20;

    private const int OFFSET_SIZE = 4;

    private const int CRC_SIZE = 4;

    private const int HASH_MAP_THRESHOLD = 500;

    /**
     * @var array<int, int> 256-entry fanout table (1-indexed from unpack)
     */
    private array $fanout = [];

    private int $totalObjects = 0;

    private int $hashesOffset = 0;

    private int $offsetsOffset = 0;

    private int $largeOffsetsOffset = 0;

    /**
     * In-memory large offset table (8-byte big-endian entries for offsets > 2GB).
     */
    private ?string $largeOffsetTable = null;

    /**
     * @var resource|null
     */
    private $fh;

    private bool $initialized = false;

    /**
     * In-memory hash table (all hashes concatenated, 20 bytes each).
     * Loaded lazily on first lookup for fast binary search without fseek.
     */
    private ?string $hashTable = null;

    /**
     * In-memory offset table (all 4-byte big-endian offsets concatenated).
     */
    private ?string $offsetTable = null;

    /**
     * @var array<int, string>|null offset → hex hash (built lazily for delta reuse)
     */
    private ?array $offsetToHash = null;

    private int $lookupCount = 0;

    /**
     * @var array<string, int>|null binHash → pack file offset (built after HASH_MAP_THRESHOLD lookups)
     */
    private ?array $hashMap = null;

    public function __construct(
        private readonly string $indexPath,
    ) {
    }

    public function __destruct()
    {
        if ($this->fh !== null) {
            fclose($this->fh);
        }
    }

    public function findOffset(ObjectId $id): ?int
    {
        return $this->findOffsetByBinary($id->toBinary());
    }

    /**
     * Find pack offset by raw 20-byte binary hash. Avoids ObjectId creation overhead.
     *
     * @param string $binHash 20-byte raw hash
     */
    public function findOffsetByBinary(string $binHash): ?int
    {
        // Fast path: hash map stores binHash → offset directly
        $hashMap = $this->hashMap;
        if ($hashMap !== null) {
            return $hashMap[$binHash] ?? null;
        }

        $this->ensureInitialized();

        $position = $this->binarySearchHash($binHash);

        // binarySearchHash may have built the hash map at threshold — recheck
        if ($this->hashMap !== null) {
            return $this->hashMap[$binHash] ?? null;
        }

        if ($position === null) {
            return null;
        }

        return $this->readOffsetAt($position);
    }

    public function hasObject(ObjectId $id): bool
    {
        if ($this->hashMap !== null) {
            return isset($this->hashMap[$id->toBinary()]);
        }

        $this->ensureInitialized();

        $binHash = $id->toBinary();
        if ($this->binarySearchHash($binHash) !== null) {
            return true;
        }

        // binarySearchHash may have built the hash map at threshold — recheck
        if ($this->hashMap !== null) {
            return isset($this->hashMap[$binHash]);
        }

        return false;
    }

    /**
     * @return list<ObjectId>
     */
    public function getAllIds(): array
    {
        $this->ensureInitialized();
        $this->ensureTables();

        if ($this->hashTable === null || $this->hashTable === '') {
            return [];
        }

        $ids = [];
        for ($i = 0; $i < $this->totalObjects; $i++) {
            $ids[] = ObjectId::fromBinary(substr($this->hashTable, $i * self::HASH_SIZE, self::HASH_SIZE));
        }

        return $ids;
    }

    /**
     * Reverse lookup: find the object stored at a given pack file offset.
     * Builds the full offset→hash map on first call (used for delta reuse).
     */
    public function findObjectAtOffset(int $offset): ?ObjectId
    {
        $this->ensureInitialized();
        $this->ensureOffsetMap();

        $hash = $this->offsetToHash[$offset] ?? null;

        return $hash !== null ? ObjectId::fromHex($hash) : null;
    }

    private function ensureOffsetMap(): void
    {
        if ($this->offsetToHash !== null) {
            return;
        }

        $this->ensureTables();

        if ($this->totalObjects === 0 || $this->hashTable === null || $this->offsetTable === null) {
            $this->offsetToHash = [];

            return;
        }

        $map = [];
        for ($i = 0; $i < $this->totalObjects; $i++) {
            $hash = substr($this->hashTable, $i * self::HASH_SIZE, self::HASH_SIZE);
            /** @var array{o: int} $off */
            $off = unpack('No', $this->offsetTable, $i * self::OFFSET_SIZE);
            $map[$this->resolveOffset($off['o'])] = bin2hex($hash);
        }

        $this->offsetToHash = $map;
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        $fh = fopen($this->indexPath, 'rb');
        if ($fh === false) {
            throw new InvalidObjectException(sprintf('Cannot read pack index: %s', $this->indexPath));
        }

        $header = fread($fh, self::HEADER_SIZE);
        if ($header === false || strlen($header) < self::HEADER_SIZE) {
            fclose($fh);
            throw new InvalidObjectException('Pack index too short');
        }

        $this->validateHeader($header, $fh);

        $fanoutData = fread($fh, self::FANOUT_SIZE);
        if ($fanoutData === false || strlen($fanoutData) < self::FANOUT_SIZE) {
            fclose($fh);
            throw new InvalidObjectException('Pack index fanout truncated');
        }

        /** @var array<int, int> $fanout */
        $fanout = unpack('N256', $fanoutData);
        $this->fanout = $fanout;
        $this->totalObjects = $fanout[256];
        $this->hashesOffset = self::HEADER_SIZE + self::FANOUT_SIZE;
        $this->offsetsOffset = $this->hashesOffset
            + ($this->totalObjects * self::HASH_SIZE)
            + ($this->totalObjects * self::CRC_SIZE);
        $this->largeOffsetsOffset = $this->offsetsOffset
            + ($this->totalObjects * self::OFFSET_SIZE);

        $this->fh = $fh;
        $this->initialized = true;
    }

    /**
     * @param resource $fh
     */
    private function validateHeader(string $header, $fh): void
    {
        if (! str_starts_with($header, "\xfftOc")) {
            fclose($fh);
            throw new InvalidObjectException('Invalid pack index magic');
        }

        /** @var array{v: int} $unpacked */
        $unpacked = unpack('Nv', $header, 4);
        if ($unpacked['v'] !== 2) {
            fclose($fh);
            throw new InvalidObjectException(sprintf('Unsupported pack index version: %d', $unpacked['v']));
        }
    }

    private function ensureTables(): void
    {
        if ($this->hashTable !== null) {
            return;
        }

        $fh = $this->fh;
        if ($fh === null || $this->totalObjects === 0) {
            $this->hashTable = '';
            $this->offsetTable = '';
            $this->largeOffsetTable = '';

            return;
        }

        $hashBytes = max(1, $this->totalObjects * self::HASH_SIZE);
        $offsetBytes = max(1, $this->totalObjects * self::OFFSET_SIZE);

        fseek($fh, $this->hashesOffset);
        $this->hashTable = (string) fread($fh, $hashBytes);

        fseek($fh, $this->offsetsOffset);
        $this->offsetTable = (string) fread($fh, $offsetBytes);

        // Large offset table starts right after the 4-byte offset table.
        // Read remaining bytes before the trailing checksums (40 bytes = 2x SHA-1).
        $this->loadLargeOffsetTable($fh);
    }

    /**
     * @param resource $fh
     */
    private function loadLargeOffsetTable($fh): void
    {
        $this->largeOffsetsOffset = $this->offsetsOffset + ($this->totalObjects * self::OFFSET_SIZE);
        $stat = fstat($fh);
        if ($stat === false) {
            $this->largeOffsetTable = '';

            return;
        }

        // File ends with: large_offsets + pack_checksum(20) + index_checksum(20)
        $largeOffsetBytes = $stat['size'] - $this->largeOffsetsOffset - 40;
        if ($largeOffsetBytes <= 0) {
            $this->largeOffsetTable = '';

            return;
        }

        fseek($fh, $this->largeOffsetsOffset);
        $this->largeOffsetTable = (string) fread($fh, $largeOffsetBytes);
    }

    /**
     * Binary search with auto-escalation to hash map.
     *
     * Returns position index (for readOffsetAt) or null. After threshold,
     * builds hash map and returns null — caller rechecks hashMap directly.
     *
     * @param string $binHash 20-byte raw hash
     * @return int|null position index if found, null otherwise
     */
    private function binarySearchHash(string $binHash): ?int
    {
        $this->ensureTables();

        $hashTable = $this->hashTable;
        if ($hashTable === null || $hashTable === '') {
            return null;
        }

        if (++$this->lookupCount >= self::HASH_MAP_THRESHOLD) {
            $this->buildHashMap();

            // Caller (findOffset/hasObject) rechecks $this->hashMap
            return null;
        }

        $firstByte = ord($binHash[0]);
        $lo = $firstByte === 0 ? 0 : $this->fanout[$firstByte];
        $hi = $this->fanout[$firstByte + 1] - 1;

        while ($lo <= $hi) {
            $mid = $lo + (int) (($hi - $lo) / 2);
            $candidate = substr($hashTable, $mid * self::HASH_SIZE, self::HASH_SIZE);

            $cmp = strcmp($binHash, $candidate);
            if ($cmp === 0) {
                return $mid;
            }

            $cmp < 0 ? $hi = $mid - 1 : $lo = $mid + 1;
        }

        return null;
    }

    private function buildHashMap(): void
    {
        $hashTable = $this->hashTable;
        $offsetTable = $this->offsetTable;
        if ($hashTable === null || $hashTable === '' || $offsetTable === null || $offsetTable === '') {
            $this->hashMap = [];

            return;
        }

        $map = [];
        for ($i = 0; $i < $this->totalObjects; $i++) {
            /** @var array{o: int} $off */
            $off = unpack('No', $offsetTable, $i * self::OFFSET_SIZE);
            $map[substr($hashTable, $i * self::HASH_SIZE, self::HASH_SIZE)] = $this->resolveOffset($off['o']);
        }

        $this->hashMap = $map;
    }

    private function readOffsetAt(int $position): int
    {
        $this->ensureTables();

        if ($this->offsetTable === null || $this->offsetTable === '') {
            return 0;
        }

        /** @var array{o: int} $off */
        $off = unpack('No', $this->offsetTable, $position * self::OFFSET_SIZE);

        return $this->resolveOffset($off['o']);
    }

    /**
     * Resolve a 4-byte pack index offset. If MSB is set, the lower 31 bits
     * are an index into the large offset table (8-byte entries for packs > 2GB).
     */
    private function resolveOffset(int $rawOffset): int
    {
        if (($rawOffset & 0x80000000) === 0) {
            return $rawOffset;
        }

        $largeIndex = $rawOffset & 0x7FFFFFFF;

        if ($this->largeOffsetTable === null || $this->largeOffsetTable === '') {
            return $rawOffset;
        }

        /** @var array{hi: int, lo: int} $parts */
        $parts = unpack('Nhi/Nlo', $this->largeOffsetTable, $largeIndex * 8);

        return ($parts['hi'] << 32) | $parts['lo'];
    }
}

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

    /**
     * @var array<int, int> 256-entry fanout table (1-indexed from unpack)
     */
    private array $fanout = [];

    private int $totalObjects = 0;

    private int $hashesOffset = 0;

    private int $offsetsOffset = 0;

    /**
     * @var resource|null
     */
    private $fh;

    private bool $initialized = false;

    /**
     * @var array<int, string>|null offset → hex hash (built lazily for delta reuse)
     */
    private ?array $offsetToHash = null;

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
        $this->ensureInitialized();

        $position = $this->binarySearchHash($id->toBinary());
        if ($position === null) {
            return null;
        }

        return $this->readOffsetAt($position);
    }

    public function hasObject(ObjectId $id): bool
    {
        $this->ensureInitialized();

        return $this->binarySearchHash($id->toBinary()) !== null;
    }

    /**
     * @return list<ObjectId>
     */
    public function getAllIds(): array
    {
        $this->ensureInitialized();

        $ids = [];
        $fh = $this->fh;
        if ($fh === null) {
            return [];
        }

        for ($i = 0; $i < $this->totalObjects; $i++) {
            fseek($fh, $this->hashesOffset + ($i * self::HASH_SIZE));
            $raw = fread($fh, self::HASH_SIZE);
            if ($raw === false || strlen($raw) < self::HASH_SIZE) {
                break;
            }
            $ids[] = ObjectId::fromBinary($raw);
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

        $fh = $this->fh;
        if ($fh === null) {
            $this->offsetToHash = [];
            return;
        }

        if ($this->totalObjects === 0) {
            $this->offsetToHash = [];
            return;
        }

        // Sequential pass 1: read all hashes
        $hashBytes = max(1, $this->totalObjects * self::HASH_SIZE);
        fseek($fh, $this->hashesOffset);
        $hashData = fread($fh, $hashBytes);
        if ($hashData === false) {
            $this->offsetToHash = [];
            return;
        }

        // Sequential pass 2: read all offsets
        $offsetBytes = max(1, $this->totalObjects * self::OFFSET_SIZE);
        fseek($fh, $this->offsetsOffset);
        $offsetData = fread($fh, $offsetBytes);
        if ($offsetData === false) {
            $this->offsetToHash = [];
            return;
        }

        // Build map from bulk reads
        $map = [];
        for ($i = 0; $i < $this->totalObjects; $i++) {
            $hash = substr($hashData, $i * self::HASH_SIZE, self::HASH_SIZE);
            /** @var array{o: int} $off */
            $off = unpack('No', $offsetData, $i * self::OFFSET_SIZE);
            $map[$off['o']] = bin2hex($hash);
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

    /**
     * Binary search in the on-disk hash table using the fanout for range bounds.
     *
     * @param string $binHash 20-byte raw hash
     * @return int|null position index if found, null otherwise
     */
    private function binarySearchHash(string $binHash): ?int
    {
        $fh = $this->fh;
        if ($fh === null) {
            return null;
        }

        [$lo, $hi] = $this->fanoutRange(ord($binHash[0]));

        while ($lo <= $hi) {
            $mid = $lo + (int) (($hi - $lo) / 2);
            fseek($fh, $this->hashesOffset + ($mid * self::HASH_SIZE));
            $candidate = fread($fh, self::HASH_SIZE);
            if ($candidate === false) {
                return null;
            }

            $cmp = strcmp($binHash, $candidate);
            if ($cmp === 0) {
                return $mid;
            }

            $cmp < 0 ? $hi = $mid - 1 : $lo = $mid + 1;
        }

        return null;
    }

    /**
     * @return array{int, int} [lo, hi] index range from the fanout table
     */
    private function fanoutRange(int $firstByte): array
    {
        $lo = $firstByte === 0 ? 0 : $this->fanout[$firstByte];
        $hi = $this->fanout[$firstByte + 1] - 1;

        return [$lo, $hi];
    }

    private function readOffsetAt(int $position): int
    {
        $fh = $this->fh;
        if ($fh === null) {
            return 0;
        }

        fseek($fh, $this->offsetsOffset + ($position * self::OFFSET_SIZE));
        $data = fread($fh, self::OFFSET_SIZE);
        if ($data === false || strlen($data) < self::OFFSET_SIZE) {
            return 0;
        }

        /** @var array{o: int} $off */
        $off = unpack('No', $data);

        return $off['o'];
    }
}

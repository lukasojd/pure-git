<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\CommitGraph;

use Lukasojd\PureGit\Domain\CommitGraph\CommitGraphInterface;
use Lukasojd\PureGit\Domain\Exception\InvalidObjectException;
use Lukasojd\PureGit\Domain\Object\ObjectId;

final class CommitGraphReader implements CommitGraphInterface
{
    private const string MAGIC = 'PCGR';

    private const int VERSION = 1;

    private const int HEADER_SIZE = 16;

    private const int FANOUT_SIZE = 1024;

    private const int OID_SIZE = 20;

    private const int COMMIT_DATA_SIZE = 36;

    private const int CHECKSUM_SIZE = 20;

    private const int NO_PARENT = 0xFFFFFFFF;

    private const int EXTRA_PARENTS_MARKER = 0xFFFFFFFE;

    private const int SENTINEL_BIT = 0x80000000;

    private bool $initialized = false;

    private string $data = '';

    private int $numCommits = 0;

    private int $extraParentsOffset = 0;

    /**
     * @var array<int, int>
     */
    private array $fanout = [];

    private int $oidTableOffset = 0;

    private int $commitDataOffset = 0;

    private int $extraParentsChunkOffset = 0;

    public function __construct(
        private readonly string $graphPath,
    ) {
    }

    public function hasCommit(ObjectId $id): bool
    {
        $this->ensureInitialized();

        return $this->findIndex($id->toBinary()) !== null;
    }

    /**
     * @return list<ObjectId>
     */
    public function getParents(ObjectId $id): array
    {
        $this->ensureInitialized();

        $index = $this->findIndex($id->toBinary());
        if ($index === null) {
            throw new InvalidObjectException(sprintf('Commit %s not found in commit-graph', $id->hash));
        }

        return $this->readParents($index);
    }

    public function getGeneration(ObjectId $id): int
    {
        $this->ensureInitialized();

        $index = $this->findIndex($id->toBinary());
        if ($index === null) {
            throw new InvalidObjectException(sprintf('Commit %s not found in commit-graph', $id->hash));
        }

        $offset = $this->commitDataOffset + ($index * self::COMMIT_DATA_SIZE);

        /** @var array{g: int} $unpacked */
        $unpacked = unpack('Ng', $this->data, $offset + 16);

        return $unpacked['g'];
    }

    public function getTimestamp(ObjectId $id): int
    {
        $this->ensureInitialized();

        $index = $this->findIndex($id->toBinary());
        if ($index === null) {
            throw new InvalidObjectException(sprintf('Commit %s not found in commit-graph', $id->hash));
        }

        $offset = $this->commitDataOffset + ($index * self::COMMIT_DATA_SIZE);

        /** @var array{t: int} $unpacked */
        $unpacked = unpack('Nt', $this->data, $offset + 20);

        return $unpacked['t'];
    }

    public function getCommitCount(): int
    {
        $this->ensureInitialized();

        return $this->numCommits;
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        $data = file_get_contents($this->graphPath);
        if ($data === false) {
            throw new InvalidObjectException(sprintf('Cannot read commit-graph: %s', $this->graphPath));
        }

        $fileSize = strlen($data);
        if ($fileSize < self::HEADER_SIZE + self::FANOUT_SIZE + self::CHECKSUM_SIZE) {
            throw new InvalidObjectException('Commit-graph file too short');
        }

        // Validate checksum
        $storedChecksum = substr($data, -self::CHECKSUM_SIZE);
        $computedChecksum = sha1(substr($data, 0, -self::CHECKSUM_SIZE), true);
        if ($storedChecksum !== $computedChecksum) {
            throw new InvalidObjectException('Commit-graph checksum mismatch');
        }

        // Parse header
        $magic = substr($data, 0, 4);
        if ($magic !== self::MAGIC) {
            throw new InvalidObjectException(sprintf('Invalid commit-graph magic: %s', bin2hex($magic)));
        }

        /** @var array{v: int, n: int, e: int} $header */
        $header = unpack('Nv/Nn/Ne', $data, 4);
        if ($header['v'] !== self::VERSION) {
            throw new InvalidObjectException(sprintf('Unsupported commit-graph version: %d', $header['v']));
        }

        $this->numCommits = $header['n'];
        $this->extraParentsOffset = $header['e'];

        // Parse fanout
        /** @var array<int, int> $fanout */
        $fanout = unpack('N256', $data, self::HEADER_SIZE);
        $this->fanout = $fanout;

        // Validate commit count matches fanout
        if ($this->fanout[256] !== $this->numCommits) {
            throw new InvalidObjectException('Commit-graph fanout mismatch');
        }

        $this->oidTableOffset = self::HEADER_SIZE + self::FANOUT_SIZE;
        $this->commitDataOffset = $this->oidTableOffset + ($this->numCommits * self::OID_SIZE);
        $this->extraParentsChunkOffset = $this->extraParentsOffset;

        $this->data = $data;
        $this->initialized = true;
    }

    private function findIndex(string $binHash): ?int
    {
        $firstByte = ord($binHash[0]);

        $lo = $firstByte === 0 ? 0 : $this->fanout[$firstByte];
        $hi = $this->fanout[$firstByte + 1] - 1;

        while ($lo <= $hi) {
            $mid = $lo + (int) (($hi - $lo) / 2);
            $candidate = substr($this->data, $this->oidTableOffset + ($mid * self::OID_SIZE), self::OID_SIZE);

            $cmp = strcmp($binHash, $candidate);
            if ($cmp === 0) {
                return $mid;
            }

            $cmp < 0 ? $hi = $mid - 1 : $lo = $mid + 1;
        }

        return null;
    }

    /**
     * @return list<ObjectId>
     */
    private function readParents(int $index): array
    {
        $offset = $this->commitDataOffset + ($index * self::COMMIT_DATA_SIZE);

        /** @var array{p1: int, p2: int, eo: int} $unpacked */
        $unpacked = unpack('Np1/Np2/Neo', $this->data, $offset + 4);

        $parents = [];

        if ($unpacked['p1'] !== self::NO_PARENT) {
            $parents[] = $this->oidAtIndex($unpacked['p1']);
        }

        if ($unpacked['p2'] === self::NO_PARENT) {
            return $parents;
        }

        if ($unpacked['p2'] !== self::EXTRA_PARENTS_MARKER) {
            $parents[] = $this->oidAtIndex($unpacked['p2']);

            return $parents;
        }

        // Read extra parents
        $extraOffset = $this->extraParentsChunkOffset + $unpacked['eo'];

        for ($i = 0; $i < $this->numCommits; $i++) {
            /** @var array{v: int} $entry */
            $entry = unpack('Nv', $this->data, $extraOffset + ($i * 4));
            $value = $entry['v'];

            $isSentinel = ($value & self::SENTINEL_BIT) !== 0;
            $parentIndex = $value & 0x7FFFFFFF;

            $parents[] = $this->oidAtIndex($parentIndex);

            if ($isSentinel) {
                break;
            }
        }

        return $parents;
    }

    private function oidAtIndex(int $index): ObjectId
    {
        $bin = substr($this->data, $this->oidTableOffset + ($index * self::OID_SIZE), self::OID_SIZE);

        return ObjectId::fromBinary($bin);
    }
}

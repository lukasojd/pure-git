<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Object;

use Lukasojd\PureGit\Domain\Exception\InvalidObjectException;
use Lukasojd\PureGit\Domain\Object\ObjectId;

final class PackIndexReader
{
    /**
     * @var array<string, int> object hash (hex) => pack offset
     */
    private array $entries = [];

    private bool $loaded = false;

    public function __construct(
        private readonly string $indexPath,
    ) {
    }

    public function findOffset(ObjectId $id): ?int
    {
        $this->ensureLoaded();

        return $this->entries[$id->hash] ?? null;
    }

    public function hasObject(ObjectId $id): bool
    {
        $this->ensureLoaded();

        return isset($this->entries[$id->hash]);
    }

    /**
     * @return list<ObjectId>
     */
    public function getAllIds(): array
    {
        $this->ensureLoaded();

        return array_map(ObjectId::fromHex(...), array_keys($this->entries));
    }

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        $data = file_get_contents($this->indexPath);
        if ($data === false) {
            throw new InvalidObjectException(sprintf('Cannot read pack index: %s', $this->indexPath));
        }

        $this->parseIndex($data);
        $this->loaded = true;
    }

    private function parseIndex(string $data): void
    {
        $magic = substr($data, 0, 4);
        if ($magic !== "\xfftOc") {
            throw new InvalidObjectException('Invalid pack index magic');
        }

        /** @var array{v: int} $unpacked */
        $unpacked = unpack('Nv', $data, 4);
        if ($unpacked['v'] !== 2) {
            throw new InvalidObjectException(sprintf('Unsupported pack index version: %d', $unpacked['v']));
        }

        // Fanout: 256 x uint32 starting at offset 8
        /** @var array<int, int> $fanout */
        $fanout = unpack('N256', $data, 8);
        $totalObjects = $fanout[256];

        // Hashes start at offset 8 + 1024
        $hashesOffset = 1032;
        // CRC32 starts after hashes
        $crcOffset = $hashesOffset + $totalObjects * 20;
        // Offsets start after CRC32
        $offsetsOffset = $crcOffset + $totalObjects * 4;

        for ($i = 0; $i < $totalObjects; $i++) {
            $hash = bin2hex(substr($data, $hashesOffset + ($i * 20), 20));
            /** @var array{o: int} $off */
            $off = unpack('No', $data, $offsetsOffset + ($i * 4));
            $this->entries[$hash] = $off['o'];
        }
    }
}

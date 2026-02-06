<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Cache;

use Lukasojd\PureGit\Domain\Object\GitObject;
use Lukasojd\PureGit\Domain\Object\ObjectId;

final class ObjectCache
{
    /**
     * @var array<string, GitObject>
     */
    private array $cache = [];

    /**
     * @var array<string, int> hash => access counter for LRU
     */
    private array $accessOrder = [];

    private int $size = 0;

    private int $accessCounter = 0;

    public function __construct(
        private readonly int $maxSize = 4096,
    ) {
    }

    public function get(ObjectId $id): ?GitObject
    {
        $hash = $id->hash;
        if (! isset($this->cache[$hash])) {
            return null;
        }

        $this->accessOrder[$hash] = ++$this->accessCounter;

        return $this->cache[$hash];
    }

    public function set(ObjectId $id, GitObject $object): void
    {
        $hash = $id->hash;

        if ($this->size >= $this->maxSize && ! isset($this->cache[$hash])) {
            $this->evict();
        }

        if (! isset($this->cache[$hash])) {
            $this->size++;
        }

        $this->cache[$hash] = $object;
        $this->accessOrder[$hash] = ++$this->accessCounter;
    }

    public function has(ObjectId $id): bool
    {
        return isset($this->cache[$id->hash]);
    }

    public function clear(): void
    {
        $this->cache = [];
        $this->accessOrder = [];
        $this->size = 0;
        $this->accessCounter = 0;
    }

    private function evict(): void
    {
        asort($this->accessOrder);
        $removeCount = (int) ($this->maxSize * 0.25);
        $removed = 0;

        foreach (array_keys($this->accessOrder) as $hash) {
            if ($removed >= $removeCount) {
                break;
            }
            unset($this->cache[$hash], $this->accessOrder[$hash]);
            $this->size--;
            $removed++;
        }
    }
}

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

    private int $size = 0;

    public function __construct(
        private readonly int $maxSize = 1024,
    ) {
    }

    public function get(ObjectId $id): ?GitObject
    {
        return $this->cache[$id->hash] ?? null;
    }

    public function set(ObjectId $id, GitObject $object): void
    {
        if ($this->size >= $this->maxSize && ! isset($this->cache[$id->hash])) {
            $this->evict();
        }

        if (! isset($this->cache[$id->hash])) {
            $this->size++;
        }

        $this->cache[$id->hash] = $object;
    }

    public function has(ObjectId $id): bool
    {
        return isset($this->cache[$id->hash]);
    }

    public function clear(): void
    {
        $this->cache = [];
        $this->size = 0;
    }

    private function evict(): void
    {
        $keys = array_keys($this->cache);
        $removeCount = (int) ($this->maxSize * 0.25);

        for ($i = 0; $i < $removeCount && $i < count($keys); $i++) {
            unset($this->cache[$keys[$i]]);
            $this->size--;
        }
    }
}

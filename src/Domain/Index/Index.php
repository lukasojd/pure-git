<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Index;

final class Index
{
    /**
     * @param array<string, IndexEntry> $entries keyed by path
     */
    public function __construct(
        private array $entries = [],
    ) {
    }

    public function addEntry(IndexEntry $entry): void
    {
        $this->entries[$entry->path] = $entry;
    }

    public function removeEntry(string $path): void
    {
        unset($this->entries[$path]);
    }

    public function getEntry(string $path): ?IndexEntry
    {
        return $this->entries[$path] ?? null;
    }

    public function hasEntry(string $path): bool
    {
        return isset($this->entries[$path]);
    }

    /**
     * @return array<string, IndexEntry>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /**
     * @return list<IndexEntry>
     */
    public function getSortedEntries(): array
    {
        $sorted = $this->entries;
        ksort($sorted);

        return array_values($sorted);
    }

    public function count(): int
    {
        return count($this->entries);
    }
}

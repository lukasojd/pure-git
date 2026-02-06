<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Domain\Index;

use Lukasojd\PureGit\Domain\Index\Index;
use Lukasojd\PureGit\Domain\Index\IndexEntry;
use Lukasojd\PureGit\Domain\Object\FileMode;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use PHPUnit\Framework\TestCase;

final class IndexTest extends TestCase
{
    public function testEmptyIndex(): void
    {
        $index = new Index();
        self::assertSame(0, $index->count());
        self::assertSame([], $index->getEntries());
    }

    public function testAddAndGetEntry(): void
    {
        $index = new Index();
        $objectId = ObjectId::fromHex('da39a3ee5e6b4b0d3255bfef95601890afd80709');
        $entry = IndexEntry::create('test.txt', $objectId, FileMode::Regular, 100);

        $index->addEntry($entry);

        self::assertTrue($index->hasEntry('test.txt'));
        self::assertSame($entry, $index->getEntry('test.txt'));
        self::assertSame(1, $index->count());
    }

    public function testRemoveEntry(): void
    {
        $index = new Index();
        $objectId = ObjectId::fromHex('da39a3ee5e6b4b0d3255bfef95601890afd80709');
        $entry = IndexEntry::create('test.txt', $objectId, FileMode::Regular, 100);

        $index->addEntry($entry);
        $index->removeEntry('test.txt');

        self::assertFalse($index->hasEntry('test.txt'));
        self::assertNull($index->getEntry('test.txt'));
        self::assertSame(0, $index->count());
    }

    public function testGetSortedEntries(): void
    {
        $index = new Index();
        $objectId = ObjectId::fromHex('da39a3ee5e6b4b0d3255bfef95601890afd80709');

        $index->addEntry(IndexEntry::create('c.txt', $objectId, FileMode::Regular, 10));
        $index->addEntry(IndexEntry::create('a.txt', $objectId, FileMode::Regular, 10));
        $index->addEntry(IndexEntry::create('b.txt', $objectId, FileMode::Regular, 10));

        $sorted = $index->getSortedEntries();
        self::assertSame('a.txt', $sorted[0]->path);
        self::assertSame('b.txt', $sorted[1]->path);
        self::assertSame('c.txt', $sorted[2]->path);
    }
}

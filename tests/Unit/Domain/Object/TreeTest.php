<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Domain\Object;

use Lukasojd\PureGit\Domain\Object\FileMode;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\ObjectType;
use Lukasojd\PureGit\Domain\Object\Tree;
use Lukasojd\PureGit\Domain\Object\TreeEntry;
use PHPUnit\Framework\TestCase;

final class TreeTest extends TestCase
{
    public function testCreateTree(): void
    {
        $entry = new TreeEntry(
            FileMode::Regular,
            'test.txt',
            ObjectId::fromHex('da39a3ee5e6b4b0d3255bfef95601890afd80709'),
        );
        $tree = new Tree([$entry]);

        self::assertSame(ObjectType::Tree, $tree->getType());
        self::assertCount(1, $tree->entries);
    }

    public function testSerializeAndDeserialize(): void
    {
        $entry = new TreeEntry(
            FileMode::Regular,
            'hello.txt',
            ObjectId::fromHex('da39a3ee5e6b4b0d3255bfef95601890afd80709'),
        );
        $tree = new Tree([$entry]);

        $serialized = $tree->serialize();
        $restored = Tree::fromSerialized($serialized);

        self::assertCount(1, $restored->entries);
        self::assertSame('hello.txt', $restored->entries[0]->name);
        self::assertSame(FileMode::Regular, $restored->entries[0]->mode);
        self::assertTrue($restored->entries[0]->objectId->equals($entry->objectId));
    }

    public function testFindEntry(): void
    {
        $entry1 = new TreeEntry(FileMode::Regular, 'a.txt', ObjectId::fromHex('da39a3ee5e6b4b0d3255bfef95601890afd80709'));
        $entry2 = new TreeEntry(FileMode::Regular, 'b.txt', ObjectId::fromHex('0000000000000000000000000000000000000000'));
        $tree = new Tree([$entry1, $entry2]);

        $found = $tree->findEntry('b.txt');
        self::assertNotNull($found);
        self::assertSame('b.txt', $found->name);

        self::assertNull($tree->findEntry('nonexistent.txt'));
    }

    public function testTreeEntryTypes(): void
    {
        $fileEntry = new TreeEntry(FileMode::Regular, 'file.txt', ObjectId::fromHex('da39a3ee5e6b4b0d3255bfef95601890afd80709'));
        $dirEntry = new TreeEntry(FileMode::Directory, 'subdir', ObjectId::fromHex('da39a3ee5e6b4b0d3255bfef95601890afd80709'));

        self::assertTrue($fileEntry->isBlob());
        self::assertFalse($fileEntry->isTree());
        self::assertTrue($dirEntry->isTree());
        self::assertFalse($dirEntry->isBlob());
    }
}

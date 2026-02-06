<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Domain\Object;

use DateTimeImmutable;
use DateTimeZone;
use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\ObjectType;
use Lukasojd\PureGit\Domain\Object\PersonInfo;
use PHPUnit\Framework\TestCase;

final class CommitTest extends TestCase
{
    public function testCreateCommit(): void
    {
        $treeId = ObjectId::fromHex('da39a3ee5e6b4b0d3255bfef95601890afd80709');
        $author = new PersonInfo('Test User', 'test@example.com', new DateTimeImmutable('2024-01-01 00:00:00', new DateTimeZone('+0000')));

        $commit = new Commit($treeId, [], $author, $author, 'Initial commit');

        self::assertSame(ObjectType::Commit, $commit->getType());
        self::assertSame('Initial commit', $commit->message);
        self::assertTrue($commit->isRoot());
        self::assertSame([], $commit->parents);
    }

    public function testSerializeAndDeserialize(): void
    {
        $treeId = ObjectId::fromHex('da39a3ee5e6b4b0d3255bfef95601890afd80709');
        $parentId = ObjectId::fromHex('0000000000000000000000000000000000000000');
        $author = new PersonInfo('Author', 'author@test.com', new DateTimeImmutable('@1704067200', new DateTimeZone('+0000')));
        $committer = new PersonInfo('Committer', 'committer@test.com', new DateTimeImmutable('@1704067200', new DateTimeZone('+0000')));

        $commit = new Commit($treeId, [$parentId], $author, $committer, 'Test message');
        $serialized = $commit->serialize();
        $restored = Commit::fromSerialized($serialized);

        self::assertTrue($restored->treeId->equals($treeId));
        self::assertCount(1, $restored->parents);
        self::assertTrue($restored->parents[0]->equals($parentId));
        self::assertSame('Author', $restored->author->name);
        self::assertSame('Committer', $restored->committer->name);
        self::assertSame('Test message', $restored->message);
        self::assertFalse($restored->isRoot());
    }

    public function testContentAddressedId(): void
    {
        $treeId = ObjectId::fromHex('da39a3ee5e6b4b0d3255bfef95601890afd80709');
        $author = new PersonInfo('Test', 'test@test.com', new DateTimeImmutable('@1704067200', new DateTimeZone('+0000')));

        $a = new Commit($treeId, [], $author, $author, 'msg');
        $b = new Commit($treeId, [], $author, $author, 'msg');

        self::assertTrue($a->getId()->equals($b->getId()));
    }
}

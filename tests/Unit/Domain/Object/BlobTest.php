<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Domain\Object;

use Lukasojd\PureGit\Domain\Object\Blob;
use Lukasojd\PureGit\Domain\Object\ObjectType;
use PHPUnit\Framework\TestCase;

final class BlobTest extends TestCase
{
    public function testCreateBlob(): void
    {
        $blob = new Blob('hello world');
        self::assertSame('hello world', $blob->content);
        self::assertSame(ObjectType::Blob, $blob->getType());
        self::assertSame(11, $blob->getSize());
    }

    public function testSerialize(): void
    {
        $blob = new Blob('hello world');
        self::assertSame('hello world', $blob->serialize());
    }

    public function testFromSerialized(): void
    {
        $blob = Blob::fromSerialized('test content');
        self::assertSame('test content', $blob->content);
    }

    public function testIdIsContentAddressed(): void
    {
        $a = new Blob('same content');
        $b = new Blob('same content');
        $c = new Blob('different content');

        self::assertTrue($a->getId()->equals($b->getId()));
        self::assertFalse($a->getId()->equals($c->getId()));
    }

    public function testEmptyBlob(): void
    {
        $blob = new Blob('');
        self::assertSame(0, $blob->getSize());
        self::assertSame('e69de29bb2d1d6434b8b29ae775ad8c2e48c5391', $blob->getId()->hash);
    }
}

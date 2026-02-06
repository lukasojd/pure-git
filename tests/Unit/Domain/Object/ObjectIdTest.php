<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Domain\Object;

use Lukasojd\PureGit\Domain\Exception\InvalidObjectException;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\ObjectType;
use PHPUnit\Framework\TestCase;

final class ObjectIdTest extends TestCase
{
    public function testFromHexValid(): void
    {
        $id = ObjectId::fromHex('da39a3ee5e6b4b0d3255bfef95601890afd80709');
        self::assertSame('da39a3ee5e6b4b0d3255bfef95601890afd80709', $id->hash);
    }

    public function testFromHexInvalid(): void
    {
        $this->expectException(InvalidObjectException::class);
        ObjectId::fromHex('invalid');
    }

    public function testFromHexUpperCase(): void
    {
        $id = ObjectId::fromHex('DA39A3EE5E6B4B0D3255BFEF95601890AFD80709');
        self::assertSame('da39a3ee5e6b4b0d3255bfef95601890afd80709', $id->hash);
    }

    public function testFromBinary(): void
    {
        $binary = hex2bin('da39a3ee5e6b4b0d3255bfef95601890afd80709');
        self::assertIsString($binary);
        $id = ObjectId::fromBinary($binary);
        self::assertSame('da39a3ee5e6b4b0d3255bfef95601890afd80709', $id->hash);
    }

    public function testFromBinaryInvalid(): void
    {
        $this->expectException(InvalidObjectException::class);
        ObjectId::fromBinary('too-short');
    }

    public function testHash(): void
    {
        // SHA1 of "blob 0\0" is the empty blob hash
        $id = ObjectId::hash('', ObjectType::Blob);
        self::assertSame('e69de29bb2d1d6434b8b29ae775ad8c2e48c5391', $id->hash);
    }

    public function testToBinary(): void
    {
        $id = ObjectId::fromHex('da39a3ee5e6b4b0d3255bfef95601890afd80709');
        $binary = $id->toBinary();
        self::assertSame(20, strlen($binary));
        self::assertSame('da39a3ee5e6b4b0d3255bfef95601890afd80709', bin2hex($binary));
    }

    public function testPrefix(): void
    {
        $id = ObjectId::fromHex('da39a3ee5e6b4b0d3255bfef95601890afd80709');
        self::assertSame('da', $id->prefix());
    }

    public function testSuffix(): void
    {
        $id = ObjectId::fromHex('da39a3ee5e6b4b0d3255bfef95601890afd80709');
        self::assertSame('39a3ee5e6b4b0d3255bfef95601890afd80709', $id->suffix());
    }

    public function testShort(): void
    {
        $id = ObjectId::fromHex('da39a3ee5e6b4b0d3255bfef95601890afd80709');
        self::assertSame('da39a3e', $id->short());
        self::assertSame('da39a3ee5e', $id->short(10));
    }

    public function testEquals(): void
    {
        $a = ObjectId::fromHex('da39a3ee5e6b4b0d3255bfef95601890afd80709');
        $b = ObjectId::fromHex('da39a3ee5e6b4b0d3255bfef95601890afd80709');
        $c = ObjectId::fromHex('0000000000000000000000000000000000000000');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    public function testToString(): void
    {
        $id = ObjectId::fromHex('da39a3ee5e6b4b0d3255bfef95601890afd80709');
        self::assertSame('da39a3ee5e6b4b0d3255bfef95601890afd80709', (string) $id);
    }
}

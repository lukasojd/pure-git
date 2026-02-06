<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Infrastructure\Object;

use Lukasojd\PureGit\Infrastructure\Object\DeltaDecoder;
use PHPUnit\Framework\TestCase;

final class DeltaDecoderTest extends TestCase
{
    public function testApplyInsertDelta(): void
    {
        $base = 'Hello, World!';
        // Build a delta that inserts "Hello, World!" at the beginning
        // Base size varint: 13 (0x0D)
        // Result size varint: 13 (0x0D)
        // Insert 13 bytes: "Hello, World!"
        $delta = chr(13) . chr(13) . chr(13) . 'Hello, World!';
        $result = DeltaDecoder::apply($base, $delta);
        self::assertSame('Hello, World!', $result);
    }

    public function testApplyCopyDelta(): void
    {
        $base = 'Hello, World!';
        // Base size: 13, Result size: 13
        // Copy from offset 0, size 13
        // Command: 0x80 | 0x01 (offset byte 0) | 0x10 (size byte 0)
        // = 0x91
        $delta = chr(13) . chr(13) . chr(0x91) . chr(0) . chr(13);
        $result = DeltaDecoder::apply($base, $delta);
        self::assertSame('Hello, World!', $result);
    }
}

<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Support;

use Lukasojd\PureGit\Domain\Exception\PathTraversalException;
use Lukasojd\PureGit\Support\PathUtils;
use PHPUnit\Framework\TestCase;

final class PathUtilsTest extends TestCase
{
    public function testNormalize(): void
    {
        self::assertSame('a/b/c', PathUtils::normalize('a//b///c'));
        self::assertSame('a/b/c', PathUtils::normalize('a\\b\\c'));
        self::assertSame('a/b', PathUtils::normalize('a/b/'));
    }

    public function testJoin(): void
    {
        self::assertSame('a/b/c', PathUtils::join('a', 'b', 'c'));
        self::assertSame('a/b', PathUtils::join('a/', '/b'));
    }

    public function testRelativeTo(): void
    {
        self::assertSame('file.txt', PathUtils::relativeTo('/repo/file.txt', '/repo'));
        self::assertSame('sub/file.txt', PathUtils::relativeTo('/repo/sub/file.txt', '/repo'));
    }

    public function testParentDirectories(): void
    {
        $dirs = PathUtils::parentDirectories('a/b/c/file.txt');
        self::assertSame(['a', 'a/b', 'a/b/c'], $dirs);
    }

    public function testValidateRelativePathValid(): void
    {
        PathUtils::validateRelativePath('src/file.txt');
        self::assertTrue(true); // No exception
    }

    public function testValidateRelativePathTraversal(): void
    {
        $this->expectException(PathTraversalException::class);
        PathUtils::validateRelativePath('../etc/passwd');
    }

    public function testValidateRelativePathAbsolute(): void
    {
        $this->expectException(PathTraversalException::class);
        PathUtils::validateRelativePath('/etc/passwd');
    }
}

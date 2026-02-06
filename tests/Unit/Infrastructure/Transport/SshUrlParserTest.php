<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Infrastructure\Transport;

use Lukasojd\PureGit\Infrastructure\Transport\SshUrlParser;
use PHPUnit\Framework\TestCase;

final class SshUrlParserTest extends TestCase
{
    public function testParsesStandardSshUrl(): void
    {
        $result = SshUrlParser::tryParse('ssh://git@github.com/user/repo.git');

        self::assertNotNull($result);
        self::assertSame('github.com', $result->host);
        self::assertSame(22, $result->port);
        self::assertSame('git', $result->user);
        self::assertSame('/user/repo.git', $result->path);
    }

    public function testParsesStandardSshUrlWithCustomPort(): void
    {
        $result = SshUrlParser::tryParse('ssh://git@github.com:2222/user/repo.git');

        self::assertNotNull($result);
        self::assertSame('github.com', $result->host);
        self::assertSame(2222, $result->port);
        self::assertSame('git', $result->user);
        self::assertSame('/user/repo.git', $result->path);
    }

    public function testParsesScpLikeUrl(): void
    {
        $result = SshUrlParser::tryParse('git@github.com:user/repo.git');

        self::assertNotNull($result);
        self::assertSame('github.com', $result->host);
        self::assertSame(22, $result->port);
        self::assertSame('git', $result->user);
        self::assertSame('/user/repo.git', $result->path);
    }

    public function testParsesScpLikeUrlWithNestedPath(): void
    {
        $result = SshUrlParser::tryParse('git@gitlab.com:group/sub/repo.git');

        self::assertNotNull($result);
        self::assertSame('gitlab.com', $result->host);
        self::assertSame(22, $result->port);
        self::assertSame('git', $result->user);
        self::assertSame('/group/sub/repo.git', $result->path);
    }

    public function testReturnsNullForHttpsUrl(): void
    {
        self::assertNull(SshUrlParser::tryParse('https://github.com/user/repo.git'));
    }

    public function testReturnsNullForLocalPath(): void
    {
        self::assertNull(SshUrlParser::tryParse('/local/path/to/repo'));
    }

    public function testIsSshUrlReturnsTrueForSshScheme(): void
    {
        self::assertTrue(SshUrlParser::isSshUrl('ssh://git@github.com/user/repo.git'));
    }

    public function testIsSshUrlReturnsTrueForScpLike(): void
    {
        self::assertTrue(SshUrlParser::isSshUrl('git@github.com:user/repo.git'));
    }

    public function testIsSshUrlReturnsFalseForHttps(): void
    {
        self::assertFalse(SshUrlParser::isSshUrl('https://github.com/user/repo.git'));
    }

    public function testIsSshUrlReturnsFalseForLocalPath(): void
    {
        self::assertFalse(SshUrlParser::isSshUrl('/local/path/to/repo'));
    }

    public function testIsSshUrlReturnsFalseForGitProtocol(): void
    {
        self::assertFalse(SshUrlParser::isSshUrl('git://github.com/user/repo.git'));
    }

    public function testParsesStandardSshUrlWithDefaultUser(): void
    {
        $result = SshUrlParser::tryParse('ssh://example.com/repo.git');

        self::assertNotNull($result);
        self::assertSame('example.com', $result->host);
        self::assertSame('git', $result->user);
        self::assertSame('/repo.git', $result->path);
    }
}

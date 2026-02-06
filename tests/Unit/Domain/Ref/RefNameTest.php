<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Domain\Ref;

use Lukasojd\PureGit\Domain\Exception\InvalidRefNameException;
use Lukasojd\PureGit\Domain\Ref\RefName;
use PHPUnit\Framework\TestCase;

final class RefNameTest extends TestCase
{
    public function testBranch(): void
    {
        $ref = RefName::branch('main');
        self::assertSame('refs/heads/main', $ref->value);
        self::assertTrue($ref->isBranch());
        self::assertFalse($ref->isTag());
        self::assertSame('main', $ref->shortName());
    }

    public function testBranchWithFullPrefix(): void
    {
        $ref = RefName::branch('refs/heads/main');
        self::assertSame('refs/heads/main', $ref->value);
    }

    public function testTag(): void
    {
        $ref = RefName::tag('v1.0');
        self::assertSame('refs/tags/v1.0', $ref->value);
        self::assertTrue($ref->isTag());
        self::assertFalse($ref->isBranch());
        self::assertSame('v1.0', $ref->shortName());
    }

    public function testHead(): void
    {
        $ref = RefName::head();
        self::assertSame('HEAD', $ref->value);
        self::assertTrue($ref->isHead());
    }

    public function testInvalidEmpty(): void
    {
        $this->expectException(InvalidRefNameException::class);
        RefName::fromString('');
    }

    public function testInvalidDoubleDot(): void
    {
        $this->expectException(InvalidRefNameException::class);
        RefName::fromString('main..branch');
    }

    public function testInvalidSpace(): void
    {
        $this->expectException(InvalidRefNameException::class);
        RefName::fromString('main branch');
    }

    public function testInvalidTrailingDot(): void
    {
        $this->expectException(InvalidRefNameException::class);
        RefName::fromString('main.');
    }

    public function testInvalidTrailingLock(): void
    {
        $this->expectException(InvalidRefNameException::class);
        RefName::fromString('main.lock');
    }

    public function testEquals(): void
    {
        $a = RefName::branch('main');
        $b = RefName::branch('main');
        $c = RefName::branch('develop');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    public function testToString(): void
    {
        $ref = RefName::branch('feature/test');
        self::assertSame('refs/heads/feature/test', (string) $ref);
    }
}

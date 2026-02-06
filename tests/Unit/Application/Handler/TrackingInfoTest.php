<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Application\Handler;

use Lukasojd\PureGit\Application\Handler\TrackingInfo;
use PHPUnit\Framework\TestCase;

final class TrackingInfoTest extends TestCase
{
    public function testUpToDate(): void
    {
        $info = new TrackingInfo(upstream: 'origin/main', ahead: 0, behind: 0);

        self::assertSame("Your branch is up to date with 'origin/main'.", $info->formatMessage());
    }

    public function testAhead(): void
    {
        $info = new TrackingInfo(upstream: 'origin/main', ahead: 3, behind: 0);

        self::assertStringContainsString('ahead', $info->formatMessage());
        self::assertStringContainsString('3 commits', $info->formatMessage());
    }

    public function testAheadSingular(): void
    {
        $info = new TrackingInfo(upstream: 'origin/main', ahead: 1, behind: 0);

        self::assertStringContainsString('1 commit', $info->formatMessage());
        self::assertStringNotContainsString('1 commits', $info->formatMessage());
    }

    public function testBehind(): void
    {
        $info = new TrackingInfo(upstream: 'origin/main', ahead: 0, behind: 5);

        self::assertStringContainsString('behind', $info->formatMessage());
        self::assertStringContainsString('5 commits', $info->formatMessage());
        self::assertStringContainsString('git pull', $info->formatMessage());
    }

    public function testDiverged(): void
    {
        $info = new TrackingInfo(upstream: 'origin/main', ahead: 2, behind: 3);

        self::assertStringContainsString('diverged', $info->formatMessage());
        self::assertStringContainsString('2 and 3', $info->formatMessage());
    }

    public function testGone(): void
    {
        $info = new TrackingInfo(upstream: 'origin/testfff', ahead: 0, behind: 0, gone: true);

        self::assertSame(
            "Your branch is based on 'origin/testfff', but the upstream is gone.\n  (use \"git branch --unset-upstream\" to fixup)",
            $info->formatMessage(),
        );
    }

    public function testGoneIgnoresAheadBehind(): void
    {
        $info = new TrackingInfo(upstream: 'origin/feature', ahead: 5, behind: 3, gone: true);

        self::assertStringContainsString('upstream is gone', $info->formatMessage());
        self::assertStringNotContainsString('ahead', $info->formatMessage());
        self::assertStringNotContainsString('diverged', $info->formatMessage());
    }
}

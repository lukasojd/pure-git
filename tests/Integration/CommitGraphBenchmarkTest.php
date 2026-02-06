<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Integration;

use Lukasojd\PureGit\Application\Handler\CommitGraphHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Infrastructure\CommitGraph\CommitGraphReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CommitGraphBenchmarkTest extends TestCase
{
    private const string REPO_PATH = '/private/tmp/pure-git-clone';

    #[Test]
    public function commitGraphCountMatchesBfs(): void
    {
        if (! is_dir(self::REPO_PATH . '/objects')) {
            $this->markTestSkipped('Benchmark repository not available at ' . self::REPO_PATH);
        }

        $repo = Repository::open(self::REPO_PATH);
        $handler = new CommitGraphHandler($repo);

        // Write the commit-graph
        $result = $handler->write();
        $this->assertGreaterThan(0, $result->commitCount);

        // Verify it matches BFS count (full verify)
        $verifyResult = $handler->verify(true);
        $this->assertTrue($verifyResult->valid, $verifyResult->message);
    }

    #[Test]
    public function commitGraphReadPerformance(): void
    {
        if (! is_dir(self::REPO_PATH . '/objects')) {
            $this->markTestSkipped('Benchmark repository not available at ' . self::REPO_PATH);
        }

        $repo = Repository::open(self::REPO_PATH);
        $handler = new CommitGraphHandler($repo);
        $handler->write();

        $graphPath = self::REPO_PATH . '/objects/info/commit-graph';
        $this->assertFileExists($graphPath);

        $start = hrtime(true);
        $reader = new CommitGraphReader($graphPath);
        $count = $reader->getCommitCount();
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        $this->assertGreaterThan(0, $count);

        // Reading commit count from graph should be < 100ms (with xdebug multiplier)
        $limit = 100 * $this->xdebugSlowdown();
        $this->assertLessThan($limit, $elapsed, sprintf(
            'Commit-graph read took %.1f ms (expected < %d ms)',
            $elapsed,
            $limit,
        ));
    }

    private function xdebugSlowdown(): int
    {
        return extension_loaded('xdebug') ? 20 : 1;
    }
}

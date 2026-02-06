<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Acceptance;

use Lukasojd\PureGit\Application\Handler\FetchHandler;
use Lukasojd\PureGit\Application\Handler\PullHandler;
use Lukasojd\PureGit\Application\Handler\PushHandler;
use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Domain\Ref\RefName;
use PHPUnit\Framework\Attributes\Group;

#[Group('acceptance')]
final class FullCycleTest extends AcceptanceTestCase
{
    /**
     * @var list<string>
     */
    private array $cleanupDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupDirs as $dir) {
            $this->cleanupDir($dir);
        }

        parent::tearDown();
    }

    public function testCloneCommitPushCloneVerify(): void
    {
        $url = $this->getHttpUrl();

        // Clone first copy
        $repo1 = $this->cloneToTempDir($url);
        $this->cleanupDirs[] = $repo1->workDir;

        // Add file and push
        $content = 'Full cycle test ' . uniqid();
        $this->addFileAndCommit($repo1, 'cycle-test.txt', $content, 'Add cycle test file');

        $pushHandler = new PushHandler($repo1);
        $pushResult = $pushHandler->push('origin');
        self::assertFalse($pushResult->upToDate);

        // Clone second copy and verify
        $repo2 = $this->cloneToTempDir($url);
        $this->cleanupDirs[] = $repo2->workDir;

        $headId = $repo2->refs->resolve(RefName::branch('main'));
        $commit = $repo2->objects->read($headId);
        self::assertInstanceOf(Commit::class, $commit);
        self::assertSame('Add cycle test file', $commit->message);
    }

    public function testPullMerge(): void
    {
        $url = $this->getHttpUrl();

        // Clone two copies
        $repo1 = $this->cloneToTempDir($url);
        $this->cleanupDirs[] = $repo1->workDir;

        $repo2 = $this->cloneToTempDir($url);
        $this->cleanupDirs[] = $repo2->workDir;

        // Repo1 makes a change and pushes
        $this->addFileAndCommit($repo1, 'merge-test-1.txt', 'from repo1', 'Add from repo1');
        $pushHandler = new PushHandler($repo1);
        $pushHandler->push('origin');

        // Repo2 pulls — should get the new commit
        $pullHandler = new PullHandler($repo2);
        $result = $pullHandler->pull('origin');

        self::assertFalse($result->upToDate, 'Pull should bring new changes');
    }

    public function testPullAfterFetchFastForwards(): void
    {
        $url = $this->getHttpUrl();

        // Clone two copies
        $repo1 = $this->cloneToTempDir($url);
        $this->cleanupDirs[] = $repo1->workDir;

        $repo2 = $this->cloneToTempDir($url);
        $this->cleanupDirs[] = $repo2->workDir;

        // Repo1 pushes a new commit
        $this->addFileAndCommit($repo1, 'fetch-then-pull.txt', 'test content', 'Upstream change');
        $pushHandler = new PushHandler($repo1);
        $pushHandler->push('origin');

        // Repo2 fetches (tracking ref updated, local branch still behind)
        $fetchHandler = new FetchHandler($repo2);
        $fetchResult = $fetchHandler->fetch('origin');
        self::assertFalse($fetchResult->upToDate, 'Fetch should find new objects');

        // Pull should fast-forward even though fetch is now up to date
        $pullHandler = new PullHandler($repo2);
        $result = $pullHandler->pull('origin');

        self::assertFalse($result->upToDate, 'Pull must fast-forward local branch');
        self::assertTrue($result->fastForward);

        // Verify the file is in the working tree
        self::assertFileExists($repo2->workDir . '/fetch-then-pull.txt');
    }

    public function testPullRebase(): void
    {
        $url = $this->getHttpUrl();

        // Clone two copies
        $repo1 = $this->cloneToTempDir($url);
        $this->cleanupDirs[] = $repo1->workDir;

        $repo2 = $this->cloneToTempDir($url);
        $this->cleanupDirs[] = $repo2->workDir;

        // Repo1 pushes a commit
        $this->addFileAndCommit($repo1, 'rebase-upstream.txt', 'upstream change', 'Upstream commit');
        $pushHandler = new PushHandler($repo1);
        $pushHandler->push('origin');

        // Repo2 makes a local commit, then pulls with rebase
        $this->addFileAndCommit($repo2, 'rebase-local.txt', 'local change', 'Local commit');

        $pullHandler = new PullHandler($repo2);
        $result = $pullHandler->pull('origin', rebase: true);

        self::assertFalse($result->upToDate);
        self::assertTrue($result->rebase);

        // Verify linear history (no merge commits)
        $headId = $repo2->refs->resolve(RefName::branch('main'));
        $commit = $repo2->objects->read($headId);
        self::assertInstanceOf(Commit::class, $commit);
        self::assertCount(1, $commit->parents, 'Rebased commit should have exactly one parent');
    }
}

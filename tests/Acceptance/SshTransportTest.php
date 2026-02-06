<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Acceptance;

use Lukasojd\PureGit\Application\Handler\FetchHandler;
use Lukasojd\PureGit\Application\Handler\PullHandler;
use Lukasojd\PureGit\Application\Handler\PushHandler;
use PHPUnit\Framework\Attributes\Group;

#[Group('acceptance')]
final class SshTransportTest extends AcceptanceTestCase
{
    private string $cloneDir = '';

    protected function tearDown(): void
    {
        if ($this->cloneDir !== '') {
            $this->cleanupDir($this->cloneDir);
        }

        parent::tearDown();
    }

    public function testCloneViaSsh(): void
    {
        $repo = $this->cloneToTempDir($this->getSshUrl());
        $this->cloneDir = $repo->workDir;

        $refs = $repo->refs->listRefs('refs/remotes/origin/');
        self::assertNotEmpty($refs, 'Should have tracking refs after clone');
    }

    public function testFetchNewCommitsViaSsh(): void
    {
        $repo = $this->cloneToTempDir($this->getSshUrl());
        $this->cloneDir = $repo->workDir;

        $fetchHandler = new FetchHandler($repo);
        $result = $fetchHandler->fetch('origin');

        self::assertTrue($result->upToDate, 'Second fetch should be up to date');
    }

    public function testPullViaSsh(): void
    {
        $repo = $this->cloneToTempDir($this->getSshUrl());
        $this->cloneDir = $repo->workDir;

        $pullHandler = new PullHandler($repo);
        $result = $pullHandler->pull('origin');

        self::assertTrue($result->upToDate, 'Pull on fresh clone should be up to date');
    }

    public function testPushViaSsh(): void
    {
        $repo = $this->cloneToTempDir($this->getSshUrl());
        $this->cloneDir = $repo->workDir;

        $this->addFileAndCommit($repo, 'ssh-test.txt', 'SSH push test', 'Add SSH test file');

        $pushHandler = new PushHandler($repo);
        $result = $pushHandler->push('origin');

        self::assertFalse($result->upToDate, 'Push should not be up to date');
        self::assertGreaterThan(0, $result->objectsSent, 'Should have sent objects');
        self::assertNotEmpty($result->refUpdates, 'Should have ref updates');
    }
}

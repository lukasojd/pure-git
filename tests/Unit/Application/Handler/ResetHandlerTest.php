<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Application\Handler;

use Lukasojd\PureGit\Application\Handler\AddHandler;
use Lukasojd\PureGit\Application\Handler\CommitHandler;
use Lukasojd\PureGit\Application\Handler\ResetHandler;
use Lukasojd\PureGit\Application\Handler\ResetMode;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Object\PersonInfo;
use Lukasojd\PureGit\Domain\Ref\RefName;
use PHPUnit\Framework\TestCase;

final class ResetHandlerTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/puregit-reset-test-' . uniqid();
        mkdir($this->testDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function testResetSoftKeepsIndexAndWorkTree(): void
    {
        $repo = $this->createRepoWithCommits(2);
        $headBefore = $repo->refs->resolve(RefName::head());

        $handler = new ResetHandler($repo);
        $handler->handle('HEAD~1', ResetMode::Soft);

        $headAfter = $repo->refs->resolve(RefName::head());
        self::assertFalse($headBefore->equals($headAfter));
        self::assertFileExists($this->testDir . '/file-1.txt');
    }

    public function testResetHardResetsWorkTree(): void
    {
        $repo = $this->createRepoWithCommits(3);

        $handler = new ResetHandler($repo);
        $handler->handle('HEAD~2', ResetMode::Hard);

        self::assertFileExists($this->testDir . '/file-0.txt');
        self::assertFileDoesNotExist($this->testDir . '/file-1.txt');
        self::assertFileDoesNotExist($this->testDir . '/file-2.txt');
    }

    public function testResetWithCaretSyntax(): void
    {
        $repo = $this->createRepoWithCommits(4);
        $headBefore = $repo->refs->resolve(RefName::head());

        $handler = new ResetHandler($repo);
        $handler->handle('HEAD^^^', ResetMode::Hard);

        $headAfter = $repo->refs->resolve(RefName::head());
        self::assertFalse($headBefore->equals($headAfter));

        // HEAD^^^ = 3 steps back from commit 3 → commit 0
        self::assertFileExists($this->testDir . '/file-0.txt');
        self::assertFileDoesNotExist($this->testDir . '/file-1.txt');
    }

    public function testResetWithSingleCaret(): void
    {
        $repo = $this->createRepoWithCommits(2);

        $handler = new ResetHandler($repo);
        $handler->handle('HEAD^', ResetMode::Hard);

        self::assertFileExists($this->testDir . '/file-0.txt');
        self::assertFileDoesNotExist($this->testDir . '/file-1.txt');
    }

    public function testResetWithTildeSyntax(): void
    {
        $repo = $this->createRepoWithCommits(3);

        $handler = new ResetHandler($repo);
        $handler->handle('HEAD~2', ResetMode::Hard);

        self::assertFileExists($this->testDir . '/file-0.txt');
        self::assertFileDoesNotExist($this->testDir . '/file-1.txt');
        self::assertFileDoesNotExist($this->testDir . '/file-2.txt');
    }

    public function testResetMixedResetsIndexButKeepsWorkTree(): void
    {
        $repo = $this->createRepoWithCommits(2);

        $handler = new ResetHandler($repo);
        $handler->handle('HEAD~1', ResetMode::Mixed);

        // Working tree files should still exist
        self::assertFileExists($this->testDir . '/file-1.txt');
    }

    public function testResetToFullCommitHash(): void
    {
        $repo = $this->createRepoWithCommits(3);

        // Get the first commit hash
        $headId = $repo->refs->resolve(RefName::head());
        $firstCommit = $repo->objects->read($headId);
        \assert($firstCommit instanceof \Lukasojd\PureGit\Domain\Object\Commit);
        $parentId = $firstCommit->parents[0];
        $parent = $repo->objects->read($parentId);
        \assert($parent instanceof \Lukasojd\PureGit\Domain\Object\Commit);
        $firstId = $parent->parents[0];

        $handler = new ResetHandler($repo);
        $handler->handle($firstId->hash, ResetMode::Hard);

        $headAfter = $repo->refs->resolve(RefName::head());
        self::assertTrue($firstId->equals($headAfter));
        self::assertFileExists($this->testDir . '/file-0.txt');
        self::assertFileDoesNotExist($this->testDir . '/file-1.txt');
    }

    public function testResetToShortHash(): void
    {
        $repo = $this->createRepoWithCommits(2);

        $firstId = $repo->objects->read($repo->refs->resolve(RefName::head()));
        \assert($firstId instanceof \Lukasojd\PureGit\Domain\Object\Commit);
        $targetId = $firstId->parents[0];

        $handler = new ResetHandler($repo);
        $handler->handle($targetId->short(7), ResetMode::Hard);

        $headAfter = $repo->refs->resolve(RefName::head());
        self::assertTrue($targetId->equals($headAfter));
    }

    public function testResetToBranchName(): void
    {
        $repo = $this->createRepoWithCommits(2);
        $headId = $repo->refs->resolve(RefName::head());

        // Create a branch pointing to parent commit
        $commit = $repo->objects->read($headId);
        \assert($commit instanceof \Lukasojd\PureGit\Domain\Object\Commit);
        $parentId = $commit->parents[0];
        $repo->refs->updateRef(RefName::branch('feature'), $parentId);

        $handler = new ResetHandler($repo);
        $handler->handle('feature', ResetMode::Hard);

        $headAfter = $repo->refs->resolve(RefName::head());
        self::assertTrue($parentId->equals($headAfter));
    }

    public function testResetBranchWithTilde(): void
    {
        $repo = $this->createRepoWithCommits(3);
        $headId = $repo->refs->resolve(RefName::head());

        // Create branch at HEAD
        $repo->refs->updateRef(RefName::branch('feature'), $headId);

        $handler = new ResetHandler($repo);
        $handler->handle('feature~2', ResetMode::Hard);

        self::assertFileExists($this->testDir . '/file-0.txt');
        self::assertFileDoesNotExist($this->testDir . '/file-1.txt');
    }

    public function testResetInvalidRevisionThrows(): void
    {
        $repo = $this->createRepoWithCommits(1);

        $handler = new ResetHandler($repo);

        $this->expectException(PureGitException::class);
        $handler->handle('nonexistent-branch', ResetMode::Hard);
    }

    private function createRepoWithCommits(int $count): Repository
    {
        $repo = Repository::init($this->testDir);
        $person = new PersonInfo('Test', 'test@test.com', new \DateTimeImmutable());

        for ($i = 0; $i < $count; $i++) {
            $filename = "file-{$i}.txt";
            file_put_contents($this->testDir . '/' . $filename, "content {$i}");
            $add = new AddHandler($repo);
            $add->handle([$filename]);
            $commit = new CommitHandler($repo);
            $commit->handle("Commit {$i}", $person);
        }

        return $repo;
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}

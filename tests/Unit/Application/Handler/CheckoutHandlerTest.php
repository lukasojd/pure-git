<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Application\Handler;

use Lukasojd\PureGit\Application\Handler\AddHandler;
use Lukasojd\PureGit\Application\Handler\CheckoutHandler;
use Lukasojd\PureGit\Application\Handler\CheckoutResult;
use Lukasojd\PureGit\Application\Handler\CommitHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Object\PersonInfo;
use Lukasojd\PureGit\Domain\Ref\RefName;
use PHPUnit\Framework\TestCase;

final class CheckoutHandlerTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/puregit-checkout-test-' . uniqid();
        mkdir($this->testDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function testCheckoutNewBranch(): void
    {
        $repo = $this->createRepoWithCommits(1);
        $headBefore = $repo->refs->resolve(RefName::head());

        $handler = new CheckoutHandler($repo);
        $result = $handler->checkoutNewBranch('feature');

        self::assertSame(CheckoutResult::CreatedAndSwitched, $result);

        // HEAD should now point to the new branch
        $currentBranch = $repo->refs->getSymbolicRef(RefName::head());
        self::assertNotNull($currentBranch);
        self::assertTrue($currentBranch->equals(RefName::branch('feature')));

        // Branch should point to the same commit as before
        $branchId = $repo->refs->resolve(RefName::branch('feature'));
        self::assertTrue($headBefore->equals($branchId));
    }

    public function testCheckoutNewBranchFromStartPoint(): void
    {
        $repo = $this->createRepoWithCommits(3);

        // Get parent commit hash
        $headId = $repo->refs->resolve(RefName::head());
        $headCommit = $repo->objects->read($headId);
        \assert($headCommit instanceof \Lukasojd\PureGit\Domain\Object\Commit);
        $parentId = $headCommit->parents[0];

        $handler = new CheckoutHandler($repo);
        $result = $handler->checkoutNewBranch('hotfix', $parentId->hash);

        self::assertSame(CheckoutResult::CreatedAndSwitched, $result);

        // Branch should point to the parent commit, not HEAD
        $branchId = $repo->refs->resolve(RefName::branch('hotfix'));
        self::assertTrue($parentId->equals($branchId));
    }

    public function testCheckoutNewBranchFromBranchName(): void
    {
        $repo = $this->createRepoWithCommits(2);

        // Create a source branch pointing to parent
        $headId = $repo->refs->resolve(RefName::head());
        $headCommit = $repo->objects->read($headId);
        \assert($headCommit instanceof \Lukasojd\PureGit\Domain\Object\Commit);
        $parentId = $headCommit->parents[0];
        $repo->refs->updateRef(RefName::branch('base'), $parentId);

        $handler = new CheckoutHandler($repo);
        $result = $handler->checkoutNewBranch('derived', 'base');

        self::assertSame(CheckoutResult::CreatedAndSwitched, $result);

        $branchId = $repo->refs->resolve(RefName::branch('derived'));
        self::assertTrue($parentId->equals($branchId));
    }

    public function testCheckoutNewBranchAlreadyExists(): void
    {
        $repo = $this->createRepoWithCommits(1);

        $handler = new CheckoutHandler($repo);

        $this->expectException(PureGitException::class);
        $this->expectExceptionMessage("A branch named 'main' already exists");
        $handler->checkoutNewBranch('main');
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

<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Application\Handler;

use Lukasojd\PureGit\Application\Handler\AddHandler;
use Lukasojd\PureGit\Application\Handler\CommitHandler;
use Lukasojd\PureGit\Application\Handler\DiffHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Diff\FileStatus;
use Lukasojd\PureGit\Domain\Object\PersonInfo;
use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Infrastructure\Diff\MyersDiffAlgorithm;
use PHPUnit\Framework\TestCase;

final class DiffHandlerTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/puregit-diff-test-' . uniqid();
        mkdir($this->testDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function testDiffCommitsIdentical(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => 'hello',
        ]);
        $headId = $repo->refs->resolve(RefName::head());

        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $handler->diffCommits($headId, $headId);

        self::assertSame([], $diffs);
    }

    public function testDiffCommitsAddedFile(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => 'hello',
        ]);
        $firstId = $repo->refs->resolve(RefName::head());

        file_put_contents($repo->workDir . '/new.txt', "line1\nline2\n");
        $this->commitFiles($repo, ['new.txt'], 'Add new file');
        $secondId = $repo->refs->resolve(RefName::head());

        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $handler->diffCommits($firstId, $secondId);

        self::assertCount(1, $diffs);
        self::assertSame('new.txt', $diffs[0]->path);
        self::assertSame(FileStatus::Added, $diffs[0]->status);
    }

    public function testDiffCommitsDeletedFile(): void
    {
        $repo = $this->createRepoWithCommit([
            'keep.txt' => 'keep',
            'remove.txt' => 'will be removed',
        ]);
        $firstId = $repo->refs->resolve(RefName::head());

        unlink($repo->workDir . '/remove.txt');
        $rmHandler = new \Lukasojd\PureGit\Application\Handler\RmHandler($repo);
        $rmHandler->handle(['remove.txt']);
        $commit = new CommitHandler($repo);
        $commit->handle('Remove file', new PersonInfo('Test', 'test@test.com', new \DateTimeImmutable()));
        $secondId = $repo->refs->resolve(RefName::head());

        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $handler->diffCommits($firstId, $secondId);

        self::assertCount(1, $diffs);
        self::assertSame('remove.txt', $diffs[0]->path);
        self::assertSame(FileStatus::Deleted, $diffs[0]->status);
    }

    public function testDiffCommitsModifiedFile(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => "line1\nline2\n",
        ]);
        $firstId = $repo->refs->resolve(RefName::head());

        file_put_contents($repo->workDir . '/file.txt', "line1\nmodified\nline3\n");
        $this->commitFiles($repo, ['file.txt'], 'Modify file');
        $secondId = $repo->refs->resolve(RefName::head());

        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $handler->diffCommits($firstId, $secondId);

        self::assertCount(1, $diffs);
        self::assertSame('file.txt', $diffs[0]->path);
        self::assertSame(FileStatus::Modified, $diffs[0]->status);
        self::assertNotEmpty($diffs[0]->hunks);
    }

    public function testDiffCommitsMixedChanges(): void
    {
        $repo = $this->createRepoWithCommit([
            'unchanged.txt' => 'same',
            'modified.txt' => 'old content',
            'deleted.txt' => 'will go',
        ]);
        $firstId = $repo->refs->resolve(RefName::head());

        file_put_contents($repo->workDir . '/modified.txt', 'new content');
        file_put_contents($repo->workDir . '/added.txt', 'brand new');
        unlink($repo->workDir . '/deleted.txt');
        $rmHandler = new \Lukasojd\PureGit\Application\Handler\RmHandler($repo);
        $rmHandler->handle(['deleted.txt']);
        $add = new AddHandler($repo);
        $add->handle(['modified.txt', 'added.txt']);
        $commit = new CommitHandler($repo);
        $commit->handle('Mixed changes', new PersonInfo('Test', 'test@test.com', new \DateTimeImmutable()));
        $secondId = $repo->refs->resolve(RefName::head());

        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $handler->diffCommits($firstId, $secondId);

        self::assertCount(3, $diffs);

        $paths = array_map(fn (\Lukasojd\PureGit\Domain\Diff\FileDiff $d): string => $d->path, $diffs);
        sort($paths);
        self::assertSame(['added.txt', 'deleted.txt', 'modified.txt'], $paths);

        $statuses = [];
        foreach ($diffs as $d) {
            $statuses[$d->path] = $d->status;
        }
        self::assertSame(FileStatus::Added, $statuses['added.txt']);
        self::assertSame(FileStatus::Deleted, $statuses['deleted.txt']);
        self::assertSame(FileStatus::Modified, $statuses['modified.txt']);
    }

    /**
     * @param array<string, string> $files
     */
    private function createRepoWithCommit(array $files): Repository
    {
        $repo = Repository::init($this->testDir);
        foreach ($files as $name => $content) {
            file_put_contents($this->testDir . '/' . $name, $content);
        }
        $add = new AddHandler($repo);
        $add->handle(array_keys($files));
        $commit = new CommitHandler($repo);
        $commit->handle('Initial commit', new PersonInfo('Test', 'test@test.com', new \DateTimeImmutable()));

        return $repo;
    }

    /**
     * @param list<string> $files
     */
    private function commitFiles(Repository $repo, array $files, string $message): void
    {
        $add = new AddHandler($repo);
        $add->handle($files);
        $commit = new CommitHandler($repo);
        $commit->handle($message, new PersonInfo('Test', 'test@test.com', new \DateTimeImmutable()));
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

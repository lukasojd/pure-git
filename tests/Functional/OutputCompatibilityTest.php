<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Functional;

use Lukasojd\PureGit\Application\Handler\AddHandler;
use Lukasojd\PureGit\Application\Handler\CheckoutHandler;
use Lukasojd\PureGit\Application\Handler\CommitHandler;
use Lukasojd\PureGit\Application\Handler\DiffHandler;
use Lukasojd\PureGit\Application\Handler\MergeHandler;
use Lukasojd\PureGit\Application\Handler\MergeResult;
use Lukasojd\PureGit\Application\Handler\ResetHandler;
use Lukasojd\PureGit\Application\Handler\ResetMode;
use Lukasojd\PureGit\Application\Handler\ShowHandler;
use Lukasojd\PureGit\Application\Handler\StatusHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Diff\DiffHunk;
use Lukasojd\PureGit\Domain\Diff\DiffLine;
use Lukasojd\PureGit\Domain\Diff\DiffLineType;
use Lukasojd\PureGit\Domain\Diff\FileDiff;
use Lukasojd\PureGit\Domain\Diff\FileStatus;
use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\PersonInfo;
use Lukasojd\PureGit\Domain\Object\Tag;
use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Infrastructure\Diff\MyersDiffAlgorithm;
use PHPUnit\Framework\TestCase;

final class OutputCompatibilityTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/puregit-compat-test-' . uniqid();
        mkdir($this->testDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    /**
     * Bug 1: Diff of added file must produce hunks (not empty array).
     */
    public function testDiffAddedFileProducesHunks(): void
    {
        $repo = $this->createRepoWithCommit([
            'base.txt' => 'base',
        ]);
        $firstId = $repo->refs->resolve(RefName::head());

        file_put_contents($repo->workDir . '/new.txt', "line1\nline2\n");
        $this->commitFiles($repo, ['new.txt'], 'Add file');
        $secondId = $repo->refs->resolve(RefName::head());

        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $handler->diffCommits($firstId, $secondId);

        self::assertCount(1, $diffs);
        self::assertSame(FileStatus::Added, $diffs[0]->status);
        self::assertNotEmpty($diffs[0]->hunks, 'Added file diff must have hunks');
        self::assertNull($diffs[0]->oldId, 'Added file must have null oldId');
        self::assertInstanceOf(ObjectId::class, $diffs[0]->newId, 'Added file must have newId');
    }

    /**
     * Bug 2: Diff of deleted file must produce hunks (not empty array).
     */
    public function testDiffDeletedFileProducesHunks(): void
    {
        $repo = $this->createRepoWithCommit([
            'keep.txt' => 'keep',
            'remove.txt' => "line1\nline2\n",
        ]);
        $firstId = $repo->refs->resolve(RefName::head());

        unlink($repo->workDir . '/remove.txt');
        $rm = new \Lukasojd\PureGit\Application\Handler\RmHandler($repo);
        $rm->handle(['remove.txt']);
        $commit = new CommitHandler($repo);
        $commit->handle('Remove', new PersonInfo('Test', 'test@test.com', new \DateTimeImmutable()));
        $secondId = $repo->refs->resolve(RefName::head());

        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $handler->diffCommits($firstId, $secondId);

        self::assertCount(1, $diffs);
        self::assertSame(FileStatus::Deleted, $diffs[0]->status);
        self::assertNotEmpty($diffs[0]->hunks, 'Deleted file diff must have hunks');
        self::assertInstanceOf(ObjectId::class, $diffs[0]->oldId, 'Deleted file must have oldId');
        self::assertNull($diffs[0]->newId, 'Deleted file must have null newId');
    }

    /**
     * Bug 3: FileDiff modified file carries both oldId and newId for index line.
     */
    public function testDiffModifiedFileCarriesObjectIds(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => "old\n",
        ]);
        $firstId = $repo->refs->resolve(RefName::head());

        file_put_contents($repo->workDir . '/file.txt', "new\n");
        $this->commitFiles($repo, ['file.txt'], 'Modify');
        $secondId = $repo->refs->resolve(RefName::head());

        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $handler->diffCommits($firstId, $secondId);

        self::assertCount(1, $diffs);
        self::assertInstanceOf(ObjectId::class, $diffs[0]->oldId);
        self::assertInstanceOf(ObjectId::class, $diffs[0]->newId);
        self::assertFalse($diffs[0]->oldId->equals($diffs[0]->newId));
    }

    /**
     * Bug 4: DiffHunk header omits ',1' when count is 1 (git convention).
     */
    public function testDiffHunkHeaderOmitsCommaOneForSingleLine(): void
    {
        $hunk = new DiffHunk(
            oldStart: 1,
            oldCount: 1,
            newStart: 1,
            newCount: 1,
            lines: [new DiffLine(DiffLineType::Context, 'single', 1, 1)],
        );

        self::assertSame('@@ -1 +1 @@', $hunk->header());
    }

    public function testDiffHunkHeaderShowsCountWhenNotOne(): void
    {
        $hunk = new DiffHunk(
            oldStart: 1,
            oldCount: 3,
            newStart: 1,
            newCount: 5,
            lines: [],
        );

        self::assertSame('@@ -1,3 +1,5 @@', $hunk->header());
    }

    public function testDiffHunkHeaderZeroCount(): void
    {
        $hunk = new DiffHunk(
            oldStart: 0,
            oldCount: 0,
            newStart: 1,
            newCount: 2,
            lines: [],
        );

        self::assertSame('@@ -0,0 +1,2 @@', $hunk->header());
    }

    /**
     * Bug 5: diffRootCommit shows all files as Added with hunks.
     */
    public function testDiffRootCommitShowsAllFilesAsAdded(): void
    {
        $repo = $this->createRepoWithCommit([
            'a.txt' => "hello\n",
            'b.txt' => "world\n",
        ]);
        $headId = $repo->refs->resolve(RefName::head());

        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $handler->diffRootCommit($headId);

        self::assertCount(2, $diffs);
        foreach ($diffs as $diff) {
            self::assertSame(FileStatus::Added, $diff->status);
            self::assertNotEmpty($diff->hunks);
        }
    }

    /**
     * Bug 6: ShowHandler resolves tag names.
     */
    public function testShowHandlerResolvesTagName(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => 'content',
        ]);
        $headId = $repo->refs->resolve(RefName::head());

        // Create a lightweight tag
        $tagRef = RefName::fromString('refs/tags/v1.0');
        $repo->refs->updateRef($tagRef, $headId);

        $handler = new ShowHandler($repo);
        $object = $handler->handle('v1.0');

        self::assertInstanceOf(Commit::class, $object);
        self::assertTrue($headId->equals($object->getId()));
    }

    /**
     * Bug 6b: ShowHandler resolves short hash prefix.
     */
    public function testShowHandlerResolvesShortHash(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => 'content',
        ]);
        $headId = $repo->refs->resolve(RefName::head());

        $handler = new ShowHandler($repo);
        $shortHash = substr($headId->hash, 0, 7);
        $object = $handler->handle($shortHash);

        self::assertInstanceOf(Commit::class, $object);
        self::assertTrue($headId->equals($object->getId()));
    }

    /**
     * Bug 6c: ShowHandler peels annotated tag to commit.
     */
    public function testShowHandlerPeelsAnnotatedTag(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => 'content',
        ]);
        $headId = $repo->refs->resolve(RefName::head());

        // Create annotated tag object
        $tag = new Tag(
            $headId,
            \Lukasojd\PureGit\Domain\Object\ObjectType::Commit,
            'v2.0',
            new PersonInfo('Tagger', 'tag@test.com', new \DateTimeImmutable()),
            'Release v2.0',
        );
        $repo->objects->write($tag);
        $repo->refs->updateRef(RefName::fromString('refs/tags/v2.0'), $tag->getId());

        $handler = new ShowHandler($repo);
        $object = $handler->handle('v2.0');

        // Should peel to the commit, not return the tag
        self::assertInstanceOf(Commit::class, $object);
        self::assertTrue($headId->equals($object->getId()));
    }

    /**
     * Bug 7: Merge handler returns MergeResult with fastForward flag.
     */
    public function testMergeFastForwardReturnsMergeResult(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => 'initial',
        ]);
        $mainId = $repo->refs->resolve(RefName::head());

        // Create feature branch at same point
        $repo->refs->updateRef(RefName::branch('feature'), $mainId);

        // Add commit on feature
        $checkout = new CheckoutHandler($repo);
        $checkout->checkout('feature');
        file_put_contents($repo->workDir . '/new.txt', 'feature content');
        $this->commitFiles($repo, ['new.txt'], 'feature commit');

        // Switch to main and merge
        $checkout->checkout('main');
        $handler = new MergeHandler($repo);
        $result = $handler->handle('feature');

        self::assertInstanceOf(MergeResult::class, $result);
        self::assertTrue($result->fastForward);
        self::assertTrue($mainId->equals($result->oldId));
        self::assertFalse($mainId->equals($result->commitId));
    }

    /**
     * Bug 8: Reset handler works without errors in all modes.
     */
    public function testResetSoftDoesNotChangeWorkingTree(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => 'v1',
        ]);
        $firstId = $repo->refs->resolve(RefName::head());

        file_put_contents($repo->workDir . '/file.txt', 'v2');
        $this->commitFiles($repo, ['file.txt'], 'v2');
        $repo->refs->resolve(RefName::head());

        $handler = new ResetHandler($repo);
        $handler->handle($firstId->hash, ResetMode::Soft);

        // HEAD moved back
        $headId = $repo->refs->resolve(RefName::head());
        self::assertTrue($firstId->equals($headId));

        // Working tree still has v2
        self::assertSame('v2', file_get_contents($repo->workDir . '/file.txt'));
    }

    /**
     * Bug 9: splitLines strips trailing newline to avoid phantom empty line.
     */
    public function testDiffNoPhantomLineFromTrailingNewline(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => "line1\n",
        ]);
        $firstId = $repo->refs->resolve(RefName::head());

        file_put_contents($repo->workDir . '/file.txt', "line1\nline2\n");
        $this->commitFiles($repo, ['file.txt'], 'Add line');
        $secondId = $repo->refs->resolve(RefName::head());

        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $handler->diffCommits($firstId, $secondId);

        self::assertCount(1, $diffs);
        $hunk = $diffs[0]->hunks[0];

        // Should not have empty-string lines from trailing \n
        foreach ($hunk->lines as $line) {
            if ($line->type === DiffLineType::Added) {
                self::assertSame('line2', $line->content);
            }
        }
    }

    /**
     * Bug 10: Status handler reports correctly with unstaged changes.
     */
    public function testStatusWithUnstagedChanges(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => 'original',
        ]);

        // Modify without staging
        file_put_contents($repo->workDir . '/file.txt', 'modified');

        $handler = new StatusHandler($repo);
        $result = $handler->handle();

        self::assertNotEmpty($result['unstaged']);
        self::assertSame([], $result['staged']);
        self::assertArrayHasKey('file.txt', $result['unstaged']);
    }

    /**
     * Bug 11: diffIndexVsHead shows added files with hunks.
     */
    public function testDiffIndexVsHeadAddedFile(): void
    {
        $repo = $this->createRepoWithCommit([
            'existing.txt' => 'content',
        ]);

        // Add new file to index
        file_put_contents($repo->workDir . '/staged.txt', "new content\n");
        $add = new AddHandler($repo);
        $add->handle(['staged.txt']);

        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $handler->diffIndexVsHead();

        self::assertCount(1, $diffs);
        self::assertSame('staged.txt', $diffs[0]->path);
        self::assertSame(FileStatus::Added, $diffs[0]->status);
        self::assertNotEmpty($diffs[0]->hunks, 'Staged added file must produce hunks');
    }

    /**
     * Bug 12: diffIndexVsHead shows deleted files with hunks.
     */
    public function testDiffIndexVsHeadDeletedFile(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => "content\n",
        ]);

        // Remove from index
        $rm = new \Lukasojd\PureGit\Application\Handler\RmHandler($repo);
        unlink($repo->workDir . '/file.txt');
        $rm->handle(['file.txt']);

        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $handler->diffIndexVsHead();

        self::assertCount(1, $diffs);
        self::assertSame('file.txt', $diffs[0]->path);
        self::assertSame(FileStatus::Deleted, $diffs[0]->status);
        self::assertNotEmpty($diffs[0]->hunks, 'Staged deleted file must produce hunks');
    }

    /**
     * @param array<string, string> $files
     */
    private function createRepoWithCommit(array $files): Repository
    {
        $repo = Repository::init($this->testDir);
        foreach ($files as $name => $content) {
            $dir = dirname($this->testDir . '/' . $name);
            if (! is_dir($dir)) {
                mkdir($dir, 0o777, true);
            }
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

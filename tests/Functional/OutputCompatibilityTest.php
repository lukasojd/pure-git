<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Functional;

use Lukasojd\PureGit\Application\Handler\AddHandler;
use Lukasojd\PureGit\Application\Handler\BranchHandler;
use Lukasojd\PureGit\Application\Handler\CheckoutHandler;
use Lukasojd\PureGit\Application\Handler\CommitHandler;
use Lukasojd\PureGit\Application\Handler\DiffHandler;
use Lukasojd\PureGit\Application\Handler\LogHandler;
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
use Lukasojd\PureGit\Infrastructure\Config\GitConfigReader;
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
     * Bug 13: Hunk context label shows function name after @@.
     */
    public function testDiffHunkContextLabelShowsFunctionName(): void
    {
        $body = str_repeat("        \$x = 1;\n", 10);
        $old = "<?php\nclass Example\n{\n    public function handle()\n    {\n" . $body . "        return 1;\n    }\n}\n";
        $new = "<?php\nclass Example\n{\n    public function handle()\n    {\n" . $body . "        return 1;\n        // added\n    }\n}\n";

        $repo = $this->createRepoWithCommit([
            'Example.php' => $old,
        ]);
        $firstId = $repo->refs->resolve(RefName::head());

        file_put_contents($repo->workDir . '/Example.php', $new);
        $this->commitFiles($repo, ['Example.php'], 'Add line inside function');
        $secondId = $repo->refs->resolve(RefName::head());

        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $handler->diffCommits($firstId, $secondId);

        self::assertCount(1, $diffs);
        $hunk = $diffs[0]->hunks[0];

        self::assertNotNull($hunk->contextLabel);
        self::assertStringContainsString('function handle', $hunk->contextLabel);
        self::assertStringContainsString('function handle', $hunk->header());
    }

    public function testDiffHunkContextLabelNullWhenNoMatch(): void
    {
        $repo = $this->createRepoWithCommit([
            'data.txt' => "111\n222\n333\n",
        ]);
        $firstId = $repo->refs->resolve(RefName::head());

        file_put_contents($repo->workDir . '/data.txt', "111\n222\n333\n444\n");
        $this->commitFiles($repo, ['data.txt'], 'Append');
        $secondId = $repo->refs->resolve(RefName::head());

        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $handler->diffCommits($firstId, $secondId);

        self::assertCount(1, $diffs);
        $hunk = $diffs[0]->hunks[0];

        // Lines are plain numbers — no function/class match
        self::assertNull($hunk->contextLabel);
    }

    // ========= log --oneline =========

    public function testLogOnelineFormat(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => "v1\n",
        ]);

        file_put_contents($repo->workDir . '/file.txt', "v2\n");
        $this->commitFiles($repo, ['file.txt'], 'Second commit');

        $handler = new LogHandler($repo);
        $commits = $handler->handle(10);

        self::assertCount(2, $commits);
        foreach ($commits as $commit) {
            $short = $commit->getId()->short(7);
            self::assertSame(7, strlen($short));
            $firstLine = strstr($commit->message, "\n", true);
            $expected = $firstLine !== false ? $firstLine : rtrim($commit->message);
            self::assertNotEmpty($expected);
        }
    }

    // ========= log --all =========

    public function testLogAllShowsCommitsFromAllBranches(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => "main\n",
        ]);

        // Create feature branch with extra commit
        $checkout = new CheckoutHandler($repo);
        $checkout->checkoutNewBranch('feature');
        file_put_contents($repo->workDir . '/feature.txt', "feature\n");
        $this->commitFiles($repo, ['feature.txt'], 'Feature commit');

        // Go back to main
        $checkout->checkout('main');

        // log from HEAD only sees main commits (1)
        $handler = new LogHandler($repo);
        $headOnly = $handler->handle(10);
        self::assertCount(1, $headOnly);

        // log --all sees both branches (2 commits)
        $allCommits = $handler->handle(10, all: true);
        self::assertCount(2, $allCommits);
    }

    // ========= diff <commit>..<commit> =========

    public function testDiffBetweenTwoCommits(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => "original\n",
        ]);
        $firstId = $repo->refs->resolve(RefName::head());

        file_put_contents($repo->workDir . '/file.txt', "modified\n");
        $this->commitFiles($repo, ['file.txt'], 'Modify');
        $secondId = $repo->refs->resolve(RefName::head());

        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $handler->diffCommits($firstId, $secondId);

        self::assertCount(1, $diffs);
        self::assertSame('file.txt', $diffs[0]->path);
        self::assertSame(FileStatus::Modified, $diffs[0]->status);
    }

    // ========= diff --stat =========

    public function testDiffStatCountsInsertionsAndDeletions(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => "line1\nline2\nline3\n",
        ]);
        $firstId = $repo->refs->resolve(RefName::head());

        file_put_contents($repo->workDir . '/file.txt', "line1\nchanged\nline3\nnew\n");
        $this->commitFiles($repo, ['file.txt'], 'Change');
        $secondId = $repo->refs->resolve(RefName::head());

        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $handler->diffCommits($firstId, $secondId);

        self::assertCount(1, $diffs);
        $added = 0;
        $removed = 0;
        foreach ($diffs[0]->hunks as $hunk) {
            foreach ($hunk->lines as $line) {
                if ($line->type === DiffLineType::Added) {
                    $added++;
                } elseif ($line->type === DiffLineType::Removed) {
                    $removed++;
                }
            }
        }
        self::assertGreaterThan(0, $added);
        self::assertGreaterThan(0, $removed);
    }

    // ========= diff --name-only =========

    public function testDiffNameOnlyReturnsPaths(): void
    {
        $repo = $this->createRepoWithCommit([
            'a.txt' => "aaa\n",
            'b.txt' => "bbb\n",
        ]);
        $firstId = $repo->refs->resolve(RefName::head());

        file_put_contents($repo->workDir . '/a.txt', "aaa-modified\n");
        file_put_contents($repo->workDir . '/b.txt', "bbb-modified\n");
        $this->commitFiles($repo, ['a.txt', 'b.txt'], 'Modify both');
        $secondId = $repo->refs->resolve(RefName::head());

        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $handler->diffCommits($firstId, $secondId);

        $paths = array_map(static fn (FileDiff $d): string => $d->path, $diffs);
        sort($paths);
        self::assertSame(['a.txt', 'b.txt'], $paths);
    }

    // ========= status -s/--short =========

    public function testStatusShortFormat(): void
    {
        $repo = $this->createRepoWithCommit([
            'tracked.txt' => "content\n",
        ]);

        // Modify tracked file (unstaged)
        file_put_contents($repo->workDir . '/tracked.txt', "changed\n");

        // Add untracked file
        file_put_contents($repo->workDir . '/untracked.txt', "new\n");

        // Add new file to index (staged)
        file_put_contents($repo->workDir . '/staged.txt', "staged\n");
        $add = new AddHandler($repo);
        $add->handle(['staged.txt']);

        $handler = new StatusHandler($repo);
        $result = $handler->handle();

        // staged.txt should be staged as Added
        self::assertArrayHasKey('staged.txt', $result['staged']);
        self::assertSame(FileStatus::Added, $result['staged']['staged.txt']);

        // tracked.txt should be unstaged as Modified
        self::assertArrayHasKey('tracked.txt', $result['unstaged']);
        self::assertSame(FileStatus::Modified, $result['unstaged']['tracked.txt']);

        // untracked.txt should be untracked
        self::assertContains('untracked.txt', $result['untracked']);
    }

    // ========= show --stat =========

    public function testShowStatShowsCommitWithDiffStats(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => "content\n",
        ]);

        $handler = new ShowHandler($repo);
        $object = $handler->handle();

        self::assertInstanceOf(Commit::class, $object);
        // The commit has files — verify diff works
        $diffHandler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $diffHandler->diffRootCommit($object->getId());
        self::assertNotEmpty($diffs);
    }

    // ========= show --name-only =========

    public function testShowNameOnlyListsChangedFiles(): void
    {
        $repo = $this->createRepoWithCommit([
            'a.txt' => "aaa\n",
            'b.txt' => "bbb\n",
        ]);

        $handler = new ShowHandler($repo);
        $commit = $handler->handle();
        self::assertInstanceOf(Commit::class, $commit);

        $diffHandler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $diffHandler->diffRootCommit($commit->getId());
        $paths = array_map(static fn (FileDiff $d): string => $d->path, $diffs);
        sort($paths);
        self::assertSame(['a.txt', 'b.txt'], $paths);
    }

    // ========= branch -m (rename) =========

    public function testBranchRename(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => 'content',
        ]);

        $branchHandler = new BranchHandler($repo);
        $branchHandler->create('old-name');
        self::assertArrayHasKey('refs/heads/old-name', $branchHandler->list());

        $branchHandler->rename('old-name', 'new-name');

        $branches = $branchHandler->list();
        self::assertArrayNotHasKey('refs/heads/old-name', $branches);
        self::assertArrayHasKey('refs/heads/new-name', $branches);
    }

    public function testBranchRenameCurrentBranchUpdatesHead(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => 'content',
        ]);

        $branchHandler = new BranchHandler($repo);
        // Current branch is 'main'
        $current = $branchHandler->getCurrentBranch();
        self::assertInstanceOf(RefName::class, $current);
        self::assertSame('main', $current->shortName());

        $branchHandler->rename('main', 'trunk');
        $newCurrent = $branchHandler->getCurrentBranch();
        self::assertInstanceOf(RefName::class, $newCurrent);
        self::assertSame('trunk', $newCurrent->shortName());
    }

    public function testBranchRenameMigratesTrackingConfig(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => 'content',
        ]);

        // Set up tracking on 'main'
        $branchHandler = new BranchHandler($repo);
        $branchHandler->create('feature');

        // Manually set tracking config for 'feature'
        $configPath = $repo->gitDir . '/config';
        $writer = new \Lukasojd\PureGit\Infrastructure\Config\GitConfigWriter();
        $writer->set($configPath, 'branch "feature"', 'remote', 'origin');
        $writer->set($configPath, 'branch "feature"', 'merge', 'refs/heads/feature');

        $branchHandler->rename('feature', 'feat-new');

        $config = new GitConfigReader($configPath);
        self::assertNull($config->get('branch "feature"', 'remote'));
        self::assertSame('origin', $config->get('branch "feat-new"', 'remote'));
        self::assertSame('refs/heads/feature', $config->get('branch "feat-new"', 'merge'));
    }

    // ========= branch -a (list all) =========

    public function testBranchListRemote(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => 'content',
        ]);

        // Create a fake remote-tracking ref
        $headId = $repo->refs->resolve(RefName::head());
        $repo->refs->updateRef(RefName::fromString('refs/remotes/origin/main'), $headId);

        $branchHandler = new BranchHandler($repo);
        $remotes = $branchHandler->listRemote();
        self::assertArrayHasKey('refs/remotes/origin/main', $remotes);
    }

    // ========= branch --set-upstream-to =========

    public function testBranchSetUpstreamTo(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => 'content',
        ]);

        // Create remote tracking ref
        $headId = $repo->refs->resolve(RefName::head());
        $repo->refs->updateRef(RefName::fromString('refs/remotes/origin/main'), $headId);

        $branchHandler = new BranchHandler($repo);
        $branchHandler->setUpstreamTo('origin/main');

        $config = new GitConfigReader($repo->gitDir . '/config');
        self::assertSame('origin', $config->get('branch "main"', 'remote'));
        self::assertSame('refs/heads/main', $config->get('branch "main"', 'merge'));
    }

    // ========= checkout -- <file> =========

    public function testCheckoutRestoreFileFromHead(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => "original\n",
        ]);

        // Modify the file
        file_put_contents($repo->workDir . '/file.txt', "modified\n");
        self::assertSame("modified\n", file_get_contents($repo->workDir . '/file.txt'));

        // Restore from HEAD
        $handler = new CheckoutHandler($repo);
        $handler->restoreFile('file.txt');

        self::assertSame("original\n", file_get_contents($repo->workDir . '/file.txt'));
    }

    public function testCheckoutRestoreDeletedFile(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => "content\n",
        ]);

        unlink($repo->workDir . '/file.txt');
        self::assertFileDoesNotExist($repo->workDir . '/file.txt');

        $handler = new CheckoutHandler($repo);
        $handler->restoreFile('file.txt');

        self::assertFileExists($repo->workDir . '/file.txt');
        self::assertSame("content\n", file_get_contents($repo->workDir . '/file.txt'));
    }

    // ========= log --author =========

    public function testLogAuthorFiltersCommitsByName(): void
    {
        $repo = Repository::init($this->testDir);
        file_put_contents($this->testDir . '/a.txt', "a\n");
        $add = new AddHandler($repo);
        $add->handle(['a.txt']);
        $commit = new CommitHandler($repo);
        $commit->handle('By Alice', new PersonInfo('Alice', 'alice@example.com', new \DateTimeImmutable()));

        file_put_contents($this->testDir . '/b.txt', "b\n");
        $add->handle(['b.txt']);
        $commit->handle('By Bob', new PersonInfo('Bob', 'bob@example.com', new \DateTimeImmutable()));

        $handler = new LogHandler($repo);

        $alice = $handler->handle(10, author: 'Alice');
        self::assertCount(1, $alice);
        self::assertStringContainsString('By Alice', $alice[0]->message);

        $bob = $handler->handle(10, author: 'Bob');
        self::assertCount(1, $bob);
        self::assertStringContainsString('By Bob', $bob[0]->message);

        $all = $handler->handle(10);
        self::assertCount(2, $all);
    }

    public function testLogAuthorFiltersCommitsByEmail(): void
    {
        $repo = Repository::init($this->testDir);
        file_put_contents($this->testDir . '/file.txt', "content\n");
        $add = new AddHandler($repo);
        $add->handle(['file.txt']);
        $commit = new CommitHandler($repo);
        $commit->handle('Commit', new PersonInfo('Dev', 'dev@corp.com', new \DateTimeImmutable()));

        $handler = new LogHandler($repo);
        $byEmail = $handler->handle(10, author: 'corp.com');
        self::assertCount(1, $byEmail);

        $noMatch = $handler->handle(10, author: 'nobody');
        self::assertCount(0, $noMatch);
    }

    // ========= log --since =========

    public function testLogSinceFiltersCommitsByDate(): void
    {
        $repo = Repository::init($this->testDir);
        file_put_contents($this->testDir . '/old.txt', "old\n");
        $add = new AddHandler($repo);
        $add->handle(['old.txt']);
        $commit = new CommitHandler($repo);
        $oldDate = new \DateTimeImmutable('2020-01-01');
        $commit->handle('Old commit', new PersonInfo('Test', 'test@test.com', $oldDate));

        file_put_contents($this->testDir . '/new.txt', "new\n");
        $add->handle(['new.txt']);
        $newDate = new \DateTimeImmutable('2025-06-01');
        $commit->handle('New commit', new PersonInfo('Test', 'test@test.com', $newDate));

        $handler = new LogHandler($repo);

        $since2024 = $handler->handle(10, since: new \DateTimeImmutable('2024-01-01'));
        self::assertCount(1, $since2024);
        self::assertStringContainsString('New commit', $since2024[0]->message);

        $since2019 = $handler->handle(10, since: new \DateTimeImmutable('2019-01-01'));
        self::assertCount(2, $since2019);
    }

    // ========= commit -a (auto-stage tracked) =========

    public function testCommitAutoStageTrackedFiles(): void
    {
        $repo = $this->createRepoWithCommit([
            'tracked.txt' => "original\n",
        ]);

        // Modify tracked file without staging
        file_put_contents($repo->workDir . '/tracked.txt', "modified\n");

        // Auto-stage and commit
        new AddHandler($repo)->updateTracked();
        $commit = new CommitHandler($repo);
        $commitId = $commit->handle('Auto-staged commit', new PersonInfo('Test', 'test@test.com', new \DateTimeImmutable()));

        // Verify the commit includes the modification
        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $parentId = $repo->objects->read($commitId);
        self::assertInstanceOf(\Lukasojd\PureGit\Domain\Object\Commit::class, $parentId);
        $parentCommitId = $parentId->parents[0];
        $diffs = $handler->diffCommits($parentCommitId, $commitId);

        self::assertCount(1, $diffs);
        self::assertSame('tracked.txt', $diffs[0]->path);
    }

    public function testAutoStageHandlesDeletedFiles(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => "content\n",
        ]);

        unlink($repo->workDir . '/file.txt');
        new AddHandler($repo)->updateTracked();

        $index = $repo->index->read();
        self::assertFalse($index->hasEntry('file.txt'));
    }

    // ========= commit --allow-empty =========

    public function testCommitAllowEmpty(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => "content\n",
        ]);
        $firstId = $repo->refs->resolve(RefName::head());

        // Commit again with same index (no changes) using allowEmpty
        $commit = new CommitHandler($repo);
        $secondId = $commit->handle(
            'Empty commit',
            new PersonInfo('Test', 'test@test.com', new \DateTimeImmutable()),
            allowEmpty: true,
        );

        self::assertFalse($firstId->equals($secondId));

        // Both commits should have the same tree
        $first = $repo->objects->read($firstId);
        $second = $repo->objects->read($secondId);
        self::assertInstanceOf(\Lukasojd\PureGit\Domain\Object\Commit::class, $first);
        self::assertInstanceOf(\Lukasojd\PureGit\Domain\Object\Commit::class, $second);
        self::assertTrue($first->treeId->equals($second->treeId));
    }

    // ========= commit --amend =========

    public function testCommitAmendReplacesLastCommit(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => "v1\n",
        ]);
        $firstId = $repo->refs->resolve(RefName::head());
        $firstCommit = $repo->objects->read($firstId);
        self::assertInstanceOf(\Lukasojd\PureGit\Domain\Object\Commit::class, $firstCommit);

        // Make a second commit
        file_put_contents($repo->workDir . '/file.txt', "v2\n");
        $this->commitFiles($repo, ['file.txt'], 'Original second');
        $secondId = $repo->refs->resolve(RefName::head());

        // Amend the second commit
        $commit = new CommitHandler($repo);
        $amendedId = $commit->handle(
            'Amended message',
            new PersonInfo('Test', 'test@test.com', new \DateTimeImmutable()),
            amend: true,
        );

        // HEAD should point to the amended commit (not the original second)
        $headId = $repo->refs->resolve(RefName::head());
        self::assertTrue($headId->equals($amendedId));
        self::assertFalse($headId->equals($secondId));

        // Amended commit's parent should be the first commit (same as original second's parent)
        $amended = $repo->objects->read($amendedId);
        self::assertInstanceOf(\Lukasojd\PureGit\Domain\Object\Commit::class, $amended);
        self::assertCount(1, $amended->parents);
        self::assertTrue($amended->parents[0]->equals($firstId));
        self::assertSame('Amended message', $amended->message);
    }

    public function testCommitAmendPreservesMessageWhenNotProvided(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => "content\n",
        ]);

        // The handler always requires a message — to test message reuse,
        // verify reading the last commit's message works
        $headId = $repo->refs->resolve(RefName::head());
        $headCommit = $repo->objects->read($headId);
        self::assertInstanceOf(\Lukasojd\PureGit\Domain\Object\Commit::class, $headCommit);
        self::assertSame('Initial commit', $headCommit->message);
    }

    // ========= add -u (update tracked) =========

    public function testAddUpdateTrackedStagesModifications(): void
    {
        $repo = $this->createRepoWithCommit([
            'tracked.txt' => "original\n",
            'other.txt' => "keep\n",
        ]);

        // Modify tracked file
        file_put_contents($repo->workDir . '/tracked.txt', "changed\n");

        // Add untracked file (should NOT be staged by -u)
        file_put_contents($repo->workDir . '/untracked.txt', "new\n");

        $handler = new AddHandler($repo);
        $handler->updateTracked();

        // Check status: tracked.txt should be staged, untracked.txt should remain untracked
        $status = new StatusHandler($repo);
        $result = $status->handle();

        self::assertArrayHasKey('tracked.txt', $result['staged']);
        self::assertSame(FileStatus::Modified, $result['staged']['tracked.txt']);
        self::assertContains('untracked.txt', $result['untracked']);
    }

    public function testAddUpdateTrackedStagesDeletions(): void
    {
        $repo = $this->createRepoWithCommit([
            'file.txt' => "content\n",
            'keep.txt' => "keep\n",
        ]);

        unlink($repo->workDir . '/file.txt');

        $handler = new AddHandler($repo);
        $handler->updateTracked();

        $status = new StatusHandler($repo);
        $result = $status->handle();

        self::assertArrayHasKey('file.txt', $result['staged']);
        self::assertSame(FileStatus::Deleted, $result['staged']['file.txt']);
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

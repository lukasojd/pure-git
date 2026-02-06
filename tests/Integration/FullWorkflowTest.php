<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Integration;

use Lukasojd\PureGit\Application\Handler\AddHandler;
use Lukasojd\PureGit\Application\Handler\BranchHandler;
use Lukasojd\PureGit\Application\Handler\CheckoutHandler;
use Lukasojd\PureGit\Application\Handler\CommitHandler;
use Lukasojd\PureGit\Application\Handler\LogHandler;
use Lukasojd\PureGit\Application\Handler\ResetHandler;
use Lukasojd\PureGit\Application\Handler\ResetMode;
use Lukasojd\PureGit\Application\Handler\RmHandler;
use Lukasojd\PureGit\Application\Handler\StatusHandler;
use Lukasojd\PureGit\Application\Handler\TagHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Diff\FileStatus;
use PHPUnit\Framework\TestCase;

final class FullWorkflowTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/puregit-test-' . uniqid();
        mkdir($this->testDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function testInitAddCommitLogStatus(): void
    {
        // Init
        $repo = $this->initRepoWithUser();
        self::assertDirectoryExists($this->testDir . '/.git');
        self::assertDirectoryExists($this->testDir . '/.git/objects');
        self::assertDirectoryExists($this->testDir . '/.git/refs');

        // Create a file
        file_put_contents($this->testDir . '/hello.txt', 'Hello, World!');

        // Status — should show untracked
        $status = new StatusHandler($repo);
        $result = $status->handle();
        self::assertContains('hello.txt', $result['untracked']);

        // Add
        $add = new AddHandler($repo);
        $add->handle(['hello.txt']);

        // Status — should show staged
        $result = $status->handle();
        self::assertArrayHasKey('hello.txt', $result['staged']);
        self::assertSame(FileStatus::Added, $result['staged']['hello.txt']);

        // Commit
        $commit = new CommitHandler($repo);
        $commitId = $commit->handle('Initial commit');
        self::assertNotEmpty($commitId->hash);

        // Status — should be clean
        $result = $status->handle();
        self::assertSame([], $result['staged']);
        self::assertSame([], $result['unstaged']);

        // Log
        $log = new LogHandler($repo);
        $commits = $log->handle();
        self::assertCount(1, $commits);
        self::assertSame('Initial commit', $commits[0]->message);

        // Modify and recommit
        file_put_contents($this->testDir . '/hello.txt', 'Modified content');
        $result = $status->handle();
        self::assertArrayHasKey('hello.txt', $result['unstaged']);

        $add->handle(['hello.txt']);
        $commit->handle('Second commit');

        $commits = $log->handle();
        self::assertCount(2, $commits);
    }

    public function testBranchAndCheckout(): void
    {
        $repo = $this->initRepoWithUser();
        file_put_contents($this->testDir . '/main.txt', 'main content');

        $add = new AddHandler($repo);
        $add->handle(['main.txt']);

        $commitHandler = new CommitHandler($repo);
        $commitHandler->handle('Initial commit on main');

        // Create branch
        $branch = new BranchHandler($repo);
        $branch->create('feature');

        $branches = $branch->list();
        self::assertArrayHasKey('refs/heads/main', $branches);
        self::assertArrayHasKey('refs/heads/feature', $branches);

        // Checkout feature
        $checkout = new CheckoutHandler($repo);
        $checkout->checkout('feature');

        $currentBranch = $branch->getCurrentBranch();
        self::assertNotNull($currentBranch);
        self::assertSame('feature', $currentBranch->shortName());

        // Create file on feature
        file_put_contents($this->testDir . '/feature.txt', 'feature content');
        $add->handle(['feature.txt']);
        $commitHandler->handle('Feature commit');

        // Checkout back to main
        $checkout->checkout('main');
        self::assertFileDoesNotExist($this->testDir . '/feature.txt');

        // Delete feature branch
        $branch->delete('feature');
        $branches = $branch->list();
        self::assertArrayNotHasKey('refs/heads/feature', $branches);
    }

    public function testCheckoutPreservesExecutablePermission(): void
    {
        $repo = $this->initRepoWithUser();

        // Create an executable script
        $scriptPath = $this->testDir . '/run.sh';
        file_put_contents($scriptPath, "#!/bin/bash\necho hello\n");
        chmod($scriptPath, 0o755);

        $add = new AddHandler($repo);
        $add->handle(['run.sh']);
        $commitHandler = new CommitHandler($repo);
        $commitHandler->handle('Add executable script');

        // Create branch, switch to it, switch back
        $branch = new BranchHandler($repo);
        $branch->create('other');
        $checkout = new CheckoutHandler($repo);
        $checkout->checkout('other');
        $checkout->checkout('main');

        // Executable bit must be preserved after checkout
        self::assertFileExists($scriptPath);
        self::assertTrue(is_executable($scriptPath), 'run.sh should be executable after checkout');
    }

    public function testTagOperations(): void
    {
        $repo = $this->initRepoWithUser();
        file_put_contents($this->testDir . '/file.txt', 'content');

        $add = new AddHandler($repo);
        $add->handle(['file.txt']);

        $commit = new CommitHandler($repo);
        $commit->handle('First commit');

        $tag = new TagHandler($repo);

        // Lightweight
        $tag->createLightweight('v1.0');
        $tags = $tag->list();
        self::assertArrayHasKey('refs/tags/v1.0', $tags);

        // Annotated
        $tag->createAnnotated('v2.0', 'Release 2.0');
        $tags = $tag->list();
        self::assertArrayHasKey('refs/tags/v2.0', $tags);

        // Delete
        $tag->delete('v1.0');
        $tags = $tag->list();
        self::assertArrayNotHasKey('refs/tags/v1.0', $tags);
    }

    public function testResetModes(): void
    {
        $repo = $this->initRepoWithUser();

        file_put_contents($this->testDir . '/file.txt', 'version 1');
        $add = new AddHandler($repo);
        $add->handle(['file.txt']);
        $commit = new CommitHandler($repo);
        $firstId = $commit->handle('First');

        file_put_contents($this->testDir . '/file.txt', 'version 2');
        $add->handle(['file.txt']);
        $commit->handle('Second');

        // Reset soft
        $reset = new ResetHandler($repo);
        $reset->handle($firstId->hash, ResetMode::Soft);

        $log = new LogHandler($repo);
        $commits = $log->handle();
        self::assertCount(1, $commits);

        // File content unchanged by soft reset
        self::assertSame('version 2', file_get_contents($this->testDir . '/file.txt'));
    }

    public function testRmHandler(): void
    {
        $repo = $this->initRepoWithUser();
        file_put_contents($this->testDir . '/delete-me.txt', 'to be deleted');

        $add = new AddHandler($repo);
        $add->handle(['delete-me.txt']);

        $commit = new CommitHandler($repo);
        $commit->handle('Add file');

        $rm = new RmHandler($repo);
        $rm->handle(['delete-me.txt']);

        self::assertFileDoesNotExist($this->testDir . '/delete-me.txt');

        $status = new StatusHandler($repo);
        $result = $status->handle();
        self::assertArrayHasKey('delete-me.txt', $result['staged']);
        self::assertSame(FileStatus::Deleted, $result['staged']['delete-me.txt']);
    }

    public function testSubdirectoryFiles(): void
    {
        $repo = $this->initRepoWithUser();

        mkdir($this->testDir . '/src/lib', 0o777, true);
        file_put_contents($this->testDir . '/src/app.php', '<?php echo "hi";');
        file_put_contents($this->testDir . '/src/lib/util.php', '<?php function util() {}');

        $add = new AddHandler($repo);
        $add->handle(['src']);

        $status = new StatusHandler($repo);
        $result = $status->handle();
        self::assertArrayHasKey('src/app.php', $result['staged']);
        self::assertArrayHasKey('src/lib/util.php', $result['staged']);

        $commit = new CommitHandler($repo);
        $commit->handle('Add src');

        $result = $status->handle();
        self::assertSame([], $result['staged']);
    }

    public function testGitignoreFiltering(): void
    {
        Repository::init($this->testDir);

        // Create .gitignore and files, then re-open (like a real CLI invocation)
        file_put_contents($this->testDir . '/.gitignore', "*.log\nbuild/\n");
        file_put_contents($this->testDir . '/code.php', '<?php echo "hi";');
        file_put_contents($this->testDir . '/debug.log', 'log data');
        mkdir($this->testDir . '/build', 0o777, true);
        file_put_contents($this->testDir . '/build/output.js', 'compiled');

        // Re-open so GitignoreMatcher sees the .gitignore file
        $repo = Repository::open($this->testDir);

        // Status should not show ignored files as untracked
        $status = new StatusHandler($repo);
        $result = $status->handle();
        self::assertContains('code.php', $result['untracked']);
        self::assertContains('.gitignore', $result['untracked']);
        self::assertNotContains('debug.log', $result['untracked']);

        // Add . should not stage ignored files
        $add = new AddHandler($repo);
        $add->handle(['.gitignore', 'code.php']);

        // Also test add with directory - build/ should be ignored
        $add->handle(['build']);
        $result = $status->handle();
        self::assertArrayHasKey('code.php', $result['staged']);
        self::assertArrayHasKey('.gitignore', $result['staged']);
        self::assertArrayNotHasKey('debug.log', $result['staged']);
        self::assertArrayNotHasKey('build/output.js', $result['staged']);
    }

    private function initRepoWithUser(): Repository
    {
        $repo = Repository::init($this->testDir);
        $configPath = $repo->gitDir . '/config';
        $config = file_exists($configPath) ? file_get_contents($configPath) : '';
        file_put_contents($configPath, $config . "\n[user]\n\tname = Test User\n\temail = test@test.com\n");

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
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}

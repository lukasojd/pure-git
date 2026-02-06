<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Application\Handler;

use DateTimeImmutable;
use Lukasojd\PureGit\Application\Handler\AddHandler;
use Lukasojd\PureGit\Application\Handler\BranchHandler;
use Lukasojd\PureGit\Application\Handler\CommitHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Object\PersonInfo;
use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Infrastructure\Config\GitConfigReader;
use Lukasojd\PureGit\Infrastructure\Config\GitConfigWriter;
use PHPUnit\Framework\TestCase;

final class BranchHandlerTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/puregit-branch-test-' . uniqid();
        mkdir($this->testDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function testUnsetUpstreamRemovesTrackingConfig(): void
    {
        $repo = $this->createRepoWithCommit();

        // Set up tracking config
        $writer = new GitConfigWriter();
        $configPath = $repo->gitDir . '/config';
        $writer->set($configPath, 'branch "main"', 'remote', 'origin');
        $writer->set($configPath, 'branch "main"', 'merge', 'refs/heads/main');

        // Verify config exists
        $config = new GitConfigReader($configPath);
        self::assertSame('origin', $config->get('branch "main"', 'remote'));

        // Unset upstream
        $handler = new BranchHandler($repo);
        $handler->unsetUpstream('main');

        // Verify config is gone
        $config = new GitConfigReader($configPath);
        self::assertNull($config->get('branch "main"', 'remote'));
        self::assertNull($config->get('branch "main"', 'merge'));
    }

    public function testUnsetUpstreamDefaultsToCurrentBranch(): void
    {
        $repo = $this->createRepoWithCommit();

        // Set up tracking config for current branch (main)
        $writer = new GitConfigWriter();
        $configPath = $repo->gitDir . '/config';
        $writer->set($configPath, 'branch "main"', 'remote', 'origin');
        $writer->set($configPath, 'branch "main"', 'merge', 'refs/heads/main');

        $handler = new BranchHandler($repo);
        $handler->unsetUpstream(); // no argument = current branch

        $config = new GitConfigReader($configPath);
        self::assertNull($config->get('branch "main"', 'remote'));
        self::assertNull($config->get('branch "main"', 'merge'));
    }

    public function testGetTrackingInfoReturnsGoneWhenUpstreamDeleted(): void
    {
        $repo = $this->createRepoWithCommit();

        // Set tracking config pointing to a remote ref that doesn't exist
        $writer = new GitConfigWriter();
        $configPath = $repo->gitDir . '/config';
        $writer->set($configPath, 'branch "main"', 'remote', 'origin');
        $writer->set($configPath, 'branch "main"', 'merge', 'refs/heads/main');

        $handler = new BranchHandler($repo);
        $tracking = $handler->getTrackingInfo(RefName::branch('main'));

        self::assertNotNull($tracking);
        self::assertTrue($tracking->gone);
        self::assertStringContainsString('origin/main', $tracking->upstream);
    }

    public function testGetTrackingInfoReturnsNullWithoutConfig(): void
    {
        $repo = $this->createRepoWithCommit();

        $handler = new BranchHandler($repo);
        $tracking = $handler->getTrackingInfo(RefName::branch('main'));

        self::assertNull($tracking);
    }

    private function createRepoWithCommit(): Repository
    {
        $repo = Repository::init($this->testDir);
        file_put_contents($this->testDir . '/test.txt', 'hello');
        $addHandler = new AddHandler($repo);
        $addHandler->handle(['test.txt']);
        $commitHandler = new CommitHandler($repo);
        $commitHandler->handle('Initial commit', new PersonInfo('Test', 'test@test.com', new DateTimeImmutable()));

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

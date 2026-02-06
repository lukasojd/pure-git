<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Application\Handler;

use DateTimeImmutable;
use Lukasojd\PureGit\Application\Handler\AddHandler;
use Lukasojd\PureGit\Application\Handler\CommitHandler;
use Lukasojd\PureGit\Application\Handler\PushHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Object\PersonInfo;
use Lukasojd\PureGit\Infrastructure\Config\GitConfigReader;
use Lukasojd\PureGit\Infrastructure\Config\GitConfigWriter;
use Lukasojd\PureGit\Infrastructure\Transport\StreamingPackReceiver;
use Lukasojd\PureGit\Infrastructure\Transport\TransportFactory;
use PHPUnit\Framework\TestCase;

final class PushHandlerTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/puregit-push-test-' . uniqid();
        mkdir($this->testDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function testPushUpToDate(): void
    {
        [$localRepo, $remoteDir] = $this->setupLocalAndRemote();

        $handler = new PushHandler($localRepo);
        $result = $handler->push('origin');

        self::assertTrue($result->upToDate);
        self::assertSame(0, $result->objectsSent);
    }

    public function testPushNewCommit(): void
    {
        [$localRepo, $remoteDir] = $this->setupLocalAndRemote();

        // Add a new commit locally
        file_put_contents($localRepo->workDir . '/new.txt', 'new content');
        $addHandler = new AddHandler($localRepo);
        $addHandler->handle(['new.txt']);
        $commitHandler = new CommitHandler($localRepo);
        $commitHandler->handle('New commit', new PersonInfo('Test', 'test@test.com', new DateTimeImmutable()));

        $handler = new PushHandler($localRepo);
        $result = $handler->push('origin');

        self::assertFalse($result->upToDate);
        self::assertGreaterThan(0, $result->objectsSent);
        self::assertCount(1, $result->refUpdates);
        self::assertSame('refs/heads/main', $result->refUpdates[0]->refName);
    }

    public function testPushSkipsRemoteObjectsNotAvailableLocally(): void
    {
        [$localRepo, $remoteDir] = $this->setupLocalAndRemote();

        // Create a separate branch in the remote that the local doesn't have
        $remote = Repository::open($remoteDir);
        file_put_contents($remoteDir . '/remote-only.txt', 'remote only content');
        $addHandler = new AddHandler($remote);
        $addHandler->handle(['remote-only.txt']);
        $commitHandler = new CommitHandler($remote);
        $commitHandler->handle('Remote-only commit', new PersonInfo('Test', 'test@test.com', new DateTimeImmutable()));

        // Create a branch pointing to this new commit
        $headId = $remote->refs->resolve(\Lukasojd\PureGit\Domain\Ref\RefName::head());
        $remote->refs->updateRef(\Lukasojd\PureGit\Domain\Ref\RefName::branch('feature'), $headId);

        // Reset remote main back so local is still ahead after adding a commit
        // The local doesn't have the feature branch objects
        file_put_contents($localRepo->workDir . '/local-new.txt', 'local content');
        $addHandler = new AddHandler($localRepo);
        $addHandler->handle(['local-new.txt']);
        $commitHandler = new CommitHandler($localRepo);
        $commitHandler->handle('Local commit', new PersonInfo('Test', 'test@test.com', new DateTimeImmutable()));

        // This should NOT crash even though remote has objects we don't have locally
        $handler = new PushHandler($localRepo);
        $result = $handler->push('origin');

        self::assertFalse($result->upToDate);
        self::assertGreaterThan(0, $result->objectsSent);
    }

    public function testSetUpstreamTracking(): void
    {
        [$localRepo] = $this->setupLocalAndRemote();

        $handler = new PushHandler($localRepo);
        $handler->setUpstreamTracking('origin', 'refs/heads/main');

        $config = new GitConfigReader($localRepo->gitDir . '/config');
        self::assertSame('origin', $config->get('branch "main"', 'remote'));
        self::assertSame('refs/heads/main', $config->get('branch "main"', 'merge'));
    }

    public function testSetUpstreamTrackingWithShortRef(): void
    {
        [$localRepo] = $this->setupLocalAndRemote();

        $handler = new PushHandler($localRepo);
        $handler->setUpstreamTracking('origin', 'feature');

        $config = new GitConfigReader($localRepo->gitDir . '/config');
        self::assertSame('origin', $config->get('branch "feature"', 'remote'));
        self::assertSame('refs/heads/feature', $config->get('branch "feature"', 'merge'));
    }

    public function testPushNewBranch(): void
    {
        [$localRepo, $remoteDir] = $this->setupLocalAndRemote();

        // Create a local branch
        $headId = $localRepo->refs->resolve(\Lukasojd\PureGit\Domain\Ref\RefName::head());
        $localRepo->refs->updateRef(\Lukasojd\PureGit\Domain\Ref\RefName::branch('new-branch'), $headId);

        $handler = new PushHandler($localRepo);
        $result = $handler->push('origin', 'new-branch');

        self::assertFalse($result->upToDate);
        self::assertCount(1, $result->refUpdates);
        self::assertNull($result->refUpdates[0]->oldHash);
        self::assertSame('refs/heads/new-branch', $result->refUpdates[0]->refName);
    }

    /**
     * @return array{Repository, string}
     */
    private function setupLocalAndRemote(): array
    {
        $remoteDir = $this->testDir . '/remote';
        mkdir($remoteDir, 0o777, true);
        $remote = Repository::init($remoteDir);

        // Create initial commit in remote
        file_put_contents($remoteDir . '/test.txt', 'hello');
        $addHandler = new AddHandler($remote);
        $addHandler->handle(['test.txt']);
        $commitHandler = new CommitHandler($remote);
        $commitHandler->handle('Initial commit', new PersonInfo('Test', 'test@test.com', new DateTimeImmutable()));

        // Clone locally
        $localDir = $this->testDir . '/local';
        $this->setupLocalClone($remoteDir, $localDir);

        return [Repository::open($localDir), $remoteDir];
    }

    private function setupLocalClone(string $remoteDir, string $localDir): void
    {
        mkdir($localDir, 0o777, true);
        $gitDir = $localDir . '/.git';

        $transport = TransportFactory::create($remoteDir);
        $refs = $transport->listRefs();

        foreach ([$gitDir, $gitDir . '/objects', $gitDir . '/objects/pack', $gitDir . '/refs', $gitDir . '/refs/heads', $gitDir . '/refs/tags', $gitDir . '/refs/remotes', $gitDir . '/refs/remotes/origin'] as $dir) {
            mkdir($dir, 0o777, true);
        }

        $wants = [];
        $seen = [];
        foreach ($refs as $name => $id) {
            if ($name === 'HEAD' || isset($seen[$id->hash])) {
                continue;
            }
            $seen[$id->hash] = true;
            $wants[] = $id;
        }

        if ($wants !== []) {
            $tempPath = $gitDir . '/objects/pack/tmp-fetch.pack';
            $packPath = $transport->fetchPack($wants, [], $tempPath);

            $receiverPath = $gitDir . '/objects/pack/tmp-recv.pack';
            $receiver = new StreamingPackReceiver($receiverPath);
            $receiver->feedPackData((string) file_get_contents($packPath));
            $receivedPath = $receiver->finish();
            unlink($packPath);

            $fh = fopen($receivedPath, 'rb');
            if ($fh !== false) {
                fseek($fh, -20, SEEK_END);
                $checksum = fread($fh, 20);
                fclose($fh);
                if ($checksum !== false) {
                    $hex = bin2hex($checksum);
                    rename($receivedPath, $gitDir . '/objects/pack/pack-' . $hex . '.pack');
                    $idxPath = substr($receivedPath, 0, -5) . '.idx';
                    if (file_exists($idxPath)) {
                        rename($idxPath, $gitDir . '/objects/pack/pack-' . $hex . '.idx');
                    }
                }
            }
        }

        // Write local branch pointing to same commit as remote main
        foreach ($refs as $name => $id) {
            if ($name === 'HEAD') {
                continue;
            }
            if (str_starts_with($name, 'refs/heads/')) {
                // Write local branch
                $branchPath = $gitDir . '/' . $name;
                $branchDir = dirname($branchPath);
                if (! is_dir($branchDir)) {
                    mkdir($branchDir, 0o777, true);
                }
                file_put_contents($branchPath, $id->hash . "\n");

                // Write remote-tracking ref
                $trackingRef = 'refs/remotes/origin/' . substr($name, strlen('refs/heads/'));
                $refPath = $gitDir . '/' . $trackingRef;
                $refDir = dirname($refPath);
                if (! is_dir($refDir)) {
                    mkdir($refDir, 0o777, true);
                }
                file_put_contents($refPath, $id->hash . "\n");
            }
        }

        file_put_contents($gitDir . '/HEAD', "ref: refs/heads/main\n");

        $writer = new GitConfigWriter();
        $configPath = $gitDir . '/config';
        $writer->set($configPath, 'core', 'repositoryformatversion', '0');
        $writer->set($configPath, 'core', 'filemode', 'true');
        $writer->set($configPath, 'core', 'bare', 'false');
        $writer->set($configPath, 'remote "origin"', 'url', $remoteDir);
        $writer->set($configPath, 'remote "origin"', 'fetch', '+refs/heads/*:refs/remotes/origin/*');
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

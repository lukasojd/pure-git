<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Application\Handler;

use Lukasojd\PureGit\Application\Handler\FetchHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Object\PersonInfo;
use PHPUnit\Framework\TestCase;

final class FetchHandlerTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/puregit-fetch-test-' . uniqid();
        mkdir($this->testDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function testThrowsWhenRemoteNotConfigured(): void
    {
        $repo = Repository::init($this->testDir);
        $handler = new FetchHandler($repo);

        $this->expectException(PureGitException::class);
        $this->expectExceptionMessage("'origin' does not appear to be a git repository");
        $handler->fetch();
    }

    public function testAlreadyUpToDateWithLocalTransport(): void
    {
        // Set up a "remote" bare repository
        $remoteDir = $this->testDir . '/remote.git';
        mkdir($remoteDir, 0o777, true);
        $remote = Repository::init($remoteDir);

        // Create a commit in the remote
        file_put_contents($remoteDir . '/test.txt', 'hello');
        $addHandler = new \Lukasojd\PureGit\Application\Handler\AddHandler($remote);
        $addHandler->handle(['test.txt']);
        $commitHandler = new \Lukasojd\PureGit\Application\Handler\CommitHandler($remote);
        $commitHandler->handle('Initial commit', new PersonInfo('Test', 'test@test.com', new \DateTimeImmutable()));

        // Clone locally using local transport
        $localDir = $this->testDir . '/local';
        $this->setupLocalClone($remoteDir, $localDir);

        // Now fetch — should be up to date since we already have everything
        $localRepo = Repository::open($localDir);
        $handler = new FetchHandler($localRepo);
        $result = $handler->fetch();

        self::assertTrue($result->upToDate);
        self::assertSame(0, $result->newObjects);
        self::assertSame(0, $result->updatedRefs);
    }

    public function testFetchNewCommitsFromLocal(): void
    {
        // Set up a "remote" repository
        $remoteDir = $this->testDir . '/remote';
        mkdir($remoteDir, 0o777, true);
        $remote = Repository::init($remoteDir);

        // Create initial commit in remote
        file_put_contents($remoteDir . '/test.txt', 'hello');
        $addHandler = new \Lukasojd\PureGit\Application\Handler\AddHandler($remote);
        $addHandler->handle(['test.txt']);
        $commitHandler = new \Lukasojd\PureGit\Application\Handler\CommitHandler($remote);
        $commitHandler->handle('Initial commit', new PersonInfo('Test', 'test@test.com', new \DateTimeImmutable()));

        // Clone locally
        $localDir = $this->testDir . '/local';
        $this->setupLocalClone($remoteDir, $localDir);

        // Add a new commit to the remote
        $remote = Repository::open($remoteDir);
        file_put_contents($remoteDir . '/test2.txt', 'world');
        $addHandler = new \Lukasojd\PureGit\Application\Handler\AddHandler($remote);
        $addHandler->handle(['test2.txt']);
        $commitHandler = new \Lukasojd\PureGit\Application\Handler\CommitHandler($remote);
        $commitHandler->handle('Second commit', new PersonInfo('Test', 'test@test.com', new \DateTimeImmutable()));

        // Fetch — should get new objects
        $localRepo = Repository::open($localDir);
        $handler = new FetchHandler($localRepo);
        $result = $handler->fetch();

        self::assertFalse($result->upToDate);
        self::assertGreaterThan(0, $result->newObjects);
        self::assertGreaterThan(0, $result->updatedRefs);
    }

    private function setupLocalClone(string $remoteDir, string $localDir): void
    {
        // Simulate a clone by using CloneCommand-like logic with local transport
        mkdir($localDir, 0o777, true);
        $gitDir = $localDir . '/.git';

        $transport = \Lukasojd\PureGit\Infrastructure\Transport\TransportFactory::create($remoteDir);
        $refs = $transport->listRefs();

        // Create bare structure
        foreach ([$gitDir, $gitDir . '/objects', $gitDir . '/objects/pack', $gitDir . '/refs', $gitDir . '/refs/heads', $gitDir . '/refs/tags', $gitDir . '/refs/remotes', $gitDir . '/refs/remotes/origin'] as $dir) {
            mkdir($dir, 0o777, true);
        }

        // Fetch pack
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

            // LocalTransport doesn't generate .idx, so we re-index via StreamingPackReceiver
            $receiverPath = $gitDir . '/objects/pack/tmp-recv.pack';
            $receiver = new \Lukasojd\PureGit\Infrastructure\Transport\StreamingPackReceiver($receiverPath);
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

        // Write remote-tracking refs
        foreach ($refs as $name => $id) {
            if ($name === 'HEAD') {
                continue;
            }
            if (str_starts_with($name, 'refs/heads/')) {
                $trackingRef = 'refs/remotes/origin/' . substr($name, strlen('refs/heads/'));
                $refPath = $gitDir . '/' . $trackingRef;
                $refDir = dirname($refPath);
                if (! is_dir($refDir)) {
                    mkdir($refDir, 0o777, true);
                }
                file_put_contents($refPath, $id->hash . "\n");
            }
        }

        // Write HEAD and config
        file_put_contents($gitDir . '/HEAD', "ref: refs/heads/main\n");
        file_put_contents($gitDir . '/config', "[core]\n\trepositoryformatversion = 0\n\tfilemode = true\n\tbare = false\n[remote \"origin\"]\n\turl = " . $remoteDir . "\n\tfetch = +refs/heads/*:refs/remotes/origin/*\n");
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

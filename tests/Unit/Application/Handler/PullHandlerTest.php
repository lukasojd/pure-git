<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Application\Handler;

use Lukasojd\PureGit\Application\Handler\AddHandler;
use Lukasojd\PureGit\Application\Handler\CommitHandler;
use Lukasojd\PureGit\Application\Handler\FetchHandler;
use Lukasojd\PureGit\Application\Handler\PullHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\PersonInfo;
use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Infrastructure\Transport\TransportFactory;
use PHPUnit\Framework\TestCase;

final class PullHandlerTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/puregit-pull-test-' . uniqid();
        mkdir($this->testDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function testPullUpToDate(): void
    {
        [$localRepo] = $this->setupClonedRepo();

        // Set local branch to tracking ref
        $trackingId = $localRepo->refs->resolve(RefName::fromString('refs/remotes/origin/main'));
        $localRepo->refs->updateRef(RefName::branch('main'), $trackingId);

        $handler = new PullHandler($localRepo);
        $result = $handler->pull('origin');

        self::assertTrue($result->upToDate);
    }

    public function testPullFastForward(): void
    {
        [$localRepo, $remoteDir] = $this->setupClonedRepo();

        // Set local branch to tracking ref first
        $trackingId = $localRepo->refs->resolve(RefName::fromString('refs/remotes/origin/main'));
        $localRepo->refs->updateRef(RefName::branch('main'), $trackingId);

        // Add a commit to remote
        $remote = Repository::open($remoteDir);
        $this->addFileAndCommit($remote, 'new.txt', 'new content', 'Remote commit');

        $handler = new PullHandler($localRepo);
        $result = $handler->pull('origin');

        self::assertFalse($result->upToDate);
        self::assertTrue($result->fastForward);
        self::assertFalse($result->rebase);
        self::assertInstanceOf(ObjectId::class, $result->oldHeadId);
        self::assertInstanceOf(ObjectId::class, $result->newHeadId);
        self::assertFalse($result->oldHeadId->equals($result->newHeadId));
    }

    public function testPullAfterFetchStillFastForwards(): void
    {
        [$localRepo, $remoteDir] = $this->setupClonedRepo();

        // Set local branch to tracking ref
        $trackingId = $localRepo->refs->resolve(RefName::fromString('refs/remotes/origin/main'));
        $localRepo->refs->updateRef(RefName::branch('main'), $trackingId);

        // Add commit to remote
        $remote = Repository::open($remoteDir);
        $this->addFileAndCommit($remote, 'fetched.txt', 'content', 'Fetched commit');

        // Fetch first (tracking ref updated, local branch behind)
        $fetchHandler = new FetchHandler($localRepo);
        $fetchResult = $fetchHandler->fetch('origin');
        self::assertFalse($fetchResult->upToDate);

        // Pull should still fast-forward
        $handler = new PullHandler($localRepo);
        $result = $handler->pull('origin');

        self::assertFalse($result->upToDate);
        self::assertTrue($result->fastForward);
    }

    public function testPullRebase(): void
    {
        [$localRepo, $remoteDir] = $this->setupClonedRepo();

        // Set local branch to tracking ref
        $trackingId = $localRepo->refs->resolve(RefName::fromString('refs/remotes/origin/main'));
        $localRepo->refs->updateRef(RefName::branch('main'), $trackingId);

        // Add commit to remote
        $remote = Repository::open($remoteDir);
        $this->addFileAndCommit($remote, 'upstream.txt', 'upstream', 'Upstream commit');

        // Add local commit
        $this->addFileAndCommit($localRepo, 'local.txt', 'local', 'Local commit');

        // Pull with rebase
        $handler = new PullHandler($localRepo);
        $result = $handler->pull('origin', rebase: true);

        self::assertFalse($result->upToDate);
        self::assertTrue($result->rebase);
    }

    public function testPullThrowsWhenNotOnBranch(): void
    {
        [$localRepo] = $this->setupClonedRepo();

        // Create local branch, then detach HEAD
        $trackingId = $localRepo->refs->resolve(RefName::fromString('refs/remotes/origin/main'));
        $localRepo->refs->updateRef(RefName::branch('main'), $trackingId);
        $localRepo->refs->updateRef(RefName::head(), $trackingId);

        $handler = new PullHandler($localRepo);

        $this->expectException(PureGitException::class);
        $this->expectExceptionMessage('not currently on a branch');
        $handler->pull('origin');
    }

    /**
     * @return array{Repository, string}
     */
    private function setupClonedRepo(): array
    {
        $remoteDir = $this->testDir . '/remote';
        mkdir($remoteDir, 0o777, true);
        $remote = Repository::init($remoteDir);

        $this->addFileAndCommit($remote, 'test.txt', 'hello', 'Initial commit');

        $localDir = $this->testDir . '/local';
        $this->setupLocalClone($remoteDir, $localDir);

        return [Repository::open($localDir), $remoteDir];
    }

    private function addFileAndCommit(Repository $repo, string $file, string $content, string $message): void
    {
        file_put_contents($repo->workDir . '/' . $file, $content);
        $add = new AddHandler($repo);
        $add->handle([$file]);
        $commit = new CommitHandler($repo);
        $commit->handle($message, new PersonInfo('Test', 'test@test.com', new \DateTimeImmutable()));
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

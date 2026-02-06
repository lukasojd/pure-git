<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Acceptance;

use Lukasojd\PureGit\Application\Handler\CommitHandler;
use Lukasojd\PureGit\Application\Handler\FetchHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Object\Blob;
use Lukasojd\PureGit\Domain\Object\FileMode;
use Lukasojd\PureGit\Domain\Ref\RefName;
use PHPUnit\Framework\TestCase;

abstract class AcceptanceTestCase extends TestCase
{
    protected string $sshHost;

    protected int $sshPort;

    protected string $httpHost;

    protected int $httpPort;

    protected string $repoPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sshHost = getenv('GIT_SERVER_HOST') ?: 'gitserver';
        $this->sshPort = (int) (getenv('GIT_SERVER_SSH_PORT') ?: '22');
        $this->httpHost = getenv('GIT_SERVER_HOST') ?: 'gitserver';
        $this->httpPort = (int) (getenv('GIT_SERVER_HTTP_PORT') ?: '80');
        $this->repoPath = getenv('GIT_REPO_PATH') ?: '/srv/git/test-repo.git';
    }

    protected function getSshUrl(): string
    {
        if ($this->sshPort !== 22) {
            return sprintf('ssh://git@%s:%d%s', $this->sshHost, $this->sshPort, $this->repoPath);
        }

        return sprintf('git@%s:%s', $this->sshHost, ltrim($this->repoPath, '/'));
    }

    protected function getHttpUrl(): string
    {
        return sprintf('http://%s:%d/test-repo.git', $this->httpHost, $this->httpPort);
    }

    protected function cloneToTempDir(string $url): Repository
    {
        $tempDir = sys_get_temp_dir() . '/puregit-test-' . uniqid();
        mkdir($tempDir, 0o777, true);

        $repo = Repository::init($tempDir);

        $configPath = $repo->gitDir . '/config';
        $config = file_get_contents($configPath);
        $config .= sprintf(
            "\n[remote \"origin\"]\n\turl = %s\n\tfetch = +refs/heads/*:refs/remotes/origin/*\n",
            $url,
        );
        file_put_contents($configPath, $config);

        $fetchHandler = new FetchHandler($repo);
        $fetchHandler->fetch('origin');

        // Set up main branch tracking
        $trackingRefs = $repo->refs->listRefs('refs/remotes/origin/');
        foreach ($trackingRefs as $refName => $id) {
            $branch = str_replace('refs/remotes/origin/', '', $refName);
            $repo->refs->updateRef(RefName::branch($branch), $id);
            break;
        }

        // Set HEAD
        $repo->refs->updateSymbolicRef(RefName::head(), RefName::branch('main'));

        // Add branch tracking config
        $config = file_get_contents($configPath);
        $config .= "\n[branch \"main\"]\n\tremote = origin\n\tmerge = refs/heads/main\n";
        $config .= "\n[user]\n\tname = PureGit Test\n\temail = test@puregit.local\n";
        file_put_contents($configPath, $config);

        return Repository::open($tempDir);
    }

    protected function addFileAndCommit(Repository $repo, string $filename, string $content, string $message): void
    {
        $blob = new Blob($content);
        $repo->objects->write($blob);

        $index = $repo->index->read();
        $entry = \Lukasojd\PureGit\Domain\Index\IndexEntry::create(
            $filename,
            $blob->getId(),
            FileMode::Regular,
            strlen($content),
        );
        $index->addEntry($entry);
        $repo->index->write($index);

        $commitHandler = new CommitHandler($repo);
        $commitHandler->handle($message);
    }

    protected function cleanupDir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($path);
    }
}

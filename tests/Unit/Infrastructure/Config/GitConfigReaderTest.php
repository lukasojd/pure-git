<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Infrastructure\Config;

use Lukasojd\PureGit\Infrastructure\Config\GitConfigReader;
use PHPUnit\Framework\TestCase;

final class GitConfigReaderTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir() . '/puregit-config-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->configPath)) {
            unlink($this->configPath);
        }
    }

    public function testReadsCoreSection(): void
    {
        file_put_contents($this->configPath, "[core]\n\tbare = false\n\tfilemode = true\n");
        $reader = new GitConfigReader($this->configPath);

        self::assertSame('false', $reader->get('core', 'bare'));
        self::assertSame('true', $reader->get('core', 'filemode'));
    }

    public function testReadsBranchTrackingConfig(): void
    {
        $config = <<<'INI'
[core]
	bare = false
[branch "main"]
	remote = origin
	merge = refs/heads/main
[branch "feature/test"]
	remote = origin
	merge = refs/heads/feature/test
INI;
        file_put_contents($this->configPath, $config);
        $reader = new GitConfigReader($this->configPath);

        self::assertSame('refs/remotes/origin/main', $reader->getUpstreamRef('main'));
        self::assertSame('refs/remotes/origin/feature/test', $reader->getUpstreamRef('feature/test'));
        self::assertNull($reader->getUpstreamRef('nonexistent'));
    }

    public function testNonexistentFile(): void
    {
        $reader = new GitConfigReader('/nonexistent/path');

        self::assertNull($reader->get('core', 'bare'));
    }

    public function testCommentsAndEmptyLines(): void
    {
        $config = <<<'INI'
# Comment
[core]
	; another comment
	bare = false

[remote "origin"]
	url = https://example.com/repo.git
INI;
        file_put_contents($this->configPath, $config);
        $reader = new GitConfigReader($this->configPath);

        self::assertSame('false', $reader->get('core', 'bare'));
        self::assertSame('https://example.com/repo.git', $reader->get('remote "origin"', 'url'));
    }
}

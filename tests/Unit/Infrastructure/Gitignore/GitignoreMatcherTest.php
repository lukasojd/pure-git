<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Infrastructure\Gitignore;

use Lukasojd\PureGit\Infrastructure\Gitignore\GitignoreMatcher;
use PHPUnit\Framework\TestCase;

final class GitignoreMatcherTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/puregit-gitignore-test-' . uniqid();
        mkdir($this->testDir, 0o777, true);
        mkdir($this->testDir . '/.git/info', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function testGitDirectoryAlwaysIgnored(): void
    {
        $matcher = $this->createMatcher();

        self::assertTrue($matcher->isIgnored('.git'));
        self::assertTrue($matcher->isIgnored('.git/objects'));
    }

    public function testWildcardPattern(): void
    {
        $this->writeGitignore("*.log\n");
        $matcher = $this->createMatcher();

        self::assertTrue($matcher->isIgnored('debug.log'));
        self::assertTrue($matcher->isIgnored('sub/debug.log'));
        self::assertFalse($matcher->isIgnored('code.php'));
    }

    public function testDirectoryOnlyPattern(): void
    {
        $this->writeGitignore("build/\n");
        $matcher = $this->createMatcher();

        self::assertTrue($matcher->isIgnored('build', true));
        self::assertFalse($matcher->isIgnored('build', false));
        self::assertTrue($matcher->isIgnored('sub/build', true));
    }

    public function testNegationPattern(): void
    {
        $this->writeGitignore("*.log\n!important.log\n");
        $matcher = $this->createMatcher();

        self::assertTrue($matcher->isIgnored('debug.log'));
        self::assertFalse($matcher->isIgnored('important.log'));
    }

    public function testLeadingSlashAnchorsToRoot(): void
    {
        $this->writeGitignore("/root-only\n");
        $matcher = $this->createMatcher();

        self::assertTrue($matcher->isIgnored('root-only'));
        self::assertFalse($matcher->isIgnored('sub/root-only'));
    }

    public function testDoubleStarPrefix(): void
    {
        $this->writeGitignore("**/logs\n");
        $matcher = $this->createMatcher();

        self::assertTrue($matcher->isIgnored('logs'));
        self::assertTrue($matcher->isIgnored('a/logs'));
        self::assertTrue($matcher->isIgnored('a/b/logs'));
    }

    public function testDoubleStarMiddle(): void
    {
        $this->writeGitignore("a/**/b\n");
        $matcher = $this->createMatcher();

        self::assertTrue($matcher->isIgnored('a/b'));
        self::assertTrue($matcher->isIgnored('a/x/b'));
        self::assertTrue($matcher->isIgnored('a/x/y/b'));
        self::assertFalse($matcher->isIgnored('b'));
    }

    public function testPatternWithSlashMatchesPath(): void
    {
        $this->writeGitignore("doc/*.txt\n");
        $matcher = $this->createMatcher();

        self::assertTrue($matcher->isIgnored('doc/notes.txt'));
        self::assertFalse($matcher->isIgnored('doc/sub/notes.txt'));
        self::assertFalse($matcher->isIgnored('notes.txt'));
    }

    public function testCommentsAndBlankLines(): void
    {
        $this->writeGitignore("# comment\n\n*.log\n  \n");
        $matcher = $this->createMatcher();

        self::assertTrue($matcher->isIgnored('debug.log'));
        self::assertFalse($matcher->isIgnored('code.php'));
    }

    public function testQuestionMarkWildcard(): void
    {
        $this->writeGitignore("file?.txt\n");
        $matcher = $this->createMatcher();

        self::assertTrue($matcher->isIgnored('file1.txt'));
        self::assertTrue($matcher->isIgnored('fileA.txt'));
        self::assertFalse($matcher->isIgnored('file12.txt'));
    }

    public function testInfoExcludeRules(): void
    {
        file_put_contents($this->testDir . '/.git/info/exclude', "*.bak\n");
        $matcher = $this->createMatcher();

        self::assertTrue($matcher->isIgnored('data.bak'));
        self::assertFalse($matcher->isIgnored('data.txt'));
    }

    public function testSubdirectoryGitignore(): void
    {
        $this->writeGitignore("*.log\n");
        mkdir($this->testDir . '/vendor', 0o777, true);
        file_put_contents($this->testDir . '/vendor/.gitignore', "*.tmp\n");
        $matcher = $this->createMatcher();

        self::assertTrue($matcher->isIgnored('vendor/cache.tmp'));
        self::assertFalse($matcher->isIgnored('cache.tmp'));
        self::assertTrue($matcher->isIgnored('vendor/debug.log'));
    }

    public function testDoubleStarSuffix(): void
    {
        $this->writeGitignore("logs/**\n");
        $matcher = $this->createMatcher();

        self::assertTrue($matcher->isIgnored('logs/debug.log'));
        self::assertTrue($matcher->isIgnored('logs/sub/trace.log'));
    }

    public function testNoGitignoreFile(): void
    {
        $matcher = $this->createMatcher();

        self::assertFalse($matcher->isIgnored('anything.txt'));
        self::assertTrue($matcher->isIgnored('.git'));
    }

    public function testCharacterClass(): void
    {
        $this->writeGitignore("file[0-9].txt\n");
        $matcher = $this->createMatcher();

        self::assertTrue($matcher->isIgnored('file5.txt'));
        self::assertFalse($matcher->isIgnored('fileA.txt'));
    }

    public function testGlobalExcludesFile(): void
    {
        // Create a fake XDG config dir with global ignore
        $xdgDir = $this->testDir . '/xdg-config/git';
        mkdir($xdgDir, 0o777, true);
        file_put_contents($xdgDir . '/ignore', "**/.secret\n*.bak\n");

        // Point XDG_CONFIG_HOME to our test directory
        $origXdg = $_SERVER['XDG_CONFIG_HOME'] ?? null;
        $_SERVER['XDG_CONFIG_HOME'] = $this->testDir . '/xdg-config';

        try {
            $matcher = $this->createMatcher();
            self::assertTrue($matcher->isIgnored('.secret'));
            self::assertTrue($matcher->isIgnored('sub/.secret'));
            self::assertTrue($matcher->isIgnored('backup.bak'));
            self::assertFalse($matcher->isIgnored('code.php'));
        } finally {
            if ($origXdg === null) {
                unset($_SERVER['XDG_CONFIG_HOME']);
            } else {
                $_SERVER['XDG_CONFIG_HOME'] = $origXdg;
            }
        }
    }

    public function testCoreExcludesFileFromConfig(): void
    {
        // Write a custom excludes file
        $excludesPath = $this->testDir . '/my-global-ignore';
        file_put_contents($excludesPath, "*.generated\n");

        // Write repo config with core.excludesFile
        file_put_contents(
            $this->testDir . '/.git/config',
            "[core]\n\texcludesFile = " . $excludesPath . "\n",
        );

        $matcher = $this->createMatcher();
        self::assertTrue($matcher->isIgnored('output.generated'));
        self::assertFalse($matcher->isIgnored('output.txt'));
    }

    private function createMatcher(): GitignoreMatcher
    {
        return new GitignoreMatcher($this->testDir, $this->testDir . '/.git');
    }

    private function writeGitignore(string $content): void
    {
        file_put_contents($this->testDir . '/.gitignore', $content);
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

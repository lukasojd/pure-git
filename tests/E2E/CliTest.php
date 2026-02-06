<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\E2E;

use Lukasojd\PureGit\CLI\Application;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    private string $testDir;

    private string $originalDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/puregit-cli-test-' . uniqid();
        mkdir($this->testDir, 0o777, true);
        $cwd = getcwd();
        self::assertIsString($cwd);
        $this->originalDir = $cwd;
        chdir($this->testDir);
    }

    protected function tearDown(): void
    {
        chdir($this->originalDir);
        $this->removeDir($this->testDir);
    }

    public function testHelpCommand(): void
    {
        $app = new Application();
        $exit = $this->runCapture($app, ['puregit', '--help']);
        self::assertSame(0, $exit);
    }

    public function testVersionCommand(): void
    {
        $app = new Application();
        $exit = $this->runCapture($app, ['puregit', '--version']);
        self::assertSame(0, $exit);
    }

    public function testInitCommand(): void
    {
        $app = new Application();
        $exit = $this->runCapture($app, ['puregit', 'init']);
        self::assertSame(0, $exit);
        self::assertDirectoryExists($this->testDir . '/.git');
    }

    public function testFullCLIWorkflow(): void
    {
        $app = new Application();

        // Init
        $exit = $this->runCapture($app, ['puregit', 'init']);
        self::assertSame(0, $exit);

        // Set user identity
        $configPath = $this->testDir . '/.git/config';
        file_put_contents($configPath, file_get_contents($configPath) . "\n[user]\n\tname = PureGit User\n\temail = user@puregit.local\n");

        // Create file
        file_put_contents($this->testDir . '/test.txt', 'CLI test');

        // Add
        $exit = $this->runCapture($app, ['puregit', 'add', 'test.txt']);
        self::assertSame(0, $exit);

        // Commit
        $exit = $this->runCapture($app, ['puregit', 'commit', '-m', 'CLI commit']);
        self::assertSame(0, $exit);

        // Status
        $exit = $this->runCapture($app, ['puregit', 'status']);
        self::assertSame(0, $exit);

        // Log
        $exit = $this->runCapture($app, ['puregit', 'log']);
        self::assertSame(0, $exit);

        // Branch
        $exit = $this->runCapture($app, ['puregit', 'branch', 'test-branch']);
        self::assertSame(0, $exit);

        // Tag
        $exit = $this->runCapture($app, ['puregit', 'tag', 'v0.1']);
        self::assertSame(0, $exit);

        // Show
        $exit = $this->runCapture($app, ['puregit', 'show']);
        self::assertSame(0, $exit);

        // Diff
        $exit = $this->runCapture($app, ['puregit', 'diff']);
        self::assertSame(0, $exit);

        // Branch list
        $exit = $this->runCapture($app, ['puregit', 'branch']);
        self::assertSame(0, $exit);

        // Tag list
        $exit = $this->runCapture($app, ['puregit', 'tag']);
        self::assertSame(0, $exit);
    }

    public function testUnknownCommand(): void
    {
        $app = new Application();
        $exit = $this->runCapture($app, ['puregit', 'nonexistent']);
        self::assertSame(1, $exit);
    }

    public function testCommandHelp(): void
    {
        $app = new Application();
        $exit = $this->runCapture($app, ['puregit', 'init', '--help']);
        self::assertSame(0, $exit);
    }

    /**
     * Run CLI app while suppressing fwrite to STDOUT/STDERR.
     *
     * @param list<string> $argv
     */
    private function runCapture(Application $app, array $argv): int
    {
        // Redirect STDOUT to suppress test output
        $tmpFile = tempnam(sys_get_temp_dir(), 'puregit-stdout-');
        self::assertIsString($tmpFile);

        // We can't easily redirect STDOUT fwrite, so just suppress via output buffering
        // and accept that some output leaks to test runner
        ob_start();
        $exit = $app->run($argv);
        ob_end_clean();

        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }

        return $exit;
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

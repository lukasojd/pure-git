<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Infrastructure\Config;

use Lukasojd\PureGit\Infrastructure\Config\GitConfigWriter;
use PHPUnit\Framework\TestCase;

final class GitConfigWriterTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir() . '/puregit-writer-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->configPath)) {
            unlink($this->configPath);
        }
    }

    public function testSetCreatesNewFileWithSection(): void
    {
        $writer = new GitConfigWriter();
        $writer->set($this->configPath, 'user', 'name', 'John Doe');

        $content = file_get_contents($this->configPath);
        self::assertSame("[user]\n\tname = John Doe\n", $content);
    }

    public function testSetAddsKeyToExistingSection(): void
    {
        file_put_contents($this->configPath, "[user]\n\tname = John Doe\n");

        $writer = new GitConfigWriter();
        $writer->set($this->configPath, 'user', 'email', 'john@example.com');

        $content = file_get_contents($this->configPath);
        self::assertSame("[user]\n\tname = John Doe\n\temail = john@example.com\n", $content);
    }

    public function testSetUpdatesExistingKey(): void
    {
        file_put_contents($this->configPath, "[user]\n\tname = John Doe\n");

        $writer = new GitConfigWriter();
        $writer->set($this->configPath, 'user', 'name', 'Jane Doe');

        $content = file_get_contents($this->configPath);
        self::assertSame("[user]\n\tname = Jane Doe\n", $content);
    }

    public function testSetCreatesNewSection(): void
    {
        file_put_contents($this->configPath, "[core]\n\tbare = false\n");

        $writer = new GitConfigWriter();
        $writer->set($this->configPath, 'user', 'name', 'John Doe');

        $content = file_get_contents($this->configPath);
        self::assertSame("[core]\n\tbare = false\n[user]\n\tname = John Doe\n", $content);
    }

    public function testSetPreservesComments(): void
    {
        file_put_contents($this->configPath, "# My config\n[user]\n\t; comment\n\tname = John Doe\n");

        $writer = new GitConfigWriter();
        $writer->set($this->configPath, 'user', 'email', 'john@example.com');

        $content = file_get_contents($this->configPath);
        self::assertStringContainsString('# My config', $content);
        self::assertStringContainsString('; comment', $content);
        self::assertStringContainsString('email = john@example.com', $content);
    }

    public function testUnsetRemovesKey(): void
    {
        file_put_contents($this->configPath, "[user]\n\tname = John Doe\n\temail = john@example.com\n");

        $writer = new GitConfigWriter();
        $result = $writer->unsetKey($this->configPath, 'user', 'name');

        self::assertTrue($result);
        $content = file_get_contents($this->configPath);
        self::assertStringNotContainsString('name', $content);
        self::assertStringContainsString('email = john@example.com', $content);
    }

    public function testUnsetReturnsFalseForMissingKey(): void
    {
        file_put_contents($this->configPath, "[user]\n\tname = John Doe\n");

        $writer = new GitConfigWriter();
        $result = $writer->unsetKey($this->configPath, 'user', 'email');

        self::assertFalse($result);
    }

    public function testSetWithSubsection(): void
    {
        $writer = new GitConfigWriter();
        $writer->set($this->configPath, 'branch "main"', 'remote', 'origin');

        $content = file_get_contents($this->configPath);
        self::assertSame("[branch \"main\"]\n\tremote = origin\n", $content);
    }
}

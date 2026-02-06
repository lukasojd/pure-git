<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Application\Handler;

use Lukasojd\PureGit\Application\Handler\ConfigHandler;
use Lukasojd\PureGit\Application\Handler\ConfigScope;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
use PHPUnit\Framework\TestCase;

final class ConfigHandlerTest extends TestCase
{
    private string $gitDir;

    private string $homeDir;

    private string|false $originalHome;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/puregit-config-handler-' . uniqid();
        $this->gitDir = $base . '/.git';
        $this->homeDir = $base . '/home';
        mkdir($this->gitDir, 0o777, true);
        mkdir($this->homeDir, 0o777, true);

        $this->originalHome = getenv('HOME');
        putenv('HOME=' . $this->homeDir);
    }

    protected function tearDown(): void
    {
        if ($this->originalHome === false) {
            putenv('HOME');
        } else {
            putenv('HOME=' . $this->originalHome);
        }

        $this->removeDir(dirname($this->gitDir));
    }

    public function testGetReturnsLocalValue(): void
    {
        file_put_contents($this->gitDir . '/config', "[user]\n\tname = Local User\n");

        $handler = new ConfigHandler($this->gitDir);
        self::assertSame('Local User', $handler->get('user.name'));
    }

    public function testGetFallsBackToGlobal(): void
    {
        file_put_contents($this->homeDir . '/.gitconfig', "[user]\n\tname = Global User\n");

        $handler = new ConfigHandler($this->gitDir);
        self::assertSame('Global User', $handler->get('user.name'));
    }

    public function testGetLocalOverridesGlobal(): void
    {
        file_put_contents($this->gitDir . '/config', "[user]\n\tname = Local User\n");
        file_put_contents($this->homeDir . '/.gitconfig', "[user]\n\tname = Global User\n");

        $handler = new ConfigHandler($this->gitDir);
        self::assertSame('Local User', $handler->get('user.name'));
    }

    public function testGetWithScopeIgnoresOther(): void
    {
        file_put_contents($this->gitDir . '/config', "[user]\n\tname = Local User\n");
        file_put_contents($this->homeDir . '/.gitconfig', "[user]\n\tname = Global User\n");

        $handler = new ConfigHandler($this->gitDir);
        self::assertSame('Global User', $handler->get('user.name', ConfigScope::Global));
        self::assertSame('Local User', $handler->get('user.name', ConfigScope::Local));
    }

    public function testSetLocalWritesToConfig(): void
    {
        $handler = new ConfigHandler($this->gitDir);
        $handler->set('user.name', 'New User', ConfigScope::Local);

        self::assertSame('New User', $handler->get('user.name', ConfigScope::Local));
    }

    public function testSetGlobalWritesToGitconfig(): void
    {
        $handler = new ConfigHandler($this->gitDir);
        $handler->set('user.name', 'Global User', ConfigScope::Global);

        self::assertSame('Global User', $handler->get('user.name', ConfigScope::Global));
        self::assertFileExists($this->homeDir . '/.gitconfig');
    }

    public function testUnsetRemovesKey(): void
    {
        file_put_contents($this->gitDir . '/config', "[user]\n\tname = Local User\n\temail = a@b.com\n");

        $handler = new ConfigHandler($this->gitDir);
        $handler->unsetKey('user.name', ConfigScope::Local);

        self::assertNull($handler->get('user.name', ConfigScope::Local));
        self::assertSame('a@b.com', $handler->get('user.email', ConfigScope::Local));
    }

    public function testListMergesGlobalAndLocal(): void
    {
        file_put_contents($this->homeDir . '/.gitconfig', "[user]\n\tname = Global\n\temail = global@example.com\n");
        file_put_contents($this->gitDir . '/config', "[user]\n\tname = Local\n[core]\n\tbare = false\n");

        $handler = new ConfigHandler($this->gitDir);
        $list = $handler->list();

        self::assertSame('Local', $list['user.name']);
        self::assertSame('global@example.com', $list['user.email']);
        self::assertSame('false', $list['core.bare']);
    }

    public function testParseKeyWithSubsection(): void
    {
        $handler = new ConfigHandler();
        [$section, $property] = $handler->parseKey('branch.main.remote');

        self::assertSame('branch "main"', $section);
        self::assertSame('remote', $property);
    }

    public function testLocalScopeWithoutGitDirThrows(): void
    {
        $handler = new ConfigHandler();

        $this->expectException(PureGitException::class);
        $this->expectExceptionMessage('Cannot use --local outside a git repository');
        $handler->set('user.name', 'Foo', ConfigScope::Local);
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

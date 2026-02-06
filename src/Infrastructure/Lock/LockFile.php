<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Lock;

use Lukasojd\PureGit\Domain\Exception\LockException;

final class LockFile
{
    private readonly string $lockPath;

    /**
     * @var resource|null
     */
    private $handle;

    public function __construct(
        private readonly string $targetPath,
    ) {
        $this->lockPath = $targetPath . '.lock';
    }

    public function __destruct()
    {
        $this->rollback();
    }

    public function acquire(): void
    {
        $dir = dirname($this->lockPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $handle = fopen($this->lockPath, 'x');
        if ($handle === false) {
            throw LockException::alreadyLocked($this->lockPath);
        }

        $this->handle = $handle;
    }

    public function write(string $data): void
    {
        if ($this->handle === null) {
            throw LockException::alreadyLocked($this->lockPath);
        }

        fwrite($this->handle, $data);
    }

    public function commit(): void
    {
        if ($this->handle === null) {
            throw LockException::alreadyLocked($this->lockPath);
        }

        fflush($this->handle);
        fclose($this->handle);
        $this->handle = null;

        rename($this->lockPath, $this->targetPath);
    }

    public function rollback(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }

        if (file_exists($this->lockPath)) {
            unlink($this->lockPath);
        }
    }
}

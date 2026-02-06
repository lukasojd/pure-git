<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Exception;

final class LockException extends PureGitException
{
    public static function alreadyLocked(string $path): self
    {
        return new self(sprintf('Unable to create lock file: %s', $path));
    }
}

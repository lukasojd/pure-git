<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Exception;

final class RepositoryException extends PureGitException
{
    public static function notARepository(string $path): self
    {
        return new self(sprintf('Not a PureGit repository: %s', $path));
    }

    public static function alreadyExists(string $path): self
    {
        return new self(sprintf('Repository already exists: %s', $path));
    }
}

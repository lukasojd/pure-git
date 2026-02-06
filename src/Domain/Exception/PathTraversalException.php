<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Exception;

final class PathTraversalException extends PureGitException
{
    public static function detected(string $path): self
    {
        return new self(sprintf('Path traversal detected: %s', $path));
    }
}

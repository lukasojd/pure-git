<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Exception;

final class InvalidRefNameException extends PureGitException
{
    public static function withName(string $name): self
    {
        return new self(sprintf('Invalid reference name: %s', $name));
    }
}

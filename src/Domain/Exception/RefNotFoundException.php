<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Exception;

final class RefNotFoundException extends PureGitException
{
    public static function withName(string $name): self
    {
        return new self(sprintf('Reference not found: %s', $name));
    }
}

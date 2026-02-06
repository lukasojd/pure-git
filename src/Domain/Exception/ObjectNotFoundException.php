<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Exception;

final class ObjectNotFoundException extends PureGitException
{
    public static function withId(string $id): self
    {
        return new self(sprintf('Object not found: %s', $id));
    }
}

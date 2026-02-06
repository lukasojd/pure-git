<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Exception;

final class InvalidObjectException extends PureGitException
{
    public static function invalidHash(string $hash): self
    {
        return new self(sprintf('Invalid object hash: %s', $hash));
    }

    public static function invalidType(string $type): self
    {
        return new self(sprintf('Invalid object type: %s', $type));
    }

    public static function corruptObject(string $id, string $reason): self
    {
        return new self(sprintf('Corrupt object %s: %s', $id, $reason));
    }
}

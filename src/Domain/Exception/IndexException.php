<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Exception;

final class IndexException extends PureGitException
{
    public static function corruptIndex(string $reason): self
    {
        return new self(sprintf('Corrupt index: %s', $reason));
    }
}

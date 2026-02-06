<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Exception;

final class MergeConflictException extends PureGitException
{
    /**
     * @param list<string> $conflictedPaths
     */
    public function __construct(
        public readonly array $conflictedPaths,
        string $message = 'Merge conflict detected',
    ) {
        parent::__construct($message);
    }
}

<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Domain\Object\ObjectId;

final readonly class RebaseResult
{
    public function __construct(
        public int $replayedCommits,
        public ObjectId $newHeadId,
    ) {
    }
}

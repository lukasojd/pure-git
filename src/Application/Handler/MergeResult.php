<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Domain\Object\ObjectId;

final readonly class MergeResult
{
    public function __construct(
        public ObjectId $commitId,
        public bool $fastForward,
        public ObjectId $oldId,
    ) {
    }
}

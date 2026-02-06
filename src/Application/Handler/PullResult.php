<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Domain\Object\ObjectId;

final readonly class PullResult
{
    public function __construct(
        public FetchResult $fetchResult,
        public ?ObjectId $mergeCommitId,
        public bool $upToDate,
        public bool $fastForward,
        public bool $rebase,
    ) {
    }
}

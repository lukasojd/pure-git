<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

final readonly class VerifyResult
{
    public function __construct(
        public bool $valid,
        public string $message,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Diff;

final readonly class DiffLine
{
    public function __construct(
        public DiffLineType $type,
        public string $content,
        public ?int $oldLineNumber,
        public ?int $newLineNumber,
    ) {
    }
}

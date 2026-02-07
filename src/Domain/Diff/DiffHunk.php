<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Diff;

final readonly class DiffHunk
{
    /**
     * @param list<DiffLine> $lines
     */
    public function __construct(
        public int $oldStart,
        public int $oldCount,
        public int $newStart,
        public int $newCount,
        public array $lines,
        public ?string $contextLabel = null,
    ) {
    }

    public function header(): string
    {
        $old = $this->oldCount === 1 ? (string) $this->oldStart : sprintf('%d,%d', $this->oldStart, $this->oldCount);
        $new = $this->newCount === 1 ? (string) $this->newStart : sprintf('%d,%d', $this->newStart, $this->newCount);

        $label = $this->contextLabel !== null ? ' ' . $this->contextLabel : '';

        return sprintf('@@ -%s +%s @@%s', $old, $new, $label);
    }
}

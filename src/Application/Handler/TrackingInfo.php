<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

final readonly class TrackingInfo
{
    public function __construct(
        public string $upstream,
        public int $ahead,
        public int $behind,
        public bool $gone = false,
    ) {
    }

    public function formatMessage(): string
    {
        if ($this->gone) {
            return sprintf(
                "Your branch is based on '%s', but the upstream is gone.\n  (use \"git branch --unset-upstream\" to fixup)",
                $this->upstream,
            );
        }

        if ($this->ahead === 0 && $this->behind === 0) {
            return sprintf("Your branch is up to date with '%s'.", $this->upstream);
        }

        if ($this->ahead > 0 && $this->behind > 0) {
            return $this->formatDiverged();
        }

        return $this->ahead > 0 ? $this->formatAhead() : $this->formatBehind();
    }

    private function formatAhead(): string
    {
        return sprintf(
            "Your branch is ahead of '%s' by %d commit%s.",
            $this->upstream,
            $this->ahead,
            $this->ahead === 1 ? '' : 's',
        );
    }

    private function formatBehind(): string
    {
        return sprintf(
            "Your branch is behind '%s' by %d commit%s, and can be fast-forwarded.\n  (use \"git pull\" to update your local branch)",
            $this->upstream,
            $this->behind,
            $this->behind === 1 ? '' : 's',
        );
    }

    private function formatDiverged(): string
    {
        return sprintf(
            "Your branch and '%s' have diverged,\nand have %d and %d different commits each, respectively.\n  (use \"git pull\" if you want to integrate the remote branch with yours)",
            $this->upstream,
            $this->ahead,
            $this->behind,
        );
    }
}

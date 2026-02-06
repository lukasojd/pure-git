<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Diff;

interface DiffAlgorithm
{
    /**
     * @param list<string> $oldLines
     * @param list<string> $newLines
     * @return list<DiffHunk>
     */
    public function diff(array $oldLines, array $newLines): array;
}

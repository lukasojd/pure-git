<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Diff;

final readonly class HunkContextLabelResolver
{
    /**
     * @param list<DiffHunk> $hunks
     * @param list<string> $oldLines
     * @return list<DiffHunk>
     */
    public function addLabels(array $hunks, array $oldLines): array
    {
        if ($oldLines === []) {
            return $hunks;
        }

        $result = [];
        foreach ($hunks as $hunk) {
            $label = $this->findLabel($oldLines, $hunk->oldStart - 1);
            $result[] = $label !== null
                ? new DiffHunk($hunk->oldStart, $hunk->oldCount, $hunk->newStart, $hunk->newCount, $hunk->lines, $label)
                : $hunk;
        }

        return $result;
    }

    /**
     * @param list<string> $lines
     */
    private function findLabel(array $lines, int $searchFrom): ?string
    {
        for ($i = min($searchFrom, count($lines)) - 1; $i >= 0; $i--) {
            $line = $lines[$i];
            if ($this->isContextLine($line)) {
                return $line;
            }
        }

        return null;
    }

    private function isContextLine(string $line): bool
    {
        return preg_match(
            '/^(?:[\t ]*(?:(?:public|protected|private|static|abstract|final|readonly)\s+)*function\s|[\t ]*class\s|[\t ]*interface\s|[\t ]*trait\s|[\t ]*enum\s|[a-zA-Z$_])/',
            $line,
        ) === 1;
    }
}

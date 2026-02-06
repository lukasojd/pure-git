<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Diff;

use Lukasojd\PureGit\Domain\Diff\DiffAlgorithm;
use Lukasojd\PureGit\Domain\Diff\DiffHunk;
use Lukasojd\PureGit\Domain\Diff\DiffLine;
use Lukasojd\PureGit\Domain\Diff\DiffLineType;

final class MyersDiffAlgorithm implements DiffAlgorithm
{
    private const int CONTEXT_LINES = 3;

    /**
     * @param list<string> $oldLines
     * @param list<string> $newLines
     * @return list<DiffHunk>
     */
    public function diff(array $oldLines, array $newLines): array
    {
        $lcs = $this->computeLcs($oldLines, $newLines);
        $editScript = $this->buildEditScript($oldLines, $newLines, $lcs);

        return $this->buildHunks($editScript);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return array<int, array<int, int>>
     */
    private function computeLcs(array $a, array $b): array
    {
        $m = count($a);
        $n = count($b);

        $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                $dp[$i][$j] = $a[$i - 1] === $b[$j - 1] ? $dp[$i - 1][$j - 1] + 1 : max($dp[$i - 1][$j], $dp[$i][$j - 1]);
            }
        }

        return $dp;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @param array<int, array<int, int>> $dp
     * @return list<array{type: DiffLineType, oldLine: ?int, newLine: ?int, content: string}>
     */
    private function buildEditScript(array $a, array $b, array $dp): array
    {
        $result = [];
        $i = count($a);
        $j = count($b);

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $a[$i - 1] === $b[$j - 1]) {
                $result[] = [
                    'type' => DiffLineType::Context,
                    'oldLine' => $i,
                    'newLine' => $j,
                    'content' => $a[$i - 1],
                ];
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $dp[$i][$j - 1] >= $dp[$i - 1][$j])) {
                $result[] = [
                    'type' => DiffLineType::Added,
                    'oldLine' => null,
                    'newLine' => $j,
                    'content' => $b[$j - 1],
                ];
                $j--;
            } elseif ($i > 0) {
                $result[] = [
                    'type' => DiffLineType::Removed,
                    'oldLine' => $i,
                    'newLine' => null,
                    'content' => $a[$i - 1],
                ];
                $i--;
            }
        }

        return array_reverse($result);
    }

    /**
     * @param list<array{type: DiffLineType, oldLine: ?int, newLine: ?int, content: string}> $editScript
     * @return list<DiffHunk>
     */
    private function buildHunks(array $editScript): array
    {
        $changeRanges = $this->findChangeRanges($editScript);

        if ($changeRanges === []) {
            return [];
        }

        $mergedRanges = $this->mergeRanges($changeRanges);

        return $this->buildHunksFromRanges($editScript, $mergedRanges);
    }

    /**
     * @param list<array{type: DiffLineType, oldLine: ?int, newLine: ?int, content: string}> $editScript
     * @return list<array{int, int}>
     */
    private function findChangeRanges(array $editScript): array
    {
        $changeRanges = [];
        $inChange = false;
        $changeStart = -1;

        foreach ($editScript as $idx => $edit) {
            if ($edit['type'] !== DiffLineType::Context) {
                if (! $inChange) {
                    $changeStart = $idx;
                    $inChange = true;
                }
            } elseif ($inChange) {
                $changeRanges[] = [$changeStart, $idx - 1];
                $inChange = false;
            }
        }

        if ($inChange) {
            $changeRanges[] = [$changeStart, count($editScript) - 1];
        }

        return $changeRanges;
    }

    /**
     * @param list<array{type: DiffLineType, oldLine: ?int, newLine: ?int, content: string}> $editScript
     * @param list<array{int, int}> $mergedRanges
     * @return list<DiffHunk>
     */
    private function buildHunksFromRanges(array $editScript, array $mergedRanges): array
    {
        $hunks = [];

        foreach ($mergedRanges as [$start, $end]) {
            $hunks[] = $this->buildSingleHunk($editScript, $start, $end);
        }

        return $hunks;
    }

    /**
     * @param list<array{type: DiffLineType, oldLine: ?int, newLine: ?int, content: string}> $editScript
     */
    private function buildSingleHunk(array $editScript, int $start, int $end): DiffHunk
    {
        $contextStart = max(0, $start - self::CONTEXT_LINES);
        $contextEnd = min(count($editScript) - 1, $end + self::CONTEXT_LINES);

        $lines = [];
        $oldStart = null;
        $newStart = null;
        $oldCount = 0;
        $newCount = 0;

        for ($k = $contextStart; $k <= $contextEnd; $k++) {
            $edit = $editScript[$k];
            $lines[] = new DiffLine($edit['type'], $edit['content'], $edit['oldLine'], $edit['newLine']);
            $this->updateHunkCounts($edit, $oldStart, $newStart, $oldCount, $newCount);
        }

        return new DiffHunk(
            oldStart: $oldStart ?? 1,
            oldCount: $oldCount,
            newStart: $newStart ?? 1,
            newCount: $newCount,
            lines: $lines,
        );
    }

    /**
     * @param array{type: DiffLineType, oldLine: ?int, newLine: ?int, content: string} $edit
     */
    private function updateHunkCounts(array $edit, ?int &$oldStart, ?int &$newStart, int &$oldCount, int &$newCount): void
    {
        match ($edit['type']) {
            DiffLineType::Context => (function () use (&$oldCount, &$newCount, $edit, &$oldStart, &$newStart): void {
                $oldCount++;
                $newCount++;
                $oldStart ??= $edit['oldLine'];
                $newStart ??= $edit['newLine'];
            })(),
            DiffLineType::Added => (function () use (&$newCount, $edit, &$oldStart, &$newStart): void {
                $newCount++;
                $newStart ??= $edit['newLine'];
                $oldStart ??= ($edit['oldLine'] ?? 1);
            })(),
            DiffLineType::Removed => (function () use (&$oldCount, $edit, &$oldStart, &$newStart): void {
                $oldCount++;
                $oldStart ??= $edit['oldLine'];
                $newStart ??= ($edit['newLine'] ?? 1);
            })(),
        };
    }

    /**
     * @param list<array{int, int}> $ranges
     * @return list<array{int, int}>
     */
    private function mergeRanges(array $ranges): array
    {
        if ($ranges === []) {
            return [];
        }

        $merged = [$ranges[0]];
        $counter = count($ranges);

        for ($i = 1; $i < $counter; $i++) {
            $last = &$merged[count($merged) - 1];
            $current = $ranges[$i];

            // Merge if within 2 * context lines
            if (2 * self::CONTEXT_LINES + 1 >= $current[0] - $last[1]) {
                $last[1] = $current[1];
            } else {
                $merged[] = $current;
            }
        }

        return $merged;
    }
}

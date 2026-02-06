<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Merge;

use Lukasojd\PureGit\Domain\Merge\MergeResult;
use Lukasojd\PureGit\Domain\Merge\MergeStrategy;

final class ThreeWayMerge implements MergeStrategy
{
    /**
     * @param list<string> $baseLines
     * @param list<string> $oursLines
     * @param list<string> $theirsLines
     */
    public function merge(array $baseLines, array $oursLines, array $theirsLines): MergeResult
    {
        $oursChanges = $this->computeChanges($baseLines, $oursLines);
        $theirsChanges = $this->computeChanges($baseLines, $theirsLines);

        $merger = new ThreeWayMergeProcessor($oursChanges, $theirsChanges, $baseLines, $oursLines, $theirsLines);

        return $merger->process();
    }

    /**
     * @param list<string> $baseLines
     * @param list<string> $changedLines
     * @return array<int, array{count: int, new: string}>
     */
    private function computeChanges(array $baseLines, array $changedLines): array
    {
        $lcs = $this->buildLcsSequence($baseLines, $changedLines);

        return $this->extractChangesFromLcs($baseLines, $changedLines, $lcs);
    }

    /**
     * @param list<string> $baseLines
     * @param list<string> $changedLines
     * @param list<string> $lcs
     * @return array<int, array{count: int, new: string}>
     */
    private function extractChangesFromLcs(array $baseLines, array $changedLines, array $lcs): array
    {
        $changes = [];
        $baseIdx = 0;
        $changedIdx = 0;
        $lcsIdx = 0;

        while ($baseIdx < count($baseLines) || $changedIdx < count($changedLines)) {
            if ($this->isLcsMatch($baseLines, $changedLines, $lcs, $baseIdx, $changedIdx, $lcsIdx)) {
                $baseIdx++;
                $changedIdx++;
                $lcsIdx++;
                continue;
            }

            $changeStart = $baseIdx;
            $baseIdx = $this->advanceBaseToLcs($baseLines, $lcs, $baseIdx, $lcsIdx);
            $newLines = $this->collectChangedLines($changedLines, $lcs, $changedIdx, $lcsIdx);
            $changedIdx += count($newLines);

            if ($baseIdx > $changeStart || $newLines !== []) {
                $changes[$changeStart] = [
                    'count' => $baseIdx - $changeStart,
                    'new' => implode("\n", $newLines),
                ];
            }
        }

        return $changes;
    }

    /**
     * @param list<string> $baseLines
     * @param list<string> $changedLines
     * @param list<string> $lcs
     */
    private function isLcsMatch(array $baseLines, array $changedLines, array $lcs, int $baseIdx, int $changedIdx, int $lcsIdx): bool
    {
        return $lcsIdx < count($lcs)
            && $baseIdx < count($baseLines)
            && $changedIdx < count($changedLines)
            && $baseLines[$baseIdx] === $lcs[$lcsIdx]
            && $changedLines[$changedIdx] === $lcs[$lcsIdx];
    }

    /**
     * @param list<string> $baseLines
     * @param list<string> $lcs
     */
    private function advanceBaseToLcs(array $baseLines, array $lcs, int $baseIdx, int $lcsIdx): int
    {
        while ($baseIdx < count($baseLines) && ($lcsIdx >= count($lcs) || $baseLines[$baseIdx] !== $lcs[$lcsIdx])) {
            $baseIdx++;
        }

        return $baseIdx;
    }

    /**
     * @param list<string> $changedLines
     * @param list<string> $lcs
     * @return list<string>
     */
    private function collectChangedLines(array $changedLines, array $lcs, int $changedIdx, int $lcsIdx): array
    {
        $newLines = [];

        while ($changedIdx < count($changedLines) && ($lcsIdx >= count($lcs) || $changedLines[$changedIdx] !== $lcs[$lcsIdx])) {
            $newLines[] = $changedLines[$changedIdx];
            $changedIdx++;
        }

        return $newLines;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    private function buildLcsSequence(array $a, array $b): array
    {
        $dp = $this->buildLcsTable($a, $b);

        return $this->backtrackLcs($a, $b, $dp);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return array<int, array<int, int>>
     */
    private function buildLcsTable(array $a, array $b): array
    {
        $m = count($a);
        $n = count($b);
        $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                $dp[$i][$j] = $a[$i - 1] === $b[$j - 1]
                    ? $dp[$i - 1][$j - 1] + 1
                    : max($dp[$i - 1][$j], $dp[$i][$j - 1]);
            }
        }

        return $dp;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @param array<int, array<int, int>> $dp
     * @return list<string>
     */
    private function backtrackLcs(array $a, array $b, array $dp): array
    {
        $result = [];
        $i = count($a);
        $j = count($b);

        while ($i > 0 && $j > 0) {
            if ($a[$i - 1] === $b[$j - 1]) {
                $result[] = $a[$i - 1];
                $i--;
                $j--;
            } elseif ($dp[$i - 1][$j] > $dp[$i][$j - 1]) {
                $i--;
            } else {
                $j--;
            }
        }

        return array_reverse($result);
    }
}

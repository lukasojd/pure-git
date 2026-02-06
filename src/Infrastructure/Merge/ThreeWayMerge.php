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

        $result = [];
        $hasConflict = false;
        $baseLen = count($baseLines);

        $oursIdx = 0;
        $theirsIdx = 0;
        $baseIdx = 0;

        while ($baseIdx < $baseLen || $oursIdx < count($oursLines) || $theirsIdx < count($theirsLines)) {
            $oursChanged = isset($oursChanges[$baseIdx]);
            $theirsChanged = isset($theirsChanges[$baseIdx]);

            if ($oursChanged && $theirsChanged) {
                $oursBlock = $oursChanges[$baseIdx];
                $theirsBlock = $theirsChanges[$baseIdx];

                if ($oursBlock['new'] === $theirsBlock['new']) {
                    // Same change on both sides
                    foreach (explode("\n", $oursBlock['new']) as $line) {
                        $result[] = $line;
                    }
                } else {
                    // Conflict
                    $hasConflict = true;
                    $result[] = '<<<<<<< ours';
                    if ($oursBlock['new'] !== '') {
                        foreach (explode("\n", $oursBlock['new']) as $line) {
                            $result[] = $line;
                        }
                    }
                    $result[] = '=======';
                    if ($theirsBlock['new'] !== '') {
                        foreach (explode("\n", $theirsBlock['new']) as $line) {
                            $result[] = $line;
                        }
                    }
                    $result[] = '>>>>>>> theirs';
                }

                $baseIdx += max($oursBlock['count'], $theirsBlock['count']);
                $oursIdx += count(explode("\n", $oursBlock['new']));
                $theirsIdx += count(explode("\n", $theirsBlock['new']));
            } elseif ($oursChanged) {
                $block = $oursChanges[$baseIdx];
                if ($block['new'] !== '') {
                    foreach (explode("\n", $block['new']) as $line) {
                        $result[] = $line;
                    }
                }
                $baseIdx += $block['count'];
                $oursIdx += $block['new'] === '' ? 0 : count(explode("\n", $block['new']));
                $theirsIdx += $block['count'];
            } elseif ($theirsChanged) {
                $block = $theirsChanges[$baseIdx];
                if ($block['new'] !== '') {
                    foreach (explode("\n", $block['new']) as $line) {
                        $result[] = $line;
                    }
                }
                $baseIdx += $block['count'];
                $oursIdx += $block['count'];
                $theirsIdx += $block['new'] === '' ? 0 : count(explode("\n", $block['new']));
            } elseif ($baseIdx < $baseLen) {
                $result[] = $baseLines[$baseIdx];
                $baseIdx++;
                $oursIdx++;
                $theirsIdx++;
            } else {
                break;
            }
        }

        // Append remaining lines from ours or theirs
        while ($oursIdx < count($oursLines)) {
            $result[] = $oursLines[$oursIdx];
            $oursIdx++;
        }

        $content = implode("\n", $result);
        $conflicts = $hasConflict ? ['content'] : [];

        return $hasConflict
            ? MergeResult::conflicted($content, $conflicts)
            : MergeResult::clean($content);
    }

    /**
     * @param list<string> $baseLines
     * @param list<string> $changedLines
     * @return array<int, array{count: int, new: string}>
     */
    private function computeChanges(array $baseLines, array $changedLines): array
    {
        $lcs = $this->lcs($baseLines, $changedLines);
        $changes = [];

        $baseIdx = 0;
        $changedIdx = 0;
        $lcsIdx = 0;

        while ($baseIdx < count($baseLines) || $changedIdx < count($changedLines)) {
            if ($lcsIdx < count($lcs) && $baseIdx < count($baseLines) && $changedIdx < count($changedLines)
                && $baseLines[$baseIdx] === $lcs[$lcsIdx] && $changedLines[$changedIdx] === $lcs[$lcsIdx]) {
                $baseIdx++;
                $changedIdx++;
                $lcsIdx++;
                continue;
            }

            $changeStart = $baseIdx;
            $newLines = [];

            while ($baseIdx < count($baseLines) && ($lcsIdx >= count($lcs) || $baseLines[$baseIdx] !== $lcs[$lcsIdx])) {
                $baseIdx++;
            }

            while ($changedIdx < count($changedLines) && ($lcsIdx >= count($lcs) || $changedLines[$changedIdx] !== $lcs[$lcsIdx])) {
                $newLines[] = $changedLines[$changedIdx];
                $changedIdx++;
            }

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
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    private function lcs(array $a, array $b): array
    {
        $m = count($a);
        $n = count($b);
        $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                $dp[$i][$j] = $a[$i - 1] === $b[$j - 1] ? $dp[$i - 1][$j - 1] + 1 : max($dp[$i - 1][$j], $dp[$i][$j - 1]);
            }
        }

        $result = [];
        $i = $m;
        $j = $n;

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

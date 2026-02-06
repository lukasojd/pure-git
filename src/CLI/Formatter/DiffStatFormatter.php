<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Formatter;

use Lukasojd\PureGit\Domain\Diff\DiffLineType;
use Lukasojd\PureGit\Domain\Diff\FileDiff;
use Lukasojd\PureGit\Domain\Diff\FileStatus;

final class DiffStatFormatter
{
    private const int MAX_GRAPH_WIDTH = 40;

    private const string GREEN = "\033[32m";

    private const string RED = "\033[31m";

    private const string RESET = "\033[0m";

    /**
     * @param list<FileDiff> $diffs
     */
    public static function format(array $diffs): string
    {
        if ($diffs === []) {
            return '';
        }

        $stats = self::collectStats($diffs);
        $maxPathLen = self::maxPathLength($stats);
        $maxChanges = self::maxTotalChanges($stats);
        $scale = $maxChanges > self::MAX_GRAPH_WIDTH ? self::MAX_GRAPH_WIDTH / $maxChanges : 1.0;

        $lines = [];
        $totalAdd = 0;
        $totalDel = 0;

        foreach ($stats as $stat) {
            $lines[] = self::formatFileLine($stat, $maxPathLen, $scale);
            $totalAdd += $stat['add'];
            $totalDel += $stat['del'];
        }

        $lines[] = self::formatSummary(count($stats), $totalAdd, $totalDel);
        $lines[] = self::formatModeLines($diffs);

        return implode("\n", array_filter($lines, fn (string $l): bool => $l !== ''));
    }

    /**
     * @param list<FileDiff> $diffs
     * @return list<array{path: string, add: int, del: int, status: FileStatus}>
     */
    private static function collectStats(array $diffs): array
    {
        $stats = [];
        foreach ($diffs as $diff) {
            $add = 0;
            $del = 0;
            foreach ($diff->hunks as $hunk) {
                foreach ($hunk->lines as $line) {
                    match ($line->type) {
                        DiffLineType::Added => $add++,
                        DiffLineType::Removed => $del++,
                        default => null,
                    };
                }
            }
            $stats[] = [
                'path' => $diff->path,
                'add' => $add,
                'del' => $del,
                'status' => $diff->status,
            ];
        }

        return $stats;
    }

    /**
     * @param list<array{path: string, add: int, del: int, status: FileStatus}> $stats
     */
    private static function maxPathLength(array $stats): int
    {
        $max = 0;
        foreach ($stats as $stat) {
            $len = strlen($stat['path']);
            if ($len > $max) {
                $max = $len;
            }
        }

        return $max;
    }

    /**
     * @param list<array{path: string, add: int, del: int, status: FileStatus}> $stats
     */
    private static function maxTotalChanges(array $stats): int
    {
        $max = 0;
        foreach ($stats as $stat) {
            $total = $stat['add'] + $stat['del'];
            if ($total > $max) {
                $max = $total;
            }
        }

        return $max;
    }

    /**
     * @param array{path: string, add: int, del: int, status: FileStatus} $stat
     */
    private static function formatFileLine(array $stat, int $maxPathLen, float $scale): string
    {
        $total = $stat['add'] + $stat['del'];
        $plusCount = (int) round($stat['add'] * $scale);
        $minusCount = (int) round($stat['del'] * $scale);
        $graph = self::colorGraph($plusCount, $minusCount);

        return sprintf(' %-*s | %3d %s', $maxPathLen, $stat['path'], $total, $graph);
    }

    private static function colorGraph(int $plusCount, int $minusCount): string
    {
        $graph = '';
        if ($plusCount > 0) {
            $graph .= self::GREEN . str_repeat('+', $plusCount) . self::RESET;
        }
        if ($minusCount > 0) {
            $graph .= self::RED . str_repeat('-', $minusCount) . self::RESET;
        }

        return $graph;
    }

    private static function formatSummary(int $fileCount, int $totalAdd, int $totalDel): string
    {
        $parts = [];
        $parts[] = sprintf(' %d %s changed', $fileCount, $fileCount === 1 ? 'file' : 'files');

        if ($totalAdd > 0) {
            $parts[] = sprintf('%d %s(+)', $totalAdd, $totalAdd === 1 ? 'insertion' : 'insertions');
        }

        if ($totalDel > 0) {
            $parts[] = sprintf('%d %s(-)', $totalDel, $totalDel === 1 ? 'deletion' : 'deletions');
        }

        return implode(', ', $parts);
    }

    /**
     * @param list<FileDiff> $diffs
     */
    private static function formatModeLines(array $diffs): string
    {
        $lines = [];
        foreach ($diffs as $diff) {
            if ($diff->status === FileStatus::Added) {
                $lines[] = ' create mode 100644 ' . $diff->path;
            } elseif ($diff->status === FileStatus::Deleted) {
                $lines[] = ' delete mode 100644 ' . $diff->path;
            }
        }

        return implode("\n", $lines);
    }
}

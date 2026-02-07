<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Domain\Diff\DiffLineType;
use Lukasojd\PureGit\Domain\Diff\FileDiff;

final class DiffStatPrinter
{
    /**
     * @param list<FileDiff> $diffs
     */
    public function print(array $diffs): void
    {
        $stats = $this->collectStats($diffs);
        $this->printLines($stats);
        $this->printSummary($stats);
    }

    /**
     * @param list<FileDiff> $diffs
     * @return list<array{path: string, added: int, deleted: int}>
     */
    private function collectStats(array $diffs): array
    {
        $stats = [];

        foreach ($diffs as $diff) {
            $stats[] = $this->countFileDiff($diff);
        }

        return $stats;
    }

    /**
     * @return array{path: string, added: int, deleted: int}
     */
    private function countFileDiff(FileDiff $diff): array
    {
        $added = 0;
        $deleted = 0;
        foreach ($diff->hunks as $hunk) {
            foreach ($hunk->lines as $line) {
                if ($line->type === DiffLineType::Added) {
                    $added++;
                } elseif ($line->type === DiffLineType::Removed) {
                    $deleted++;
                }
            }
        }

        return [
            'path' => $diff->path,
            'added' => $added,
            'deleted' => $deleted,
        ];
    }

    /**
     * @param list<array{path: string, added: int, deleted: int}> $stats
     */
    private function printLines(array $stats): void
    {
        $maxPath = 0;
        $maxChanges = 0;
        foreach ($stats as $s) {
            $maxPath = max($maxPath, strlen($s['path']));
            $maxChanges = max($maxChanges, $s['added'] + $s['deleted']);
        }

        $barWidth = min($maxChanges, 50);

        foreach ($stats as $s) {
            $total = $s['added'] + $s['deleted'];
            $bar = $this->buildBar($s['added'], $s['deleted'], $maxChanges, $barWidth);
            fwrite(STDOUT, sprintf(" %-*s | %*d %s\n", $maxPath, $s['path'], strlen((string) $maxChanges), $total, $bar));
        }
    }

    private function buildBar(int $added, int $deleted, int $maxChanges, int $barWidth): string
    {
        if ($maxChanges === 0) {
            return '';
        }

        $scaledPlus = (int) round($added / $maxChanges * $barWidth);
        $scaledMinus = (int) round($deleted / $maxChanges * $barWidth);

        if ($scaledPlus + $scaledMinus === 0) {
            $scaledPlus = $added >= $deleted ? 1 : 0;
            $scaledMinus = $scaledPlus === 0 ? 1 : 0;
        }

        return str_repeat('+', $scaledPlus) . str_repeat('-', $scaledMinus);
    }

    /**
     * @param list<array{path: string, added: int, deleted: int}> $stats
     */
    private function printSummary(array $stats): void
    {
        $totalAdded = 0;
        $totalDeleted = 0;
        foreach ($stats as $s) {
            $totalAdded += $s['added'];
            $totalDeleted += $s['deleted'];
        }

        $fileWord = count($stats) === 1 ? 'file' : 'files';
        $parts = [sprintf(' %d %s changed', count($stats), $fileWord)];
        if ($totalAdded > 0) {
            $parts[] = sprintf('%d insertion%s(+)', $totalAdded, $totalAdded === 1 ? '' : 's');
        }
        if ($totalDeleted > 0) {
            $parts[] = sprintf('%d deletion%s(-)', $totalDeleted, $totalDeleted === 1 ? '' : 's');
        }
        fwrite(STDOUT, implode(', ', $parts) . "\n");
    }
}

<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\DiffHandler;
use Lukasojd\PureGit\Application\Handler\ShowHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Diff\FileDiff;
use Lukasojd\PureGit\Domain\Diff\FileStatus;
use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Infrastructure\Diff\MyersDiffAlgorithm;

final class DiffCommand implements CliCommand
{
    private const string ZERO_HASH_SHORT = '0000000';

    public function name(): string
    {
        return 'diff';
    }

    public function description(): string
    {
        return 'Show changes between commits, index, and working tree';
    }

    public function usage(): string
    {
        return 'diff [--cached] [--stat] [--name-only] [<commit>..<commit>]';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        $cached = false;
        $stat = false;
        $nameOnly = false;
        $positional = [];

        foreach ($args as $arg) {
            match ($arg) {
                '--cached', '--staged' => $cached = true,
                '--stat' => $stat = true,
                '--name-only' => $nameOnly = true,
                default => $positional[] = $arg,
            };
        }

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: Cannot determine current directory\n");

            return 128;
        }

        $repo = Repository::discover($cwd);
        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $this->resolveDiffs($handler, $repo, $cached, $positional);

        $this->printOutput($diffs, $stat, $nameOnly);

        return 0;
    }

    public function printFileDiff(FileDiff $diff): void
    {
        fwrite(STDOUT, sprintf("diff --git a/%s b/%s\n", $diff->path, $diff->path));

        $this->printModeAndIndex($diff);
        $this->printFileHeaders($diff);

        foreach ($diff->hunks as $hunk) {
            fwrite(STDOUT, $hunk->header() . "\n");

            foreach ($hunk->lines as $line) {
                fwrite(STDOUT, sprintf("%s%s\n", $line->type->value, $line->content));
            }
        }
    }

    /**
     * @param list<FileDiff> $diffs
     */
    private function printOutput(array $diffs, bool $stat, bool $nameOnly): void
    {
        if ($nameOnly) {
            foreach ($diffs as $diff) {
                fwrite(STDOUT, $diff->path . "\n");
            }
        } elseif ($stat) {
            new DiffStatPrinter()->print($diffs);
        } else {
            foreach ($diffs as $diff) {
                $this->printFileDiff($diff);
            }
        }
    }

    /**
     * @param list<string> $positional
     * @return list<FileDiff>
     */
    private function resolveDiffs(DiffHandler $handler, Repository $repo, bool $cached, array $positional): array
    {
        if ($positional !== []) {
            return $this->diffBetweenRefs($handler, $repo, $positional[0]);
        }

        return $cached ? $handler->diffIndexVsHead() : $handler->diffWorkingVsIndex();
    }

    /**
     * @return list<FileDiff>
     */
    private function diffBetweenRefs(DiffHandler $handler, Repository $repo, string $refSpec): array
    {
        $parts = explode('..', $refSpec, 2);
        if (count($parts) !== 2) {
            fwrite(STDERR, sprintf("fatal: unrecognized argument: %s\n", $refSpec));

            return [];
        }

        $showHandler = new ShowHandler($repo);
        $oldObj = $showHandler->handle($parts[0]);
        $newObj = $showHandler->handle($parts[1]);

        if (! $oldObj instanceof Commit || ! $newObj instanceof Commit) {
            fwrite(STDERR, "fatal: arguments must be commits\n");

            return [];
        }

        return $handler->diffCommits($oldObj->getId(), $newObj->getId());
    }

    private function printModeAndIndex(FileDiff $diff): void
    {
        if ($diff->status === FileStatus::Added) {
            fwrite(STDOUT, "new file mode 100644\n");
        } elseif ($diff->status === FileStatus::Deleted) {
            fwrite(STDOUT, "deleted file mode 100644\n");
        }

        $oldShort = $diff->oldId instanceof \Lukasojd\PureGit\Domain\Object\ObjectId ? $diff->oldId->short(7) : self::ZERO_HASH_SHORT;
        $newShort = $diff->newId instanceof \Lukasojd\PureGit\Domain\Object\ObjectId ? $diff->newId->short(7) : self::ZERO_HASH_SHORT;
        $mode = ($diff->status !== FileStatus::Added && $diff->status !== FileStatus::Deleted) ? ' 100644' : '';
        fwrite(STDOUT, sprintf("index %s..%s%s\n", $oldShort, $newShort, $mode));
    }

    private function printFileHeaders(FileDiff $diff): void
    {
        $oldPath = $diff->status === FileStatus::Added ? '/dev/null' : 'a/' . $diff->path;
        $newPath = $diff->status === FileStatus::Deleted ? '/dev/null' : 'b/' . $diff->path;
        fwrite(STDOUT, sprintf("--- %s\n", $oldPath));
        fwrite(STDOUT, sprintf("+++ %s\n", $newPath));
    }
}

<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\DiffHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Diff\FileDiff;
use Lukasojd\PureGit\Domain\Diff\FileStatus;
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
        return 'diff [--cached]';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        $cached = in_array('--cached', $args, true) || in_array('--staged', $args, true);

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: Cannot determine current directory\n");

            return 128;
        }

        $repo = Repository::discover($cwd);
        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());

        $diffs = $cached ? $handler->diffIndexVsHead() : $handler->diffWorkingVsIndex();

        foreach ($diffs as $diff) {
            $this->printFileDiff($diff);
        }

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

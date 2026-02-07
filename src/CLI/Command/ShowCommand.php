<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\DiffHandler;
use Lukasojd\PureGit\Application\Handler\ShowHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Object\Blob;
use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Domain\Object\GitObject;
use Lukasojd\PureGit\Domain\Object\Tag;
use Lukasojd\PureGit\Domain\Object\Tree;
use Lukasojd\PureGit\Infrastructure\Diff\MyersDiffAlgorithm;

final class ShowCommand implements CliCommand
{
    public function name(): string
    {
        return 'show';
    }

    public function description(): string
    {
        return 'Show various types of objects';
    }

    public function usage(): string
    {
        return 'show [<object>]';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        $target = $args[0] ?? null;

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: Cannot determine current directory\n");

            return 128;
        }

        $repo = Repository::discover($cwd);
        $handler = new ShowHandler($repo);
        $object = $handler->handle($target);

        $this->printObject($object, $repo);

        return 0;
    }

    private function printObject(GitObject $object, Repository $repo): void
    {
        match (true) {
            $object instanceof Commit => $this->printCommit($object, $repo),
            $object instanceof Tree => $this->printTree($object),
            $object instanceof Blob => fwrite(STDOUT, $object->content),
            $object instanceof Tag => $this->printTag($object),
            default => fwrite(STDERR, sprintf("Unknown object type: %s\n", $object->getType()->value)),
        };
    }

    private function printCommit(Commit $commit, Repository $repo): void
    {
        fwrite(STDOUT, sprintf("commit %s\n", $commit->getId()->hash));
        fwrite(STDOUT, sprintf("Author: %s <%s>\n", $commit->author->name, $commit->author->email));
        fwrite(STDOUT, sprintf("Date:   %s\n", $commit->author->timestamp->format('D M j H:i:s Y O')));
        fwrite(STDOUT, sprintf("\n    %s\n", $commit->message));

        $this->printCommitDiff($commit, $repo);
    }

    private function printCommitDiff(Commit $commit, Repository $repo): void
    {
        $diffHandler = new DiffHandler($repo, new MyersDiffAlgorithm());

        $diffs = $commit->parents === []
            ? $diffHandler->diffRootCommit($commit->getId())
            : $diffHandler->diffCommits($commit->parents[0], $commit->getId());

        if ($diffs === []) {
            return;
        }

        fwrite(STDOUT, "\n");
        $diffCommand = new DiffCommand();
        foreach ($diffs as $diff) {
            $diffCommand->printFileDiff($diff);
        }
    }

    private function printTree(Tree $tree): void
    {
        fwrite(STDOUT, sprintf("tree %s\n\n", $tree->getId()->hash));
        foreach ($tree->entries as $entry) {
            fwrite(STDOUT, sprintf("%s %s %s\t%s\n", $entry->mode->toOctal(), $entry->isTree() ? 'tree' : 'blob', $entry->objectId->hash, $entry->name));
        }
    }

    private function printTag(Tag $tag): void
    {
        fwrite(STDOUT, sprintf("tag %s\n", $tag->tagName));
        fwrite(STDOUT, sprintf("Tagger: %s <%s>\n", $tag->tagger->name, $tag->tagger->email));
        fwrite(STDOUT, sprintf("Date:   %s\n", $tag->tagger->timestamp->format('D M j H:i:s Y O')));
        fwrite(STDOUT, sprintf("\n%s\n", $tag->message));
    }
}

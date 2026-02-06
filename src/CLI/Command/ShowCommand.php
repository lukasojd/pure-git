<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\ShowHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Object\Blob;
use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Domain\Object\Tag;
use Lukasojd\PureGit\Domain\Object\Tree;

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

        if ($object instanceof Commit) {
            fwrite(STDOUT, sprintf("commit %s\n", $object->getId()->hash));
            fwrite(STDOUT, sprintf("Author: %s <%s>\n", $object->author->name, $object->author->email));
            fwrite(STDOUT, sprintf("Date:   %s\n", $object->author->timestamp->format('Y-m-d H:i:s O')));
            fwrite(STDOUT, sprintf("\n    %s\n", $object->message));
        } elseif ($object instanceof Tree) {
            fwrite(STDOUT, sprintf("tree %s\n\n", $object->getId()->hash));
            foreach ($object->entries as $entry) {
                fwrite(STDOUT, sprintf("%s %s %s\t%s\n", $entry->mode->toOctal(), $entry->isTree() ? 'tree' : 'blob', $entry->objectId->hash, $entry->name));
            }
        } elseif ($object instanceof Blob) {
            fwrite(STDOUT, $object->content);
        } elseif ($object instanceof Tag) {
            fwrite(STDOUT, sprintf("tag %s\n", $object->tagName));
            fwrite(STDOUT, sprintf("Tagger: %s <%s>\n", $object->tagger->name, $object->tagger->email));
            fwrite(STDOUT, sprintf("\n%s\n", $object->message));
        }

        return 0;
    }
}

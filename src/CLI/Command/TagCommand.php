<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\TagHandler;
use Lukasojd\PureGit\Application\Service\Repository;

final class TagCommand implements CliCommand
{
    public function name(): string
    {
        return 'tag';
    }

    public function description(): string
    {
        return 'Create, list, or delete tags';
    }

    public function usage(): string
    {
        return 'tag [<name>] [-a <name> -m <message>] [-d <name>]';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: Cannot determine current directory\n");

            return 128;
        }

        $repo = Repository::discover($cwd);
        $handler = new TagHandler($repo);

        if ($this->isDeleteRequest($args)) {
            return $this->deleteTag($handler, $args[1]);
        }

        $parsed = $this->parseTagArgs($args);

        if ($parsed['name'] !== null && $parsed['annotated'] && $parsed['message'] !== null) {
            return $this->createAnnotatedTag($handler, $parsed['name'], $parsed['message']);
        }

        if ($parsed['name'] !== null) {
            return $this->createLightweightTag($handler, $parsed['name']);
        }

        return $this->listTags($handler);
    }

    /**
     * @param list<string> $args
     */
    private function isDeleteRequest(array $args): bool
    {
        return isset($args[0]) && $args[0] === '-d' && isset($args[1]);
    }

    /**
     * @param list<string> $args
     * @return array{annotated: bool, name: ?string, message: ?string}
     */
    private function parseTagArgs(array $args): array
    {
        $annotated = false;
        $name = null;
        $message = null;
        $counter = count($args);

        for ($i = 0; $i < $counter; $i++) {
            if ($args[$i] === '-a' && isset($args[$i + 1])) {
                $annotated = true;
                $name = $args[$i + 1];
                $i++;
            } elseif ($args[$i] === '-m' && isset($args[$i + 1])) {
                $message = $args[$i + 1];
                $i++;
            } elseif ($name === null && $args[$i][0] !== '-') {
                $name = $args[$i];
            }
        }

        return [
            'annotated' => $annotated,
            'name' => $name,
            'message' => $message,
        ];
    }

    private function deleteTag(TagHandler $handler, string $name): int
    {
        $handler->delete($name);
        fwrite(STDOUT, sprintf("Deleted tag '%s'\n", $name));

        return 0;
    }

    private function createAnnotatedTag(TagHandler $handler, string $name, string $message): int
    {
        $handler->createAnnotated($name, $message);
        fwrite(STDOUT, sprintf("Created annotated tag '%s'\n", $name));

        return 0;
    }

    private function createLightweightTag(TagHandler $handler, string $name): int
    {
        $handler->createLightweight($name);
        fwrite(STDOUT, sprintf("Created tag '%s'\n", $name));

        return 0;
    }

    private function listTags(TagHandler $handler): int
    {
        $tags = $handler->list();
        foreach (array_keys($tags) as $refName) {
            $short = str_replace('refs/tags/', '', $refName);
            fwrite(STDOUT, sprintf("%s\n", $short));
        }

        return 0;
    }
}

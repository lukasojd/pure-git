<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\AddHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Support\PathUtils;

final class AddCommand implements CliCommand
{
    public function name(): string
    {
        return 'add';
    }

    public function description(): string
    {
        return 'Add file contents to the index';
    }

    public function usage(): string
    {
        return 'add <pathspec>...';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        if ($args === []) {
            fwrite(STDERR, "Nothing specified, nothing added.\n");

            return 0;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: Cannot determine current directory\n");

            return 128;
        }

        $repo = Repository::discover($cwd);
        $handler = new AddHandler($repo);
        $paths = $this->resolvePaths($args, $repo);
        $handler->handle($paths);

        return 0;
    }

    /**
     * @param list<string> $args
     * @return list<string>
     */
    private function resolvePaths(array $args, Repository $repo): array
    {
        $paths = [];

        foreach ($args as $arg) {
            if ($arg === '.') {
                $paths = [...$paths, ...$this->collectAllWorkingTreeFiles($repo)];
            } else {
                $paths[] = PathUtils::relativeTo($arg, $repo->workDir);
            }
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function collectAllWorkingTreeFiles(Repository $repo): array
    {
        $gitignore = $repo->gitignore;

        if ($gitignore instanceof \Lukasojd\PureGit\Infrastructure\Gitignore\GitignoreMatcher) {
            return $gitignore->walkWorkingTree();
        }

        $files = $repo->filesystem->listFilesRecursive($repo->workDir);

        return array_values(array_filter(
            $files,
            static fn (string $file): bool => ! str_starts_with($file, '.git/') && $file !== '.git',
        ));
    }
}

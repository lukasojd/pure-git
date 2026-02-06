<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use DateTimeImmutable;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Index\Index;
use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Domain\Object\FileMode;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\PersonInfo;
use Lukasojd\PureGit\Domain\Object\Tree;
use Lukasojd\PureGit\Domain\Object\TreeEntry;
use Lukasojd\PureGit\Domain\Ref\RefName;

final readonly class CommitHandler
{
    public function __construct(
        private Repository $repository,
    ) {
    }

    public function handle(string $message, ?PersonInfo $author = null, ?PersonInfo $committer = null): ObjectId
    {
        $index = $this->repository->index->read();

        if ($index->count() === 0) {
            throw new PureGitException('Nothing to commit');
        }

        $now = new DateTimeImmutable();
        $defaultPerson = new PersonInfo(
            $this->getConfigValue('user.name', 'PureGit User'),
            $this->getConfigValue('user.email', 'user@puregit.local'),
            $now,
        );

        $author ??= $defaultPerson;
        $committer ??= $defaultPerson;

        $treeId = $this->buildTree($index);

        $parents = [];
        $head = RefName::head();

        try {
            $parentId = $this->repository->refs->resolve($head);
            $parents[] = $parentId;
        } catch (\Throwable) {
            // Initial commit — no parents
        }

        $commit = new Commit($treeId, $parents, $author, $committer, $message);
        $this->repository->objects->write($commit);

        // Update HEAD
        $symbolicRef = $this->repository->refs->getSymbolicRef($head);
        if ($symbolicRef instanceof \Lukasojd\PureGit\Domain\Ref\RefName) {
            $this->repository->refs->updateRef($symbolicRef, $commit->getId());
        } else {
            $this->repository->refs->updateRef($head, $commit->getId());
        }

        return $commit->getId();
    }

    public function buildTree(Index $index): ObjectId
    {
        /** @var array<string, TreeNode> $root */
        $root = [];

        foreach ($index->getSortedEntries() as $entry) {
            $parts = explode('/', $entry->path);
            $current = &$root;

            for ($i = 0; $i < count($parts) - 1; $i++) {
                $dirName = $parts[$i];
                if (! isset($current[$dirName])) {
                    $current[$dirName] = TreeNode::directory();
                }
                $node = $current[$dirName];
                $current = &$current[$dirName]->children;
            }

            $fileName = $parts[count($parts) - 1];
            $current[$fileName] = TreeNode::file($entry->mode, $entry->objectId);
        }

        return $this->writeTreeRecursive($root);
    }

    /**
     * @param array<string, TreeNode> $nodes
     */
    private function writeTreeRecursive(array $nodes): ObjectId
    {
        $entries = [];

        foreach ($nodes as $name => $node) {
            if ($node->isFile) {
                assert($node->mode !== null);
                assert($node->objectId !== null);
                $entries[] = new TreeEntry(
                    $node->mode,
                    $name,
                    $node->objectId,
                );
            } else {
                $subtreeId = $this->writeTreeRecursive($node->children);
                $entries[] = new TreeEntry(
                    FileMode::Directory,
                    $name,
                    $subtreeId,
                );
            }
        }

        usort($entries, static function (TreeEntry $a, TreeEntry $b): int {
            $aName = $a->isTree() ? $a->name . '/' : $a->name;
            $bName = $b->isTree() ? $b->name . '/' : $b->name;

            return strcmp($aName, $bName);
        });

        $tree = new Tree($entries);
        $this->repository->objects->write($tree);

        return $tree->getId();
    }

    private function getConfigValue(string $key, string $default): string
    {
        $configPath = $this->repository->gitDir . '/config';
        if (! file_exists($configPath)) {
            return $default;
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            return $default;
        }

        // Simple INI-like parsing for user.name / user.email
        $parts = explode('.', $key, 2);
        if (count($parts) !== 2) {
            return $default;
        }

        $section = $parts[0];
        $property = $parts[1];
        $inSection = false;

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (preg_match('/^\[(.+)\]$/', $line, $m) === 1) {
                $inSection = strtolower($m[1]) === strtolower($section);
                continue;
            }

            if ($inSection && str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                if (trim($k) === $property) {
                    return trim($v);
                }
            }
        }

        return $default;
    }
}

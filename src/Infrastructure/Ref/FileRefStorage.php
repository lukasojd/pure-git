<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Ref;

use Lukasojd\PureGit\Domain\Exception\RefNotFoundException;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Domain\Repository\RefStorageInterface;
use Lukasojd\PureGit\Infrastructure\Lock\LockFile;

final class FileRefStorage implements RefStorageInterface
{
    /**
     * @var array<string, ObjectId>|null
     */
    private ?array $packedRefsCache = null;

    private int $packedRefsMtime = 0;

    public function __construct(
        private readonly string $gitDir,
    ) {
    }

    public function resolve(RefName $ref): ObjectId
    {
        return $this->resolveRef($ref, 0);
    }

    public function updateRef(RefName $ref, ObjectId $id): void
    {
        $path = $this->refPath($ref);
        $lock = new LockFile($path);
        $lock->acquire();
        $lock->write($id->hash . "\n");
        $lock->commit();
    }

    public function deleteRef(RefName $ref): void
    {
        $path = $this->refPath($ref);
        if (file_exists($path)) {
            unlink($path);
        }
    }

    public function exists(RefName $ref): bool
    {
        $path = $this->refPath($ref);
        if (file_exists($path)) {
            return true;
        }

        // Check packed-refs
        return $this->findInPackedRefs($ref) instanceof ObjectId;
    }

    /**
     * @return array<string, ObjectId>
     */
    public function listRefs(string $prefix = 'refs/'): array
    {
        $refs = [];

        // Read packed refs first (loose refs override)
        $this->readPackedRefs($refs, $prefix);

        // Read loose refs
        $refsDir = $this->gitDir . '/' . $prefix;
        if (is_dir($refsDir)) {
            $this->collectLooseRefs($refsDir, $prefix, $refs);
        }

        return $refs;
    }

    public function getSymbolicRef(RefName $ref): ?RefName
    {
        $path = $this->refPath($ref);
        if (! file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        $content = trim($raw !== false ? $raw : '');
        if (str_starts_with($content, 'ref: ')) {
            return RefName::fromString(substr($content, 5));
        }

        return null;
    }

    public function updateSymbolicRef(RefName $ref, RefName $target): void
    {
        $path = $this->refPath($ref);
        $lock = new LockFile($path);
        $lock->acquire();
        $lock->write('ref: ' . $target->value . "\n");
        $lock->commit();
    }

    private function resolveRef(RefName $ref, int $depth): ObjectId
    {
        if ($depth > 10) {
            throw new RefNotFoundException('Symbolic ref loop detected');
        }

        $path = $this->refPath($ref);

        if (file_exists($path)) {
            $raw = file_get_contents($path);
            $content = trim($raw !== false ? $raw : '');

            if (str_starts_with($content, 'ref: ')) {
                $targetName = substr($content, 5);

                return $this->resolveRef(RefName::fromString($targetName), $depth + 1);
            }

            if ($content !== '') {
                return ObjectId::fromHex($content);
            }
        }

        // Check packed-refs
        $packedId = $this->findInPackedRefs($ref);
        if ($packedId instanceof ObjectId) {
            return $packedId;
        }

        throw RefNotFoundException::withName($ref->value);
    }

    private function findInPackedRefs(RefName $ref): ?ObjectId
    {
        return $this->getPackedRefs()[$ref->value] ?? null;
    }

    /**
     * @param array<string, ObjectId> $refs
     */
    private function readPackedRefs(array &$refs, string $prefix): void
    {
        foreach ($this->getPackedRefs() as $name => $id) {
            if (str_starts_with($name, $prefix)) {
                $refs[$name] = $id;
            }
        }
    }

    /**
     * @return array<string, ObjectId>
     */
    private function getPackedRefs(): array
    {
        $packedRefsPath = $this->gitDir . '/packed-refs';
        $mtime = @filemtime($packedRefsPath);
        if ($mtime === false) {
            $this->packedRefsCache = null;
            return [];
        }

        if ($this->packedRefsCache !== null && $mtime === $this->packedRefsMtime) {
            return $this->packedRefsCache;
        }

        $this->packedRefsCache = $this->parsePackedRefs($packedRefsPath);
        $this->packedRefsMtime = $mtime;

        return $this->packedRefsCache;
    }

    /**
     * @return array<string, ObjectId>
     */
    private function parsePackedRefs(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $refs = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                continue;
            }

            $parts = explode(' ', $line, 2);
            if (count($parts) === 2) {
                $refs[$parts[1]] = ObjectId::fromHex($parts[0]);
            }
        }

        return $refs;
    }

    /**
     * @param array<string, ObjectId> $refs
     */
    private function collectLooseRefs(string $dir, string $prefix, array &$refs): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $dir . '/' . $item;
            $refPath = $prefix . $item;

            if (is_dir($fullPath)) {
                $this->collectLooseRefs($fullPath, $refPath . '/', $refs);
                continue;
            }

            $this->tryAddLooseRef($fullPath, $refPath, $refs);
        }
    }

    /**
     * @param array<string, ObjectId> $refs
     */
    private function tryAddLooseRef(string $fullPath, string $refPath, array &$refs): void
    {
        if (! is_file($fullPath)) {
            return;
        }

        $raw = file_get_contents($fullPath);
        $content = trim($raw !== false ? $raw : '');
        if ($content !== '' && ! str_starts_with($content, 'ref: ') && preg_match('/^[0-9a-f]{40}$/', $content) === 1) {
            $refs[$refPath] = ObjectId::fromHex($content);
        }
    }

    private function refPath(RefName $ref): string
    {
        return $this->gitDir . '/' . $ref->value;
    }
}

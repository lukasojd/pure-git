<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Object;

use Lukasojd\PureGit\Domain\Object\GitObject;

/**
 * Finds reusable deltas from existing pack files.
 *
 * When an object already exists as an OFS_DELTA in an existing pack and
 * its base is also included in the new pack, the delta data is reused
 * directly — avoiding the expensive DeltaEncoder hash table build.
 */
final readonly class DeltaReuseFinder
{
    private const int OBJ_OFS_DELTA = 6;

    /**
     * @param list<PackfileReader> $sources
     * @param array<string, true> $objectSet hashes of all objects in the new pack
     * @param array<string, PackEntry> $entryByHash already-processed entries
     */
    public function tryReuse(
        GitObject $object,
        string $data,
        string $fullCompressed,
        array $sources,
        array $objectSet,
        array $entryByHash,
        PackWriterConfig $config,
    ): ?PackEntry {
        foreach ($sources as $source) {
            $entry = $this->tryReuseFromSource(
                $source,
                $object,
                $data,
                $fullCompressed,
                $objectSet,
                $entryByHash,
                $config,
            );
            if ($entry instanceof \Lukasojd\PureGit\Infrastructure\Object\PackEntry) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param array<string, true> $objectSet
     * @param array<string, PackEntry> $entryByHash
     */
    private function tryReuseFromSource(
        PackfileReader $source,
        GitObject $object,
        string $data,
        string $fullCompressed,
        array $objectSet,
        array $entryByHash,
        PackWriterConfig $config,
    ): ?PackEntry {
        $info = $source->getDeltaReuse($object->getId());
        if (! $info instanceof \Lukasojd\PureGit\Infrastructure\Object\DeltaReuseInfo) {
            return null;
        }

        if (! isset($objectSet[$info->baseId->hash])) {
            return null;
        }

        $baseDepth = isset($entryByHash[$info->baseId->hash]) ? $entryByHash[$info->baseId->hash]->depth : 0;
        if ($baseDepth + 1 > $config->maxDepth) {
            return null;
        }

        $compressedDelta = gzcompress($info->deltaData, $config->compressionLevel);
        if ($compressedDelta === false || strlen($compressedDelta) >= strlen($fullCompressed)) {
            return null;
        }

        return new PackEntry(
            self::OBJ_OFS_DELTA,
            $data,
            $compressedDelta,
            0,
            $info->baseId->hash,
            $object->getId()->hash,
            $baseDepth + 1,
        );
    }
}

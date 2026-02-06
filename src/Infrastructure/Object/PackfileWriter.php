<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Object;

use Lukasojd\PureGit\Domain\Object\GitObject;
use Lukasojd\PureGit\Domain\Object\ObjectType;

/**
 * Writes packfiles with delta compression using a sliding window approach.
 *
 * Objects are sorted by type, then size. A sliding window (default 10) is used
 * to find the best base for delta encoding. OFS_DELTA is preferred for compact
 * packs. Delta chain depth is limited (default 50).
 */
final class PackfileWriter
{
    private const int OBJ_COMMIT = 1;

    private const int OBJ_TREE = 2;

    private const int OBJ_BLOB = 3;

    private const int OBJ_TAG = 4;

    private const int OBJ_OFS_DELTA = 6;

    /**
     * @param list<GitObject> $objects
     * @param list<PackfileReader> $reuseSources existing packs to check for reusable deltas
     */
    public function write(array $objects, string $outputPath, ?PackWriterConfig $config = null, array $reuseSources = []): void
    {
        $config ??= new PackWriterConfig();
        $prepared = $this->prepareObjects($objects, $config, $reuseSources);

        $this->writePack($prepared, $outputPath);

        if ($config->generateIndex) {
            $this->writeIndex($prepared, $outputPath);
        }
    }

    /**
     * @param list<GitObject> $objects
     * @param list<PackfileReader> $reuseSources
     * @return list<PackEntry>
     */
    private function prepareObjects(array $objects, PackWriterConfig $config, array $reuseSources): array
    {
        $objectSet = $this->buildObjectHashSet($objects);
        $sorted = $this->sortObjects($objects);
        $entries = [];
        $window = [];
        /** @var array<string, PackEntry> $entryByHash */
        $entryByHash = [];

        foreach ($sorted as $object) {
            $entry = $this->tryDeltaEncode($object, $window, $config, $reuseSources, $objectSet, $entryByHash);
            $entries[] = $entry;
            $entryByHash[$entry->hash] = $entry;
            $window[] = $entry;

            if (count($window) > $config->window) {
                array_shift($window);
            }
        }

        return $entries;
    }

    /**
     * @param list<GitObject> $objects
     * @return array<string, true>
     */
    private function buildObjectHashSet(array $objects): array
    {
        $set = [];
        foreach ($objects as $obj) {
            $set[$obj->getId()->hash] = true;
        }

        return $set;
    }

    /**
     * Sort objects by type priority, then by serialized size descending.
     * This groups similar objects and puts larger objects first for better delta bases.
     *
     * @param list<GitObject> $objects
     * @return list<GitObject>
     */
    private function sortObjects(array $objects): array
    {
        $sizeMap = [];
        foreach ($objects as $obj) {
            $sizeMap[$obj->getId()->hash] = strlen($obj->serialize());
        }

        usort($objects, function (GitObject $a, GitObject $b) use ($sizeMap): int {
            $typePriority = $this->typePriority($a->getType()) <=> $this->typePriority($b->getType());
            if ($typePriority !== 0) {
                return $typePriority;
            }

            return $sizeMap[$b->getId()->hash] <=> $sizeMap[$a->getId()->hash];
        });

        return $objects;
    }

    /**
     * Attempt delta encoding: try reuse first, then window-based fresh delta.
     *
     * @param list<PackEntry> $window
     * @param list<PackfileReader> $reuseSources
     * @param array<string, true> $objectSet
     * @param array<string, PackEntry> $entryByHash
     */
    private function tryDeltaEncode(
        GitObject $object,
        array $window,
        PackWriterConfig $config,
        array $reuseSources,
        array $objectSet,
        array $entryByHash,
    ): PackEntry {
        $data = $object->serialize();
        $type = $this->objectTypeToPackType($object->getType());

        $compressed = gzcompress($data, $config->compressionLevel);
        $fullCompressed = $compressed !== false ? $compressed : $data;
        $fullEntry = new PackEntry($type, $data, $fullCompressed, 0, null, $object->getId()->hash, 0);

        if (! $config->enableDelta) {
            return $fullEntry;
        }

        if ($reuseSources !== []) {
            $reused = new DeltaReuseFinder()->tryReuse($object, $data, $fullCompressed, $reuseSources, $objectSet, $entryByHash, $config);
            if ($reused instanceof \Lukasojd\PureGit\Infrastructure\Object\PackEntry) {
                return $reused;
            }
        }

        $best = $this->findBestDelta($object, $data, $fullCompressed, $window, $config);

        return $best ?? $fullEntry;
    }

    /**
     * Search the window for the best delta base.
     *
     * Guardrails: max candidate comparisons per object, size-bucket filtering
     * (skip candidates where size differs by more than sizeBucketRatio),
     * and early abort when a good-enough delta is found (< 25% of full size).
     *
     * @param list<PackEntry> $window
     */
    private function findBestDelta(
        GitObject $object,
        string $data,
        string $fullCompressed,
        array $window,
        PackWriterConfig $config,
    ): ?PackEntry {
        $bestEntry = null;
        $bestScore = strlen($fullCompressed);
        $type = $this->objectTypeToPackType($object->getType());
        $dataSize = strlen($data);
        $tested = 0;
        $earlyAbortThreshold = strlen($fullCompressed) / 4;

        foreach (array_reverse($window) as $candidate) {
            if ($tested >= $config->maxCandidatesPerObject) {
                break;
            }

            if (! $this->isValidDeltaCandidate($candidate, $type, $dataSize, $config)) {
                continue;
            }

            $tested++;
            $result = $this->tryDeltaCandidate($candidate, $data, $object, $config, $bestScore);

            if ($result === null) {
                continue;
            }

            [$bestEntry, $bestScore] = $result;

            if ($bestScore < $earlyAbortThreshold) {
                break;
            }
        }

        return $bestEntry;
    }

    /**
     * Try a single delta candidate and return the entry + score if better.
     *
     * @return array{PackEntry, int}|null
     */
    private function tryDeltaCandidate(
        PackEntry $candidate,
        string $data,
        GitObject $object,
        PackWriterConfig $config,
        int $bestScore,
    ): ?array {
        $delta = DeltaEncoder::encode($candidate->rawData, $data);
        if ($delta === null) {
            return null;
        }

        $compressedDelta = gzcompress($delta, $config->compressionLevel);
        if ($compressedDelta === false) {
            return null;
        }

        $score = strlen($compressedDelta) + $this->depthPenalty($candidate->depth, $config);
        if ($score >= $bestScore) {
            return null;
        }

        $entry = new PackEntry(
            self::OBJ_OFS_DELTA,
            $data,
            $compressedDelta,
            0,
            $candidate->hash,
            $object->getId()->hash,
            $candidate->depth + 1,
        );

        return [$entry, $score];
    }

    private function isValidDeltaCandidate(PackEntry $candidate, int $type, int $targetSize, PackWriterConfig $config): bool
    {
        if ($candidate->depth >= $config->maxDepth) {
            return false;
        }

        $candidateSize = strlen($candidate->rawData);
        $sizeDiff = abs($candidateSize - $targetSize);
        $maxDiff = (int) (max($candidateSize, $targetSize) * $config->sizeBucketRatio);
        if ($sizeDiff > $maxDiff) {
            return false;
        }

        if ($candidate->packType === self::OBJ_OFS_DELTA) {
            return true;
        }

        return $candidate->packType === $type;
    }

    private function depthPenalty(int $depth, PackWriterConfig $config): int
    {
        return $depth * $config->depthPenaltyFactor;
    }

    /**
     * Write pack file with streaming output.
     *
     * @param list<PackEntry> $entries
     */
    private function writePack(array &$entries, string $outputPath): void
    {
        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $tmpPath = $outputPath . '.tmp.' . getmypid();
        $fh = fopen($tmpPath, 'wb');
        if ($fh === false) {
            return;
        }

        $hashCtx = hash_init('sha1');

        $this->writeAndHash($fh, $hashCtx, 'PACK');
        $this->writeAndHash($fh, $hashCtx, pack('N', 2));
        $this->writeAndHash($fh, $hashCtx, pack('N', count($entries)));

        $offsets = [];
        foreach ($entries as $i => $entry) {
            $pos = (int) ftell($fh);
            $offsets[$entry->hash] = $pos;
            $entries[$i] = new PackEntry(
                $entry->packType,
                $entry->rawData,
                $entry->compressedData,
                $pos,
                $entry->baseHash,
                $entry->hash,
                $entry->depth,
            );
            $this->writeEntry($fh, $hashCtx, $entries[$i], $offsets);
        }

        $checksum = hash_final($hashCtx, true);
        fwrite($fh, $checksum);
        fclose($fh);

        rename($tmpPath, $outputPath);
    }

    /**
     * @param resource $fh
     * @param array<string, int> $offsets hash => file offset
     */
    private function writeEntry($fh, \HashContext $hashCtx, PackEntry $entry, array $offsets): void
    {
        if ($entry->packType === self::OBJ_OFS_DELTA && $entry->baseHash !== null) {
            $this->writeOfsDeltaEntry($fh, $hashCtx, $entry, $offsets);
        } else {
            $this->writeWholeEntry($fh, $hashCtx, $entry);
        }
    }

    /**
     * @param resource $fh
     */
    private function writeWholeEntry($fh, \HashContext $hashCtx, PackEntry $entry): void
    {
        $size = strlen($entry->rawData);
        $header = $this->encodeObjectHeader($entry->packType, $size);
        $this->writeAndHash($fh, $hashCtx, $header);
        $this->writeAndHash($fh, $hashCtx, $entry->compressedData);
    }

    /**
     * @param resource $fh
     * @param array<string, int> $offsets
     */
    private function writeOfsDeltaEntry($fh, \HashContext $hashCtx, PackEntry $entry, array $offsets): void
    {
        $baseOffset = $offsets[$entry->baseHash] ?? null;
        if ($baseOffset === null) {
            $this->writeWholeEntry($fh, $hashCtx, $entry);
            return;
        }

        $currentOffset = (int) ftell($fh);
        $negativeOffset = $currentOffset - $baseOffset;

        $deltaSize = strlen($entry->compressedData);
        $rawDeltaData = gzuncompress($entry->compressedData);
        $rawDeltaSize = $rawDeltaData !== false ? strlen($rawDeltaData) : $deltaSize;

        $header = $this->encodeObjectHeader(self::OBJ_OFS_DELTA, $rawDeltaSize);
        $this->writeAndHash($fh, $hashCtx, $header);

        $ofsEncoded = $this->encodeNegativeOffset($negativeOffset);
        $this->writeAndHash($fh, $hashCtx, $ofsEncoded);

        $this->writeAndHash($fh, $hashCtx, $entry->compressedData);
    }

    private function encodeNegativeOffset(int $offset): string
    {
        $bytes = [chr($offset & 0x7F)];
        $offset >>= 7;

        while ($offset > 0) {
            $offset--;
            array_unshift($bytes, chr(0x80 | ($offset & 0x7F)));
            $offset >>= 7;
        }

        return implode('', $bytes);
    }

    /**
     * @param resource $fh
     */
    private function writeAndHash($fh, \HashContext $hashCtx, string $data): void
    {
        fwrite($fh, $data);
        hash_update($hashCtx, $data);
    }

    /**
     * Generate pack index v2 alongside the pack file.
     *
     * @param list<PackEntry> $entries
     */
    private function writeIndex(array $entries, string $packPath): void
    {
        $idxPath = substr($packPath, 0, -5) . '.idx';
        $sorted = $this->sortEntriesForIndex($entries);

        $fh = fopen($idxPath, 'w+b');
        if ($fh === false) {
            return;
        }

        fwrite($fh, "\xfftOc");
        fwrite($fh, pack('N', 2));

        $this->writeFanout($fh, $sorted);
        $this->writeHashTable($fh, $sorted);
        $this->writeCrcTable($fh, $sorted);
        $this->writeOffsetTable($fh, $sorted);

        $packChecksum = hash_file('sha1', $packPath, true);
        if ($packChecksum !== false) {
            fwrite($fh, $packChecksum);
        }

        fseek($fh, 0);
        $content = stream_get_contents($fh);
        if ($content !== false) {
            $idxChecksum = hash('sha1', $content, true);
            fseek($fh, 0, SEEK_END);
            fwrite($fh, $idxChecksum);
        }

        fclose($fh);
    }

    /**
     * @param list<PackEntry> $entries
     * @return list<PackEntry>
     */
    private function sortEntriesForIndex(array $entries): array
    {
        usort($entries, fn (PackEntry $a, PackEntry $b): int => strcmp($a->hash, $b->hash));

        return $entries;
    }

    /**
     * @param resource $fh
     * @param list<PackEntry> $sorted
     */
    private function writeFanout($fh, array $sorted): void
    {
        $fanout = array_fill(0, 256, 0);
        foreach ($sorted as $entry) {
            $firstByte = intval(substr($entry->hash, 0, 2), 16);
            for ($j = $firstByte; $j < 256; $j++) {
                $fanout[$j]++;
            }
        }
        foreach ($fanout as $count) {
            fwrite($fh, pack('N', $count));
        }
    }

    /**
     * @param resource $fh
     * @param list<PackEntry> $sorted
     */
    private function writeHashTable($fh, array $sorted): void
    {
        foreach ($sorted as $entry) {
            $bin = hex2bin($entry->hash);
            if ($bin !== false) {
                fwrite($fh, $bin);
            }
        }
    }

    /**
     * @param resource $fh
     * @param list<PackEntry> $sorted
     */
    private function writeCrcTable($fh, array $sorted): void
    {
        foreach ($sorted as $entry) {
            $crc = crc32($entry->compressedData);
            fwrite($fh, pack('N', $crc));
        }
    }

    /**
     * @param resource $fh
     * @param list<PackEntry> $sorted
     */
    private function writeOffsetTable($fh, array $sorted): void
    {
        foreach ($sorted as $entry) {
            fwrite($fh, pack('N', $entry->packOffset));
        }
    }

    private function encodeObjectHeader(int $type, int $size): string
    {
        $byte = ($type << 4) | ($size & 0x0F);
        $size >>= 4;
        $header = '';

        while ($size > 0) {
            $header .= chr($byte | 0x80);
            $byte = $size & 0x7F;
            $size >>= 7;
        }

        return $header . chr($byte);
    }

    private function objectTypeToPackType(ObjectType $type): int
    {
        return match ($type) {
            ObjectType::Commit => self::OBJ_COMMIT,
            ObjectType::Tree => self::OBJ_TREE,
            ObjectType::Blob => self::OBJ_BLOB,
            ObjectType::Tag => self::OBJ_TAG,
        };
    }

    private function typePriority(ObjectType $type): int
    {
        return match ($type) {
            ObjectType::Commit => 0,
            ObjectType::Tree => 1,
            ObjectType::Blob => 2,
            ObjectType::Tag => 3,
        };
    }
}

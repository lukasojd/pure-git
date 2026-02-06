<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\CommitGraph;

use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\ObjectType;
use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Domain\Repository\ObjectStorageInterface;
use Lukasojd\PureGit\Domain\Repository\RefStorageInterface;
use Lukasojd\PureGit\Infrastructure\Lock\LockFile;
use SplQueue;

final class CommitGraphWriter
{
    private const string MAGIC = 'PCGR';

    private const int VERSION = 1;

    private const int HEADER_SIZE = 16;

    private const int FANOUT_SIZE = 1024;

    private const int OID_SIZE = 20;

    private const int COMMIT_DATA_SIZE = 36;

    private const int NO_PARENT = 0xFFFFFFFF;

    private const int EXTRA_PARENTS_MARKER = 0xFFFFFFFE;

    public function write(
        ObjectStorageInterface $objects,
        RefStorageInterface $refs,
        string $outputPath,
    ): int {
        $commits = $this->collectCommits($objects, $refs);

        if ($commits === []) {
            return 0;
        }

        $oids = $this->sortedOids($commits);
        $oidToIndex = array_flip($oids);
        $generations = new GenerationComputer()->compute($commits, $oidToIndex);
        $extraParents = $this->buildExtraParents($commits, $oids, $oidToIndex);

        $this->writeFile($commits, $oids, $oidToIndex, $generations, $extraParents, $outputPath);

        return count($oids);
    }

    public function countReachable(
        ObjectStorageInterface $objects,
        RefStorageInterface $refs,
    ): int {
        return count($this->collectCommits($objects, $refs));
    }

    /**
     * @param array<string, array{parents: list<string>, timestamp: int}> $commits
     * @return list<string>
     */
    private function sortedOids(array $commits): array
    {
        $oids = array_keys($commits);
        sort($oids, SORT_STRING);

        return $oids;
    }

    /**
     * @return array<string, array{parents: list<string>, timestamp: int}>
     */
    private function collectCommits(
        ObjectStorageInterface $objects,
        RefStorageInterface $refs,
    ): array {
        $startIds = $this->collectStartIds($objects, $refs);

        return $this->bfsCollect($objects, $startIds);
    }

    /**
     * @return list<ObjectId>
     */
    private function collectStartIds(
        ObjectStorageInterface $objects,
        RefStorageInterface $refs,
    ): array {
        $allRefs = $refs->listRefs('refs/');

        try {
            $allRefs['HEAD'] = $refs->resolve(RefName::head());
        } catch (\Throwable) {
        }

        $startIds = [];
        $seen = [];

        foreach ($allRefs as $id) {
            $peeled = $this->peelToCommit($objects, $id);
            if ($peeled instanceof \Lukasojd\PureGit\Domain\Object\ObjectId && ! isset($seen[$peeled->hash])) {
                $seen[$peeled->hash] = true;
                $startIds[] = $peeled;
            }
        }

        return $startIds;
    }

    /**
     * @param list<ObjectId> $startIds
     * @return array<string, array{parents: list<string>, timestamp: int}>
     */
    private function bfsCollect(ObjectStorageInterface $objects, array $startIds): array
    {
        $extractor = new CommitDataExtractor();
        $commits = [];
        $visited = [];
        /** @var SplQueue<string> $queue */
        $queue = new SplQueue();

        foreach ($startIds as $id) {
            $visited[$id->hash] = true;
            $queue->enqueue($id->hash);
        }

        while (! $queue->isEmpty()) {
            $hex = $queue->dequeue();

            if (isset($commits[$hex])) {
                continue;
            }

            $binHash = hex2bin($hex);
            if ($binHash === false) {
                continue;
            }

            $raw = $objects->readRawHeaderByBinary($binHash);
            $data = $extractor->extract($raw);
            if ($data === null) {
                continue;
            }

            $commits[$hex] = $data;
            $this->enqueueParents($data['parents'], $visited, $queue);
        }

        return $commits;
    }

    /**
     * @param list<string> $parents
     * @param array<string, true> $visited
     * @param SplQueue<string> $queue
     */
    private function enqueueParents(array $parents, array &$visited, SplQueue $queue): void
    {
        foreach ($parents as $parentHex) {
            if (! isset($visited[$parentHex])) {
                $visited[$parentHex] = true;
                $queue->enqueue($parentHex);
            }
        }
    }

    private function peelToCommit(ObjectStorageInterface $objects, ObjectId $id): ?ObjectId
    {
        $extractor = new CommitDataExtractor();

        for ($i = 0; $i < 10; $i++) {
            $raw = $objects->readRawHeader($id);

            if ($raw->type === ObjectType::Commit) {
                return $id;
            }

            if ($raw->type !== ObjectType::Tag) {
                return null;
            }

            $targetHex = $extractor->extractTagTarget($raw);
            if ($targetHex === null) {
                return null;
            }

            $id = ObjectId::fromTrustedHex($targetHex);
        }

        return null;
    }

    /**
     * @param array<string, array{parents: list<string>, timestamp: int}> $commits
     * @param list<string> $oids
     * @param array<string, int> $oidToIndex
     * @return array{chunk: string, offsets: array<string, int>}
     */
    private function buildExtraParents(array $commits, array $oids, array $oidToIndex): array
    {
        $chunk = '';
        $offsets = [];

        foreach ($oids as $hex) {
            $parents = $commits[$hex]['parents'];
            if (count($parents) <= 2) {
                continue;
            }

            $offsets[$hex] = strlen($chunk);
            $chunk .= $this->encodeExtraParents(array_slice($parents, 1), $oidToIndex);
        }

        return [
            'chunk' => $chunk,
            'offsets' => $offsets,
        ];
    }

    /**
     * @param list<string> $parents
     * @param array<string, int> $oidToIndex
     */
    private function encodeExtraParents(array $parents, array $oidToIndex): string
    {
        $data = '';
        $lastIdx = count($parents) - 1;

        foreach ($parents as $i => $parentHex) {
            $index = $oidToIndex[$parentHex] ?? self::NO_PARENT;
            if ($i === $lastIdx) {
                $index |= 0x80000000;
            }
            $data .= pack('N', $index);
        }

        return $data;
    }

    /**
     * @param array<string, array{parents: list<string>, timestamp: int}> $commits
     * @param list<string> $oids
     * @param array<string, int> $oidToIndex
     * @param array<string, int> $generations
     * @param array{chunk: string, offsets: array<string, int>} $extraParents
     */
    private function writeFile(
        array $commits,
        array $oids,
        array $oidToIndex,
        array $generations,
        array $extraParents,
        string $outputPath,
    ): void {
        $numCommits = count($oids);

        $extraParentsOffset = self::HEADER_SIZE
            + self::FANOUT_SIZE
            + ($numCommits * self::OID_SIZE)
            + ($numCommits * self::COMMIT_DATA_SIZE);

        $lock = new LockFile($outputPath);
        $lock->acquire();

        $hashCtx = hash_init('sha1');
        $writeAndHash = static function (string $data) use ($lock, $hashCtx): void {
            hash_update($hashCtx, $data);
            $lock->write($data);
        };

        $writeAndHash(self::MAGIC . pack('NNN', self::VERSION, $numCommits, $extraParentsOffset));
        $this->writeFanout($oids, $writeAndHash);
        $this->writeOidTable($oids, $writeAndHash);
        $this->writeCommitDataTable($commits, $oids, $oidToIndex, $generations, $extraParents, $writeAndHash);

        if ($extraParents['chunk'] !== '') {
            $writeAndHash($extraParents['chunk']);
        }

        $lock->write(hash_final($hashCtx, true));
        $lock->commit();
    }

    /**
     * @param list<string> $oids
     * @param callable(string): void $writeAndHash
     */
    private function writeFanout(array $oids, callable $writeAndHash): void
    {
        $fanout = array_fill(0, 256, 0);

        foreach ($oids as $hex) {
            $fanout[(int) hexdec(substr($hex, 0, 2))]++;
        }

        for ($i = 1; $i < 256; $i++) {
            $fanout[$i] += $fanout[$i - 1];
        }

        $data = '';
        for ($i = 0; $i < 256; $i++) {
            $data .= pack('N', $fanout[$i]);
        }
        $writeAndHash($data);
    }

    /**
     * @param list<string> $oids
     * @param callable(string): void $writeAndHash
     */
    private function writeOidTable(array $oids, callable $writeAndHash): void
    {
        foreach ($oids as $hex) {
            $bin = hex2bin($hex);
            if ($bin !== false) {
                $writeAndHash($bin);
            }
        }
    }

    /**
     * @param array<string, array{parents: list<string>, timestamp: int}> $commits
     * @param list<string> $oids
     * @param array<string, int> $oidToIndex
     * @param array<string, int> $generations
     * @param array{chunk: string, offsets: array<string, int>} $extraParents
     * @param callable(string): void $writeAndHash
     */
    private function writeCommitDataTable(
        array $commits,
        array $oids,
        array $oidToIndex,
        array $generations,
        array $extraParents,
        callable $writeAndHash,
    ): void {
        foreach ($oids as $hex) {
            $writeAndHash($this->encodeCommitEntry($commits[$hex], $hex, $oidToIndex, $generations, $extraParents));
        }
    }

    /**
     * @param array{parents: list<string>, timestamp: int} $data
     * @param array<string, int> $oidToIndex
     * @param array<string, int> $generations
     * @param array{chunk: string, offsets: array<string, int>} $extraParents
     */
    private function encodeCommitEntry(
        array $data,
        string $hex,
        array $oidToIndex,
        array $generations,
        array $extraParents,
    ): string {
        $parents = $data['parents'];
        $parent1 = isset($parents[0]) ? ($oidToIndex[$parents[0]] ?? self::NO_PARENT) : self::NO_PARENT;
        $parent2 = $this->resolveParent2($parents, $oidToIndex);

        $extraOffset = count($parents) > 2 ? ($extraParents['offsets'][$hex] ?? 0) : 0;

        return pack('N', self::NO_PARENT)
            . pack('N', $parent1)
            . pack('N', $parent2)
            . pack('N', $extraOffset)
            . pack('N', $generations[$hex] ?? 1)
            . pack('N', $data['timestamp'])
            . str_repeat("\0", 12);
    }

    /**
     * @param list<string> $parents
     * @param array<string, int> $oidToIndex
     */
    private function resolveParent2(array $parents, array $oidToIndex): int
    {
        if (count($parents) <= 1) {
            return self::NO_PARENT;
        }

        if (count($parents) === 2) {
            return $oidToIndex[$parents[1]] ?? self::NO_PARENT;
        }

        return self::EXTRA_PARENTS_MARKER;
    }
}

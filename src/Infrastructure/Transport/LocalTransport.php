<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Transport;

use Generator;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\ObjectType;
use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Domain\Repository\RawObject;
use Lukasojd\PureGit\Infrastructure\Cache\ObjectCache;
use Lukasojd\PureGit\Infrastructure\Object\CombinedObjectStorage;
use Lukasojd\PureGit\Infrastructure\Object\LooseObjectStorage;
use Lukasojd\PureGit\Infrastructure\Ref\FileRefStorage;
use SplQueue;

final readonly class LocalTransport implements TransportInterface
{
    private string $remoteGitDir;

    public function __construct(
        string $remotePath,
    ) {
        if (is_dir($remotePath . '/.git')) {
            $this->remoteGitDir = $remotePath . '/.git';
        } elseif (is_dir($remotePath . '/objects')) {
            $this->remoteGitDir = $remotePath;
        } else {
            throw new PureGitException(sprintf('Not a valid repository: %s', $remotePath));
        }
    }

    /**
     * @return array<string, ObjectId>
     */
    public function listRefs(): array
    {
        $refStorage = new FileRefStorage($this->remoteGitDir);
        $refs = $refStorage->listRefs('refs/');

        $headRef = RefName::head();
        if ($refStorage->exists($headRef)) {
            $refs['HEAD'] = $refStorage->resolve($headRef);
        }

        return $refs;
    }

    /**
     * @param list<ObjectId> $wants
     * @param list<ObjectId> $haves
     */
    public function fetchPack(array $wants, array $haves = [], ?string $outputPath = null): string
    {
        $objectStorage = $this->createRemoteObjectStorage();

        // Two-pass: first collect IDs, then stream-serialize
        $ids = $this->collectObjectIds($objectStorage, $wants, $haves);
        $count = count($ids);

        $packPath = $outputPath ?? sys_get_temp_dir() . '/pure-git-pack-' . getmypid() . '.pack';
        $this->serializePack($this->yieldRawObjects($objectStorage, $ids), $count, $packPath);

        return $packPath;
    }

    public function sendPack(string $packData, string $refUpdates): string
    {
        throw new PureGitException('Push via local transport not yet supported');
    }

    private function createRemoteObjectStorage(): CombinedObjectStorage
    {
        return new CombinedObjectStorage(
            new LooseObjectStorage($this->remoteGitDir . '/objects'),
            $this->remoteGitDir . '/objects',
            new ObjectCache(),
        );
    }

    /**
     * Collect objects reachable from wants but not from haves.
     *
     * Two-phase approach:
     * 1. Walk ALL objects reachable from have commits (trees + blobs included)
     * 2. Walk want side, skipping anything already in the have set
     *
     * This prevents re-discovering shared trees/blobs between commits,
     * which is the primary cause of O(C * T * B) scaling on large repos.
     *
     * @param list<ObjectId> $wants
     * @param list<ObjectId> $haves
     * @return list<ObjectId>
     */
    private function collectObjectIds(CombinedObjectStorage $objectStorage, array $wants, array $haves): array
    {
        $haveSet = $this->collectReachableIds($objectStorage, $haves);

        return $this->collectMissingIds($objectStorage, $wants, $haveSet);
    }

    /**
     * Build the have set using two complementary walks:
     *
     * 1. Commit ancestry walk: follow parent chains (commits only, no trees)
     *    to catch merge paths the want-side BFS might follow. Limited to
     *    MAX_HAVE_COMMITS to avoid walking entire repository history.
     *
     * 2. Tree walk: walk the direct have commits' trees to collect tree/blob IDs.
     *    This is what deduplicates the actual object content.
     *
     * @param list<ObjectId> $roots
     * @return array<string, true>
     */
    private function collectReachableIds(CombinedObjectStorage $objectStorage, array $roots): array
    {
        $seen = [];

        /** @var SplQueue<ObjectId> $treeQueue */
        $treeQueue = new SplQueue();
        $this->walkHaveCommitAncestry($objectStorage, $roots, $treeQueue, $seen);
        $this->walkTrees($objectStorage, $treeQueue, $seen);

        return $seen;
    }

    /**
     * Walk commit parent chains to collect ancestor commit IDs + tree roots.
     * Limited to 1000 commits to avoid full history walk on large repos.
     * Only reads commit objects (small), never reads trees or blobs.
     *
     * @param list<ObjectId> $roots
     * @param SplQueue<ObjectId> $treeQueue receives tree IDs to walk
     * @param array<string, true> $seen receives commit IDs
     */
    private function walkHaveCommitAncestry(
        CombinedObjectStorage $objectStorage,
        array $roots,
        SplQueue $treeQueue,
        array &$seen,
    ): void {
        $commitLimit = 1000;
        $commitCount = 0;

        /** @var SplQueue<ObjectId> $commitQueue */
        $commitQueue = new SplQueue();
        foreach ($roots as $root) {
            $commitQueue->enqueue($root);
        }

        while (! $commitQueue->isEmpty() && $commitCount < $commitLimit) {
            $id = $commitQueue->dequeue();
            if (isset($seen[$id->hash])) {
                continue;
            }
            $seen[$id->hash] = true;
            $commitCount++;

            if (! $objectStorage->exists($id)) {
                continue;
            }

            $raw = $objectStorage->readRaw($id);
            if ($raw->type !== ObjectType::Commit) {
                continue;
            }

            $this->parseCommitForHaveWalk($raw->data, $commitQueue, $treeQueue);
        }
    }

    /**
     * Parse commit data: enqueue parent commits + tree ID.
     *
     * @param SplQueue<ObjectId> $commitQueue
     * @param SplQueue<ObjectId> $treeQueue
     */
    private function parseCommitForHaveWalk(string $data, SplQueue $commitQueue, SplQueue $treeQueue): void
    {
        foreach (explode("\n", $data) as $line) {
            if (str_starts_with($line, 'tree ')) {
                $treeQueue->enqueue(ObjectId::fromHex(substr($line, 5)));
            } elseif (str_starts_with($line, 'parent ')) {
                $commitQueue->enqueue(ObjectId::fromHex(substr($line, 7)));
            } elseif ($line === '') {
                break;
            }
        }
    }

    /**
     * Walk tree objects, collecting blob hashes from entries.
     *
     * @param SplQueue<ObjectId> $treeQueue
     * @param array<string, true> $seen
     */
    private function walkTrees(CombinedObjectStorage $objectStorage, SplQueue $treeQueue, array &$seen): void
    {
        while (! $treeQueue->isEmpty()) {
            $id = $treeQueue->dequeue();
            if (isset($seen[$id->hash])) {
                continue;
            }
            $seen[$id->hash] = true;

            if (! $objectStorage->exists($id)) {
                continue;
            }

            $raw = $objectStorage->readRaw($id);
            if ($raw->type === ObjectType::Tree) {
                $this->collectTreeChildren($raw->data, $treeQueue, $seen);
            }
        }
    }

    /**
     * Process tree entries: enqueue subtrees for traversal, mark blobs as seen directly.
     *
     * Blobs have no children, so we only need their hash (already in the tree entry).
     * This avoids expensive readRaw() calls for every blob in the have set.
     *
     * @param SplQueue<ObjectId> $queue
     * @param array<string, true> $seen
     */
    private function collectTreeChildren(string $data, SplQueue $queue, array &$seen): void
    {
        $offset = 0;
        $len = strlen($data);

        while ($offset < $len) {
            $spacePos = strpos($data, ' ', $offset);
            if ($spacePos === false) {
                break;
            }
            $mode = substr($data, $offset, $spacePos - $offset);
            $nullPos = strpos($data, "\0", $spacePos);
            if ($nullPos === false) {
                break;
            }
            $hash = substr($data, $nullPos + 1, 20);
            $childId = ObjectId::fromBinary($hash);

            if ($mode === '40000') {
                $queue->enqueue($childId);
            } else {
                $seen[$childId->hash] = true;
            }

            $offset = $nullPos + 21;
        }
    }

    /**
     * BFS from want roots, collecting only objects NOT in the have set.
     *
     * @param list<ObjectId> $wants
     * @param array<string, true> $haveSet
     * @return list<ObjectId>
     */
    private function collectMissingIds(CombinedObjectStorage $objectStorage, array $wants, array $haveSet): array
    {
        $ids = [];
        $seen = [];

        /** @var SplQueue<ObjectId> $queue */
        $queue = new SplQueue();
        foreach ($wants as $want) {
            $queue->enqueue($want);
        }

        while (! $queue->isEmpty()) {
            $id = $queue->dequeue();
            if (isset($seen[$id->hash]) || isset($haveSet[$id->hash])) {
                continue;
            }
            $seen[$id->hash] = true;

            if (! $objectStorage->exists($id)) {
                continue;
            }

            $ids[] = $id;
            $raw = $objectStorage->readRaw($id);
            $this->enqueueFromRaw($raw, $queue);
        }

        return $ids;
    }

    /**
     * Yield raw objects one at a time using a generator for memory efficiency.
     *
     * @param list<ObjectId> $ids
     * @return Generator<int, RawObject>
     */
    private function yieldRawObjects(CombinedObjectStorage $objectStorage, array $ids): Generator
    {
        foreach ($ids as $id) {
            yield $objectStorage->readRaw($id);
        }
    }

    /**
     * @param SplQueue<ObjectId> $queue
     */
    private function enqueueFromRaw(RawObject $raw, SplQueue $queue): void
    {
        if ($raw->type === ObjectType::Commit) {
            $this->enqueueCommitRefs($raw->data, $queue);
            return;
        }

        if ($raw->type === ObjectType::Tree) {
            $this->enqueueTreeRefs($raw->data, $queue);
        }
    }

    /**
     * @param SplQueue<ObjectId> $queue
     */
    private function enqueueCommitRefs(string $data, SplQueue $queue): void
    {
        foreach (explode("\n", $data) as $line) {
            if (str_starts_with($line, 'tree ')) {
                $queue->enqueue(ObjectId::fromHex(substr($line, 5)));
            } elseif (str_starts_with($line, 'parent ')) {
                $queue->enqueue(ObjectId::fromHex(substr($line, 7)));
            } elseif ($line === '') {
                break;
            }
        }
    }

    /**
     * @param SplQueue<ObjectId> $queue
     */
    private function enqueueTreeRefs(string $data, SplQueue $queue): void
    {
        $offset = 0;
        $len = strlen($data);

        while ($offset < $len) {
            $spacePos = strpos($data, ' ', $offset);
            if ($spacePos === false) {
                break;
            }
            $nullPos = strpos($data, "\0", $spacePos);
            if ($nullPos === false) {
                break;
            }
            $hash = substr($data, $nullPos + 1, 20);
            $queue->enqueue(ObjectId::fromBinary($hash));
            $offset = $nullPos + 21;
        }
    }

    /**
     * @param Generator<int, RawObject> $objects
     */
    private function serializePack(Generator $objects, int $count, string $outputPath): void
    {
        new StreamingPackSerializer()->serialize($objects, $count, $outputPath);
    }
}

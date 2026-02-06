<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Transport;

use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Object\GitObject;
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
    public function fetchPack(array $wants, array $haves = []): string
    {
        $objectStorage = $this->createRemoteObjectStorage();
        $rawObjects = $this->collectRawObjects($objectStorage, $wants, $haves);

        return $this->serializeRawPack($rawObjects);
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
     * Collect raw objects using BFS with SplQueue for O(1) dequeue.
     * Stores RawObject instead of deserialized GitObject to avoid memory overhead.
     *
     * @param list<ObjectId> $wants
     * @param list<ObjectId> $haves
     * @return list<array{type: ObjectType, data: string}>
     */
    private function collectRawObjects(CombinedObjectStorage $objectStorage, array $wants, array $haves): array
    {
        $toSend = [];
        $seen = [];
        $haveSet = [];

        foreach ($haves as $have) {
            $haveSet[$have->hash] = true;
        }

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

            $raw = $objectStorage->readRaw($id);
            $toSend[] = [
                'type' => $raw->type,
                'data' => $raw->data,
            ];
            $this->enqueueFromRaw($raw, $queue);
        }

        return $toSend;
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
     * @param list<array{type: ObjectType, data: string}> $rawObjects
     */
    private function serializeRawPack(array $rawObjects): string
    {
        $header = 'PACK' . pack('N', 2) . pack('N', count($rawObjects));
        $chunks = [$header];

        foreach ($rawObjects as $obj) {
            $type = match ($obj['type']) {
                ObjectType::Commit => 1,
                ObjectType::Tree => 2,
                ObjectType::Blob => 3,
                ObjectType::Tag => 4,
            };

            $chunks[] = $this->encodeObjectHeader($type, strlen($obj['data']));
            $compressed = gzcompress($obj['data']);
            $chunks[] = $compressed !== false ? $compressed : '';
        }

        $data = implode('', $chunks);

        return $data . hash('sha1', $data, true);
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
}

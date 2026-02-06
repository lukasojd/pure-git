<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Transport;

use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Domain\Object\GitObject;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\ObjectType;
use Lukasojd\PureGit\Domain\Object\Tree;
use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Infrastructure\Cache\ObjectCache;
use Lukasojd\PureGit\Infrastructure\Object\CombinedObjectStorage;
use Lukasojd\PureGit\Infrastructure\Object\LooseObjectStorage;
use Lukasojd\PureGit\Infrastructure\Ref\FileRefStorage;

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
        $toSend = $this->collectObjectsToSend($objectStorage, $wants, $haves);

        return $this->serializePack($toSend);
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
     * @param list<ObjectId> $wants
     * @param list<ObjectId> $haves
     * @return list<GitObject>
     */
    private function collectObjectsToSend(CombinedObjectStorage $objectStorage, array $wants, array $haves): array
    {
        $toSend = [];
        $seen = [];
        $haveSet = [];

        foreach ($haves as $have) {
            $haveSet[$have->hash] = true;
        }

        $queue = $wants;

        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($seen[$id->hash]) || isset($haveSet[$id->hash])) {
                continue;
            }
            $seen[$id->hash] = true;

            if (! $objectStorage->exists($id)) {
                continue;
            }

            $object = $objectStorage->read($id);
            $toSend[] = $object;
            $this->enqueueReferencedObjects($object, $queue);
        }

        return $toSend;
    }

    /**
     * @param list<ObjectId> $queue
     */
    private function enqueueReferencedObjects(GitObject $object, array &$queue): void
    {
        if ($object instanceof Commit) {
            $queue[] = $object->treeId;
            foreach ($object->parents as $parent) {
                $queue[] = $parent;
            }
            return;
        }

        if ($object instanceof Tree) {
            foreach ($object->entries as $entry) {
                $queue[] = $entry->objectId;
            }
        }
    }

    /**
     * @param list<GitObject> $objects
     */
    private function serializePack(array $objects): string
    {
        $data = 'PACK';
        $data .= pack('N', 2); // version
        $data .= pack('N', count($objects));

        foreach ($objects as $object) {
            $data .= $this->serializeObject($object);
        }

        return $data . hash('sha1', $data, true);
    }

    private function serializeObject(GitObject $object): string
    {
        $content = $object->serialize();
        $type = match ($object->getType()) {
            ObjectType::Commit => 1,
            ObjectType::Tree => 2,
            ObjectType::Blob => 3,
            ObjectType::Tag => 4,
        };

        $header = $this->encodeObjectHeader($type, strlen($content));
        $compressed = gzcompress($content);

        return $header . ($compressed !== false ? $compressed : '');
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

        $header .= chr($byte);

        return $header;
    }
}

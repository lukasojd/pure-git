<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Transport;

use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Object\ObjectId;
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
        $objectStorage = new CombinedObjectStorage(
            new LooseObjectStorage($this->remoteGitDir . '/objects'),
            $this->remoteGitDir . '/objects',
            new ObjectCache(),
        );

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

            $raw = $object->serialize();
            $type = $object->getType();

            if ($type === \Lukasojd\PureGit\Domain\Object\ObjectType::Commit) {
                /** @var \Lukasojd\PureGit\Domain\Object\Commit $commit */
                $commit = $object;
                $queue[] = $commit->treeId;
                foreach ($commit->parents as $parent) {
                    $queue[] = $parent;
                }
            } elseif ($type === \Lukasojd\PureGit\Domain\Object\ObjectType::Tree) {
                /** @var \Lukasojd\PureGit\Domain\Object\Tree $tree */
                $tree = $object;
                foreach ($tree->entries as $entry) {
                    $queue[] = $entry->objectId;
                }
            }
        }

        // Serialize as a simple pack format
        $data = 'PACK';
        $data .= pack('N', 2); // version
        $data .= pack('N', count($toSend));

        foreach ($toSend as $object) {
            $content = $object->serialize();
            $type = match ($object->getType()) {
                \Lukasojd\PureGit\Domain\Object\ObjectType::Commit => 1,
                \Lukasojd\PureGit\Domain\Object\ObjectType::Tree => 2,
                \Lukasojd\PureGit\Domain\Object\ObjectType::Blob => 3,
                \Lukasojd\PureGit\Domain\Object\ObjectType::Tag => 4,
            };

            $size = strlen($content);
            $byte = ($type << 4) | ($size & 0x0F);
            $size >>= 4;

            while ($size > 0) {
                $data .= chr($byte | 0x80);
                $byte = $size & 0x7F;
                $size >>= 7;
            }
            $data .= chr($byte);

            $compressed = gzcompress($content);
            if ($compressed !== false) {
                $data .= $compressed;
            }
        }

        return $data . hash('sha1', $data, true);
    }

    public function sendPack(string $packData, string $refUpdates): string
    {
        throw new PureGitException('Push via local transport not yet supported');
    }
}

<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Generator;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\ObjectType;
use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Domain\Repository\RawObject;
use Lukasojd\PureGit\Infrastructure\Config\GitConfigReader;
use Lukasojd\PureGit\Infrastructure\Transport\PktLine;
use Lukasojd\PureGit\Infrastructure\Transport\StreamingPackSerializer;
use Lukasojd\PureGit\Infrastructure\Transport\TransportFactory;
use SplQueue;

final readonly class PushHandler
{
    private const string ZERO_HASH = '0000000000000000000000000000000000000000';

    public function __construct(
        private Repository $repository,
    ) {
    }

    public function push(string $remoteName = 'origin', ?string $refspec = null): PushResult
    {
        $config = new GitConfigReader($this->repository->gitDir . '/config');
        $url = $config->get('remote "' . $remoteName . '"', 'url');
        if ($url === null) {
            throw new PureGitException(sprintf("fatal: '%s' does not appear to be a git repository", $remoteName));
        }

        [$localRef, $remoteRef] = $this->resolveRefspec($refspec);
        $localId = $this->repository->refs->resolve(RefName::fromString($localRef));

        $transport = TransportFactory::create($url);
        $remoteRefs = $transport->listRefs();

        $remoteId = $remoteRefs[$remoteRef] ?? null;
        $oldHash = $remoteId instanceof ObjectId ? $remoteId->hash : self::ZERO_HASH;

        if ($remoteId instanceof ObjectId && $remoteId->equals($localId)) {
            return new PushResult(
                upToDate: true,
                objectsSent: 0,
                remoteUrl: $url,
                refUpdates: [],
            );
        }

        $haves = $this->collectRemoteIds($remoteRefs);
        $missingIds = $this->collectMissingIds([$localId], $haves);

        $packPath = $this->buildPackFile($missingIds);
        $refUpdateLines = $this->buildRefUpdateLines($oldHash, $localId->hash, $remoteRef);

        $response = $transport->sendPack($refUpdateLines, $packPath);

        $this->cleanupPackFile($packPath);

        $refUpdate = new PushRefUpdate(
            refName: $remoteRef,
            oldHash: $oldHash === self::ZERO_HASH ? null : $oldHash,
            newHash: $localId->hash,
            status: $this->parseResponseStatus($response, $remoteRef),
        );

        return new PushResult(
            upToDate: false,
            objectsSent: count($missingIds),
            remoteUrl: $url,
            refUpdates: [$refUpdate],
        );
    }

    /**
     * @return array{string, string} [localRef, remoteRef]
     */
    private function resolveRefspec(?string $refspec): array
    {
        if ($refspec !== null) {
            return $this->parseRefspecPair($refspec);
        }

        $head = RefName::head();
        $symbolicRef = $this->repository->refs->getSymbolicRef($head);
        if (! $symbolicRef instanceof RefName || ! $symbolicRef->isBranch()) {
            throw new PureGitException('Cannot push: not on a branch');
        }

        return [$symbolicRef->value, $symbolicRef->value];
    }

    /**
     * @return array{string, string}
     */
    private function parseRefspecPair(string $refspec): array
    {
        $colonPos = strpos($refspec, ':');
        if ($colonPos !== false) {
            $src = substr($refspec, 0, $colonPos);
            $dst = substr($refspec, $colonPos + 1);

            return [
                $this->normalizeRef($src),
                $this->normalizeRef($dst),
            ];
        }

        $normalized = $this->normalizeRef($refspec);

        return [$normalized, $normalized];
    }

    private function normalizeRef(string $ref): string
    {
        if (str_starts_with($ref, 'refs/')) {
            return $ref;
        }

        return 'refs/heads/' . $ref;
    }

    /**
     * @param array<string, ObjectId> $remoteRefs
     * @return list<ObjectId>
     */
    private function collectRemoteIds(array $remoteRefs): array
    {
        $ids = [];
        $seen = [];
        foreach ($remoteRefs as $id) {
            if (isset($seen[$id->hash])) {
                continue;
            }
            $seen[$id->hash] = true;
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param list<ObjectId> $wants
     * @param list<ObjectId> $haves
     * @return list<ObjectId>
     */
    private function collectMissingIds(array $wants, array $haves): array
    {
        $haveSet = $this->walkAncestors($haves);

        return $this->walkMissing($wants, $haveSet);
    }

    /**
     * @param list<ObjectId> $roots
     * @return array<string, true>
     */
    private function walkAncestors(array $roots): array
    {
        $seen = [];

        /** @var SplQueue<ObjectId> $queue */
        $queue = new SplQueue();
        foreach ($roots as $root) {
            if ($this->repository->objects->exists($root)) {
                $queue->enqueue($root);
            }
        }

        while (! $queue->isEmpty()) {
            $id = $queue->dequeue();
            if (isset($seen[$id->hash])) {
                continue;
            }
            $seen[$id->hash] = true;

            $raw = $this->repository->objects->readRaw($id);
            $this->enqueueChildren($raw, $queue, $seen);
        }

        return $seen;
    }

    /**
     * @param list<ObjectId> $wants
     * @param array<string, true> $haveSet
     * @return list<ObjectId>
     */
    private function walkMissing(array $wants, array $haveSet): array
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

            if (! $this->repository->objects->exists($id)) {
                continue;
            }

            $ids[] = $id;
            $raw = $this->repository->objects->readRaw($id);
            $this->enqueueChildren($raw, $queue, $seen);
        }

        return $ids;
    }

    /**
     * @param SplQueue<ObjectId> $queue
     * @param array<string, true> $seen
     */
    private function enqueueChildren(RawObject $raw, SplQueue $queue, array $seen): void
    {
        if ($raw->type === ObjectType::Commit) {
            $this->enqueueCommitChildren($raw->data, $queue, $seen);
        } elseif ($raw->type === ObjectType::Tree) {
            $this->enqueueTreeChildren($raw->data, $queue, $seen);
        }
    }

    /**
     * @param SplQueue<ObjectId> $queue
     * @param array<string, true> $seen
     */
    private function enqueueCommitChildren(string $data, SplQueue $queue, array $seen): void
    {
        foreach (explode("\n", $data) as $line) {
            $hash = $this->extractCommitLineHash($line);
            if ($hash === null) {
                break;
            }
            if ($hash !== '' && ! isset($seen[$hash])) {
                $queue->enqueue(ObjectId::fromHex($hash));
            }
        }
    }

    /**
     * @return string|null hash string, empty string for non-ref lines, null to stop
     */
    private function extractCommitLineHash(string $line): ?string
    {
        if (str_starts_with($line, 'tree ')) {
            return substr($line, 5);
        }
        if (str_starts_with($line, 'parent ')) {
            return substr($line, 7);
        }
        if ($line === '') {
            return null;
        }

        return '';
    }

    /**
     * @param SplQueue<ObjectId> $queue
     * @param array<string, true> $seen
     */
    private function enqueueTreeChildren(string $data, SplQueue $queue, array $seen): void
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
            $childId = ObjectId::fromBinary($hash);

            if (! isset($seen[$childId->hash])) {
                $queue->enqueue($childId);
            }

            $offset = $nullPos + 21;
        }
    }

    /**
     * @param list<ObjectId> $ids
     */
    private function buildPackFile(array $ids): string
    {
        $packDir = $this->repository->gitDir . '/objects/pack';
        if (! is_dir($packDir)) {
            mkdir($packDir, 0o777, true);
        }

        $packPath = $packDir . '/tmp-push-' . getmypid() . '.pack';
        $serializer = new StreamingPackSerializer();
        $serializer->serialize($this->yieldRawObjects($ids), count($ids), $packPath);

        return $packPath;
    }

    /**
     * @param list<ObjectId> $ids
     * @return Generator<int, RawObject>
     */
    private function yieldRawObjects(array $ids): Generator
    {
        foreach ($ids as $id) {
            yield $this->repository->objects->readRaw($id);
        }
    }

    private function buildRefUpdateLines(string $oldHash, string $newHash, string $refName): string
    {
        $line = $oldHash . ' ' . $newHash . ' ' . $refName . "\0 report-status\n";

        return PktLine::encode($line);
    }

    private function parseResponseStatus(string $response, string $refName): string
    {
        if (str_contains($response, 'ok ' . $refName)) {
            return 'ok ' . $refName;
        }

        if (str_contains($response, 'ng ' . $refName)) {
            $pos = strpos($response, 'ng ' . $refName);
            if ($pos !== false) {
                return trim(substr($response, $pos));
            }
        }

        return 'ok ' . $refName;
    }

    private function cleanupPackFile(string $packPath): void
    {
        if (file_exists($packPath)) {
            @unlink($packPath);
        }

        $idxPath = substr($packPath, 0, -5) . '.idx';
        if (file_exists($idxPath)) {
            @unlink($idxPath);
        }
    }
}

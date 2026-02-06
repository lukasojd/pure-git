<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Infrastructure\Config\GitConfigReader;
use Lukasojd\PureGit\Infrastructure\Object\CombinedObjectStorage;
use Lukasojd\PureGit\Infrastructure\Transport\StreamingPackReceiver;
use Lukasojd\PureGit\Infrastructure\Transport\TransportFactory;
use Lukasojd\PureGit\Infrastructure\Transport\TransportInterface;

final readonly class FetchHandler
{
    public function __construct(
        private Repository $repository
    ) {
    }

    public function fetch(string $remoteName = 'origin'): FetchResult
    {
        $config = new GitConfigReader($this->repository->gitDir . '/config');
        $url = $config->get('remote "' . $remoteName . '"', 'url');
        if ($url === null) {
            throw new PureGitException(sprintf("fatal: '%s' does not appear to be a git repository", $remoteName));
        }

        $refspec = $config->get('remote "' . $remoteName . '"', 'fetch')
            ?? '+refs/heads/*:refs/remotes/' . $remoteName . '/*';

        $transport = TransportFactory::create($url);
        $remoteRefs = $transport->listRefs();

        $wants = $this->computeWants($remoteRefs);
        $haves = $this->computeHaves($remoteName);

        if ($wants === []) {
            return new FetchResult(newObjects: 0, updatedRefs: 0, upToDate: true, remoteUrl: $url);
        }

        $newObjects = $this->fetchAndInstall($transport, $wants, $haves);
        $refUpdates = $this->updateTrackingRefs($remoteRefs, $refspec);

        return new FetchResult(
            newObjects: $newObjects,
            updatedRefs: count($refUpdates),
            upToDate: false,
            remoteUrl: $url,
            refUpdates: $refUpdates,
        );
    }

    /**
     * @return list<FetchResult>
     */
    public function fetchAll(): array
    {
        $config = new GitConfigReader($this->repository->gitDir . '/config');
        $remotes = $config->listRemotes();
        if ($remotes === []) {
            throw new PureGitException('No remotes configured');
        }

        $results = [];
        foreach ($remotes as $remote) {
            $results[] = $this->fetch($remote);
        }

        return $results;
    }

    /**
     * @param array<string, ObjectId> $remoteRefs
     * @return list<ObjectId>
     */
    private function computeWants(array $remoteRefs): array
    {
        $seen = [];
        $wants = [];

        foreach ($remoteRefs as $name => $id) {
            if ($name === 'HEAD') {
                continue;
            }
            if (isset($seen[$id->hash])) {
                continue;
            }
            if ($this->repository->objects->exists($id)) {
                continue;
            }

            $seen[$id->hash] = true;
            $wants[] = $id;
        }

        return $wants;
    }

    /**
     * @return list<ObjectId>
     */
    private function computeHaves(string $remoteName): array
    {
        $trackingRefs = $this->repository->refs->listRefs('refs/remotes/' . $remoteName . '/');
        $localRefs = $this->repository->refs->listRefs('refs/heads/');
        $allRefs = array_merge($trackingRefs, $localRefs);

        $seen = [];
        $haves = [];

        foreach ($allRefs as $id) {
            if (isset($seen[$id->hash])) {
                continue;
            }
            $seen[$id->hash] = true;
            $haves[] = $id;
        }

        return $haves;
    }

    /**
     * @param list<ObjectId> $wants
     * @param list<ObjectId> $haves
     */
    private function fetchAndInstall(TransportInterface $transport, array $wants, array $haves): int
    {
        $packDir = $this->repository->gitDir . '/objects/pack';
        $tempPath = $packDir . '/tmp-' . getmypid() . '.pack';

        $packPath = $transport->fetchPack($wants, $haves, $tempPath);

        $packChecksum = $this->readPackChecksum($packPath);
        if ($packChecksum === null) {
            return 0;
        }

        $finalPackPath = $packDir . '/pack-' . $packChecksum . '.pack';
        $finalIdxPath = $packDir . '/pack-' . $packChecksum . '.idx';

        $tempIdxPath = substr($packPath, 0, -5) . '.idx';
        if (! file_exists($tempIdxPath)) {
            $this->reindexPack($packPath);
        }

        rename($packPath, $finalPackPath);

        $tempIdxPath = substr($packPath, 0, -5) . '.idx';
        if (file_exists($tempIdxPath)) {
            rename($tempIdxPath, $finalIdxPath);
        }

        if ($this->repository->objects instanceof CombinedObjectStorage) {
            $this->repository->objects->refreshPacks();
        }

        return $this->countPackObjects($finalPackPath);
    }

    /**
     * @param array<string, ObjectId> $remoteRefs
     * @return list<RefUpdate>
     */
    private function updateTrackingRefs(array $remoteRefs, string $refspec): array
    {
        $mapping = $this->parseRefspec($refspec);
        if ($mapping === null) {
            return [];
        }

        [$srcPrefix, $dstPrefix] = $mapping;
        $updates = [];

        foreach ($remoteRefs as $name => $id) {
            if ($name === 'HEAD') {
                continue;
            }

            $localRef = $this->mapRef($name, $srcPrefix, $dstPrefix);
            if ($localRef === null) {
                continue;
            }

            $oldHash = $this->resolveRefHash($localRef);
            $this->repository->refs->updateRef(RefName::fromString($localRef), $id);
            $updates[] = new RefUpdate(
                remoteName: $name,
                localName: $localRef,
                oldHash: $oldHash,
                newHash: $id->hash,
            );
        }

        return $updates;
    }

    private function resolveRefHash(string $refName): ?string
    {
        try {
            return $this->repository->refs->resolve(RefName::fromString($refName))->hash;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{string, string}|null [srcPrefix, dstPrefix]
     */
    private function parseRefspec(string $refspec): ?array
    {
        // Strip leading '+' (force flag)
        $spec = ltrim($refspec, '+');

        $colonPos = strpos($spec, ':');
        if ($colonPos === false) {
            return null;
        }

        $src = substr($spec, 0, $colonPos);
        $dst = substr($spec, $colonPos + 1);

        // Must be glob patterns like "refs/heads/*:refs/remotes/origin/*"
        if (! str_ends_with($src, '/*') || ! str_ends_with($dst, '/*')) {
            return null;
        }

        return [
            substr($src, 0, -1),  // "refs/heads/"
            substr($dst, 0, -1),  // "refs/remotes/origin/"
        ];
    }

    private function mapRef(string $refName, string $srcPrefix, string $dstPrefix): ?string
    {
        if (! str_starts_with($refName, $srcPrefix)) {
            return null;
        }

        return $dstPrefix . substr($refName, strlen($srcPrefix));
    }

    private function reindexPack(string $packPath): void
    {
        $data = file_get_contents($packPath);
        if ($data === false) {
            return;
        }

        $receiver = new StreamingPackReceiver($packPath);
        $receiver->feedPackData($data);
        $receiver->finish();
    }

    private function readPackChecksum(string $packPath): ?string
    {
        $fh = fopen($packPath, 'rb');
        if ($fh === false) {
            return null;
        }

        fseek($fh, -20, SEEK_END);
        $checksum = fread($fh, 20);
        fclose($fh);

        return $checksum !== false ? bin2hex($checksum) : null;
    }

    private function countPackObjects(string $packPath): int
    {
        $fh = fopen($packPath, 'rb');
        if ($fh === false) {
            return 0;
        }

        // Pack header: 4 bytes magic + 4 bytes version + 4 bytes object count
        $header = fread($fh, 12);
        fclose($fh);

        if ($header === false || strlen($header) < 12) {
            return 0;
        }

        /** @var array{1: int, 2: int, 3: int}|false $unpacked */
        $unpacked = unpack('N3', $header);

        return $unpacked !== false ? $unpacked[3] : 0;
    }
}

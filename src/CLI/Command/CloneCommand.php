<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Infrastructure\Transport\TransportFactory;
use Lukasojd\PureGit\Infrastructure\Transport\TransportInterface;

final class CloneCommand implements CliCommand
{
    public function name(): string
    {
        return 'clone';
    }

    public function description(): string
    {
        return 'Clone a repository into a new directory';
    }

    public function usage(): string
    {
        return 'clone [--bare] <url> [<directory>]';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        $parsed = $this->parseArgs($args);
        if ($parsed === null) {
            fwrite(STDERR, "fatal: You must specify a repository to clone.\n");

            return 128;
        }

        [$url, $targetDir, $bare] = $parsed;

        fwrite(STDERR, sprintf("Cloning into '%s'...\n", $targetDir));

        $transport = TransportFactory::create($url);

        $gitDir = $bare ? $targetDir : $targetDir . '/.git';
        $this->performClone($transport, $gitDir);

        fwrite(STDERR, "done.\n");

        return 0;
    }

    private function performClone(TransportInterface $transport, string $gitDir): void
    {
        $refs = $transport->listRefs();
        $wants = $this->collectWants($refs);

        $this->createBareStructure($gitDir);

        if ($wants !== []) {
            $this->fetchAndInstall($transport, $wants, $gitDir);
        }

        $this->writeRefs($refs, $gitDir);
        $this->writeHead($this->determineHead($refs), $gitDir);
    }

    /**
     * @param list<string> $args
     * @return array{string, string, bool}|null
     */
    private function parseArgs(array $args): ?array
    {
        $bare = false;
        $positional = [];

        foreach ($args as $arg) {
            if ($arg === '--bare') {
                $bare = true;
            } else {
                $positional[] = $arg;
            }
        }

        if ($positional === []) {
            return null;
        }

        $url = $positional[0];
        $targetDir = $positional[1] ?? $this->deriveTargetDir($url);

        return [$url, $targetDir, $bare];
    }

    private function deriveTargetDir(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path)) {
            $path = $url;
        }

        $basename = basename($path);

        // Strip .git suffix
        if (str_ends_with($basename, '.git')) {
            $basename = substr($basename, 0, -4);
        }

        return $basename !== '' ? $basename : 'repo';
    }

    /**
     * @param array<string, ObjectId> $refs
     */
    private function determineHead(array $refs): string
    {
        if (isset($refs['HEAD'])) {
            $found = $this->findBranchForHash($refs, $refs['HEAD']->hash);
            if ($found !== null) {
                return $found;
            }
        }

        return $this->defaultBranch($refs);
    }

    /**
     * @param array<string, ObjectId> $refs
     */
    private function findBranchForHash(array $refs, string $hash): ?string
    {
        foreach ($refs as $name => $id) {
            if ($name !== 'HEAD' && $id->hash === $hash && str_starts_with($name, 'refs/heads/')) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param array<string, ObjectId> $refs
     */
    private function defaultBranch(array $refs): string
    {
        if (isset($refs['refs/heads/main'])) {
            return 'refs/heads/main';
        }

        return isset($refs['refs/heads/master']) ? 'refs/heads/master' : 'refs/heads/main';
    }

    /**
     * @param array<string, ObjectId> $refs
     * @return list<ObjectId>
     */
    private function collectWants(array $refs): array
    {
        $seen = [];
        $wants = [];

        foreach ($refs as $name => $id) {
            if ($name === 'HEAD') {
                continue;
            }
            if (isset($seen[$id->hash])) {
                continue;
            }
            $seen[$id->hash] = true;
            $wants[] = $id;
        }

        return $wants;
    }

    private function createBareStructure(string $gitDir): void
    {
        $dirs = [
            $gitDir,
            $gitDir . '/objects',
            $gitDir . '/objects/pack',
            $gitDir . '/refs',
            $gitDir . '/refs/heads',
            $gitDir . '/refs/tags',
        ];

        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0o777, true);
            }
        }
    }

    /**
     * @param list<ObjectId> $wants
     */
    private function fetchAndInstall(TransportInterface $transport, array $wants, string $gitDir): void
    {
        $packDir = $gitDir . '/objects/pack';
        $tempPath = $packDir . '/tmp-' . getmypid() . '.pack';

        $packPath = $transport->fetchPack($wants, [], $tempPath);

        // Pack's embedded checksum = SHA-1 of content before 20-byte trailer
        $packChecksum = $this->readPackChecksum($packPath);
        if ($packChecksum === null) {
            return;
        }

        $finalPackPath = $packDir . '/pack-' . $packChecksum . '.pack';
        $finalIdxPath = $packDir . '/pack-' . $packChecksum . '.idx';

        rename($packPath, $finalPackPath);

        $tempIdxPath = substr($packPath, 0, -5) . '.idx';
        if (file_exists($tempIdxPath)) {
            rename($tempIdxPath, $finalIdxPath);
        }

        fwrite(STDERR, sprintf("Receiving objects: done (pack: %s)\n", basename($finalPackPath)));
    }

    /**
     * @param array<string, ObjectId> $refs
     */
    private function writeRefs(array $refs, string $gitDir): void
    {
        foreach ($refs as $name => $id) {
            if ($name === 'HEAD') {
                continue;
            }

            $refPath = $gitDir . '/' . $name;
            $refDir = dirname($refPath);

            if (! is_dir($refDir)) {
                mkdir($refDir, 0o777, true);
            }

            file_put_contents($refPath, $id->hash . "\n");
        }
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

    private function writeHead(string $headTarget, string $gitDir): void
    {
        file_put_contents($gitDir . '/HEAD', 'ref: ' . $headTarget . "\n");
    }
}

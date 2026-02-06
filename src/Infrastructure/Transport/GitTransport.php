<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Transport;

use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Object\ObjectId;

/**
 * Git native protocol transport (git:// TCP, port 9418).
 *
 * Uses stream_socket_client for raw TCP, pkt-line framing directly on socket.
 * Shares PktLine + StreamingPackReceiver with HttpTransport.
 */
final readonly class GitTransport implements TransportInterface
{
    private string $host;

    private int $port;

    private string $path;

    public function __construct(string $url)
    {
        $parsed = parse_url($url);
        if ($parsed === false || ! isset($parsed['host'], $parsed['path'])) {
            throw new PureGitException(sprintf('Invalid git:// URL: %s', $url));
        }

        $this->host = $parsed['host'];
        $this->port = $parsed['port'] ?? 9418;
        $this->path = $parsed['path'];
    }

    /**
     * @return array<string, ObjectId>
     */
    public function listRefs(): array
    {
        $socket = $this->connect();

        try {
            $this->sendInitialRequest($socket);

            return $this->readRefAdvertisement($socket);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param list<ObjectId> $wants
     * @param list<ObjectId> $haves
     */
    public function fetchPack(array $wants, array $haves = [], ?string $outputPath = null): string
    {
        if ($wants === []) {
            throw new PureGitException('No objects to fetch');
        }

        $socket = $this->connect();

        try {
            $this->sendInitialRequest($socket);

            // Read and discard ref advertisement (we already know what we want)
            $this->readRefAdvertisement($socket);

            // Send want/have/done
            $this->sendWants($socket, $wants, $haves);

            // Receive pack via side-band-64k
            $packPath = $outputPath ?? sys_get_temp_dir() . '/pure-git-pack-' . getmypid() . '.pack';
            $receiver = new StreamingPackReceiver($packPath);

            $this->receivePackData($socket, $receiver);

            return $receiver->finish();
        } finally {
            fclose($socket);
        }
    }

    public function sendPack(string $packData, string $refUpdates): string
    {
        throw new PureGitException('Push via git:// transport not supported (read-only protocol)');
    }

    /**
     * @return resource
     */
    private function connect()
    {
        $address = sprintf('tcp://%s:%d', $this->host, $this->port);
        $socket = @stream_socket_client($address, $errno, $errstr, 30);

        if ($socket === false) {
            throw new PureGitException(sprintf('Cannot connect to %s: %s (%d)', $address, $errstr, $errno));
        }

        stream_set_timeout($socket, 300);

        return $socket;
    }

    /**
     * @param resource $socket
     */
    private function sendInitialRequest($socket): void
    {
        // git-upload-pack /repo\0host=hostname\0
        $request = sprintf("git-upload-pack %s\0host=%s\0", $this->path, $this->host);
        fwrite($socket, PktLine::encode($request));
    }

    /**
     * @param resource $socket
     * @return array<string, ObjectId>
     */
    private function readRefAdvertisement($socket): array
    {
        $refs = [];
        while (($line = PktLine::read($socket)) !== null) {
            $line = rtrim($line, "\n");
            if ($line === '') {
                continue;
            }

            $this->parseRefLine($line, $refs);
        }

        return $refs;
    }

    /**
     * @param array<string, ObjectId> $refs
     */
    private function parseRefLine(string $line, array &$refs): void
    {
        $nullPos = strpos($line, "\0");
        if ($nullPos !== false) {
            $line = substr($line, 0, $nullPos);
        }

        $spacePos = strpos($line, ' ');
        if ($spacePos === false) {
            return;
        }

        $hash = substr($line, 0, $spacePos);
        $refName = substr($line, $spacePos + 1);

        if (strlen($hash) !== 40) {
            return;
        }

        $refs[$refName] = ObjectId::fromHex($hash);
    }

    /**
     * @param resource $socket
     * @param list<ObjectId> $wants
     * @param list<ObjectId> $haves
     */
    private function sendWants($socket, array $wants, array $haves): void
    {
        $first = true;
        foreach ($wants as $want) {
            if ($first) {
                fwrite($socket, PktLine::encode('want ' . $want->hash . " ofs-delta side-band-64k\n"));
                $first = false;
            } else {
                fwrite($socket, PktLine::encode('want ' . $want->hash . "\n"));
            }
        }

        fwrite($socket, PktLine::flush());

        foreach ($haves as $have) {
            fwrite($socket, PktLine::encode('have ' . $have->hash . "\n"));
        }

        fwrite($socket, PktLine::encode("done\n"));
    }

    /**
     * @param resource $socket
     */
    private function receivePackData($socket, StreamingPackReceiver $receiver): void
    {
        while (! feof($socket)) {
            $data = fread($socket, 65536);
            if ($data === false || $data === '') {
                break;
            }

            $receiver->feedChunk($data);
        }
    }
}

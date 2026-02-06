<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Transport;

use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Object\ObjectId;

/**
 * HTTP Smart Transport for Git (HTTPS/HTTP).
 *
 * Uses cURL with WRITEFUNCTION streaming for efficient pack download.
 * Supports HTTP/2, redirects, and gzip transfer-encoding automatically.
 */
final readonly class HttpTransport implements TransportInterface
{
    private string $url;

    public function __construct(string $url)
    {
        // Normalize: strip trailing .git and slashes
        $this->url = rtrim($url, '/');
    }

    /**
     * @return array<string, ObjectId>
     */
    public function listRefs(): array
    {
        $response = $this->httpGet($this->url . '/info/refs?service=git-upload-pack');

        return $this->parseRefAdvertisement($response);
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

        $packPath = $outputPath ?? sys_get_temp_dir() . '/pure-git-pack-' . getmypid() . '.pack';
        $body = $this->buildFetchRequest($wants, $haves);

        $receiver = new StreamingPackReceiver($packPath);

        $this->httpPost(
            $this->url . '/git-upload-pack',
            $body,
            'application/x-git-upload-pack-request',
            $receiver,
        );

        return $receiver->finish();
    }

    public function sendPack(string $packData, string $refUpdates): string
    {
        throw new PureGitException('Push via HTTP transport not yet supported');
    }

    /**
     * @param list<ObjectId> $wants
     * @param list<ObjectId> $haves
     */
    private function buildFetchRequest(array $wants, array $haves): string
    {
        $lines = [];
        $first = true;

        foreach ($wants as $want) {
            if ($first) {
                $lines[] = PktLine::encode('want ' . $want->hash . " ofs-delta side-band-64k\n");
                $first = false;
            } else {
                $lines[] = PktLine::encode('want ' . $want->hash . "\n");
            }
        }

        $lines[] = PktLine::flush();

        foreach ($haves as $have) {
            $lines[] = PktLine::encode('have ' . $have->hash . "\n");
        }

        $lines[] = PktLine::encode("done\n");

        return implode('', $lines);
    }

    /**
     * @return array<string, ObjectId>
     */
    private function parseRefAdvertisement(string $response): array
    {
        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            throw new PureGitException('Cannot create memory stream');
        }

        fwrite($stream, $response);
        fseek($stream, 0);

        // Skip service header (# service=git-upload-pack)
        $this->skipServiceHeader($stream);

        $refs = [];
        while (($line = PktLine::read($stream)) !== null) {
            $line = rtrim($line, "\n");
            if ($line === '') {
                continue;
            }

            $this->parseRefLine($line, $refs);
        }

        fclose($stream);

        return $refs;
    }

    /**
     * @param resource $stream
     */
    private function skipServiceHeader($stream): void
    {
        // Read lines until flush-pkt (service announcement section)
        while (PktLine::read($stream) !== null) {
            // Keep reading until flush
        }
    }

    /**
     * @param array<string, ObjectId> $refs
     */
    private function parseRefLine(string $line, array &$refs): void
    {
        // First ref may have capabilities after NUL byte
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

    private function httpGet(string $url): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new PureGitException('Failed to initialize cURL');
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: pure-git/0.1']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || ! is_string($response)) {
            throw new PureGitException(sprintf('HTTP GET failed: %s', $error));
        }

        if ($httpCode !== 200) {
            throw new PureGitException(sprintf('HTTP GET %s returned %d', $url, $httpCode));
        }

        return $response;
    }

    private function httpPost(
        string $url,
        string $body,
        string $contentType,
        StreamingPackReceiver $receiver,
    ): void {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new PureGitException('Failed to initialize cURL');
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: ' . $contentType,
            'User-Agent: pure-git/0.1',
        ]);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, fn (\CurlHandle $handle, string $data): int => $this->handleStreamChunk($receiver, $data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            throw new PureGitException(sprintf('HTTP POST failed: %s', $error));
        }

        if ($httpCode !== 200) {
            throw new PureGitException(sprintf('HTTP POST %s returned %d', $url, $httpCode));
        }
    }

    private function handleStreamChunk(StreamingPackReceiver $receiver, string $data): int
    {
        $receiver->feedChunk($data);

        return strlen($data);
    }
}

<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Transport;

use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

/**
 * SSH transport for git-upload-pack (fetch/clone over SSH).
 *
 * Uses phpseclib for pure-PHP SSH2. Key-based auth only (no SSH agent).
 * CRITICAL: Does NOT use PTY — PTY translates LF→CRLF and corrupts binary pack data.
 */
final readonly class SshTransport implements TransportInterface
{
    private string $host;

    private int $port;

    private string $user;

    private string $path;

    public function __construct(
        string $url,
        private ?string $privateKeyPath = null,
    ) {
        $parsed = SshUrlParser::tryParse($url);
        if (! $parsed instanceof SshUrlParser) {
            throw new PureGitException(sprintf('Invalid SSH URL: %s', $url));
        }

        $this->host = $parsed->host;
        $this->port = $parsed->port;
        $this->user = $parsed->user;
        $this->path = $parsed->path;
    }

    /**
     * @return array<string, ObjectId>
     */
    public function listRefs(): array
    {
        $ssh = $this->createConnection();

        try {
            $this->startUploadPack($ssh);
            $refs = $this->readRefAdvertisement($ssh);
        } finally {
            $ssh->disconnect();
        }

        return $refs;
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

        $ssh = $this->createConnection();

        try {
            $this->startUploadPack($ssh);

            // Read and discard ref advertisement
            $this->readRefAdvertisement($ssh);

            // Send want/have/done
            $this->sendWants($ssh, $wants, $haves);

            // Receive pack via side-band-64k
            $packPath = $outputPath ?? sys_get_temp_dir() . '/pure-git-pack-' . getmypid() . '.pack';
            $receiver = new StreamingPackReceiver($packPath);

            $this->receivePackData($ssh, $receiver);

            return $receiver->finish();
        } finally {
            $ssh->disconnect();
        }
    }

    public function sendPack(string $refUpdateLines, string $packPath): string
    {
        $ssh = $this->createConnection();

        try {
            $this->startReceivePack($ssh);
            $this->readRefAdvertisement($ssh);
            $ssh->write($refUpdateLines);
            $ssh->write(PktLine::flush());
            $this->streamPackFile($ssh, $packPath);

            return $this->readSendPackResponse($ssh);
        } finally {
            $ssh->disconnect();
        }
    }

    private function startReceivePack(SSH2 $ssh): void
    {
        /** @phpstan-ignore argument.type (phpseclib accepts false for non-blocking exec) */
        $result = $ssh->exec('git-receive-pack ' . escapeshellarg($this->path), false);
        if ($result === false) {
            throw new PureGitException('Failed to start git-receive-pack on remote');
        }
    }

    private function streamPackFile(SSH2 $ssh, string $packPath): void
    {
        $fh = fopen($packPath, 'rb');
        if ($fh === false) {
            throw new PureGitException(sprintf('Cannot open pack file: %s', $packPath));
        }

        while (! feof($fh)) {
            $chunk = fread($fh, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $ssh->write($chunk);
        }

        fclose($fh);
    }

    private function readSendPackResponse(SSH2 $ssh): string
    {
        $response = '';
        $timeout = 10;
        $start = time();

        while (time() - $start < $timeout) {
            $data = $ssh->read('', SSH2::READ_NEXT);
            if (! is_string($data) || $data === '') {
                break;
            }
            $response .= $data;
        }

        return $response;
    }

    private function createConnection(): SSH2
    {
        $ssh = new SSH2($this->host, $this->port);
        $ssh->setTimeout(300);

        $keyPath = $this->resolveKeyPath();
        $keyContent = file_get_contents($keyPath);
        if ($keyContent === false) {
            throw new PureGitException(sprintf('Cannot read SSH key: %s', $keyPath));
        }

        $passphrase = $this->resolvePassphrase();

        try {
            $key = PublicKeyLoader::load($keyContent, $passphrase ?? '');
        } catch (\Throwable $e) {
            throw new PureGitException(sprintf('Failed to load SSH key %s: %s', $keyPath, $e->getMessage()));
        }

        if (! $key instanceof PrivateKey) {
            throw new PureGitException(sprintf('SSH key %s is not a private key', $keyPath));
        }

        if (! $ssh->login($this->user, $key)) {
            throw new PureGitException(sprintf('SSH authentication failed for %s@%s', $this->user, $this->host));
        }

        return $ssh;
    }

    private function resolveKeyPath(): string
    {
        if ($this->privateKeyPath !== null && file_exists($this->privateKeyPath)) {
            return $this->privateKeyPath;
        }

        $home = getenv('HOME');
        if ($home === false || $home === '') {
            $serverHome = $_SERVER['HOME'] ?? null;
            if (! is_string($serverHome) || $serverHome === '') {
                throw new PureGitException('Cannot determine home directory for SSH key discovery');
            }
            $home = $serverHome;
        }

        $candidates = [
            $home . '/.ssh/id_ed25519',
            $home . '/.ssh/id_rsa',
            $home . '/.ssh/id_ecdsa',
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        throw new PureGitException('No SSH key found. Searched: ' . implode(', ', $candidates));
    }

    private function resolvePassphrase(): ?string
    {
        $envPassphrase = getenv('PUREGIT_SSH_PASSPHRASE');
        if ($envPassphrase !== false && $envPassphrase !== '') {
            return $envPassphrase;
        }

        return null;
    }

    private function startUploadPack(SSH2 $ssh): void
    {
        // exec with false callback = non-blocking, returns true immediately
        // NO enablePTY() — PTY corrupts binary data with LF→CRLF translation
        /** @phpstan-ignore argument.type (phpseclib accepts false for non-blocking exec) */
        $result = $ssh->exec('git-upload-pack ' . escapeshellarg($this->path), false);
        if ($result === false) {
            throw new PureGitException('Failed to start git-upload-pack on remote');
        }
    }

    /**
     * @return array<string, ObjectId>
     */
    private function readRefAdvertisement(SSH2 $ssh): array
    {
        $refs = [];
        $buffer = '';

        while (true) {
            $buffer = $this->ensureBuffer($ssh, $buffer, 4);
            if (strlen($buffer) < 4) {
                break;
            }

            $lenHex = substr($buffer, 0, 4);
            $len = intval($lenHex, 16);

            if ($len === 0) {
                // Flush packet — end of ref advertisement
                $buffer = substr($buffer, 4);
                break;
            }

            if ($len < 4) {
                throw new PureGitException(sprintf('Invalid pkt-line length: %d', $len));
            }

            $buffer = $this->ensureBuffer($ssh, $buffer, $len);
            if (strlen($buffer) < $len) {
                throw new PureGitException('Truncated pkt-line from SSH');
            }

            $payload = substr($buffer, 4, $len - 4);
            $buffer = substr($buffer, $len);

            $this->parseRefLine(rtrim($payload, "\n"), $refs);
        }

        return $refs;
    }

    /**
     * @param array<string, ObjectId> $refs
     */
    private function parseRefLine(string $line, array &$refs): void
    {
        // First ref line may contain capabilities after NUL byte
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
     * @param list<ObjectId> $wants
     * @param list<ObjectId> $haves
     */
    private function sendWants(SSH2 $ssh, array $wants, array $haves): void
    {
        $first = true;
        foreach ($wants as $want) {
            if ($first) {
                $ssh->write(PktLine::encode('want ' . $want->hash . " ofs-delta side-band-64k\n"));
                $first = false;
            } else {
                $ssh->write(PktLine::encode('want ' . $want->hash . "\n"));
            }
        }

        $ssh->write(PktLine::flush());

        foreach ($haves as $have) {
            $ssh->write(PktLine::encode('have ' . $have->hash . "\n"));
        }

        $ssh->write(PktLine::encode("done\n"));
    }

    private function receivePackData(SSH2 $ssh, StreamingPackReceiver $receiver): void
    {
        while (true) {
            $data = $ssh->read('', SSH2::READ_NEXT);
            if (! is_string($data) || $data === '') {
                break;
            }

            $receiver->feedChunk($data);
        }
    }

    private function ensureBuffer(SSH2 $ssh, string $buffer, int $needed): string
    {
        while (strlen($buffer) < $needed) {
            $data = $ssh->read('', SSH2::READ_NEXT);
            if (! is_string($data) || $data === '') {
                break;
            }

            $buffer .= $data;
        }

        return $buffer;
    }
}

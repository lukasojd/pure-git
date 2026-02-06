<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Transport;

use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Infrastructure\Ref\FileRefStorage;

final readonly class LocalPackInstaller
{
    public function __construct(
        private string $remoteGitDir,
    ) {
    }

    public function installAndApply(string $refUpdateLines, string $packPath): string
    {
        $refStorage = new FileRefStorage($this->remoteGitDir);

        $this->installPackFile($packPath);
        $results = $this->applyRefUpdates($refUpdateLines, $refStorage);

        return implode("\n", $results);
    }

    private function installPackFile(string $packPath): void
    {
        if (! file_exists($packPath)) {
            return;
        }

        $packDir = $this->remoteGitDir . '/objects/pack';
        if (! is_dir($packDir)) {
            mkdir($packDir, 0o777, true);
        }

        $checksum = $this->readEmbeddedChecksum($packPath);
        if ($checksum === null) {
            return;
        }

        $destPack = $packDir . '/pack-' . $checksum . '.pack';
        copy($packPath, $destPack);

        $idxPath = substr($packPath, 0, -5) . '.idx';
        if (file_exists($idxPath)) {
            $destIdx = $packDir . '/pack-' . $checksum . '.idx';
            copy($idxPath, $destIdx);
        }
    }

    private function readEmbeddedChecksum(string $packPath): ?string
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

    /**
     * @return list<string>
     */
    private function applyRefUpdates(string $refUpdateLines, FileRefStorage $refStorage): array
    {
        $results = [];
        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            return ['ng unknown error'];
        }

        fwrite($stream, $refUpdateLines);
        fseek($stream, 0);

        while (! feof($stream)) {
            $lenHex = fread($stream, 4);
            if ($lenHex === false || strlen($lenHex) < 4) {
                break;
            }

            $len = intval($lenHex, 16);
            if ($len <= 4) {
                break;
            }

            $payload = fread($stream, $len - 4);
            if ($payload === false) {
                break;
            }

            $line = rtrim($payload, "\n");
            if ($line === '') {
                continue;
            }

            $results[] = $this->applyOneRefUpdate($line, $refStorage);
        }

        fclose($stream);

        return $results;
    }

    private function applyOneRefUpdate(string $line, FileRefStorage $refStorage): string
    {
        $nullPos = strpos($line, "\0");
        if ($nullPos !== false) {
            $line = substr($line, 0, $nullPos);
        }

        $parts = explode(' ', $line, 3);
        if (count($parts) < 3) {
            return 'ng invalid ref-update line';
        }

        [, $newHash, $refName] = $parts;
        $refStorage->updateRef(RefName::fromString($refName), ObjectId::fromHex($newHash));

        return 'ok ' . $refName;
    }
}

<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Transport;

use Lukasojd\PureGit\Domain\Object\ObjectId;

interface TransportInterface
{
    /**
     * @return array<string, ObjectId> remote refs
     */
    public function listRefs(): array;

    /**
     * Fetch objects into a packfile written to the given path.
     *
     * @param list<ObjectId> $wants
     * @param list<ObjectId> $haves
     * @return string path to the generated packfile
     */
    public function fetchPack(array $wants, array $haves = [], ?string $outputPath = null): string;

    /**
     * @return string packfile data to send
     */
    public function sendPack(string $packData, string $refUpdates): string;
}

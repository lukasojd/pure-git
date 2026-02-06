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
     * @param list<ObjectId> $wants
     * @param list<ObjectId> $haves
     * @return string packfile data
     */
    public function fetchPack(array $wants, array $haves = []): string;

    /**
     * @return string packfile data to send
     */
    public function sendPack(string $packData, string $refUpdates): string;
}

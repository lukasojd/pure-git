<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Object;

use Lukasojd\PureGit\Domain\Object\ObjectId;

/**
 * Delta reuse information from an existing pack.
 *
 * Contains the uncompressed delta data and the base object's identity,
 * allowing a pack writer to reuse the delta without running DeltaEncoder.
 */
final readonly class DeltaReuseInfo
{
    public function __construct(
        public ObjectId $baseId,
        public string $deltaData,
    ) {
    }
}

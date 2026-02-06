<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Domain\Object\FileMode;
use Lukasojd\PureGit\Domain\Object\ObjectId;

final class TreeNode
{
    /**
     * @var array<string, TreeNode>
     */
    public array $children = [];

    public function __construct(
        public readonly bool $isFile,
        public readonly ?FileMode $mode = null,
        public readonly ?ObjectId $objectId = null,
    ) {
    }

    public static function file(FileMode $mode, ObjectId $objectId): self
    {
        return new self(true, $mode, $objectId);
    }

    public static function directory(): self
    {
        return new self(false);
    }
}

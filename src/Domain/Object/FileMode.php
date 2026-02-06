<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Object;

enum FileMode: int
{
    case Regular = 0o100644;
    case Executable = 0o100755;
    case SymbolicLink = 0o120000;
    case Directory = 0o040000;
    case Submodule = 0o160000;

    public function toOctal(): string
    {
        return decoct($this->value);
    }

    public static function fromOctal(string $octal): self
    {
        $value = intval($octal, 8);

        return self::from($value);
    }
}

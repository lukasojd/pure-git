<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Diff;

enum DiffLineType: string
{
    case Context = ' ';
    case Added = '+';
    case Removed = '-';
}

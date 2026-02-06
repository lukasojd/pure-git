<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Diff;

enum FileStatus: string
{
    case Added = 'A';
    case Modified = 'M';
    case Deleted = 'D';
    case Renamed = 'R';
    case Untracked = '?';
    case Unchanged = ' ';
}

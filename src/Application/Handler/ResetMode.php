<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

enum ResetMode: string
{
    case Soft = 'soft';
    case Mixed = 'mixed';
    case Hard = 'hard';
}

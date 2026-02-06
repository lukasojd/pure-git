<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

enum ConfigScope
{
    case Local;
    case Global;
}

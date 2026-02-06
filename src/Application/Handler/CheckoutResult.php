<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

enum CheckoutResult
{
    case SwitchedToBranch;
    case AlreadyOnBranch;
    case DetachedHead;
    case CreatedAndSwitched;
}

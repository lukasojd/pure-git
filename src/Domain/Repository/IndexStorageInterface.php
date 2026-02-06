<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Repository;

use Lukasojd\PureGit\Domain\Index\Index;

interface IndexStorageInterface
{
    public function read(): Index;

    public function write(Index $index): void;
}

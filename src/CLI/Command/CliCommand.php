<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

interface CliCommand
{
    public function name(): string;

    public function description(): string;

    public function usage(): string;

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int;
}

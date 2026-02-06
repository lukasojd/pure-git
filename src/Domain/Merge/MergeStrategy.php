<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Merge;

interface MergeStrategy
{
    /**
     * @param list<string> $baseLines
     * @param list<string> $oursLines
     * @param list<string> $theirsLines
     */
    public function merge(array $baseLines, array $oursLines, array $theirsLines): MergeResult;
}

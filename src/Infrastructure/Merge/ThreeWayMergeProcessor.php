<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Merge;

use Lukasojd\PureGit\Domain\Merge\MergeResult;

final readonly class ThreeWayMergeProcessor
{
    /**
     * @param array<int, array{count: int, new: string}> $oursChanges
     * @param array<int, array{count: int, new: string}> $theirsChanges
     * @param list<string> $baseLines
     * @param list<string> $oursLines
     * @param list<string> $theirsLines
     */
    public function __construct(
        private array $oursChanges,
        private array $theirsChanges,
        private array $baseLines,
        private array $oursLines,
        private array $theirsLines,
    ) {
    }

    public function process(): MergeResult
    {
        $state = $this->mergeAllRegions();
        $result = $this->appendRemainingOursLines($state['result'], $state['oursIdx']);
        $content = implode("\n", $result);

        return $state['hasConflict']
            ? MergeResult::conflicted($content, ['content'])
            : MergeResult::clean($content);
    }

    /**
     * @return array{result: list<string>, hasConflict: bool, oursIdx: int}
     */
    private function mergeAllRegions(): array
    {
        $result = [];
        $hasConflict = false;
        $baseLen = count($this->baseLines);
        $oursIdx = 0;
        $theirsIdx = 0;
        $baseIdx = 0;

        while ($baseIdx < $baseLen || $oursIdx < count($this->oursLines) || $theirsIdx < count($this->theirsLines)) {
            $step = $this->processRegion($baseIdx, $baseLen, $result);
            if ($step === null) {
                break;
            }

            $result = $step['result'];
            $hasConflict = $hasConflict || $step['conflict'];
            $baseIdx += $step['baseAdvance'];
            $oursIdx += $step['oursAdvance'];
            $theirsIdx += $step['theirsAdvance'];
        }

        return [
            'result' => $result,
            'hasConflict' => $hasConflict,
            'oursIdx' => $oursIdx,
        ];
    }

    /**
     * @param list<string> $result
     * @return array{conflict: bool, result: list<string>, baseAdvance: int, oursAdvance: int, theirsAdvance: int}|null
     */
    private function processRegion(int $baseIdx, int $baseLen, array $result): ?array
    {
        $oursChanged = isset($this->oursChanges[$baseIdx]);
        $theirsChanged = isset($this->theirsChanges[$baseIdx]);

        if ($oursChanged && $theirsChanged) {
            return $this->processBothChanged($baseIdx, $result);
        }

        if ($oursChanged) {
            return $this->processSingleSideChanged($this->oursChanges[$baseIdx], $result, true);
        }

        if ($theirsChanged) {
            return $this->processSingleSideChanged($this->theirsChanges[$baseIdx], $result, false);
        }

        if ($baseIdx < $baseLen) {
            $result[] = $this->baseLines[$baseIdx];

            return [
                'conflict' => false,
                'result' => $result,
                'baseAdvance' => 1,
                'oursAdvance' => 1,
                'theirsAdvance' => 1,
            ];
        }

        return null;
    }

    /**
     * @param list<string> $result
     * @return array{conflict: bool, result: list<string>, baseAdvance: int, oursAdvance: int, theirsAdvance: int}
     */
    private function processBothChanged(int $baseIdx, array $result): array
    {
        $oursBlock = $this->oursChanges[$baseIdx];
        $theirsBlock = $this->theirsChanges[$baseIdx];
        $conflict = false;

        if ($oursBlock['new'] === $theirsBlock['new']) {
            $result = $this->appendBlockLines($result, $oursBlock['new']);
        } else {
            $conflict = true;
            $result = $this->appendConflictMarkers($result, $oursBlock['new'], $theirsBlock['new']);
        }

        return [
            'conflict' => $conflict,
            'result' => $result,
            'baseAdvance' => max($oursBlock['count'], $theirsBlock['count']),
            'oursAdvance' => $this->countBlockLines($oursBlock['new']),
            'theirsAdvance' => $this->countBlockLines($theirsBlock['new']),
        ];
    }

    /**
     * @param array{count: int, new: string} $block
     * @param list<string> $result
     * @return array{conflict: bool, result: list<string>, baseAdvance: int, oursAdvance: int, theirsAdvance: int}
     */
    private function processSingleSideChanged(array $block, array $result, bool $isOurs): array
    {
        $result = $this->appendBlockLines($result, $block['new']);
        $lineCount = $this->countBlockLines($block['new']);

        return [
            'conflict' => false,
            'result' => $result,
            'baseAdvance' => $block['count'],
            'oursAdvance' => $isOurs ? $lineCount : $block['count'],
            'theirsAdvance' => $isOurs ? $block['count'] : $lineCount,
        ];
    }

    /**
     * @param list<string> $result
     * @return list<string>
     */
    private function appendBlockLines(array $result, string $blockContent): array
    {
        if ($blockContent === '') {
            return $result;
        }

        foreach (explode("\n", $blockContent) as $line) {
            $result[] = $line;
        }

        return $result;
    }

    /**
     * @param list<string> $result
     * @return list<string>
     */
    private function appendConflictMarkers(array $result, string $oursContent, string $theirsContent): array
    {
        $result[] = '<<<<<<< ours';
        $result = $this->appendBlockLines($result, $oursContent);
        $result[] = '=======';
        $result = $this->appendBlockLines($result, $theirsContent);
        $result[] = '>>>>>>> theirs';

        return $result;
    }

    /**
     * @param list<string> $result
     * @return list<string>
     */
    private function appendRemainingOursLines(array $result, int $oursIdx): array
    {
        while ($oursIdx < count($this->oursLines)) {
            $result[] = $this->oursLines[$oursIdx];
            $oursIdx++;
        }

        return $result;
    }

    private function countBlockLines(string $blockContent): int
    {
        if ($blockContent === '') {
            return 0;
        }

        return count(explode("\n", $blockContent));
    }
}

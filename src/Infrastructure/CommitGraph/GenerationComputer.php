<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\CommitGraph;

use SplQueue;

/**
 * Kahn's algorithm for topological generation numbers.
 */
final class GenerationComputer
{
    /**
     * @param array<string, array{parents: list<string>, timestamp: int}> $commits
     * @param array<string, int> $oidToIndex
     * @return array<string, int>
     */
    public function compute(array $commits, array $oidToIndex): array
    {
        [$childMap, $inDegree] = $this->buildDependencyGraph($commits, $oidToIndex);

        return $this->propagate($childMap, $inDegree);
    }

    /**
     * @param array<string, array{parents: list<string>, timestamp: int}> $commits
     * @param array<string, int> $oidToIndex
     * @return array{array<string, list<string>>, array<string, int>}
     */
    private function buildDependencyGraph(array $commits, array $oidToIndex): array
    {
        $childMap = [];
        $inDegree = [];

        foreach ($commits as $hex => $data) {
            $childMap[$hex] ??= [];
            $inDegree[$hex] = 0;

            foreach ($data['parents'] as $parentHex) {
                if (! isset($oidToIndex[$parentHex])) {
                    continue;
                }

                $childMap[$parentHex] ??= [];
                $childMap[$parentHex][] = $hex;
                $inDegree[$hex]++;
            }
        }

        return [$childMap, $inDegree];
    }

    /**
     * @param array<string, list<string>> $childMap
     * @param array<string, int> $inDegree
     * @return array<string, int>
     */
    private function propagate(array $childMap, array $inDegree): array
    {
        $generations = [];
        /** @var SplQueue<string> $queue */
        $queue = new SplQueue();

        foreach ($inDegree as $hex => $degree) {
            if ($degree === 0) {
                $queue->enqueue($hex);
                $generations[$hex] = 1;
            }
        }

        while (! $queue->isEmpty()) {
            $hex = $queue->dequeue();
            $this->propagateToChildren($childMap[$hex] ?? [], $generations[$hex], $generations, $inDegree, $queue);
        }

        return $generations;
    }

    /**
     * @param list<string> $children
     * @param array<string, int> $generations
     * @param array<string, int> $inDegree
     * @param SplQueue<string> $queue
     */
    private function propagateToChildren(
        array $children,
        int $gen,
        array &$generations,
        array &$inDegree,
        SplQueue $queue,
    ): void {
        foreach ($children as $childHex) {
            $generations[$childHex] = max($generations[$childHex] ?? 0, $gen + 1);
            $inDegree[$childHex]--;

            if ($inDegree[$childHex] === 0) {
                $queue->enqueue($childHex);
            }
        }
    }
}

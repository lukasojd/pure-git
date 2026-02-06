<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\CommitGraph;

use Lukasojd\PureGit\Domain\Object\ObjectType;
use Lukasojd\PureGit\Domain\Repository\RawObject;

final class CommitDataExtractor
{
    /**
     * Extract only parent hashes and committer timestamp from a raw commit object.
     *
     * Exploits fixed commit header layout: "tree <40hex>\n" is always first (46 bytes),
     * parent lines are contiguous, then a single strpos finds committer.
     *
     * @return array{parents: list<string>, timestamp: int}|null null if not a commit
     */
    public function extract(RawObject $raw): ?array
    {
        if ($raw->type !== ObjectType::Commit) {
            return null;
        }

        $data = $raw->data;

        // Skip "tree <40hex>\n" (always first, always 46 bytes)
        $pos = 46;
        $parents = [];

        // Parent lines are contiguous: "parent <40hex>\n" = 48 bytes each
        while (isset($data[$pos]) && $data[$pos] === 'p') {
            $parents[] = substr($data, $pos + 7, 40);
            $pos += 48;
        }

        // Find committer line directly (skip author + any other headers)
        $committerPos = strpos($data, "\ncommitter ", $pos);
        $timestamp = 0;
        if ($committerPos !== false) {
            $lineStart = $committerPos + 1;
            $nl = strpos($data, "\n", $lineStart);
            $lineEnd = $nl !== false ? $nl : strlen($data);
            $timestamp = $this->extractTimestampFromRange($data, $lineStart, $lineEnd);
        }

        return [
            'parents' => $parents,
            'timestamp' => $timestamp,
        ];
    }

    /**
     * Extract the target object hex from a raw tag object.
     */
    public function extractTagTarget(RawObject $raw): ?string
    {
        if ($raw->type !== ObjectType::Tag) {
            return null;
        }

        // First line is always "object <40hex>\n"
        if (str_starts_with($raw->data, 'object ')) {
            return substr($raw->data, 7, 40);
        }

        return null;
    }

    private function extractTimestampFromRange(string $data, int $lineStart, int $lineEnd): int
    {
        // "committer Name <email> <timestamp> <tz>"
        // Find '>' within the line, then parse timestamp after "> "
        $closeBracket = strpos($data, '>', $lineStart);
        if ($closeBracket === false || $closeBracket >= $lineEnd) {
            return 0;
        }

        $tsStart = $closeBracket + 2;
        $spacePos = strpos($data, ' ', $tsStart);
        if ($spacePos === false || $spacePos > $lineEnd) {
            return (int) substr($data, $tsStart, $lineEnd - $tsStart);
        }

        return (int) substr($data, $tsStart, $spacePos - $tsStart);
    }
}

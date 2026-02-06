<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Infrastructure\CommitGraph;

use Lukasojd\PureGit\Domain\Object\ObjectType;
use Lukasojd\PureGit\Domain\Repository\RawObject;
use Lukasojd\PureGit\Infrastructure\CommitGraph\CommitDataExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CommitDataExtractorTest extends TestCase
{
    private CommitDataExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new CommitDataExtractor();
    }

    #[Test]
    public function extractsRootCommit(): void
    {
        $data = 'tree ' . str_repeat('a0', 20) . "\n"
            . "author Test User <test@example.com> 1700000000 +0000\n"
            . "committer Test User <test@example.com> 1700001000 +0100\n"
            . "\n"
            . "Initial commit\n";

        $raw = new RawObject(type: ObjectType::Commit, size: strlen($data), data: $data);
        $result = $this->extractor->extract($raw);

        $this->assertNotNull($result);
        $this->assertSame([], $result['parents']);
        $this->assertSame(1700001000, $result['timestamp']);
    }

    #[Test]
    public function extractsSingleParentCommit(): void
    {
        $parentHex = str_repeat('b1', 20);
        $data = 'tree ' . str_repeat('a0', 20) . "\n"
            . "parent {$parentHex}\n"
            . "author Test User <test@example.com> 1700000000 +0000\n"
            . "committer Test User <test@example.com> 1700002000 -0500\n"
            . "\n"
            . "Second commit\n";

        $raw = new RawObject(type: ObjectType::Commit, size: strlen($data), data: $data);
        $result = $this->extractor->extract($raw);

        $this->assertNotNull($result);
        $this->assertSame([$parentHex], $result['parents']);
        $this->assertSame(1700002000, $result['timestamp']);
    }

    #[Test]
    public function extractsMergeCommit(): void
    {
        $parent1 = str_repeat('b1', 20);
        $parent2 = str_repeat('c2', 20);
        $data = 'tree ' . str_repeat('a0', 20) . "\n"
            . "parent {$parent1}\n"
            . "parent {$parent2}\n"
            . "author Test User <test@example.com> 1700000000 +0000\n"
            . "committer Test User <test@example.com> 1700003000 +0000\n"
            . "\n"
            . "Merge branch\n";

        $raw = new RawObject(type: ObjectType::Commit, size: strlen($data), data: $data);
        $result = $this->extractor->extract($raw);

        $this->assertNotNull($result);
        $this->assertSame([$parent1, $parent2], $result['parents']);
        $this->assertSame(1700003000, $result['timestamp']);
    }

    #[Test]
    public function extractsOctopusMerge(): void
    {
        $parent1 = str_repeat('b1', 20);
        $parent2 = str_repeat('c2', 20);
        $parent3 = str_repeat('d3', 20);
        $data = 'tree ' . str_repeat('a0', 20) . "\n"
            . "parent {$parent1}\n"
            . "parent {$parent2}\n"
            . "parent {$parent3}\n"
            . "author Test User <test@example.com> 1700000000 +0000\n"
            . "committer Test User <test@example.com> 1700004000 +0000\n"
            . "\n"
            . "Octopus merge\n";

        $raw = new RawObject(type: ObjectType::Commit, size: strlen($data), data: $data);
        $result = $this->extractor->extract($raw);

        $this->assertNotNull($result);
        $this->assertSame([$parent1, $parent2, $parent3], $result['parents']);
        $this->assertSame(1700004000, $result['timestamp']);
    }

    #[Test]
    public function returnsNullForBlob(): void
    {
        $raw = new RawObject(type: ObjectType::Blob, size: 5, data: 'hello');
        $this->assertNull($this->extractor->extract($raw));
    }

    #[Test]
    public function returnsNullForTree(): void
    {
        $raw = new RawObject(type: ObjectType::Tree, size: 0, data: '');
        $this->assertNull($this->extractor->extract($raw));
    }

    #[Test]
    public function returnsNullForTag(): void
    {
        $data = 'object ' . str_repeat('ab', 20) . "\ntype commit\ntag v1.0\n\nmessage\n";
        $raw = new RawObject(type: ObjectType::Tag, size: strlen($data), data: $data);
        $this->assertNull($this->extractor->extract($raw));
    }

    #[Test]
    public function extractTagTargetFromTag(): void
    {
        $targetHex = str_repeat('ab', 20);
        $data = "object {$targetHex}\ntype commit\ntag v1.0\ntagger Test <t@t.com> 1000 +0000\n\nmessage\n";
        $raw = new RawObject(type: ObjectType::Tag, size: strlen($data), data: $data);

        $this->assertSame($targetHex, $this->extractor->extractTagTarget($raw));
    }

    #[Test]
    public function extractTagTargetReturnsNullForCommit(): void
    {
        $data = 'tree ' . str_repeat('a0', 20) . "\n\ncommit\n";
        $raw = new RawObject(type: ObjectType::Commit, size: strlen($data), data: $data);

        $this->assertNull($this->extractor->extractTagTarget($raw));
    }

    #[Test]
    public function handlesCommitterWithSpecialCharacters(): void
    {
        $data = 'tree ' . str_repeat('a0', 20) . "\n"
            . "author O'Brien <ob@test.com> 1700000000 +0000\n"
            . "committer José García <jose@example.com> 1700005000 +0200\n"
            . "\n"
            . "commit message\n";

        $raw = new RawObject(type: ObjectType::Commit, size: strlen($data), data: $data);
        $result = $this->extractor->extract($raw);

        $this->assertNotNull($result);
        $this->assertSame(1700005000, $result['timestamp']);
    }
}

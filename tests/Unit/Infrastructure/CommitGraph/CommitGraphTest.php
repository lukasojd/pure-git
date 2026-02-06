<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Infrastructure\CommitGraph;

use Lukasojd\PureGit\Domain\Exception\InvalidObjectException;
use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\ObjectType;
use Lukasojd\PureGit\Domain\Object\PersonInfo;
use Lukasojd\PureGit\Domain\Repository\ObjectStorageInterface;
use Lukasojd\PureGit\Domain\Repository\RawObject;
use Lukasojd\PureGit\Domain\Repository\RefStorageInterface;
use Lukasojd\PureGit\Infrastructure\CommitGraph\CommitGraphReader;
use Lukasojd\PureGit\Infrastructure\CommitGraph\CommitGraphWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CommitGraphTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pure-git-graph-test-' . getmypid();
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0o777, true);
        }
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function roundtripLinearChain(): void
    {
        $treeId = $this->fakeTreeId();
        $person = $this->fakePerson(1000);

        $root = new Commit($treeId, [], $person, $person, 'root');
        $child = new Commit($treeId, [$root->getId()], $person, $this->fakePerson(1001), 'child');
        $grandchild = new Commit($treeId, [$child->getId()], $person, $this->fakePerson(1002), 'grandchild');

        $commits = [$root, $child, $grandchild];
        $graphPath = $this->writeGraph($commits, $grandchild->getId());

        $reader = new CommitGraphReader($graphPath);

        $this->assertSame(3, $reader->getCommitCount());

        foreach ($commits as $commit) {
            $this->assertTrue($reader->hasCommit($commit->getId()));
        }

        // Root: no parents, generation 1
        $this->assertSame([], $this->parentHashes($reader, $root->getId()));
        $this->assertSame(1, $reader->getGeneration($root->getId()));
        $this->assertSame(1000, $reader->getTimestamp($root->getId()));

        // Child: one parent, generation 2
        $this->assertSame(
            [$root->getId()->hash],
            $this->parentHashes($reader, $child->getId()),
        );
        $this->assertSame(2, $reader->getGeneration($child->getId()));

        // Grandchild: one parent, generation 3
        $this->assertSame(
            [$child->getId()->hash],
            $this->parentHashes($reader, $grandchild->getId()),
        );
        $this->assertSame(3, $reader->getGeneration($grandchild->getId()));
    }

    #[Test]
    public function roundtripDiamondMerge(): void
    {
        $treeId = $this->fakeTreeId();
        $person = $this->fakePerson(1000);

        $root = new Commit($treeId, [], $person, $person, 'root');
        $left = new Commit($treeId, [$root->getId()], $person, $this->fakePerson(1001), 'left');
        $right = new Commit($treeId, [$root->getId()], $person, $this->fakePerson(1002), 'right');
        $merge = new Commit($treeId, [$left->getId(), $right->getId()], $person, $this->fakePerson(1003), 'merge');

        $graphPath = $this->writeGraph([$root, $left, $right, $merge], $merge->getId());
        $reader = new CommitGraphReader($graphPath);

        $this->assertSame(4, $reader->getCommitCount());

        // Merge has two parents
        $mergeParents = $this->parentHashes($reader, $merge->getId());
        $this->assertCount(2, $mergeParents);
        $this->assertContains($left->getId()->hash, $mergeParents);
        $this->assertContains($right->getId()->hash, $mergeParents);

        // Generation: root=1, left=right=2, merge=3
        $this->assertSame(1, $reader->getGeneration($root->getId()));
        $this->assertSame(2, $reader->getGeneration($left->getId()));
        $this->assertSame(2, $reader->getGeneration($right->getId()));
        $this->assertSame(3, $reader->getGeneration($merge->getId()));
    }

    #[Test]
    public function roundtripOctopusMerge(): void
    {
        $treeId = $this->fakeTreeId();
        $person = $this->fakePerson(1000);

        $root = new Commit($treeId, [], $person, $person, 'root');
        $a = new Commit($treeId, [$root->getId()], $person, $this->fakePerson(1001), 'branch-a');
        $b = new Commit($treeId, [$root->getId()], $person, $this->fakePerson(1002), 'branch-b');
        $c = new Commit($treeId, [$root->getId()], $person, $this->fakePerson(1003), 'branch-c');
        $octopus = new Commit(
            $treeId,
            [$a->getId(), $b->getId(), $c->getId()],
            $person,
            $this->fakePerson(1004),
            'octopus merge',
        );

        $graphPath = $this->writeGraph([$root, $a, $b, $c, $octopus], $octopus->getId());
        $reader = new CommitGraphReader($graphPath);

        $this->assertSame(5, $reader->getCommitCount());

        // Octopus has 3 parents (uses extra parents chunk)
        $octopusParents = $this->parentHashes($reader, $octopus->getId());
        $this->assertCount(3, $octopusParents);
        $this->assertContains($a->getId()->hash, $octopusParents);
        $this->assertContains($b->getId()->hash, $octopusParents);
        $this->assertContains($c->getId()->hash, $octopusParents);

        // Generation: root=1, a=b=c=2, octopus=3
        $this->assertSame(3, $reader->getGeneration($octopus->getId()));
    }

    #[Test]
    public function singleRootCommit(): void
    {
        $treeId = $this->fakeTreeId();
        $person = $this->fakePerson(1500);

        $root = new Commit($treeId, [], $person, $person, 'only commit');
        $graphPath = $this->writeGraph([$root], $root->getId());
        $reader = new CommitGraphReader($graphPath);

        $this->assertSame(1, $reader->getCommitCount());
        $this->assertTrue($reader->hasCommit($root->getId()));
        $this->assertSame([], $this->parentHashes($reader, $root->getId()));
        $this->assertSame(1, $reader->getGeneration($root->getId()));
        $this->assertSame(1500, $reader->getTimestamp($root->getId()));
    }

    #[Test]
    public function missingCommitReturnsFalse(): void
    {
        $treeId = $this->fakeTreeId();
        $person = $this->fakePerson(1000);

        $root = new Commit($treeId, [], $person, $person, 'root');
        $graphPath = $this->writeGraph([$root], $root->getId());
        $reader = new CommitGraphReader($graphPath);

        $missing = ObjectId::fromHex(str_repeat('ab', 20));
        $this->assertFalse($reader->hasCommit($missing));
    }

    #[Test]
    public function missingCommitThrowsOnGetParents(): void
    {
        $treeId = $this->fakeTreeId();
        $person = $this->fakePerson(1000);

        $root = new Commit($treeId, [], $person, $person, 'root');
        $graphPath = $this->writeGraph([$root], $root->getId());
        $reader = new CommitGraphReader($graphPath);

        $missing = ObjectId::fromHex(str_repeat('ab', 20));
        $this->expectException(InvalidObjectException::class);
        $reader->getParents($missing);
    }

    #[Test]
    public function missingCommitThrowsOnGetGeneration(): void
    {
        $treeId = $this->fakeTreeId();
        $person = $this->fakePerson(1000);

        $root = new Commit($treeId, [], $person, $person, 'root');
        $graphPath = $this->writeGraph([$root], $root->getId());
        $reader = new CommitGraphReader($graphPath);

        $missing = ObjectId::fromHex(str_repeat('ab', 20));
        $this->expectException(InvalidObjectException::class);
        $reader->getGeneration($missing);
    }

    #[Test]
    public function corruptMagicThrows(): void
    {
        // Build a file with wrong magic but valid checksum
        $content = 'XXXX' . str_repeat("\0", 1036);
        $checksum = sha1($content, true);
        $path = $this->tmpDir . '/bad-magic.graph';
        file_put_contents($path, $content . $checksum);

        $reader = new CommitGraphReader($path);
        $this->expectException(InvalidObjectException::class);
        $this->expectExceptionMessage('Invalid commit-graph magic');
        $reader->getCommitCount();
    }

    #[Test]
    public function truncatedFileThrows(): void
    {
        $path = $this->tmpDir . '/truncated.graph';
        file_put_contents($path, 'PCGR');

        $reader = new CommitGraphReader($path);
        $this->expectException(InvalidObjectException::class);
        $this->expectExceptionMessage('too short');
        $reader->getCommitCount();
    }

    #[Test]
    public function badChecksumThrows(): void
    {
        $treeId = $this->fakeTreeId();
        $person = $this->fakePerson(1000);

        $root = new Commit($treeId, [], $person, $person, 'root');
        $graphPath = $this->writeGraph([$root], $root->getId());

        // Corrupt a byte in the middle
        $data = file_get_contents($graphPath);
        $this->assertNotFalse($data);
        $data[50] = $data[50] === "\xff" ? "\x00" : "\xff";
        file_put_contents($graphPath, $data);

        $reader = new CommitGraphReader($graphPath);
        $this->expectException(InvalidObjectException::class);
        $this->expectExceptionMessage('checksum mismatch');
        $reader->getCommitCount();
    }

    #[Test]
    public function emptyGraphWritesNothing(): void
    {
        $objects = $this->createStub(ObjectStorageInterface::class);
        $refs = $this->createStub(RefStorageInterface::class);
        $refs->method('listRefs')->willReturn([]);
        $refs->method('resolve')->willThrowException(new \RuntimeException('No HEAD'));

        $graphPath = $this->tmpDir . '/empty.graph';
        $writer = new CommitGraphWriter();
        $count = $writer->write($objects, $refs, $graphPath);

        $this->assertSame(0, $count);
        $this->assertFileDoesNotExist($graphPath);
    }

    #[Test]
    public function timestampsArePreserved(): void
    {
        $treeId = $this->fakeTreeId();

        $t1 = 1700000000;
        $t2 = 1700001000;
        $t3 = 1700002000;

        $root = new Commit($treeId, [], $this->fakePerson($t1), $this->fakePerson($t1), 'root');
        $child = new Commit($treeId, [$root->getId()], $this->fakePerson($t2), $this->fakePerson($t2), 'child');
        $grandchild = new Commit($treeId, [$child->getId()], $this->fakePerson($t3), $this->fakePerson($t3), 'gc');

        $graphPath = $this->writeGraph([$root, $child, $grandchild], $grandchild->getId());
        $reader = new CommitGraphReader($graphPath);

        $this->assertSame($t1, $reader->getTimestamp($root->getId()));
        $this->assertSame($t2, $reader->getTimestamp($child->getId()));
        $this->assertSame($t3, $reader->getTimestamp($grandchild->getId()));
    }

    /**
     * @param list<Commit> $commits
     */
    private function writeGraph(array $commits, ObjectId $headId): string
    {
        $objectMap = [];
        foreach ($commits as $commit) {
            $objectMap[$commit->getId()->hash] = $commit;
        }

        $objects = $this->createStub(ObjectStorageInterface::class);
        $readRawCallback = function (ObjectId $id) use ($objectMap): RawObject {
            if (isset($objectMap[$id->hash])) {
                $commit = $objectMap[$id->hash];
                $data = $commit->serialize();

                return new RawObject(type: ObjectType::Commit, size: strlen($data), data: $data);
            }
            throw new InvalidObjectException(sprintf('Object not found: %s', $id->hash));
        };
        $readRawByBinaryCallback = function (string $binHash) use ($objectMap): RawObject {
            $hex = bin2hex($binHash);
            if (isset($objectMap[$hex])) {
                $commit = $objectMap[$hex];
                $data = $commit->serialize();

                return new RawObject(type: ObjectType::Commit, size: strlen($data), data: $data);
            }
            throw new InvalidObjectException(sprintf('Object not found: %s', $hex));
        };
        $objects->method('readRaw')->willReturnCallback($readRawCallback);
        $objects->method('readRawHeader')->willReturnCallback($readRawCallback);
        $objects->method('readRawHeaderByBinary')->willReturnCallback($readRawByBinaryCallback);

        $refs = $this->createStub(RefStorageInterface::class);
        $refs->method('listRefs')->willReturn([]);
        $refs->method('resolve')->willReturn($headId);

        $graphPath = $this->tmpDir . '/commit-graph';
        $writer = new CommitGraphWriter();
        $writer->write($objects, $refs, $graphPath);

        return $graphPath;
    }

    /**
     * @return list<string>
     */
    private function parentHashes(CommitGraphReader $reader, ObjectId $id): array
    {
        return array_map(
            static fn (ObjectId $oid): string => $oid->hash,
            $reader->getParents($id),
        );
    }

    private function fakeTreeId(): ObjectId
    {
        return ObjectId::fromHex(str_repeat('00', 20));
    }

    private function fakePerson(int $timestamp): PersonInfo
    {
        return PersonInfo::fromString(sprintf('Test User <test@example.com> %d +0000', $timestamp));
    }
}

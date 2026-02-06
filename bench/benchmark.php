<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Lukasojd\PureGit\Application\Handler\CheckoutHandler;
use Lukasojd\PureGit\Application\Handler\LogHandler;
use Lukasojd\PureGit\Application\Handler\StatusHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\Tree;
use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Infrastructure\Transport\LocalTransport;

/**
 * Performance benchmark for PureGit against a real repository.
 *
 * Usage: php bench/benchmark.php /path/to/bare/repo
 */

$repoPath = $argv[1] ?? '/private/tmp/pure-git-clone';

if (! is_dir($repoPath)) {
    fwrite(STDERR, "Repository not found: {$repoPath}\n");
    exit(1);
}

function bench(string $name, callable $fn): void
{
    gc_collect_cycles();
    $memBefore = memory_get_usage(true);
    $start = hrtime(true);

    $result = $fn();

    $elapsed = (hrtime(true) - $start) / 1_000_000;
    $memAfter = memory_get_usage(true);
    $peakMem = memory_get_peak_usage(true);
    $memDelta = ($memAfter - $memBefore) / 1024 / 1024;

    $resultStr = is_string($result) ? $result : '';
    printf(
        "%-45s %8.1f ms  | mem: %+.1f MB  | peak: %.1f MB  %s\n",
        $name,
        $elapsed,
        $memDelta,
        $peakMem / 1024 / 1024,
        $resultStr,
    );
}

echo "=== PureGit Benchmark ===\n";
echo "Repository: {$repoPath}\n";
echo "PHP: " . PHP_VERSION . "\n";
echo str_repeat('-', 90) . "\n\n";

// 1. Open repository
$repo = null;
bench('1. Open repository (bare)', function () use ($repoPath, &$repo) {
    $repo = Repository::open($repoPath);
    return '';
});

// 2. List refs
$refs = [];
bench('2. List all refs', function () use ($repo, &$refs) {
    $refStorage = $repo->refs;
    $refs = $refStorage->listRefs('refs/');
    return sprintf('(%d refs)', count($refs));
});

// 3. Resolve HEAD
$headId = null;
bench('3. Resolve HEAD', function () use ($repo, &$headId) {
    $headId = $repo->refs->resolve(RefName::head());
    return $headId->short();
});

// 4. Read HEAD commit
$headCommit = null;
bench('4. Read HEAD commit', function () use ($repo, $headId, &$headCommit) {
    $headCommit = $repo->objects->read($headId);
    assert($headCommit instanceof Commit);
    return '';
});

// 5. Read root tree
$rootTree = null;
bench('5. Read root tree', function () use ($repo, $headCommit, &$rootTree) {
    $rootTree = $repo->objects->read($headCommit->treeId);
    assert($rootTree instanceof Tree);
    return sprintf('(%d entries)', count($rootTree->entries));
});

// 6. Walk entire tree (all files)
$fileCount = 0;
bench('6. Walk entire tree (recursive)', function () use ($repo, $headCommit, &$fileCount) {
    $fileCount = 0;
    $walkTree = function (ObjectId $treeId) use ($repo, &$walkTree, &$fileCount): void {
        $tree = $repo->objects->read($treeId);
        assert($tree instanceof Tree);
        foreach ($tree->entries as $entry) {
            if ($entry->isTree()) {
                $walkTree($entry->objectId);
            } else {
                $fileCount++;
            }
        }
    };
    $walkTree($headCommit->treeId);
    return sprintf('(%d files)', $fileCount);
});

// 7. Read 100 random blobs
bench('7. Read 100 blobs from tree', function () use ($repo, $headCommit) {
    $blobs = [];
    $collectBlobs = function (ObjectId $treeId, int $limit) use ($repo, &$collectBlobs, &$blobs): void {
        if (count($blobs) >= $limit) {
            return;
        }
        $tree = $repo->objects->read($treeId);
        assert($tree instanceof Tree);
        foreach ($tree->entries as $entry) {
            if (count($blobs) >= $limit) {
                return;
            }
            if ($entry->isTree()) {
                $collectBlobs($entry->objectId, $limit);
            } else {
                $blobs[] = $entry->objectId;
            }
        }
    };
    $collectBlobs($headCommit->treeId, 100);

    $totalSize = 0;
    foreach ($blobs as $blobId) {
        $obj = $repo->objects->read($blobId);
        $totalSize += strlen($obj->serialize());
    }
    return sprintf('(%d KB total)', $totalSize / 1024);
});

// 8. Log: walk last 100 commits
bench('8. Log: walk 100 commits', function () use ($repo, $headId) {
    $seen = [];
    $queue = [$headId];
    $count = 0;

    while ($queue !== [] && $count < 100) {
        $id = array_shift($queue);
        if (isset($seen[$id->hash])) {
            continue;
        }
        $seen[$id->hash] = true;
        $count++;

        $commit = $repo->objects->read($id);
        assert($commit instanceof Commit);
        foreach ($commit->parents as $parent) {
            $queue[] = $parent;
        }
    }
    return sprintf('(%d commits)', $count);
});

// 9. Log: walk last 1000 commits
bench('9. Log: walk 1000 commits', function () use ($repo, $headId) {
    $seen = [];
    $queue = [$headId];
    $count = 0;

    while ($queue !== [] && $count < 1000) {
        $id = array_shift($queue);
        if (isset($seen[$id->hash])) {
            continue;
        }
        $seen[$id->hash] = true;
        $count++;

        $commit = $repo->objects->read($id);
        assert($commit instanceof Commit);
        foreach ($commit->parents as $parent) {
            $queue[] = $parent;
        }
    }
    return sprintf('(%d commits)', $count);
});

// 10. Read all blobs from a single tree (simulates checkout write)
bench('10. Read all blobs from HEAD tree', function () use ($repo, $headCommit) {
    $totalSize = 0;
    $count = 0;
    $walkTree = function (ObjectId $treeId) use ($repo, &$walkTree, &$totalSize, &$count): void {
        $tree = $repo->objects->read($treeId);
        assert($tree instanceof Tree);
        foreach ($tree->entries as $entry) {
            if ($entry->isTree()) {
                $walkTree($entry->objectId);
            } else {
                $blob = $repo->objects->read($entry->objectId);
                $totalSize += strlen($blob->serialize());
                $count++;
            }
        }
    };
    $walkTree($headCommit->treeId);
    return sprintf('(%d files, %d KB)', $count, $totalSize / 1024);
});

// 11. Fetch pack (shallow: last 5 commits only)
bench('11. Fetch pack (5 commits deep)', function () use ($repo, $headId) {
    $transport = new LocalTransport('/private/tmp/pure-git-clone');

    $seen = [];
    $queue = [$headId];
    $commits = [];
    while ($queue !== [] && count($commits) < 5) {
        $id = array_shift($queue);
        if (isset($seen[$id->hash])) {
            continue;
        }
        $seen[$id->hash] = true;
        $commit = $repo->objects->read($id);
        assert($commit instanceof Commit);
        $commits[] = $id;
        foreach ($commit->parents as $parent) {
            $queue[] = $parent;
        }
    }

    $haves = [];
    if (count($commits) >= 5) {
        $lastCommit = $repo->objects->read($commits[count($commits) - 1]);
        assert($lastCommit instanceof Commit);
        $haves = $lastCommit->parents;
    }

    $packData = $transport->fetchPack([$headId], $haves);
    return sprintf('(%d KB pack)', strlen($packData) / 1024);
});

// 12. Read 50 random tags
bench('12. Resolve 50 tags', function () use ($repo, $refs) {
    $tagRefs = array_filter($refs, fn ($name) => str_starts_with($name, 'refs/tags/'), ARRAY_FILTER_USE_KEY);
    $count = 0;
    foreach (array_slice($tagRefs, 0, 50, true) as $name => $id) {
        $repo->objects->read($id);
        $count++;
    }
    return sprintf('(%d tags)', $count);
});

echo "\n" . str_repeat('-', 90) . "\n";
printf("Peak memory: %.1f MB\n", memory_get_peak_usage(true) / 1024 / 1024);

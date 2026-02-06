<?php

declare(strict_types=1);

ini_set('memory_limit', '4G');
require_once __DIR__ . '/../vendor/autoload.php';

use Lukasojd\PureGit\Application\Handler\CheckoutHandler;
use Lukasojd\PureGit\Application\Handler\LogHandler;
use Lukasojd\PureGit\Application\Handler\StatusHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\ObjectType;
use Lukasojd\PureGit\Domain\Object\Tree;
use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Infrastructure\CommitGraph\CommitDataExtractor;
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

// 11. Fetch pack (shallow: last 2 commits only)
bench('11. Fetch pack (2 commits deep)', function () use ($repo, $headId) {
    $transport = new LocalTransport('/private/tmp/pure-git-clone');

    $headCommit2 = $repo->objects->read($headId);
    assert($headCommit2 instanceof Commit);

    $haves = $headCommit2->parents;

    $packPath = $transport->fetchPack([$headId], $haves);
    $size = file_exists($packPath) ? filesize($packPath) : 0;
    @unlink($packPath);
    return sprintf('(%d KB pack)', $size / 1024);
});

// 11b. Fetch pack (5 commits deep)
bench('11b. Fetch pack (5 commits deep)', function () use ($repo, $headId) {
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

    $packPath = $transport->fetchPack([$headId], $haves);
    $size = file_exists($packPath) ? filesize($packPath) : 0;
    @unlink($packPath);
    return sprintf('(%d KB pack)', $size / 1024);
});

// 11c. Count all commits (BFS) — uses readRaw + CommitDataExtractor
$bfsCommitCount = 0;
bench('11c. Count all commits (BFS)', function () use ($repo, &$bfsCommitCount) {
    $extractor = new CommitDataExtractor();
    $allRefs = $repo->refs->listRefs('refs/');
    try {
        $allRefs['HEAD'] = $repo->refs->resolve(RefName::head());
    } catch (\Throwable) {
    }

    $visited = [];
    $queue = new \SplQueue();
    foreach ($allRefs as $id) {
        if (! isset($visited[$id->hash])) {
            $visited[$id->hash] = true;
            $queue->enqueue($id->hash);
        }
    }

    $count = 0;
    while (! $queue->isEmpty()) {
        $hex = $queue->dequeue();
        $raw = $repo->objects->readRaw(ObjectId::fromTrustedHex($hex));
        if ($raw->type === ObjectType::Tag) {
            $targetHex = $extractor->extractTagTarget($raw);
            if ($targetHex !== null && ! isset($visited[$targetHex])) {
                $visited[$targetHex] = true;
                $queue->enqueue($targetHex);
            }
            continue;
        }
        $data = $extractor->extract($raw);
        if ($data === null) {
            continue;
        }
        $count++;
        foreach ($data['parents'] as $parentHex) {
            if (! isset($visited[$parentHex])) {
                $visited[$parentHex] = true;
                $queue->enqueue($parentHex);
            }
        }
    }
    $bfsCommitCount = $count;
    return sprintf('(%d commits)', $count);
});

// 11d. Write commit-graph
bench('11d. Write commit-graph', function () use ($repo) {
    $handler = new \Lukasojd\PureGit\Application\Handler\CommitGraphHandler($repo);
    $result = $handler->write();
    return sprintf('(%d commits, %d KB)', $result->commitCount, $result->fileSizeBytes / 1024);
});

// 11e. Count all commits (commit-graph)
bench('11e. Count all commits (commit-graph)', function () use ($repo, $bfsCommitCount) {
    $graphPath = $repo->gitDir . '/objects/info/commit-graph';
    if (! file_exists($graphPath)) {
        return '(no commit-graph file)';
    }
    $reader = new \Lukasojd\PureGit\Infrastructure\CommitGraph\CommitGraphReader($graphPath);
    $count = $reader->getCommitCount();
    $match = $count === $bfsCommitCount ? 'MATCH' : 'MISMATCH';
    return sprintf('(%d commits, %s vs BFS)', $count, $match);
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

// 13. Delta encoding benchmark: pack write with/without delta
echo "\n--- Delta Encoding Benchmark ---\n";

bench('13a. Create 50 similar blobs', function () use (&$testBlobs) {
    $testBlobs = [];
    $baseContent = str_repeat("This is a shared line of content that repeats across objects.\n", 100);
    for ($i = 0; $i < 50; $i++) {
        $testBlobs[] = new \Lukasojd\PureGit\Domain\Object\Blob($baseContent . sprintf("Unique variation line %d\n", $i));
    }
    return sprintf('(%d blobs, %.1f KB each)', count($testBlobs), strlen($testBlobs[0]->serialize()) / 1024);
});

$noDeltaPath = sys_get_temp_dir() . '/bench-nodelta-' . getmypid() . '.pack';
$deltaPath = sys_get_temp_dir() . '/bench-delta-' . getmypid() . '.pack';

bench('13b. Pack write (no delta)', function () use ($testBlobs, $noDeltaPath) {
    $writer = new \Lukasojd\PureGit\Infrastructure\Object\PackfileWriter();
    $config = new \Lukasojd\PureGit\Infrastructure\Object\PackWriterConfig(enableDelta: false);
    $writer->write($testBlobs, $noDeltaPath, $config);
    return sprintf('(%d KB)', filesize($noDeltaPath) / 1024);
});

bench('13c. Pack write (with delta)', function () use ($testBlobs, $deltaPath) {
    $writer = new \Lukasojd\PureGit\Infrastructure\Object\PackfileWriter();
    $config = new \Lukasojd\PureGit\Infrastructure\Object\PackWriterConfig(enableDelta: true);
    $writer->write($testBlobs, $deltaPath, $config);
    return sprintf('(%d KB)', filesize($deltaPath) / 1024);
});

$noDeltaSize = filesize($noDeltaPath);
$deltaSize = filesize($deltaPath);
if ($noDeltaSize > 0 && $deltaSize > 0) {
    $ratio = $deltaSize / $noDeltaSize * 100;
    printf("  Delta compression ratio: %.1f%% (%.1f KB -> %.1f KB, saved %.1f KB)\n",
        $ratio, $noDeltaSize / 1024, $deltaSize / 1024, ($noDeltaSize - $deltaSize) / 1024);
}

@unlink($noDeltaPath);
@unlink($deltaPath);

// --- PureGit vs Native Git ---
echo "\n--- PureGit vs Native Git ---\n";

/**
 * Time a native git command and return [elapsed_ms, stdout].
 *
 * @return array{float, string}
 */
function gitBench(string $repoPath, string $args): array
{
    // Remove our custom commit-graph so native git doesn't warn about it
    @unlink($repoPath . '/objects/info/commit-graph');

    $start = hrtime(true);
    $output = [];
    $code = 0;
    exec(sprintf('git -C %s %s 2>/dev/null', escapeshellarg($repoPath), $args), $output, $code);
    $elapsed = (hrtime(true) - $start) / 1_000_000;

    return [$elapsed, implode("\n", $output)];
}

/**
 * @param array<string, array{float, float}> $rows
 */
function comparisonTable(string $title, array $rows): void
{
    echo "\n";
    printf("  %-35s %12s %12s %12s\n", $title, 'PureGit', 'Native Git', 'Verdict');
    printf("  %s\n", str_repeat('-', 75));

    foreach ($rows as $label => [$pureMs, $gitMs]) {
        if ($gitMs > 0 && $pureMs > 0) {
            if ($pureMs <= $gitMs) {
                $verdict = sprintf('%.0fx faster', $gitMs / $pureMs);
            } else {
                $verdict = sprintf('%.1fx slower', $pureMs / $gitMs);
            }
        } else {
            $verdict = 'N/A';
        }

        printf(
            "  %-35s %10.1f ms %10.1f ms %12s\n",
            $label,
            $pureMs,
            $gitMs,
            $verdict,
        );
    }
}

$comparison = [];

// 14a. Count all commits — uses readRaw + CommitDataExtractor
$pureCountStart = hrtime(true);
$extractor14 = new CommitDataExtractor();
$allRefsCount = $repo->refs->listRefs('refs/');
try {
    $allRefsCount['HEAD'] = $repo->refs->resolve(RefName::head());
} catch (\Throwable) {
}
$visitedCount = [];
$queueCount = new \SplQueue();
foreach ($allRefsCount as $cid) {
    if (! isset($visitedCount[$cid->hash])) {
        $visitedCount[$cid->hash] = true;
        $queueCount->enqueue($cid->hash);
    }
}
$pureCount = 0;
while (! $queueCount->isEmpty()) {
    $chex = $queueCount->dequeue();
    $craw = $repo->objects->readRaw(ObjectId::fromTrustedHex($chex));
    if ($craw->type === ObjectType::Tag) {
        $ctarget = $extractor14->extractTagTarget($craw);
        if ($ctarget !== null && ! isset($visitedCount[$ctarget])) {
            $visitedCount[$ctarget] = true;
            $queueCount->enqueue($ctarget);
        }
        continue;
    }
    $cdata = $extractor14->extract($craw);
    if ($cdata === null) {
        continue;
    }
    $pureCount++;
    foreach ($cdata['parents'] as $cp) {
        if (! isset($visitedCount[$cp])) {
            $visitedCount[$cp] = true;
            $queueCount->enqueue($cp);
        }
    }
}
$pureCountMs = (hrtime(true) - $pureCountStart) / 1_000_000;

[$gitCountMs] = gitBench($repoPath, 'rev-list --count --all');
$comparison['Count all commits (BFS)'] = [$pureCountMs, $gitCountMs];

// 14b. Write commit-graph
$pureWriteStart = hrtime(true);
$handler2 = new \Lukasojd\PureGit\Application\Handler\CommitGraphHandler($repo);
$handler2->write();
$pureWriteMs = (hrtime(true) - $pureWriteStart) / 1_000_000;

// Remove our graph before git writes its own
@unlink($repoPath . '/objects/info/commit-graph');
[$gitWriteMs] = gitBench($repoPath, 'commit-graph write');
$comparison['Write commit-graph'] = [$pureWriteMs, $gitWriteMs];

// 14c. Count with commit-graph (PureGit: read from graph; Git: rev-list with graph)
// First write both graphs
@unlink($repoPath . '/objects/info/commit-graph');
$handler2->write();
$pureGraphStart = hrtime(true);
$graphReader = new \Lukasojd\PureGit\Infrastructure\CommitGraph\CommitGraphReader(
    $repoPath . '/objects/info/commit-graph',
);
$graphReader->getCommitCount();
$pureGraphMs = (hrtime(true) - $pureGraphStart) / 1_000_000;

// Remove our graph, let git write its own, then time rev-list --count
@unlink($repoPath . '/objects/info/commit-graph');
exec(sprintf('git -C %s commit-graph write 2>/dev/null', escapeshellarg($repoPath)));
[$gitGraphCountMs] = gitBench($repoPath, 'rev-list --count --all');
$comparison['Count commits (with graph)'] = [$pureGraphMs, $gitGraphCountMs];

// 14d. Log: last 100 commits
$pureLogStart = hrtime(true);
$logHandler = new \Lukasojd\PureGit\Application\Handler\LogHandler($repo);
$logHandler->handle(100);
$pureLogMs = (hrtime(true) - $pureLogStart) / 1_000_000;

[$gitLogMs] = gitBench($repoPath, 'log --oneline -100');
$comparison['Log 100 commits'] = [$pureLogMs, $gitLogMs];

// 14e. Log: last 1000 commits
$pureLog1kStart = hrtime(true);
$logHandler->handle(1000);
$pureLog1kMs = (hrtime(true) - $pureLog1kStart) / 1_000_000;

[$gitLog1kMs] = gitBench($repoPath, 'log --oneline -1000');
$comparison['Log 1000 commits'] = [$pureLog1kMs, $gitLog1kMs];

// 14f. List all refs
$pureRefsStart = hrtime(true);
$repo->refs->listRefs('refs/');
$pureRefsMs = (hrtime(true) - $pureRefsStart) / 1_000_000;

[$gitRefsMs] = gitBench($repoPath, 'for-each-ref');
$comparison['List all refs'] = [$pureRefsMs, $gitRefsMs];

// 14g. Resolve HEAD
$pureHeadStart = hrtime(true);
$repo->refs->resolve(RefName::head());
$pureHeadMs = (hrtime(true) - $pureHeadStart) / 1_000_000;

[$gitHeadMs] = gitBench($repoPath, 'rev-parse HEAD');
$comparison['Resolve HEAD'] = [$pureHeadMs, $gitHeadMs];

// 14h. Read HEAD tree (ls-tree)
$pureTreeStart = hrtime(true);
$headForTree = $repo->objects->read($repo->refs->resolve(RefName::head()));
assert($headForTree instanceof Commit);
$treeForBench = $repo->objects->read($headForTree->treeId);
assert($treeForBench instanceof Tree);
$treeFileCount = 0;
$walkForBench = function (ObjectId $tid) use ($repo, &$walkForBench, &$treeFileCount): void {
    $t = $repo->objects->read($tid);
    assert($t instanceof Tree);
    foreach ($t->entries as $e) {
        if ($e->isTree()) {
            $walkForBench($e->objectId);
        } else {
            $treeFileCount++;
        }
    }
};
$walkForBench($headForTree->treeId);
$pureTreeMs = (hrtime(true) - $pureTreeStart) / 1_000_000;

[$gitTreeMs] = gitBench($repoPath, 'ls-tree -r HEAD');
$comparison['Walk HEAD tree (recursive)'] = [$pureTreeMs, $gitTreeMs];

comparisonTable('Operation', $comparison);

// Clean up: remove git's commit-graph and restore ours
@unlink($repoPath . '/objects/info/commit-graph');
$handler2->write();

echo "\n" . str_repeat('-', 90) . "\n";
printf("Peak memory: %.1f MB\n", memory_get_peak_usage(true) / 1024 / 1024);

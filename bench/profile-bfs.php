<?php

declare(strict_types=1);

ini_set('memory_limit', '512M');
require_once __DIR__ . '/../vendor/autoload.php';

use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\ObjectType;
use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Infrastructure\CommitGraph\CommitDataExtractor;

$repoPath = $argv[1] ?? '/private/tmp/pure-git-clone';
$repo = Repository::open($repoPath);

$extractor = new CommitDataExtractor();
$allRefs = $repo->refs->listRefs('refs/');
try {
    $allRefs['HEAD'] = $repo->refs->resolve(RefName::head());
} catch (\Throwable) {
}

$visited = [];
$queue = new SplQueue();
foreach ($allRefs as $id) {
    if (!isset($visited[$id->hash])) {
        $visited[$id->hash] = true;
        $queue->enqueue($id->hash);
    }
}

$count = 0;
$start = hrtime(true);

while (!$queue->isEmpty()) {
    $hex = $queue->dequeue();
    $raw = $repo->objects->readRawHeader(ObjectId::fromTrustedHex($hex));
    if ($raw->type === ObjectType::Tag) {
        $target = $extractor->extractTagTarget($raw);
        if ($target !== null && !isset($visited[$target])) {
            $visited[$target] = true;
            $queue->enqueue($target);
        }
        continue;
    }
    $data = $extractor->extract($raw);
    if ($data === null) {
        continue;
    }
    $count++;
    foreach ($data['parents'] as $p) {
        if (!isset($visited[$p])) {
            $visited[$p] = true;
            $queue->enqueue($p);
        }
    }
}

$ms = (hrtime(true) - $start) / 1_000_000;
printf("BFS: %d commits in %.1f ms (%.1f µs/commit)\n", $count, $ms, $ms * 1000 / $count);
printf("Peak: %.1f MB\n", memory_get_peak_usage(true) / 1024 / 1024);

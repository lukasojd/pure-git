# PureGit

A fully functional Git-like VCS implementation in **pure PHP** — no external `git` binary, no `shell_exec`, no `proc_open`.

## Requirements

- PHP 8.4+
- Extensions: `ext-hash`, `ext-zlib`, `ext-json`, `ext-mbstring`, `ext-ctype`, `ext-curl`

## Installation

```bash
composer require lukasojd/pure-git
```

## CLI Usage

```bash
# Initialize a repository
bin/puregit init [<directory>]

# Add files to the index
bin/puregit add <pathspec>...
bin/puregit add .

# Commit staged changes
bin/puregit commit -m "Your commit message"

# Show status
bin/puregit status

# View commit history
bin/puregit log [-n <count>]

# Show diff
bin/puregit diff [--cached]

# Branch operations
bin/puregit branch                  # list branches
bin/puregit branch <name>           # create branch
bin/puregit branch -d <name>        # delete branch
bin/puregit branch --unset-upstream # remove upstream tracking

# Switch branches
bin/puregit checkout <branch>

# Tag operations
bin/puregit tag                     # list tags
bin/puregit tag <name>              # lightweight tag
bin/puregit tag -a <name> -m <msg>  # annotated tag
bin/puregit tag -d <name>           # delete tag

# Merge
bin/puregit merge <branch>

# Reset (supports HEAD~N, HEAD^^^, branch names, short hashes)
bin/puregit reset [--soft|--mixed|--hard] <commit>
bin/puregit reset --hard HEAD~3
bin/puregit reset --hard main^^
bin/puregit reset --hard abc1234
bin/puregit reset --hard feature~2

# Show object
bin/puregit show [<object>]

# Clone a repository (SSH, HTTP/HTTPS, or git:// protocol)
bin/puregit clone <url> [<directory>]
bin/puregit clone --bare <url> [<directory>]

# Fetch from remote
bin/puregit fetch [<remote>]

# Pull (fetch + merge or rebase)
bin/puregit pull [<remote>]
bin/puregit pull --rebase [<remote>]

# Push to remote
bin/puregit push [-u|--set-upstream] [<remote>] [<refspec>]

# Commit graph (fast commit counting)
bin/puregit commit-graph write
bin/puregit commit-graph verify

# Git config
bin/puregit config user.name                # get value (local > global)
bin/puregit config user.name "John Doe"     # set locally
bin/puregit config --global user.email x@y  # set globally
bin/puregit config --list                   # list all (merged)
bin/puregit config --unset user.name        # unset locally

# Remove / Move files
bin/puregit rm [--cached] <file>...
bin/puregit mv <source> <destination>
```

## API Usage

```php
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Application\Handler\AddHandler;
use Lukasojd\PureGit\Application\Handler\CommitHandler;
use Lukasojd\PureGit\Application\Handler\LogHandler;
use Lukasojd\PureGit\Application\Handler\FetchHandler;
use Lukasojd\PureGit\Application\Handler\PullHandler;
use Lukasojd\PureGit\Application\Handler\PushHandler;

// Initialize
$repo = Repository::init('/path/to/repo');

// Or open existing
$repo = Repository::open('/path/to/repo');

// Add files
$add = new AddHandler($repo);
$add->handle(['file.txt']);

// Commit
$commit = new CommitHandler($repo);
$commitId = $commit->handle('Initial commit');

// Log
$log = new LogHandler($repo);
$commits = $log->handle(10);

// Fetch
$fetch = new FetchHandler($repo);
$fetchResult = $fetch->fetch('origin');

// Pull (fetch + merge)
$pull = new PullHandler($repo);
$pullResult = $pull->pull('origin');

// Pull with rebase
$pullResult = $pull->pull('origin', rebase: true);

// Push
$push = new PushHandler($repo);
$pushResult = $push->push('origin');
```

## Architecture

Hexagonal architecture with clean separation:

- `src/Domain/` — Value objects, interfaces, exceptions (no dependencies)
- `src/Application/` — Command handlers, service layer
- `src/Infrastructure/` — File system, object storage, refs, index, diff, merge
- `src/CLI/` — Command-line interface
- `src/Support/` — Binary reader/writer, path utilities

See [docs/architecture.md](docs/architecture.md) for details.

## Transport Protocols

PureGit supports three remote transport protocols — all implemented in pure PHP:

| Protocol | Clone | Fetch | Push | Library |
|----------|-------|-------|------|---------|
| HTTP/HTTPS | Yes | Yes | Yes | ext-curl |
| SSH | Yes | Yes | Yes | phpseclib/phpseclib |
| git:// | Yes | Yes | No (read-only) | TCP sockets |

SSH transport uses [phpseclib](https://phpseclib.com/) for key-based authentication — no system `ssh` binary needed.

## Quality Assurance

```bash
# Run full QA pipeline
composer qa

# Individual tools
composer ecs:check     # coding standard (ECS)
composer stan          # static analysis (PHPStan level max)
composer rector:check  # refactoring check (Rector)
composer test          # PHPUnit tests
composer test:coverage # tests with coverage report
```

### Acceptance Tests

Docker-based acceptance tests verify the full clone → fetch → pull → push cycle over real SSH and HTTP transports:

```bash
# Start git server + PHP client containers
docker compose up -d --build --wait

# Run acceptance tests inside the container
docker compose exec puregit vendor/bin/phpunit --testsuite Acceptance --testdox

# Clean up
docker compose down -v
```

Acceptance tests run automatically in GitHub Actions CI after the QA pipeline passes.

## Performance

Delta encoding with sliding window (window=10, max depth=50) achieves ~77% compression ratio on similar objects. Packs use OFS_DELTA format. Transport streams packs directly to disk with a bounded FIFO cache (32 MB) for index building, keeping memory usage predictable regardless of repository size.

### Local operations

Benchmarked against PHPUnit bare repository (231 MB, 27k commits, 3576 files):

| Operation | Time | Peak Memory |
|---|---|---|
| Read HEAD commit | 25 ms | 34 MB |
| Walk 3576 files | 10 ms | 34 MB |
| Log 1000 commits | 13 ms | 34 MB |
| Read all blobs | 235 ms | 92 MB |
| Count all commits (commit-graph) | 2 ms | 36 MB |
| Count all commits (BFS) | 283 ms | 62 MB |

### Clone (HTTP transport)

| Repository | Objects | PureGit | Native git | Ratio | Peak Memory |
|---|---|---|---|---|---|
| defunkt/dotjs | 872 | 0.84 s | 0.70 s | 1.2x | < 32 MB |
| sebastianbergmann/phpunit | 232K | 25.1 s | 9.8 s | 2.6x | < 256 MB |

## Features

- **Local operations**: init, add, commit, status, log, diff, branch, tag, checkout, merge, reset, show, rm, mv, config
- **Remote operations**: clone, fetch, pull (merge & rebase), push
- **Transport**: SSH (phpseclib), HTTP/HTTPS (curl), git:// (TCP)
- **Internals**: loose objects, packfiles with delta encoding, pack index v2, commit-graph, three-way merge, rebase (cherry-pick chain)
- **Performance**: object cache (LRU), streaming pack I/O, BFS with binary hash path, commit-graph for instant history queries

## Limitations

- No submodule support
- No sparse checkout
- No shallow clone

## License

MIT License. See [LICENSE](LICENSE).

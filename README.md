# PureGit

A fully functional Git-like VCS implementation in **pure PHP** — no external `git` binary, no `shell_exec`, no `proc_open`.

## Requirements

- PHP 8.4+
- Extensions: `ext-hash`, `ext-zlib`, `ext-json`, `ext-mbstring`, `ext-ctype`, `ext-curl`

## Installation

```bash
composer require lukasojd/pure-git
```

## Core Command Reference

All core commands are implemented and produce output matching native git.

```bash
# Initialize a repository
bin/puregit init [<directory>]

# Add files to the index
bin/puregit add <pathspec>...
bin/puregit add .
bin/puregit add -u                  # update tracked files only

# Commit staged changes
bin/puregit commit -m <message>
bin/puregit commit -a -m <message>  # auto-stage tracked files
bin/puregit commit --amend          # replace last commit
bin/puregit commit --allow-empty    # allow empty commits

# Show status
bin/puregit status
bin/puregit status -s               # short format (XY path)

# View commit history
bin/puregit log [-n <count>]
bin/puregit log --oneline           # short hash + first line
bin/puregit log --all               # all refs, not just HEAD
bin/puregit log --author=<pattern>  # filter by author name/email
bin/puregit log --since=<date>      # show commits after date

# Show diff
bin/puregit diff [--cached]
bin/puregit diff --stat             # diffstat summary
bin/puregit diff --name-only        # just file paths
bin/puregit diff <commit>..<commit> # between two commits

# Branch operations
bin/puregit branch                  # list branches
bin/puregit branch -a               # list local + remote branches
bin/puregit branch <name>           # create branch
bin/puregit branch -d <name>        # delete branch
bin/puregit branch -m <old> <new>   # rename branch
bin/puregit branch --set-upstream-to=origin/main
bin/puregit branch --unset-upstream # remove upstream tracking

# Switch branches
bin/puregit checkout <branch>
bin/puregit checkout -b <name>      # create and switch
bin/puregit checkout -- <file>      # restore file from HEAD

# Tag operations
bin/puregit tag                     # list tags
bin/puregit tag <name>              # lightweight tag
bin/puregit tag -a <name> -m <msg>  # annotated tag
bin/puregit tag -d <name>           # delete tag

# Merge
bin/puregit merge <branch>

# Reset (supports HEAD~N, HEAD^^^, branch names, short hashes)
bin/puregit reset [--soft|--mixed|--hard] <commit>

# Show object
bin/puregit show [<object>]
bin/puregit show --stat             # commit with diffstat
bin/puregit show --name-only        # commit with file list only

# Clone a repository (SSH, HTTP/HTTPS, or git:// protocol)
bin/puregit clone <url> [<directory>]
bin/puregit clone --bare <url>
bin/puregit clone -b <branch> <url> # checkout specific branch

# Fetch / Pull / Push
bin/puregit fetch [<remote>]
bin/puregit pull [<remote>]
bin/puregit pull --rebase [<remote>]
bin/puregit push [-u|--set-upstream] [<remote>] [<refspec>]

# Commit graph (fast commit counting)
bin/puregit commit-graph write
bin/puregit commit-graph verify

# Git config
bin/puregit config user.name                # get value
bin/puregit config user.name "John Doe"     # set locally
bin/puregit config --global user.email x@y  # set globally
bin/puregit config --list                   # list all
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

Optimized for real-world working tree operations. PureGit's core algorithms are competitive with — and in many cases faster than — native git, with the CLI overhead dominated by PHP interpreter startup (~52 ms fixed cost).

Delta encoding with sliding window (window=10, max depth=50) achieves ~77% compression ratio on similar objects. Packs use OFS_DELTA format. Transport streams packs directly to disk with a bounded FIFO cache (32 MB) for index building, keeping memory usage predictable regardless of repository size.

### Working tree operations

Benchmarked on a real-world PHP project (1 056 files, 21 gitignore rules):

| Operation | PureGit | Native git | |
|---|---|---|---|
| `status` | 12 ms | 19 ms | 1.5x faster |
| `diff` | 6 ms | 13 ms | 2.2x faster |
| `diff --cached` | 3 ms | 12 ms | 4.6x faster |
| `log -10` | < 0.1 ms | 11 ms | > 100x faster |
| `show HEAD` | < 0.1 ms | 12 ms | > 100x faster |

PureGit times are handler-level (warm cache). Native git times are `exec()`-based (include ~12 ms process startup). CLI wall-clock adds ~52 ms PHP interpreter startup on top.

### Bare repository operations

Benchmarked against PHPUnit bare repository (231 MB, 27k commits, 3 576 files):

| Operation | PureGit | Native git | |
|---|---|---|---|
| Resolve HEAD | 0.1 ms | 12 ms | 120x faster |
| List all refs (995) | 0.2 ms | 15 ms | 65x faster |
| Log 100 commits | 3 ms | 15 ms | 4x faster |
| Walk HEAD tree (3 576 files) | 8 ms | 16 ms | 2x faster |
| Log 1 000 commits | 27 ms | 22 ms | 1.2x slower |
| Count all commits (BFS) | 289 ms | 128 ms | 2.2x slower |
| Count all commits (commit-graph) | 2 ms | 119 ms | 60x faster |

### Scale: Linux kernel (6.1 GB, 1.4M commits)

| Operation | PureGit | Native git | |
|---|---|---|---|
| Count all commits (BFS) | 27 s | 8 s | 3.4x slower |
| Count all commits (commit-graph) | 109 ms | — | — |
| Peak memory (BFS) | 2 087 MB | — | — |

### Clone (HTTP transport)

| Repository | Objects | PureGit | Native git | Ratio | Peak Memory |
|---|---|---|---|---|---|
| defunkt/dotjs | 872 | 0.84 s | 0.70 s | 1.2x | < 32 MB |
| sebastianbergmann/phpunit | 232K | 25.1 s | 9.8 s | 2.6x | < 256 MB |

## Feature Coverage

### Core Commands

All daily-use git commands with their most important flags:

| Command | Flags | Status |
|---------|-------|--------|
| `init` | `[<directory>]` | Done |
| `add` | `<pathspec>`, `.`, `-u` | Done |
| `commit` | `-m`, `-a`, `--amend`, `--allow-empty` | Done |
| `status` | `-s`/`--short` | Done |
| `log` | `-n`, `--oneline`, `--all`, `--author=`, `--since=` | Done |
| `diff` | `--cached`, `--stat`, `--name-only`, `<commit>..<commit>` | Done |
| `branch` | `-d`, `-m`, `-a`, `--set-upstream-to`, `--unset-upstream` | Done |
| `checkout` | `<branch>`, `-b`, `-- <file>` | Done |
| `tag` | `<name>`, `-a -m`, `-d` | Done |
| `merge` | `<branch>` (fast-forward + 3-way) | Done |
| `reset` | `--soft`, `--mixed`, `--hard`, `HEAD~N`, short hashes | Done |
| `show` | `--stat`, `--name-only` | Done |
| `clone` | `--bare`, `-b`/`--branch` | Done |
| `fetch` | `[<remote>]` | Done |
| `pull` | `--rebase` | Done |
| `push` | `-u`/`--set-upstream` | Done |
| `config` | `--global`, `--list`, `--unset` | Done |
| `rm` | `--cached` | Done |
| `mv` | `<source> <destination>` | Done |

### Extended / Not Yet Implemented

| Command | Description | Status |
|---------|-------------|--------|
| `stash` | Save/restore working directory | Planned |
| `rebase` | Reapply commits on another base | Planned |
| `cherry-pick` | Apply specific commits | Planned |
| `revert` | Undo a commit | Planned |
| `log --graph` | ASCII branch topology | Planned |
| `blame` | Line-by-line last modification | Planned |
| `clean` | Remove untracked files | Planned |
| `grep` | Search file contents in repo | Planned |
| `reflog` | Reference log (undo safety net) | Planned |
| `bisect` | Binary search for bugs | Planned |
| `submodule` | Nested repositories | Planned |
| `commit -a` + `--amend` empty check | Refuse empty amend without `--allow-empty` | Planned |
| `clone --depth` | Shallow clone | Planned |
| `add -p` | Interactive/patch staging | Planned |

## License

MIT License. See [LICENSE](LICENSE).

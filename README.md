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

# Switch branches
bin/puregit checkout <branch>

# Tag operations
bin/puregit tag                     # list tags
bin/puregit tag <name>              # lightweight tag
bin/puregit tag -a <name> -m <msg>  # annotated tag
bin/puregit tag -d <name>           # delete tag

# Merge
bin/puregit merge <branch>

# Reset
bin/puregit reset [--soft|--mixed|--hard] <commit>

# Show object
bin/puregit show [<object>]

# Clone a repository (HTTP/HTTPS or git:// protocol)
bin/puregit clone <url> [<directory>]
bin/puregit clone --bare <url> [<directory>]

# Commit graph (fast commit counting)
bin/puregit commit-graph write
bin/puregit commit-graph verify

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
```

## Architecture

Hexagonal architecture with clean separation:

- `src/Domain/` — Value objects, interfaces, exceptions (no dependencies)
- `src/Application/` — Command handlers, service layer
- `src/Infrastructure/` — File system, object storage, refs, index, diff, merge
- `src/CLI/` — Command-line interface
- `src/Support/` — Binary reader/writer, path utilities

See [docs/architecture.md](docs/architecture.md) for details.

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

## Limitations

- No submodule support
- No sparse checkout

## License

MIT License. See [LICENSE](LICENSE).

# PureGit

A fully functional Git-like VCS implementation in **pure PHP** — no external `git` binary, no `shell_exec`, no `proc_open`.

## Requirements

- PHP 8.4+
- Extensions: `ext-hash`, `ext-zlib`, `ext-json`, `ext-mbstring`, `ext-ctype`

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

## Limitations

- Transport layer currently supports local path cloning only
- Pack writer produces basic (non-delta) packs
- No `.gitignore` support yet
- No submodule support
- No sparse checkout

## License

MIT License. See [LICENSE](LICENSE).

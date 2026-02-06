# Contributing

## Development Setup

```bash
git clone git@github.com:lukasojd/pure-git.git
cd pure-git
composer install
```

## QA Pipeline

Before submitting a PR, make sure all checks pass:

```bash
composer qa
```

This runs:
1. `ecs check` — coding standard
2. `phpstan analyse` — static analysis (level max)
3. `rector --dry-run` — refactoring check
4. `phpunit` — test suite

## Code Style

- `declare(strict_types=1)` in every PHP file
- PSR-12 + Symplify ECS rules
- No `TODO`, `FIXME`, or stub code
- Small, focused classes with interface-first design
- Value objects for domain concepts

## Testing

- Write unit tests for domain logic
- Write integration tests for file system operations
- Every new handler needs at least one integration test
- Use temp directories, clean up in `tearDown()`

## Architecture Rules

- Domain layer has zero external dependencies
- Application layer depends only on Domain interfaces
- Infrastructure implements Domain interfaces
- CLI depends on Application handlers
- No global state, no singletons

## Commit Messages

Use conventional commit format:

```
feat: add sparse checkout support
fix: correct tree serialization for empty directories
refactor: extract delta decoder into separate class
test: add packfile reader integration tests
docs: update architecture diagram
```

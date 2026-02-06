# Changelog

## [Unreleased]

### Added
- SSH transport (phpseclib) for clone, fetch, push
- HTTP/HTTPS smart transport (ext-curl) for clone, fetch, push
- git:// transport (TCP sockets) for clone, fetch (read-only)
- Fetch command (`puregit fetch`)
- Pull command with merge and rebase modes (`puregit pull [--rebase]`)
- Push command (`puregit push`)
- Rebase handler (cherry-pick chain for `pull --rebase`)
- Git-style diffstat output for pull (file listing with colored +/- bar graph)
- Commit-graph binary format (280x speedup for commit counting)
- Delta encoding with sliding window packfile writer
- Streaming pack receiver with side-band-64k demuxing
- Reset: support short hashes (`abc1234`), branch names (`main`), any-ref relative syntax (`feature~3`, `main^^`)
- Object prefix search (`findByPrefix`) for abbreviated hash resolution
- Pack index generation when transport doesn't produce .idx (LocalTransport)
- Docker-based acceptance tests (SSH + HTTP full cycle)
- GitHub Actions CI for acceptance tests
- Unit tests for pull, reset, diff, diffstat formatter (186 tests, 578 assertions)

### Fixed
- Pull correctly fast-forwards when tracking ref is ahead after previous fetch
- ResetMode enum extracted to own file (PSR-4 autoloader fix)
- Reset command shows actual commit hash and message after reset

## [0.1.0] - 2026-02-06

### Added
- Repository core: init, object storage (blob, tree, commit, tag), content-addressed storage
- Loose objects + packfile reader with delta decoding
- Refs management (heads, tags, symbolic refs, packed-refs)
- Index (staging area) with binary v2 format support
- Working tree operations: add, rm, mv, status
- Diff engine (Myers algorithm) with working-vs-index and index-vs-HEAD modes
- History: commit, log, show
- Branch: create, list, delete, checkout/switch
- Tag: lightweight + annotated, list, delete
- Reset: soft, mixed, hard modes
- Merge: fast-forward + 3-way merge with conflict detection
- Local transport for clone/fetch
- Pack internals: reader, index reader, delta decoder, basic writer
- CLI (`bin/puregit`) with all commands and help
- PHPStan level max + strict rules
- ECS (Easy Coding Standard) configuration
- Rector configuration for PHP 8.4
- PHPUnit test suite (unit + integration + E2E)
- GitHub Actions CI pipeline
- Documentation: README, architecture, performance, testing

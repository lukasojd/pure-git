# Changelog

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

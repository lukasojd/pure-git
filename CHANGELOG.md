# Changelog

## [Unreleased]

## [1.0.0] - 2026-02-07

### Added
- SSH transport (phpseclib) for clone, fetch, push
- HTTP/HTTPS smart transport (ext-curl) for clone, fetch, push
- git:// transport (TCP sockets) for clone, fetch (read-only)
- Fetch command (`puregit fetch`)
- Pull command with merge and rebase modes (`puregit pull [--rebase]`)
- Push command (`puregit push [-u|--set-upstream]`)
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
- Config command (`puregit config`) for get/set/list/unset of local and global options
- `checkout -b` shorthand for creating and switching to a new branch
- `branch --unset-upstream` to remove upstream tracking configuration
- Colored status output (green for staged, red for unstaged/untracked)
- Git-style commit output with branch name, short hash, and summary
- Status shows "upstream is gone" when remote branch has been deleted
- Push updates local remote-tracking ref (`refs/remotes/<remote>/<branch>`) after success
- Push `-u`/`--set-upstream` flag for configuring upstream tracking
- ConfigHandler replaces duplicated config parsing in CommitHandler
- Unit tests for config, push, branch, tracking info (227 tests, 660 assertions)
- Hunk context labels in diff output — `@@ -2,3 +2,4 @@ public function handle()`
- `log --oneline` — compact one-line format (short hash + first line of message)
- `log --all` — walk all refs, not just HEAD
- `diff <commit>..<commit>` — compare two arbitrary commits
- `diff --stat` — diffstat summary (file-level insertions/deletions bar graph)
- `diff --name-only` — list only changed file paths
- `status -s`/`--short` — compact XY format (`M`, `A`, `D`, `??`)
- `show --stat` — commit with diffstat summary
- `show --name-only` — commit with file list only
- `branch -m <old> <new>` — rename branch (migrates tracking config, updates HEAD)
- `branch -a` — list local and remote-tracking branches
- `branch --set-upstream-to=<upstream>` — configure upstream tracking
- `checkout -- <file>` — restore file from HEAD (single file or multiple)
- `log --author=<pattern>` — filter commits by author name or email substring
- `log --since=<date>` — show only commits after the given date
- `commit -a` — auto-stage tracked modified/deleted files before committing
- `commit --amend` — replace the last commit (reuses parent chain)
- `commit --allow-empty` — allow creating commits with no changes
- `add -u` — update index for tracked files only (stage modifications and deletions, skip untracked)
- `clone -b`/`--branch=<name>` — checkout specific branch after clone
- Core/Extended feature tables in README

### Fixed
- Push crashes when remote has branches not fetched locally ("Object not found")
- Push output now matches native git (stderr for "Everything up-to-date", no "To" line when up-to-date)
- Push now updates local remote-tracking ref so `git status` shows correct state immediately
- Pull correctly fast-forwards when tracking ref is ahead after previous fetch
- ResetMode enum extracted to own file (PSR-4 autoloader fix)
- Reset command shows actual commit hash and message after reset
- Status blank line after tracking info to match native git output
- CommitHandler requires user identity configuration (user.name, user.email)

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

# BFS Performance Report — 2026-02-06

## Test Setup

- **PHP**: 8.4.12, macOS Darwin 25.1.0 (Apple Silicon)
- **Repos tested**:
  - PHPUnit bare clone: 231 MB, 27,311 commits
  - Linux kernel bare clone: 6.1 GB, 1,414,354 commits (52x larger)

## Final Performance

### PHPUnit repo (27K commits)

| Metric | Value |
|--------|-------|
| **BFS median** | **283ms** |
| **Ratio vs git** | **2.4x** |
| **Peak memory** | 101.8 MB |
| **Per-commit** | 10.4 us |

### Linux kernel (1.4M commits)

| Metric | Value |
|--------|-------|
| **BFS median (warm)** | **19,200ms** |
| **Native git** | **6,350ms** |
| **Ratio vs git** | **3.0x** |
| **Peak memory** | 2,087 MB |
| **Per-commit** | 13.6 us |
| **Commit-graph read** | **114ms** (vs 19.2s BFS = **168x speedup**) |

### Scaling comparison

| Repo | Commits | PureGit BFS | Native git | Ratio | Per-commit |
|------|---------|------------|-----------|-------|------------|
| **PHPUnit** | 27K | 283ms | 120ms | **2.4x** | 10.4 us |
| **Linux kernel** | 1.4M | 19,200ms | 6,350ms | **3.0x** | 13.6 us |

The ratio degrades from 2.4x to 3.0x at scale due to:
- PHP hash table overhead for 2M+ entries (hash map ~300MB for 2M objects)
- More delta objects requiring full resolve chains at scale
- Memory allocation pressure (2GB peak vs 100MB)

## Optimization Progression (PHPUnit repo)

| Version | BFS | Ratio | Change |
|---------|-----|-------|--------|
| Before optimizations | 370ms | 3.4x | baseline |
| + hash map + partial inflate | 358ms | 3.3x | -3% |
| + packs-first + direct offset map | 310ms | 2.8x | -16% |
| + binary hash paths (findOffsetByBinary) | **283ms** | **2.4x** | **-9%** |
| **Total improvement** | | | **-24%** |

## Implemented Optimizations

### 1. Hash Map Auto-Escalation (PackIndexReader)
After 500 binary search lookups, builds `binHash -> pack file offset` hash map for O(1) lookups.
Stores offsets directly (not position indices), eliminating `readOffsetAt()` overhead.

### 2. Partial Header-Only Decompression (PackfileReader)
`readRawHeader` / `tryReadObjectHeader` inflate commits/tags and truncate at `\n\n` boundary.
**Saves memory allocation but NOT CPU** — compressed data (~200B) fits in 512B buffer,
so inflate_add() decompresses everything in one call regardless.

### 3. Packs-First in readRawHeader (CombinedObjectStorage)
Check pack readers before loose storage in `readRawHeader()`. Avoids 27K wasted
`file_exists()` calls on bare clone repos. Saves ~38ms.

### 4. Binary Hash Paths (findOffsetByBinary + readRawHeaderByBinary)
Added `findOffsetByBinary(string $binHash)` and `readRawHeaderByBinary(string $binHash)`
to avoid ObjectId creation in hot paths. BFS in CommitGraphWriter uses `hex2bin()` directly
instead of `ObjectId::fromTrustedHex()`. Saves ~27ms.

### 5. Large Offset Table Support (PackIndexReader)
Pack index v2 large offset table for pack files >2GB. Required for Linux kernel (5.7GB pack).
When MSB of 4-byte offset is set, lower 31 bits index into 8-byte large offset table.

### Rejected Optimizations

| Optimization | Result | Why |
|-------------|--------|-----|
| **Block cache (64KB FIFO)** | REJECTED | Adds overhead vs kernel page cache; fseek+fread(512) is fast enough |
| **zlib_decode() / gzuncompress()** | REJECTED | Slower than inflate_init+inflate_add AND fails on ~46% of buffers (can't handle trailing data after deflate stream) |

## Per-Component Breakdown (PHPUnit, warm cache, at 310ms stage)

| Component | Time | % | Calls | Per-call |
|-----------|------|---|-------|----------|
| **findOffset (hash map)** | **77ms** | **25%** | 28K | 2.8 us |
| **inflate_add()** | **59ms** | **19%** | 28K | 2.1 us |
| **fseek + fread(512)** | **34ms** | **11%** | 28K | 1.2 us |
| CommitDataExtractor | 36ms | 12% | 27K | 1.3 us |
| ObjectId (fromHex+toBinary) | 17ms | 5% | 28K | 0.6 us |
| varint parsing | 12ms | 4% | 28K | 0.4 us |
| inflate_init() | 6ms | 2% | 28K | 0.2 us |
| queue + visited | 10ms | 3% | 27K | 0.4 us |
| hrtime + loop overhead | 51ms | 17% | - | - |

## Remaining Bottlenecks

**Theoretical floor** (only inflate_add + fseek/fread): ~95ms (PHPUnit). The remaining ~190ms is PHP
overhead (hash lookups, function calls, string operations). Getting below 2x native git
would require FFI or a PHP extension.

### Possible further optimizations (diminishing returns)

| Optimization | Expected savings | Effort |
|-------------|-----------------|--------|
| BFS-aware readahead buffer | ~10-15ms (PHPUnit) | Medium |
| PHP FFI for zlib (single uncompress()) | ~5-10ms | High |
| mmap via FFI (eliminate fseek/fread) | ~30ms | High |
| Reduce hash map memory for large repos | ~500MB less at 2M objects | Medium |

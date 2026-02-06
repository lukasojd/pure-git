# BFS Performance Report — 2026-02-06

## Test Setup

- **Repo**: PHPUnit bare clone, 231 MB, 27,311 commits
- **Path**: `/private/tmp/pure-git-clone`
- **PHP**: 8.4.12, macOS Darwin 25.1.0 (Apple Silicon)
- **Native git**: `rev-list --all --count` = **120ms** (warm cache median)

## Final Performance

| Metric | Value |
|--------|-------|
| **BFS median** | **283ms** |
| **Ratio vs git** | **2.4x** |
| **Peak memory** | 101.8 MB |
| **Per-commit** | 10.4 us |

### Full Progression

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

### Rejected Optimizations

| Optimization | Result | Why |
|-------------|--------|-----|
| **Block cache (64KB FIFO)** | REJECTED | Adds overhead vs kernel page cache; fseek+fread(512) is fast enough |
| **zlib_decode() / gzuncompress()** | REJECTED | Slower than inflate_init+inflate_add AND fails on ~46% of buffers (can't handle trailing data after deflate stream) |

## Per-Component Breakdown (warm cache, measured at 310ms stage)

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

## Object Breakdown

| Category | Count | Note |
|----------|-------|------|
| Whole commit/tag | 27,713 | Direct inflate from pack |
| -- truncated at `\n\n` | 17,944 | Partial decompression helps output size |
| -- full inflate | 9,769 | Small objects fully decompressed in 1 call |
| Delta fallback (OFS_DELTA) | 582 | Need full resolve chain |
| **Avg compressed** | **509 B** | Fits in 512B initial buffer |
| **Avg decompressed** | **435 B** | Header-only truncation |

## Remaining Bottlenecks

**Theoretical floor** (only inflate_add + fseek/fread): ~95ms. The remaining ~190ms is PHP
overhead (hash lookups, function calls, string operations). Getting below 2x native git
would require FFI or a PHP extension.

### Possible further optimizations (diminishing returns)

| Optimization | Expected savings | Effort |
|-------------|-----------------|--------|
| BFS-aware readahead buffer | ~10-15ms | Medium |
| PHP FFI for zlib (single uncompress()) | ~5-10ms | High |
| mmap via FFI (eliminate fseek/fread) | ~30ms | High |

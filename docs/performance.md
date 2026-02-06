# Performance

## Strategies

### Object Cache
`ObjectCache` provides an LRU-like in-memory cache for frequently accessed objects. Default capacity is 1024 objects with 25% eviction when full.

### Packfile I/O
Packfile reader uses streaming file handles (`fopen`/`fread`/`fseek`) rather than loading entire packs into memory. Pack index is loaded once and cached.

### Atomic Writes
All writes use the temp-file-then-rename pattern via `LockFile`:
1. Write to `{path}.lock`
2. `fflush()` to ensure data hits disk
3. `rename()` for atomic replacement

### Delta Decoding
Pack delta instructions (copy from base, insert new) are decoded in a single pass with minimal allocations.

### Index Binary Format
Index read/write uses direct binary packing (`pack()`/`unpack()`) matching Git's index v2 format — no intermediate parsing.

## Known Limitations

- LCS-based diff has O(n*m) time/space complexity — very large files may be slow
- Three-way merge uses full LCS computation (not patience or histogram)
- Pack writer does not produce delta objects (whole-object only)

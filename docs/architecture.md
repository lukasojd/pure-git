# Architecture

PureGit follows **hexagonal architecture** (ports and adapters) with strict layer separation.

## Layer Overview

```
src/
├── Domain/          # Core business logic, no external dependencies
│   ├── Object/      # Git objects: Blob, Tree, Commit, Tag, ObjectId
│   ├── Ref/         # Reference names (branches, tags, HEAD)
│   ├── Index/       # Staging area model
│   ├── Diff/        # Diff types and algorithm interface
│   ├── Merge/       # Merge result and strategy interface
│   ├── Repository/  # Storage interfaces (ports)
│   └── Exception/   # Domain exception hierarchy
├── Application/     # Use-case orchestration
│   ├── Handler/     # Command handlers (AddHandler, CommitHandler, ...)
│   └── Service/     # Repository factory and service
├── Infrastructure/  # Concrete implementations (adapters)
│   ├── Filesystem/  # Local filesystem abstraction
│   ├── Object/      # Loose + packfile object storage
│   ├── Ref/         # File-based ref storage
│   ├── Index/       # Binary index file reader/writer
│   ├── Lock/        # Atomic lock file mechanism
│   ├── Diff/        # Myers diff algorithm implementation
│   ├── Merge/       # Three-way merge implementation
│   ├── Transport/   # Local transport for clone/fetch
│   └── Cache/       # Object cache (LRU-like)
├── CLI/             # Command-line interface
│   └── Command/     # CLI command implementations
└── Support/         # Shared utilities
    ├── BinaryReader # Binary data parsing
    ├── BinaryWriter # Binary data writing
    └── PathUtils    # Path normalization and validation
```

## Design Principles

- **Interface-first**: Domain defines interfaces, Infrastructure provides implementations
- **No global state**: All state flows through constructor injection
- **Value objects**: ObjectId, RefName, FileMode are immutable value types
- **Content-addressed**: Objects are identified by SHA1 of their content
- **Atomic writes**: Lock files + temp-file-then-rename pattern prevents corruption

## Object Storage

Objects are stored in two formats:
1. **Loose objects**: `objects/{prefix}/{suffix}` — zlib-compressed with header
2. **Packfiles**: `objects/pack/*.pack` + `*.idx` — efficient storage with delta compression

`CombinedObjectStorage` checks loose objects first, then falls back to packfiles.

## Security

- Path traversal protection on all file operations
- Ref name validation (git spec compliant)
- Object ID validation (40-char hex SHA1)
- Lock files prevent concurrent writes

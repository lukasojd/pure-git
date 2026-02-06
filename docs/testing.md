# Testing

## Test Structure

```
tests/
├── Unit/                    # Isolated unit tests
│   ├── Domain/
│   │   ├── Object/          # ObjectId, Blob, Tree, Commit
│   │   ├── Ref/             # RefName validation
│   │   ├── Index/           # Index add/remove/sort
│   │   ├── Diff/            # Myers diff algorithm
│   │   └── Merge/           # Three-way merge
│   ├── Infrastructure/
│   │   └── Object/          # Delta decoder
│   └── Support/             # PathUtils
├── Integration/             # Multi-component tests
│   └── FullWorkflowTest     # init → add → commit → log → branch → checkout → tag → reset
└── E2E/                     # CLI end-to-end tests
    └── CliTest              # bin/puregit commands with temp repo
```

## Running Tests

```bash
# All tests
composer test

# With coverage report (HTML)
composer test:coverage

# Specific suite
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Integration
vendor/bin/phpunit --testsuite E2E
```

## Coverage Target

Minimum 90% line coverage for `src/Domain` and `src/Application`.

## Test Philosophy

- **Unit tests**: Test single classes in isolation with known inputs/outputs
- **Integration tests**: Test real file system operations in temp directories
- **E2E tests**: Test the CLI application as a whole, verifying exit codes and side effects
- All tests create temp directories and clean up after themselves

<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Transport;

/**
 * Parses SSH URLs in both standard and SCP-like formats:
 *
 * - ssh://[user@]host[:port]/path  (standard URL)
 * - [user@]host:path               (SCP-like, e.g. git@github.com:user/repo.git)
 */
final readonly class SshUrlParser
{
    public function __construct(
        public string $host,
        public int $port,
        public string $user,
        public string $path,
    ) {
    }

    public static function tryParse(string $url): ?self
    {
        if (str_starts_with($url, 'ssh://')) {
            return self::parseStandardUrl($url);
        }

        return self::parseScpLike($url);
    }

    public static function isSshUrl(string $url): bool
    {
        if (str_starts_with($url, 'ssh://')) {
            return true;
        }

        // SCP-like: user@host:path (must have @ before : and no /)
        return (bool) preg_match('#^[a-zA-Z0-9._-]+@[a-zA-Z0-9._-]+:#', $url);
    }

    private static function parseStandardUrl(string $url): ?self
    {
        $parsed = parse_url($url);
        if ($parsed === false || ! isset($parsed['host'], $parsed['path'])) {
            return null;
        }

        return new self(
            host: $parsed['host'],
            port: $parsed['port'] ?? 22,
            user: $parsed['user'] ?? 'git',
            path: $parsed['path'],
        );
    }

    private static function parseScpLike(string $url): ?self
    {
        // Exclude anything that looks like a scheme:// URL
        if (str_contains($url, '://')) {
            return null;
        }

        // Match: [user@]host:path
        if (preg_match('#^(?:([a-zA-Z0-9._-]+)@)?([a-zA-Z0-9._-]+):(.+)$#', $url, $matches) !== 1) {
            return null;
        }

        return new self(
            host: $matches[2],
            port: 22,
            user: $matches[1] !== '' ? $matches[1] : 'git',
            path: '/' . $matches[3],
        );
    }
}

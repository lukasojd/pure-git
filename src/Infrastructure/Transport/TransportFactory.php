<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Transport;

use Lukasojd\PureGit\Domain\Exception\PureGitException;

final class TransportFactory
{
    public static function create(string $url): TransportInterface
    {
        if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
            return new HttpTransport($url);
        }

        if (str_starts_with($url, 'git://')) {
            return new GitTransport($url);
        }

        // Assume local path
        if (is_dir($url) || is_dir($url . '/.git')) {
            return new LocalTransport($url);
        }

        throw new PureGitException(sprintf('Unsupported transport URL: %s', $url));
    }
}

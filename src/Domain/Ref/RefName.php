<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Ref;

use Lukasojd\PureGit\Domain\Exception\InvalidRefNameException;
use Stringable;

final readonly class RefName implements Stringable
{
    private function __construct(
        public string $value,
    ) {
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function fromString(string $name): self
    {
        self::validate($name);

        return new self($name);
    }

    public static function branch(string $name): self
    {
        self::validate($name);

        if (str_starts_with($name, 'refs/heads/')) {
            return new self($name);
        }

        return new self('refs/heads/' . $name);
    }

    public static function tag(string $name): self
    {
        self::validate($name);

        if (str_starts_with($name, 'refs/tags/')) {
            return new self($name);
        }

        return new self('refs/tags/' . $name);
    }

    public static function head(): self
    {
        return new self('HEAD');
    }

    public function shortName(): string
    {
        if (str_starts_with($this->value, 'refs/heads/')) {
            return substr($this->value, strlen('refs/heads/'));
        }
        if (str_starts_with($this->value, 'refs/tags/')) {
            return substr($this->value, strlen('refs/tags/'));
        }
        if (str_starts_with($this->value, 'refs/remotes/')) {
            return substr($this->value, strlen('refs/remotes/'));
        }

        return $this->value;
    }

    public function isBranch(): bool
    {
        return str_starts_with($this->value, 'refs/heads/');
    }

    public function isTag(): bool
    {
        return str_starts_with($this->value, 'refs/tags/');
    }

    public function isHead(): bool
    {
        return $this->value === 'HEAD';
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    private static function validate(string $name): void
    {
        if ($name === 'HEAD') {
            return;
        }

        if (in_array($name, ['', '.', '..'], true)) {
            throw InvalidRefNameException::withName($name);
        }

        if (preg_match('/[\x00-\x1f\x7f ~^:?*\[\\\\]/', $name) === 1) {
            throw InvalidRefNameException::withName($name);
        }

        if (str_contains($name, '..') || str_contains($name, '@{') || str_contains($name, '//')) {
            throw InvalidRefNameException::withName($name);
        }

        if (str_starts_with($name, '/') || str_ends_with($name, '/') || str_ends_with($name, '.') || str_ends_with($name, '.lock')) {
            throw InvalidRefNameException::withName($name);
        }
    }
}

<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Object;

use DateTimeImmutable;
use DateTimeZone;

final readonly class PersonInfo
{
    public function __construct(
        public string $name,
        public string $email,
        public DateTimeImmutable $timestamp,
    ) {
    }

    public static function fromString(string $raw): self
    {
        if (preg_match('/^(.+) <(.+)> (\d+) ([+-]\d{4})$/', $raw, $matches) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid person info: %s', $raw));
        }

        $timezone = new DateTimeZone($matches[4]);
        $timestamp = new DateTimeImmutable('@' . $matches[3])->setTimezone($timezone);

        return new self($matches[1], $matches[2], $timestamp);
    }

    public function toString(): string
    {
        return sprintf(
            '%s <%s> %d %s',
            $this->name,
            $this->email,
            $this->timestamp->getTimestamp(),
            $this->timestamp->format('O'),
        );
    }
}

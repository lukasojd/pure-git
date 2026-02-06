<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Object;

use Lukasojd\PureGit\Domain\Exception\InvalidObjectException;
use Stringable;

final readonly class ObjectId implements Stringable
{
    private function __construct(
        public string $hash,
    ) {
    }

    public function __toString(): string
    {
        return $this->hash;
    }

    public static function fromHex(string $hex): self
    {
        $hex = strtolower($hex);
        if (preg_match('/^[0-9a-f]{40}$/', $hex) !== 1) {
            throw InvalidObjectException::invalidHash($hex);
        }

        return new self($hex);
    }

    public static function fromBinary(string $binary): self
    {
        if (strlen($binary) !== 20) {
            throw InvalidObjectException::invalidHash(bin2hex($binary));
        }

        return new self(bin2hex($binary));
    }

    public static function hash(string $content, ObjectType $type): self
    {
        $header = $type->value . ' ' . strlen($content) . "\0";
        $hash = hash('sha1', $header . $content);

        return new self($hash);
    }

    public function toBinary(): string
    {
        $result = hex2bin($this->hash);
        if ($result === false) {
            throw new InvalidObjectException('Failed to convert hash to binary');
        }

        return $result;
    }

    public function prefix(): string
    {
        return substr($this->hash, 0, 2);
    }

    public function suffix(): string
    {
        return substr($this->hash, 2);
    }

    public function short(int $length = 7): string
    {
        return substr($this->hash, 0, $length);
    }

    public function equals(self $other): bool
    {
        return $this->hash === $other->hash;
    }
}

<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Support;

use Lukasojd\PureGit\Domain\Exception\PureGitException;

final class BinaryReader
{
    private int $offset = 0;

    private readonly int $length;

    public function __construct(
        private readonly string $data,
    ) {
        $this->length = strlen($data);
    }

    public function readBytes(int $count): string
    {
        $this->assertAvailable($count);
        $result = substr($this->data, $this->offset, $count);
        $this->offset += $count;

        return $result;
    }

    public function readUint8(): int
    {
        $bytes = $this->readBytes(1);
        /** @var array{value: int} $unpacked */
        $unpacked = unpack('Cvalue', $bytes);

        return $unpacked['value'];
    }

    public function readUint16(): int
    {
        $bytes = $this->readBytes(2);
        /** @var array{value: int} $unpacked */
        $unpacked = unpack('nvalue', $bytes);

        return $unpacked['value'];
    }

    public function readUint32(): int
    {
        $bytes = $this->readBytes(4);
        /** @var array{value: int} $unpacked */
        $unpacked = unpack('Nvalue', $bytes);

        return $unpacked['value'];
    }

    public function readNullTerminated(): string
    {
        $nullPos = strpos($this->data, "\0", $this->offset);
        if ($nullPos === false) {
            throw new PureGitException('No null terminator found');
        }

        $result = substr($this->data, $this->offset, $nullPos - $this->offset);
        $this->offset = $nullPos + 1;

        return $result;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function setOffset(int $offset): void
    {
        $this->offset = $offset;
    }

    public function isEof(): bool
    {
        return $this->offset >= $this->length;
    }

    public function remaining(): int
    {
        return $this->length - $this->offset;
    }

    public function skip(int $bytes): void
    {
        $this->offset += $bytes;
    }

    public function alignTo(int $alignment): void
    {
        $remainder = $this->offset % $alignment;
        if ($remainder !== 0) {
            $this->offset += $alignment - $remainder;
        }
    }

    private function assertAvailable(int $count): void
    {
        if ($this->offset + $count > $this->length) {
            throw new PureGitException(sprintf(
                'Not enough data: need %d bytes at offset %d, but only %d bytes available',
                $count,
                $this->offset,
                $this->length - $this->offset,
            ));
        }
    }
}

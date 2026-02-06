<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Support;

final class BinaryWriter
{
    private string $buffer = '';

    public function writeBytes(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    public function writeUint8(int $value): void
    {
        $this->buffer .= pack('C', $value);
    }

    public function writeUint16(int $value): void
    {
        $this->buffer .= pack('n', $value);
    }

    public function writeUint32(int $value): void
    {
        $this->buffer .= pack('N', $value);
    }

    public function writeNullTerminated(string $value): void
    {
        $this->buffer .= $value . "\0";
    }

    public function padTo(int $alignment): void
    {
        $remainder = strlen($this->buffer) % $alignment;
        if ($remainder !== 0) {
            $this->buffer .= str_repeat("\0", $alignment - $remainder);
        }
    }

    public function getBuffer(): string
    {
        return $this->buffer;
    }

    public function getLength(): int
    {
        return strlen($this->buffer);
    }
}

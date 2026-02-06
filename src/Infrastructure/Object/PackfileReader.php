<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Object;

use Lukasojd\PureGit\Domain\Exception\InvalidObjectException;
use Lukasojd\PureGit\Domain\Exception\ObjectNotFoundException;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\ObjectType;
use Lukasojd\PureGit\Domain\Repository\RawObject;

final class PackfileReader
{
    private const int PACK_SIGNATURE = 0x5041434B; // 'PACK'

    private const int OBJ_COMMIT = 1;

    private const int OBJ_TREE = 2;

    private const int OBJ_BLOB = 3;

    private const int OBJ_TAG = 4;

    private const int OBJ_OFS_DELTA = 6;

    private const int OBJ_REF_DELTA = 7;

    /**
     * @var resource|null
     */
    private $handle;

    public function __construct(
        private readonly string $packPath,
        private readonly PackIndexReader $indexReader,
    ) {
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            fclose($this->handle);
        }
    }

    public function readObject(ObjectId $id): RawObject
    {
        $offset = $this->indexReader->findOffset($id);
        if ($offset === null) {
            throw ObjectNotFoundException::withId($id->hash);
        }

        return $this->readAtOffset($offset);
    }

    public function hasObject(ObjectId $id): bool
    {
        return $this->indexReader->hasObject($id);
    }

    public function readAtOffset(int $offset): RawObject
    {
        $handle = $this->getHandle();
        fseek($handle, $offset);

        $byte = $this->readByte($handle);
        $type = ($byte >> 4) & 0x07;
        $size = $byte & 0x0F;
        $shift = 4;

        while (($byte & 0x80) !== 0) {
            $byte = $this->readByte($handle);
            $size |= ($byte & 0x7F) << $shift;
            $shift += 7;
        }

        return match ($type) {
            self::OBJ_COMMIT, self::OBJ_TREE, self::OBJ_BLOB, self::OBJ_TAG => $this->readWholeObject($handle, $type, $size),
            self::OBJ_OFS_DELTA => $this->readOfsDelta($handle, $offset, $size),
            self::OBJ_REF_DELTA => $this->readRefDelta($handle, $size),
            default => throw new InvalidObjectException(sprintf('Unknown pack object type: %d', $type)),
        };
    }

    /**
     * @param resource $handle
     */
    private function readWholeObject($handle, int $type, int $size): RawObject
    {
        $objectType = $this->packTypeToObjectType($type);
        $readSize = max(1, $size + 512);
        $compressed = fread($handle, $readSize);
        if ($compressed === false) {
            throw new InvalidObjectException('Failed to read pack object data');
        }

        $data = zlib_decode($compressed, $size);
        if ($data === false) {
            throw new InvalidObjectException('Failed to decompress pack object');
        }

        return new RawObject($objectType, $size, $data);
    }

    /**
     * @param resource $handle
     */
    private function readOfsDelta($handle, int $currentOffset, int $deltaSize): RawObject
    {
        $byte = $this->readByte($handle);
        $negativeOffset = $byte & 0x7F;

        while (($byte & 0x80) !== 0) {
            $byte = $this->readByte($handle);
            $negativeOffset = (($negativeOffset + 1) << 7) | ($byte & 0x7F);
        }

        $baseOffset = $currentOffset - $negativeOffset;

        $readSize = max(1, $deltaSize + 512);
        $compressed = fread($handle, $readSize);
        if ($compressed === false) {
            throw new InvalidObjectException('Failed to read delta data');
        }

        $deltaData = zlib_decode($compressed, $deltaSize);
        if ($deltaData === false) {
            throw new InvalidObjectException('Failed to decompress delta');
        }

        $baseObject = $this->readAtOffset($baseOffset);

        $result = DeltaDecoder::apply($baseObject->data, $deltaData);

        return new RawObject($baseObject->type, strlen($result), $result);
    }

    /**
     * @param resource $handle
     */
    private function readRefDelta($handle, int $deltaSize): RawObject
    {
        $baseHash = fread($handle, 20);
        if ($baseHash === false || strlen($baseHash) !== 20) {
            throw new InvalidObjectException('Failed to read ref delta base hash');
        }

        $baseId = ObjectId::fromBinary($baseHash);

        $readSize = max(1, $deltaSize + 512);
        $compressed = fread($handle, $readSize);
        if ($compressed === false) {
            throw new InvalidObjectException('Failed to read delta data');
        }

        $deltaData = zlib_decode($compressed, $deltaSize);
        if ($deltaData === false) {
            throw new InvalidObjectException('Failed to decompress delta');
        }

        $baseObject = $this->readObject($baseId);

        $result = DeltaDecoder::apply($baseObject->data, $deltaData);

        return new RawObject($baseObject->type, strlen($result), $result);
    }

    private function packTypeToObjectType(int $type): ObjectType
    {
        return match ($type) {
            self::OBJ_COMMIT => ObjectType::Commit,
            self::OBJ_TREE => ObjectType::Tree,
            self::OBJ_BLOB => ObjectType::Blob,
            self::OBJ_TAG => ObjectType::Tag,
            default => throw new InvalidObjectException(sprintf('Unknown pack type: %d', $type)),
        };
    }

    /**
     * @param resource $handle
     */
    private function readByte($handle): int
    {
        $byte = fread($handle, 1);
        if ($byte === false || $byte === '') {
            throw new InvalidObjectException('Unexpected end of pack data');
        }

        return ord($byte);
    }

    /**
     * @return resource
     */
    private function getHandle()
    {
        if ($this->handle !== null) {
            return $this->handle;
        }

        $handle = fopen($this->packPath, 'rb');
        if ($handle === false) {
            throw new InvalidObjectException(sprintf('Cannot open packfile: %s', $this->packPath));
        }

        // Verify header
        $signature = fread($handle, 4);
        if ($signature === false) {
            throw new InvalidObjectException('Cannot read pack signature');
        }
        /** @var array{value: int} $unpacked */
        $unpacked = unpack('Nvalue', $signature);
        if ($unpacked['value'] !== self::PACK_SIGNATURE) {
            throw new InvalidObjectException('Invalid pack signature');
        }

        $versionData = fread($handle, 4);
        if ($versionData === false) {
            throw new InvalidObjectException('Cannot read pack version');
        }
        /** @var array{value: int} $unpacked */
        $unpacked = unpack('Nvalue', $versionData);
        if ($unpacked['value'] !== 2) {
            throw new InvalidObjectException(sprintf('Unsupported pack version: %d', $unpacked['value']));
        }

        // Skip object count (4 bytes)
        fread($handle, 4);

        $this->handle = $handle;

        return $handle;
    }
}

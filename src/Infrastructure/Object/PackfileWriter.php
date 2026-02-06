<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Object;

use Lukasojd\PureGit\Domain\Object\GitObject;
use Lukasojd\PureGit\Domain\Object\ObjectType;
use Lukasojd\PureGit\Support\BinaryWriter;

final class PackfileWriter
{
    private const int OBJ_COMMIT = 1;

    private const int OBJ_TREE = 2;

    private const int OBJ_BLOB = 3;

    private const int OBJ_TAG = 4;

    /**
     * @param list<GitObject> $objects
     */
    public function write(array $objects, string $outputPath): void
    {
        $writer = new BinaryWriter();

        // Header: PACK
        $writer->writeBytes('PACK');
        // Version 2
        $writer->writeUint32(2);
        // Object count
        $writer->writeUint32(count($objects));

        foreach ($objects as $object) {
            $this->writeObject($writer, $object);
        }

        $data = $writer->getBuffer();
        $checksum = hash('sha1', $data, true);
        $data .= $checksum;

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        file_put_contents($outputPath, $data);
    }

    private function writeObject(BinaryWriter $writer, GitObject $object): void
    {
        $type = $this->objectTypeToPackType($object->getType());
        $data = $object->serialize();
        $size = strlen($data);

        // Variable-length header
        $byte = ($type << 4) | ($size & 0x0F);
        $size >>= 4;

        while ($size > 0) {
            $writer->writeUint8($byte | 0x80);
            $byte = $size & 0x7F;
            $size >>= 7;
        }
        $writer->writeUint8($byte);

        // Compress and write data
        $compressed = gzcompress($data);
        if ($compressed === false) {
            return;
        }

        // zlib format: strip 2-byte header and 4-byte checksum for raw deflate
        $writer->writeBytes($compressed);
    }

    private function objectTypeToPackType(ObjectType $type): int
    {
        return match ($type) {
            ObjectType::Commit => self::OBJ_COMMIT,
            ObjectType::Tree => self::OBJ_TREE,
            ObjectType::Blob => self::OBJ_BLOB,
            ObjectType::Tag => self::OBJ_TAG,
        };
    }
}

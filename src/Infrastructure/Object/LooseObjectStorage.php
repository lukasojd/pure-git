<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Object;

use Lukasojd\PureGit\Domain\Exception\InvalidObjectException;
use Lukasojd\PureGit\Domain\Exception\ObjectNotFoundException;
use Lukasojd\PureGit\Domain\Object\Blob;
use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Domain\Object\GitObject;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\ObjectType;
use Lukasojd\PureGit\Domain\Object\Tag;
use Lukasojd\PureGit\Domain\Object\Tree;
use Lukasojd\PureGit\Domain\Repository\ObjectStorageInterface;
use Lukasojd\PureGit\Domain\Repository\RawObject;

final readonly class LooseObjectStorage implements ObjectStorageInterface
{
    public function __construct(
        private string $objectsDir,
    ) {
    }

    public function read(ObjectId $id): GitObject
    {
        $raw = $this->readRaw($id);

        return self::deserialize($raw);
    }

    public function write(GitObject $object): ObjectId
    {
        $id = $object->getId();
        $path = $this->objectPath($id);

        if (file_exists($path)) {
            return $id;
        }

        $content = $object->serialize();
        $header = $object->getType()->value . ' ' . strlen($content) . "\0";
        $raw = $header . $content;

        $compressed = gzcompress($raw);
        if ($compressed === false) {
            throw new InvalidObjectException('Failed to compress object');
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $tmpPath = $path . '.tmp.' . getmypid();
        file_put_contents($tmpPath, $compressed);
        rename($tmpPath, $path);

        return $id;
    }

    public function exists(ObjectId $id): bool
    {
        return file_exists($this->objectPath($id));
    }

    public function readRawHeader(ObjectId $id): RawObject
    {
        return $this->readRaw($id);
    }

    public function readRawHeaderByBinary(string $binHash): RawObject
    {
        return $this->readRawHeader(ObjectId::fromBinary($binHash));
    }

    public function readRaw(ObjectId $id): RawObject
    {
        $path = $this->objectPath($id);

        if (! file_exists($path)) {
            throw ObjectNotFoundException::withId($id->hash);
        }

        $compressed = file_get_contents($path);
        if ($compressed === false) {
            throw ObjectNotFoundException::withId($id->hash);
        }

        $data = gzuncompress($compressed);
        if ($data === false) {
            throw InvalidObjectException::corruptObject($id->hash, 'decompression failed');
        }

        $nullPos = strpos($data, "\0");
        if ($nullPos === false) {
            throw InvalidObjectException::corruptObject($id->hash, 'missing null byte in header');
        }

        $header = substr($data, 0, $nullPos);
        $spacePos = strpos($header, ' ');
        if ($spacePos === false) {
            throw InvalidObjectException::corruptObject($id->hash, 'invalid header format');
        }

        $typeName = substr($header, 0, $spacePos);
        $size = (int) substr($header, $spacePos + 1);
        $type = ObjectType::tryFrom($typeName);

        if ($type === null) {
            throw InvalidObjectException::invalidType($typeName);
        }

        $body = substr($data, $nullPos + 1);

        return new RawObject($type, $size, $body);
    }

    public function findByPrefix(string $hexPrefix): ?ObjectId
    {
        if (strlen($hexPrefix) < 4) {
            return null;
        }

        $dir = $this->objectsDir . '/' . substr($hexPrefix, 0, 2);
        if (! is_dir($dir)) {
            return null;
        }

        $suffix = substr($hexPrefix, 2);
        $matches = [];
        $files = scandir($dir);
        if ($files === false) {
            return null;
        }

        /** @var string $file */
        foreach ($files as $file) {
            if (str_starts_with($file, $suffix)) {
                $matches[] = ObjectId::fromHex(substr($hexPrefix, 0, 2) . $file);
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    public static function deserialize(RawObject $raw): GitObject
    {
        return match ($raw->type) {
            ObjectType::Blob => Blob::fromSerialized($raw->data),
            ObjectType::Tree => Tree::fromSerialized($raw->data),
            ObjectType::Commit => Commit::fromSerialized($raw->data),
            ObjectType::Tag => Tag::fromSerialized($raw->data),
        };
    }

    private function objectPath(ObjectId $id): string
    {
        return $this->objectsDir . '/' . $id->prefix() . '/' . $id->suffix();
    }
}

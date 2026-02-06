<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Index;

use Lukasojd\PureGit\Domain\Exception\IndexException;
use Lukasojd\PureGit\Domain\Index\Index;
use Lukasojd\PureGit\Domain\Index\IndexEntry;
use Lukasojd\PureGit\Domain\Object\FileMode;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Repository\IndexStorageInterface;
use Lukasojd\PureGit\Infrastructure\Lock\LockFile;
use Lukasojd\PureGit\Support\BinaryReader;
use Lukasojd\PureGit\Support\BinaryWriter;

final readonly class IndexFileHandler implements IndexStorageInterface
{
    private const string SIGNATURE = 'DIRC';

    private const int VERSION = 2;

    public function __construct(
        private string $indexPath,
    ) {
    }

    public function read(): Index
    {
        if (! file_exists($this->indexPath)) {
            return new Index();
        }

        $data = file_get_contents($this->indexPath);
        if ($data === false) {
            throw IndexException::corruptIndex('Cannot read index file');
        }

        $reader = new BinaryReader($data);

        // Header
        $signature = $reader->readBytes(4);
        if ($signature !== self::SIGNATURE) {
            throw IndexException::corruptIndex('Invalid signature');
        }

        $version = $reader->readUint32();
        if ($version !== self::VERSION) {
            throw IndexException::corruptIndex(sprintf('Unsupported version: %d', $version));
        }

        $entryCount = $reader->readUint32();

        $entries = [];
        for ($i = 0; $i < $entryCount; $i++) {
            $entry = $this->readEntry($reader);
            $entries[$entry->path] = $entry;
        }

        return new Index($entries);
    }

    public function write(Index $index): void
    {
        $lock = new LockFile($this->indexPath);
        $lock->acquire();

        $writer = new BinaryWriter();

        // Header
        $writer->writeBytes(self::SIGNATURE);
        $writer->writeUint32(self::VERSION);
        $writer->writeUint32($index->count());

        foreach ($index->getSortedEntries() as $entry) {
            $this->writeEntry($writer, $entry);
        }

        $data = $writer->getBuffer();
        $checksum = hash('sha1', $data, true);

        $lock->write($data . $checksum);
        $lock->commit();
    }

    private function readEntry(BinaryReader $reader): IndexEntry
    {
        $startOffset = $reader->getOffset();

        $ctimeSec = $reader->readUint32();
        $ctimeNano = $reader->readUint32();
        $mtimeSec = $reader->readUint32();
        $mtimeNano = $reader->readUint32();
        $dev = $reader->readUint32();
        $ino = $reader->readUint32();
        $modeRaw = $reader->readUint32();
        $uid = $reader->readUint32();
        $gid = $reader->readUint32();
        $fileSize = $reader->readUint32();
        $objectId = ObjectId::fromBinary($reader->readBytes(20));
        $flags = $reader->readUint16();

        $nameLength = $flags & 0xFFF;
        $stage = ($flags >> 12) & 0x03;
        $path = $reader->readBytes($nameLength);

        // Null byte after name
        $reader->readBytes(1);

        // Padding to 8-byte boundary
        $entryLength = $reader->getOffset() - $startOffset;
        $padding = (8 - ($entryLength % 8)) % 8;
        if ($padding > 0) {
            $reader->skip($padding);
        }

        $mode = FileMode::tryFrom($modeRaw) ?? FileMode::Regular;

        return new IndexEntry(
            path: $path,
            objectId: $objectId,
            mode: $mode,
            ctime: $ctimeSec,
            ctimeNano: $ctimeNano,
            mtime: $mtimeSec,
            mtimeNano: $mtimeNano,
            dev: $dev,
            ino: $ino,
            uid: $uid,
            gid: $gid,
            fileSize: $fileSize,
            flags: $flags,
            stage: $stage,
        );
    }

    private function writeEntry(BinaryWriter $writer, IndexEntry $entry): void
    {
        $startLength = $writer->getLength();

        $writer->writeUint32($entry->ctime);
        $writer->writeUint32($entry->ctimeNano);
        $writer->writeUint32($entry->mtime);
        $writer->writeUint32($entry->mtimeNano);
        $writer->writeUint32($entry->dev);
        $writer->writeUint32($entry->ino);
        $writer->writeUint32($entry->mode->value);
        $writer->writeUint32($entry->uid);
        $writer->writeUint32($entry->gid);
        $writer->writeUint32($entry->fileSize);
        $writer->writeBytes($entry->objectId->toBinary());

        $nameLen = min(strlen($entry->path), 0xFFF);
        $flags = ($entry->stage << 12) | $nameLen;
        $writer->writeUint16($flags);

        $writer->writeBytes($entry->path);
        $writer->writeBytes("\0");

        // Pad to 8-byte boundary
        $entryLength = $writer->getLength() - $startLength;
        $padding = (8 - ($entryLength % 8)) % 8;
        if ($padding > 0) {
            $writer->writeBytes(str_repeat("\0", $padding));
        }
    }
}

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

        return $this->parseIndex($data);
    }

    public function write(Index $index): void
    {
        $lock = new LockFile($this->indexPath);
        $lock->acquire();

        $chunks = [];
        $chunks[] = pack('a4NN', self::SIGNATURE, self::VERSION, $index->count());

        foreach ($index->getSortedEntries() as $entry) {
            $chunks[] = $this->packEntry($entry);
        }

        $data = implode('', $chunks);
        $checksum = hash('sha1', $data, true);

        $lock->write($data . $checksum);
        $lock->commit();
    }

    private function parseIndex(string $data): Index
    {
        /** @var array{sig: string, version: int, count: int} $header */
        $header = unpack('a4sig/Nversion/Ncount', $data);

        if ($header['sig'] !== self::SIGNATURE) {
            throw IndexException::corruptIndex('Invalid signature');
        }

        if ($header['version'] !== self::VERSION) {
            throw IndexException::corruptIndex(sprintf('Unsupported version: %d', $header['version']));
        }

        $entryCount = $header['count'];
        $offset = 12;
        $entries = [];

        for ($i = 0; $i < $entryCount; $i++) {
            $entry = $this->readEntryDirect($data, $offset);
            $entries[$entry->path] = $entry;
        }

        return new Index($entries);
    }

    private function readEntryDirect(string $data, int &$offset): IndexEntry
    {
        /** @var array{ctime: int, ctimeNano: int, mtime: int, mtimeNano: int, dev: int, ino: int, mode: int, uid: int, gid: int, size: int} $fields */
        $fields = unpack('Nctime/NctimeNano/Nmtime/NmtimeNano/Ndev/Nino/Nmode/Nuid/Ngid/Nsize', $data, $offset);
        $hash = substr($data, $offset + 40, 20);

        /** @var array{flags: int} $flagsArr */
        $flagsArr = unpack('nflags', $data, $offset + 60);
        $flags = $flagsArr['flags'];

        $nameLen = $flags & 0xFFF;
        $stage = ($flags >> 12) & 0x03;
        $path = substr($data, $offset + 62, $nameLen);

        // Advance past entry: 62 fixed + nameLen + 1 null, padded to 8 bytes
        $entryLen = 63 + $nameLen;
        $offset += $entryLen + ((8 - ($entryLen % 8)) % 8);

        return new IndexEntry(
            path: $path,
            objectId: ObjectId::fromBinary($hash),
            mode: FileMode::tryFrom($fields['mode']) ?? FileMode::Regular,
            ctime: $fields['ctime'],
            ctimeNano: $fields['ctimeNano'],
            mtime: $fields['mtime'],
            mtimeNano: $fields['mtimeNano'],
            dev: $fields['dev'],
            ino: $fields['ino'],
            uid: $fields['uid'],
            gid: $fields['gid'],
            fileSize: $fields['size'],
            flags: $flags,
            stage: $stage,
        );
    }

    private function packEntry(IndexEntry $entry): string
    {
        $nameLen = min(strlen($entry->path), 0xFFF);
        $flags = ($entry->stage << 12) | $nameLen;

        $entryData = pack(
            'NNNNNNNNNN',
            $entry->ctime,
            $entry->ctimeNano,
            $entry->mtime,
            $entry->mtimeNano,
            $entry->dev,
            $entry->ino,
            $entry->mode->value,
            $entry->uid,
            $entry->gid,
            $entry->fileSize,
        ) . $entry->objectId->toBinary() . pack('n', $flags) . $entry->path . "\0";

        $padding = (8 - (strlen($entryData) % 8)) % 8;

        return $padding > 0 ? $entryData . str_repeat("\0", $padding) : $entryData;
    }
}

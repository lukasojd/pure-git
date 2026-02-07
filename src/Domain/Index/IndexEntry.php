<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Index;

use Lukasojd\PureGit\Domain\Object\FileMode;
use Lukasojd\PureGit\Domain\Object\ObjectId;

final readonly class IndexEntry
{
    public function __construct(
        public string $path,
        public ObjectId $objectId,
        public FileMode $mode,
        public int $ctime,
        public int $ctimeNano,
        public int $mtime,
        public int $mtimeNano,
        public int $dev,
        public int $ino,
        public int $uid,
        public int $gid,
        public int $fileSize,
        public int $flags,
        public int $stage,
    ) {
    }

    /**
     * @param array{dev: int, ino: int, uid: int, gid: int, size: int, mtime: int, ctime: int} $stat
     */
    public static function createFromStat(string $path, ObjectId $objectId, FileMode $mode, array $stat): self
    {
        return new self(
            path: $path,
            objectId: $objectId,
            mode: $mode,
            ctime: $stat['ctime'],
            ctimeNano: 0,
            mtime: $stat['mtime'],
            mtimeNano: 0,
            dev: $stat['dev'],
            ino: $stat['ino'],
            uid: $stat['uid'],
            gid: $stat['gid'],
            fileSize: $stat['size'],
            flags: min(strlen($path), 0xFFF),
            stage: 0,
        );
    }

    public static function create(string $path, ObjectId $objectId, FileMode $mode, int $fileSize): self
    {
        $now = time();

        return new self(
            path: $path,
            objectId: $objectId,
            mode: $mode,
            ctime: $now,
            ctimeNano: 0,
            mtime: $now,
            mtimeNano: 0,
            dev: 0,
            ino: 0,
            uid: 0,
            gid: 0,
            fileSize: $fileSize,
            flags: min(strlen($path), 0xFFF),
            stage: 0,
        );
    }
}

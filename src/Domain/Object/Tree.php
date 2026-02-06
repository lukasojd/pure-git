<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Object;

use Lukasojd\PureGit\Domain\Exception\InvalidObjectException;

final readonly class Tree implements GitObject
{
    private ObjectId $id;

    /**
     * @param list<TreeEntry> $entries
     */
    public function __construct(
        public array $entries,
    ) {
        $this->id = ObjectId::hash($this->serialize(), ObjectType::Tree);
    }

    public static function fromSerialized(string $data): self
    {
        $entries = [];
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $spacePos = strpos($data, ' ', $offset);
            if ($spacePos === false) {
                throw InvalidObjectException::corruptObject('', 'Invalid tree entry: no space');
            }
            $mode = substr($data, $offset, $spacePos - $offset);

            $nullPos = strpos($data, "\0", $spacePos + 1);
            if ($nullPos === false) {
                throw InvalidObjectException::corruptObject('', 'Invalid tree entry: no null');
            }
            $name = substr($data, $spacePos + 1, $nullPos - $spacePos - 1);

            $hashBinary = substr($data, $nullPos + 1, 20);
            if (strlen($hashBinary) !== 20) {
                throw InvalidObjectException::corruptObject('', 'Invalid tree entry: truncated hash');
            }

            $entries[] = new TreeEntry(
                FileMode::fromOctal($mode),
                $name,
                ObjectId::fromBinary($hashBinary),
            );

            $offset = $nullPos + 21;
        }

        return new self($entries);
    }

    public function getId(): ObjectId
    {
        return $this->id;
    }

    public function getType(): ObjectType
    {
        return ObjectType::Tree;
    }

    public function serialize(): string
    {
        $result = '';
        foreach ($this->entries as $entry) {
            $result .= $entry->mode->toOctal() . ' ' . $entry->name . "\0" . $entry->objectId->toBinary();
        }

        return $result;
    }

    public function findEntry(string $name): ?TreeEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->name === $name) {
                return $entry;
            }
        }

        return null;
    }
}

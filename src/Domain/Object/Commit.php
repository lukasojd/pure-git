<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Object;

use Lukasojd\PureGit\Domain\Exception\InvalidObjectException;

final readonly class Commit implements GitObject
{
    private ObjectId $id;

    /**
     * @param list<ObjectId> $parents
     */
    public function __construct(
        public ObjectId $treeId,
        public array $parents,
        public PersonInfo $author,
        public PersonInfo $committer,
        public string $message,
    ) {
        $this->id = ObjectId::hash($this->serialize(), ObjectType::Commit);
    }

    public static function fromSerialized(string $data): self
    {
        $boundary = strpos($data, "\n\n");
        if ($boundary === false) {
            throw InvalidObjectException::corruptObject('', 'Missing header/message boundary');
        }

        $message = substr($data, $boundary + 2);
        $headerLines = explode("\n", substr($data, 0, $boundary));

        $treeId = null;
        $parents = [];
        $author = null;
        $committer = null;

        foreach ($headerLines as $line) {
            $spacePos = strpos($line, ' ');
            if ($spacePos === false) {
                throw InvalidObjectException::corruptObject('', 'Invalid commit line');
            }

            $key = substr($line, 0, $spacePos);
            $value = substr($line, $spacePos + 1);

            switch ($key) {
                case 'tree':
                    $treeId = ObjectId::fromHex($value);
                    break;
                case 'parent':
                    $parents[] = ObjectId::fromHex($value);
                    break;
                case 'author':
                    $author = PersonInfo::fromString($value);
                    break;
                case 'committer':
                    $committer = PersonInfo::fromString($value);
                    break;
            }
        }

        if (! $treeId instanceof \Lukasojd\PureGit\Domain\Object\ObjectId || ! $author instanceof \Lukasojd\PureGit\Domain\Object\PersonInfo || ! $committer instanceof \Lukasojd\PureGit\Domain\Object\PersonInfo) {
            throw InvalidObjectException::corruptObject('', 'Missing required commit fields');
        }

        return new self($treeId, $parents, $author, $committer, $message);
    }

    public function getId(): ObjectId
    {
        return $this->id;
    }

    public function getType(): ObjectType
    {
        return ObjectType::Commit;
    }

    public function serialize(): string
    {
        $lines = [];
        $lines[] = 'tree ' . $this->treeId->hash;
        foreach ($this->parents as $parent) {
            $lines[] = 'parent ' . $parent->hash;
        }
        $lines[] = 'author ' . $this->author->toString();
        $lines[] = 'committer ' . $this->committer->toString();
        $lines[] = '';
        $lines[] = $this->message;

        return implode("\n", $lines);
    }

    public function isRoot(): bool
    {
        return $this->parents === [];
    }
}

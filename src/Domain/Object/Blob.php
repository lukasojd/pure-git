<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Object;

final readonly class Blob implements GitObject
{
    private ObjectId $id;

    public function __construct(
        public string $content,
    ) {
        $this->id = ObjectId::hash($this->serialize(), ObjectType::Blob);
    }

    public static function fromSerialized(string $data): self
    {
        return new self($data);
    }

    public function getId(): ObjectId
    {
        return $this->id;
    }

    public function getType(): ObjectType
    {
        return ObjectType::Blob;
    }

    public function serialize(): string
    {
        return $this->content;
    }

    public function getSize(): int
    {
        return strlen($this->content);
    }
}

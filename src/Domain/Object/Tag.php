<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Object;

use Lukasojd\PureGit\Domain\Exception\InvalidObjectException;

final readonly class Tag implements GitObject
{
    private ObjectId $id;

    public function __construct(
        public ObjectId $targetId,
        public ObjectType $targetType,
        public string $tagName,
        public PersonInfo $tagger,
        public string $message,
    ) {
        $this->id = ObjectId::hash($this->serialize(), ObjectType::Tag);
    }

    public static function fromSerialized(string $data): self
    {
        $lines = explode("\n", $data);
        $targetId = null;
        $targetType = null;
        $tagName = null;
        $tagger = null;
        $messageStart = false;
        $messageLines = [];

        foreach ($lines as $line) {
            if ($messageStart) {
                $messageLines[] = $line;
                continue;
            }

            if ($line === '') {
                $messageStart = true;
                continue;
            }

            $spacePos = strpos($line, ' ');
            if ($spacePos === false) {
                throw InvalidObjectException::corruptObject('', 'Invalid tag line');
            }

            $key = substr($line, 0, $spacePos);
            $value = substr($line, $spacePos + 1);

            switch ($key) {
                case 'object':
                    $targetId = ObjectId::fromHex($value);
                    break;
                case 'type':
                    $targetType = ObjectType::from($value);
                    break;
                case 'tag':
                    $tagName = $value;
                    break;
                case 'tagger':
                    $tagger = PersonInfo::fromString($value);
                    break;
            }
        }

        if (! $targetId instanceof \Lukasojd\PureGit\Domain\Object\ObjectId || $targetType === null || $tagName === null || ! $tagger instanceof \Lukasojd\PureGit\Domain\Object\PersonInfo) {
            throw InvalidObjectException::corruptObject('', 'Missing required tag fields');
        }

        return new self($targetId, $targetType, $tagName, $tagger, implode("\n", $messageLines));
    }

    public function getId(): ObjectId
    {
        return $this->id;
    }

    public function getType(): ObjectType
    {
        return ObjectType::Tag;
    }

    public function serialize(): string
    {
        $lines = [];
        $lines[] = 'object ' . $this->targetId->hash;
        $lines[] = 'type ' . $this->targetType->value;
        $lines[] = 'tag ' . $this->tagName;
        $lines[] = 'tagger ' . $this->tagger->toString();
        $lines[] = '';
        $lines[] = $this->message;

        return implode("\n", $lines);
    }
}

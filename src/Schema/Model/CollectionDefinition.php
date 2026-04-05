<?php

namespace DevBX\DTO\Schema\Model;

class CollectionDefinition
{
    public readonly string $name;

    /** @var string Type reference for collection items */
    public readonly string $itemType;

    public readonly ?string $description;

    public function __construct(string $name, string $itemType, ?string $description = null)
    {
        $this->name = $name;
        $this->itemType = $itemType;
        $this->description = $description;
    }

    public function toArray(): array
    {
        $data = ['itemType' => $this->itemType];
        if ($this->description !== null) $data['description'] = $this->description;
        return $data;
    }

    public static function fromArray(string $name, array $data): self
    {
        return new self(
            name: $name,
            itemType: $data['itemType'],
            description: $data['description'] ?? null
        );
    }
}

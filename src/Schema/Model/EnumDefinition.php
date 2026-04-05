<?php

namespace DevBX\DTO\Schema\Model;

class EnumDefinition
{
    public readonly string $name;

    /** @var "string"|"int" */
    public readonly string $backingType;

    /** @var array<string, string|int> Name => backing value */
    public readonly array $values;

    /**
     * @param "string"|"int" $backingType
     * @param array<string, string|int> $values
     */
    public function __construct(string $name, string $backingType, array $values)
    {
        $this->name = $name;
        $this->backingType = $backingType;
        $this->values = $values;
    }

    public function toArray(): array
    {
        return [
            'backingType' => $this->backingType,
            'values' => $this->values,
        ];
    }

    public static function fromArray(string $name, array $data): self
    {
        return new self(
            name: $name,
            backingType: $data['backingType'],
            values: $data['values']
        );
    }
}

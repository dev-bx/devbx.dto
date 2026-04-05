<?php

namespace DevBX\DTO\Schema\Model;

class ComputedDefinition
{
    public readonly string $name;
    public readonly string $returnType;
    public readonly ?string $mapTo;

    public function __construct(string $name, string $returnType, ?string $mapTo = null)
    {
        $this->name = $name;
        $this->returnType = $returnType;
        $this->mapTo = $mapTo;
    }

    public function toArray(): array
    {
        $data = ['returnType' => $this->returnType];

        if ($this->mapTo !== null) $data['mapTo'] = $this->mapTo;

        return $data;
    }

    public static function fromArray(string $name, array $data): self
    {
        return new self(
            name: $name,
            returnType: $data['returnType'],
            mapTo: $data['mapTo'] ?? null
        );
    }
}

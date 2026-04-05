<?php

namespace DevBX\DTO\Schema\Model;

class PropertyDefinition
{
    public readonly string $name;

    /** @var string|string[] Primitive, type reference, or union (array of types) */
    public readonly string|array $type;

    /** @var string|null Item type for arrays */
    public readonly ?string $items;

    public readonly bool $nullable;

    /** @var bool Whether a default value is defined (even if null) */
    public readonly bool $hasDefault;

    public readonly mixed $default;

    public readonly ?string $mapFrom;
    public readonly ?string $mapTo;

    /** @var string|null "query" or "body" */
    public readonly ?string $source;
    public readonly ?string $sourceKey;

    /** @var string[] Behavior flags: hidden, masked, initialize, skipNull */
    public readonly array $behavior;

    public readonly ?string $mask;

    /** @var ValidationRule[] */
    public readonly array $validation;

    public readonly ?string $description;

    /**
     * @param string|string[] $type
     * @param string[] $behavior
     * @param ValidationRule[] $validation
     */
    public function __construct(
        string $name,
        string|array $type,
        ?string $items = null,
        bool $nullable = false,
        bool $hasDefault = false,
        mixed $default = null,
        ?string $mapFrom = null,
        ?string $mapTo = null,
        ?string $source = null,
        ?string $sourceKey = null,
        array $behavior = [],
        ?string $mask = null,
        array $validation = [],
        ?string $description = null
    ) {
        $this->name = $name;
        $this->type = $type;
        $this->items = $items;
        $this->nullable = $nullable;
        $this->hasDefault = $hasDefault;
        $this->default = $default;
        $this->mapFrom = $mapFrom;
        $this->mapTo = $mapTo;
        $this->source = $source;
        $this->sourceKey = $sourceKey;
        $this->behavior = $behavior;
        $this->mask = $mask;
        $this->validation = $validation;
        $this->description = $description;
    }

    public function toArray(): array
    {
        $data = ['type' => $this->type];

        if ($this->items !== null) $data['items'] = $this->items;
        if ($this->nullable) $data['nullable'] = true;
        if ($this->hasDefault) $data['default'] = $this->default;
        if ($this->mapFrom !== null) $data['mapFrom'] = $this->mapFrom;
        if ($this->mapTo !== null) $data['mapTo'] = $this->mapTo;
        if ($this->source !== null) $data['source'] = $this->source;
        if ($this->sourceKey !== null) $data['sourceKey'] = $this->sourceKey;
        if (!empty($this->behavior)) $data['behavior'] = $this->behavior;
        if ($this->mask !== null) $data['mask'] = $this->mask;
        if (!empty($this->validation)) {
            $data['validation'] = array_map(fn(ValidationRule $r) => $r->toArray(), $this->validation);
        }
        if ($this->description !== null) $data['description'] = $this->description;

        return $data;
    }

    public static function fromArray(string $name, array $data): self
    {
        $validation = [];
        if (isset($data['validation'])) {
            foreach ($data['validation'] as $ruleData) {
                $validation[] = ValidationRule::fromArray($ruleData);
            }
        }

        return new self(
            name: $name,
            type: $data['type'],
            items: $data['items'] ?? null,
            nullable: $data['nullable'] ?? false,
            hasDefault: array_key_exists('default', $data),
            default: $data['default'] ?? null,
            mapFrom: $data['mapFrom'] ?? null,
            mapTo: $data['mapTo'] ?? null,
            source: $data['source'] ?? null,
            sourceKey: $data['sourceKey'] ?? null,
            behavior: $data['behavior'] ?? [],
            mask: $data['mask'] ?? null,
            validation: $validation,
            description: $data['description'] ?? null
        );
    }
}

<?php

namespace DevBX\DTO\Schema\Model;

class TypeDefinition
{
    public readonly string $name;
    public readonly ?string $description;
    public readonly bool $abstract;
    public readonly ?string $extends;
    public readonly bool $strict;

    /** @var array<string, PropertyDefinition> */
    public readonly array $properties;

    /** @var array<string, ComputedDefinition> */
    public readonly array $computed;

    /** @var array{postHydrate: string[], preExport: string[]} */
    public readonly array $hooks;

    /**
     * @param array<string, PropertyDefinition> $properties
     * @param array<string, ComputedDefinition> $computed
     * @param array{postHydrate: string[], preExport: string[]} $hooks
     */
    public function __construct(
        string $name,
        ?string $description = null,
        bool $abstract = false,
        ?string $extends = null,
        bool $strict = false,
        array $properties = [],
        array $computed = [],
        array $hooks = ['postHydrate' => [], 'preExport' => []]
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->abstract = $abstract;
        $this->extends = $extends;
        $this->strict = $strict;
        $this->properties = $properties;
        $this->computed = $computed;
        $this->hooks = $hooks;
    }

    public function toArray(): array
    {
        $data = [];

        if ($this->description !== null) $data['description'] = $this->description;
        if ($this->abstract) $data['abstract'] = true;
        if ($this->extends !== null) $data['extends'] = $this->extends;
        if ($this->strict) $data['strict'] = true;

        $props = [];
        foreach ($this->properties as $prop) {
            $props[$prop->name] = $prop->toArray();
        }
        if (!empty($props)) $data['properties'] = $props;

        $computed = [];
        foreach ($this->computed as $comp) {
            $computed[$comp->name] = $comp->toArray();
        }
        if (!empty($computed)) $data['computed'] = $computed;

        $hooks = [];
        if (!empty($this->hooks['postHydrate'])) $hooks['postHydrate'] = $this->hooks['postHydrate'];
        if (!empty($this->hooks['preExport'])) $hooks['preExport'] = $this->hooks['preExport'];
        if (!empty($hooks)) $data['hooks'] = $hooks;

        return $data;
    }

    public static function fromArray(string $name, array $data): self
    {
        $properties = [];
        if (isset($data['properties'])) {
            foreach ($data['properties'] as $propName => $propData) {
                $properties[$propName] = PropertyDefinition::fromArray($propName, $propData);
            }
        }

        $computed = [];
        if (isset($data['computed'])) {
            foreach ($data['computed'] as $compName => $compData) {
                $computed[$compName] = ComputedDefinition::fromArray($compName, $compData);
            }
        }

        $hooks = [
            'postHydrate' => $data['hooks']['postHydrate'] ?? [],
            'preExport' => $data['hooks']['preExport'] ?? [],
        ];

        return new self(
            name: $name,
            description: $data['description'] ?? null,
            abstract: $data['abstract'] ?? false,
            extends: $data['extends'] ?? null,
            strict: $data['strict'] ?? false,
            properties: $properties,
            computed: $computed,
            hooks: $hooks
        );
    }
}

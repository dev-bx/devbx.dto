<?php

namespace DevBX\DTO\Schema\Model;

class SchemaDefinition
{
    public const SCHEMA_VERSION = 'devbx-dto/1.0';

    public readonly string $schema;
    public readonly PackageInfo $package;

    /** @var array<string, EnumDefinition> */
    public readonly array $enums;

    /** @var array<string, TypeDefinition> */
    public readonly array $types;

    /** @var array<string, CollectionDefinition> */
    public readonly array $collections;

    /**
     * @param array<string, EnumDefinition> $enums
     * @param array<string, TypeDefinition> $types
     * @param array<string, CollectionDefinition> $collections
     */
    public function __construct(
        PackageInfo $package,
        array $enums = [],
        array $types = [],
        array $collections = [],
        string $schema = self::SCHEMA_VERSION
    ) {
        $this->schema = $schema;
        $this->package = $package;
        $this->enums = $enums;
        $this->types = $types;
        $this->collections = $collections;
    }

    public function toArray(): array
    {
        $data = [
            '$schema' => $this->schema,
            'package' => $this->package->toArray(),
        ];

        $enums = [];
        foreach ($this->enums as $enum) {
            $enums[$enum->name] = $enum->toArray();
        }
        if (!empty($enums)) $data['enums'] = $enums;

        $types = [];
        foreach ($this->types as $type) {
            $types[$type->name] = $type->toArray();
        }
        if (!empty($types)) $data['types'] = $types;

        $collections = [];
        foreach ($this->collections as $coll) {
            $collections[$coll->name] = $coll->toArray();
        }
        if (!empty($collections)) $data['collections'] = $collections;

        return $data;
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | $flags
        );
    }
}

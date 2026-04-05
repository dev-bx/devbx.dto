<?php

namespace DevBX\DTO\Schema;

use DevBX\DTO\Schema\Model\SchemaDefinition;
use DevBX\DTO\Schema\Model\PackageInfo;
use DevBX\DTO\Schema\Model\TypeDefinition;
use DevBX\DTO\Schema\Model\PropertyDefinition;
use DevBX\DTO\Schema\Model\ComputedDefinition;
use DevBX\DTO\Schema\Model\ValidationRule;
use DevBX\DTO\Schema\Model\EnumDefinition;
use DevBX\DTO\Schema\Model\CollectionDefinition;

class SchemaImporter
{
    private SchemaValidator $validator;

    public function __construct()
    {
        $this->validator = new SchemaValidator();
    }

    /**
     * Imports schema from a JSON file path.
     *
     * @throws \JsonException
     * @throws Exception\SchemaVersionException
     * @throws Exception\SchemaValidationException
     */
    public function fromFile(string $filePath, bool $strict = true): SchemaDefinition
    {
        if (!is_file($filePath)) {
            throw new \InvalidArgumentException("Schema file not found: {$filePath}");
        }

        $json = file_get_contents($filePath);
        if ($json === false) {
            throw new \RuntimeException("Failed to read schema file: {$filePath}");
        }

        return $this->fromJson($json, $strict);
    }

    /**
     * Imports schema from a JSON string.
     *
     * @throws \JsonException
     * @throws Exception\SchemaVersionException
     * @throws Exception\SchemaValidationException
     */
    public function fromJson(string $json, bool $strict = true): SchemaDefinition
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $this->fromArray($data, $strict);
    }

    /**
     * Imports schema from a decoded array.
     *
     * @throws Exception\SchemaVersionException
     * @throws Exception\SchemaValidationException
     */
    public function fromArray(array $data, bool $strict = true): SchemaDefinition
    {
        $this->validator->validate($data, $strict);

        $package = PackageInfo::fromArray($data['package']);

        $enums = [];
        if (isset($data['enums'])) {
            foreach ($data['enums'] as $name => $enumData) {
                $enums[$name] = EnumDefinition::fromArray($name, $enumData);
            }
        }

        $types = [];
        if (isset($data['types'])) {
            foreach ($data['types'] as $name => $typeData) {
                $types[$name] = TypeDefinition::fromArray($name, $typeData);
            }
        }

        $collections = [];
        if (isset($data['collections'])) {
            foreach ($data['collections'] as $name => $collData) {
                $collections[$name] = CollectionDefinition::fromArray($name, $collData);
            }
        }

        return new SchemaDefinition(
            package: $package,
            enums: $enums,
            types: $types,
            collections: $collections,
            schema: $data['$schema']
        );
    }
}

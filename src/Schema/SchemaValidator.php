<?php

namespace DevBX\DTO\Schema;

use DevBX\DTO\Schema\Exception\SchemaValidationException;
use DevBX\DTO\Schema\Exception\SchemaVersionException;
use DevBX\DTO\Schema\Model\SchemaDefinition;

class SchemaValidator
{
    private const KNOWN_KEYS = [
        'root' => ['$schema', 'package', 'enums', 'types', 'collections'],
        'package' => ['name', 'version', 'codeGen'],
        'codeGen' => ['php', 'typescript'],
        'codeGen.php' => ['namespace'],
        'codeGen.typescript' => ['module'],
        'enum' => ['backingType', 'values'],
        'type' => ['abstract', 'extends', 'strict', 'properties', 'computed', 'hooks', 'description'],
        'property' => ['type', 'items', 'nullable', 'default', 'mapFrom', 'mapTo', 'source', 'sourceKey', 'behavior', 'mask', 'validation', 'description'],
        'validation' => ['rule', 'value', 'values', 'pattern', 'strict', 'message'],
        'computed' => ['returnType', 'mapTo'],
        'hooks' => ['postHydrate', 'preExport'],
        'collection' => ['itemType', 'description'],
    ];

    /**
     * Validates raw schema data (decoded JSON array).
     *
     * @throws SchemaVersionException If major version does not match
     * @throws SchemaValidationException If strict mode is on and unknown keys are found
     */
    public function validate(array $data, bool $strict = true): void
    {
        $this->validateVersion($data);

        if ($strict) {
            $this->validateKeys($data, 'root', '$');
        }
    }

    /**
     * Checks that the $schema version is compatible.
     *
     * @throws SchemaVersionException
     */
    private function validateVersion(array $data): void
    {
        if (!isset($data['$schema'])) {
            throw new SchemaVersionException(
                SchemaDefinition::SCHEMA_VERSION,
                '(missing)'
            );
        }

        $expectedMajor = $this->extractMajorVersion(SchemaDefinition::SCHEMA_VERSION);
        $actualMajor = $this->extractMajorVersion($data['$schema']);

        if ($expectedMajor !== $actualMajor) {
            throw new SchemaVersionException(SchemaDefinition::SCHEMA_VERSION, $data['$schema']);
        }
    }

    private function extractMajorVersion(string $version): string
    {
        // "devbx-dto/1.0" → "devbx-dto/1"
        if (preg_match('/^(.+\/\d+)/', $version, $matches)) {
            return $matches[1];
        }

        return $version;
    }

    /**
     * Recursively validates keys at each schema level.
     *
     * @throws SchemaValidationException
     */
    private function validateKeys(array $data, string $level, string $path): void
    {
        if (!isset(self::KNOWN_KEYS[$level])) {
            return;
        }

        $allowedKeys = self::KNOWN_KEYS[$level];
        $unknown = array_diff(array_keys($data), $allowedKeys);

        if (!empty($unknown)) {
            throw new SchemaValidationException(array_values($unknown), $path);
        }

        // Recurse into nested structures
        switch ($level) {
            case 'root':
                if (isset($data['package'])) {
                    $this->validateKeys($data['package'], 'package', '$.package');
                }
                if (isset($data['enums'])) {
                    foreach ($data['enums'] as $name => $enumData) {
                        $this->validateKeys($enumData, 'enum', "$.enums.{$name}");
                    }
                }
                if (isset($data['types'])) {
                    foreach ($data['types'] as $name => $typeData) {
                        $this->validateTypeKeys($typeData, "$.types.{$name}");
                    }
                }
                if (isset($data['collections'])) {
                    foreach ($data['collections'] as $name => $collData) {
                        $this->validateKeys($collData, 'collection', "$.collections.{$name}");
                    }
                }
                break;

            case 'package':
                if (isset($data['codeGen'])) {
                    $this->validateKeys($data['codeGen'], 'codeGen', "{$path}.codeGen");
                    if (isset($data['codeGen']['php'])) {
                        $this->validateKeys($data['codeGen']['php'], 'codeGen.php', "{$path}.codeGen.php");
                    }
                    if (isset($data['codeGen']['typescript'])) {
                        $this->validateKeys($data['codeGen']['typescript'], 'codeGen.typescript', "{$path}.codeGen.typescript");
                    }
                }
                break;
        }
    }

    /**
     * Validates a type definition and its nested structures.
     */
    private function validateTypeKeys(array $data, string $path): void
    {
        $this->validateKeys($data, 'type', $path);

        if (isset($data['properties'])) {
            foreach ($data['properties'] as $propName => $propData) {
                $this->validateKeys($propData, 'property', "{$path}.properties.{$propName}");

                if (isset($propData['validation'])) {
                    foreach ($propData['validation'] as $i => $ruleData) {
                        $this->validateKeys($ruleData, 'validation', "{$path}.properties.{$propName}.validation[{$i}]");
                    }
                }
            }
        }

        if (isset($data['computed'])) {
            foreach ($data['computed'] as $compName => $compData) {
                $this->validateKeys($compData, 'computed', "{$path}.computed.{$compName}");
            }
        }

        if (isset($data['hooks'])) {
            $this->validateKeys($data['hooks'], 'hooks', "{$path}.hooks");
        }
    }
}

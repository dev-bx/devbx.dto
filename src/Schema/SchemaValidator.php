<?php

namespace DevBX\DTO\Schema;

use InvalidArgumentException;

class SchemaValidator implements SchemaValidatorInterface
{
    public function validate(array $schemaData): void
    {
        // 1. Проверка корневых ключей
        $this->assertKeyExists($schemaData, 'name', 'string');
        $this->assertKeyExists($schemaData, 'module', 'string');
        $this->assertKeyExists($schemaData, 'properties', 'array');

        // Проверка формата имени класса (только буквы, цифры и underscore)
        if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $schemaData['name'])) {
            throw new InvalidArgumentException("Invalid 'name' format: {$schemaData['name']}");
        }

        // 2. Опциональные корневые ключи
        if (isset($schemaData['imports'])) {
            $this->assertIsArrayOfArrays($schemaData['imports'], 'imports');
            foreach ($schemaData['imports'] as $index => $import) {
                $this->assertKeyExists($import, 'name', 'string', "imports[{$index}]");
                $this->assertKeyExists($import, 'module', 'string', "imports[{$index}]");
            }
        }

        // 3. Проверка свойств (Properties)
        foreach ($schemaData['properties'] as $propName => $propDef) {
            if (!is_array($propDef)) {
                throw new InvalidArgumentException("Property '{$propName}' must be an object/array.");
            }

            // Обязательные ключи свойства
            $this->assertKeyExists($propDef, 'type', 'array', "properties.{$propName}");
            $this->assertKeyExists($propDef, 'isNullable', 'boolean', "properties.{$propName}");

            // Ключ 'default' обязателен (может быть null)
            if (!array_key_exists('default', $propDef)) {
                throw new InvalidArgumentException("Missing required key 'default' in properties.{$propName}");
            }

            $types = $propDef['type'];
            if (empty($types)) {
                throw new InvalidArgumentException("Array 'type' cannot be empty in properties.{$propName}");
            }

            foreach ($types as $typeItem) {
                if (!is_string($typeItem)) {
                    throw new InvalidArgumentException("Elements of 'type' must be strings in properties.{$propName}");
                }
            }

            // Проверка коллекций и массивов: если это коллекция/массив, должен быть указан 'items'
            if (in_array('collection', $types, true) || in_array('array', $types, true)) {
                if (!isset($propDef['items']) || !is_string($propDef['items'])) {
                    throw new InvalidArgumentException(
                        "Property '{$propName}' is a collection/array, but missing string 'items' definition."
                    );
                }
            }

            // Опциональные метаданные
            if (isset($propDef['metadata']) && !is_array($propDef['metadata'])) {
                throw new InvalidArgumentException("Key 'metadata' must be an object/array in properties.{$propName}");
            }
        }
    }

    /**
     * Вспомогательный метод для проверки наличия и типа ключа.
     */
    private function assertKeyExists(array $data, string $key, string $expectedType, string $path = 'root'): void
    {
        if (!array_key_exists($key, $data)) {
            throw new InvalidArgumentException("Missing required key '{$key}' in {$path}.");
        }

        $actualType = gettype($data[$key]);
        if ($actualType !== $expectedType) {
            throw new InvalidArgumentException(
                "Key '{$key}' in {$path} must be of type {$expectedType}, got {$actualType}."
            );
        }
    }

    /**
     * Вспомогательный метод для массивов объектов.
     */
    private function assertIsArrayOfArrays(mixed $value, string $path): void
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException("Key '{$path}' must be an array.");
        }
        foreach ($value as $index => $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException("Element at {$path}[{$index}] must be an object/array.");
            }
        }
    }
}
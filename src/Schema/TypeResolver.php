<?php

namespace DevBX\DTO\Schema;

use DevBX\DTO\Schema\Model\SchemaDefinition;

class TypeResolver
{
    private SchemaDefinition $schema;

    public function __construct(SchemaDefinition $schema)
    {
        $this->schema = $schema;
    }

    // ─── PHP Resolution ───

    /**
     * Resolves a schema type to a PHP type string.
     */
    public function toPhpType(string|array $type, bool $nullable = false): string
    {
        if (is_array($type)) {
            $mapped = array_map(fn(string $t) => $this->toPhpSingleType($t), $type);
            $result = implode('|', $mapped);
            if ($nullable) $result .= '|null';
            return $result;
        }

        $result = $this->toPhpSingleType($type);
        if ($nullable) {
            return '?' . $result;
        }
        return $result;
    }

    private function toPhpSingleType(string $type): string
    {
        // Primitives
        $mapped = match ($type) {
            'string' => 'string',
            'int' => 'int',
            'float' => 'float',
            'bool' => 'bool',
            'any' => 'mixed',
            'array' => 'array',
            'datetime' => '\DateTime',
            default => null,
        };

        if ($mapped !== null) return $mapped;

        // Enum reference: "enum:EnumName"
        if (str_starts_with($type, 'enum:')) {
            $enumName = substr($type, 5);
            return $this->schemaNameToPhpClass($enumName);
        }

        // Type/Collection reference: "Grok.Batch.BatchState"
        return $this->schemaNameToPhpClass($type);
    }

    /**
     * Converts a dot-separated schema name to a PHP class name relative to base namespace.
     * "Grok.Batch.BatchState" → "Grok\Batch\BatchState"
     */
    public function schemaNameToPhpClass(string $schemaName): string
    {
        return str_replace('.', '\\', $schemaName);
    }

    /**
     * Returns the fully qualified PHP class name.
     */
    public function schemaNameToFqcn(string $schemaName): string
    {
        $namespace = $this->schema->package->getPhpNamespace();
        $relative = $this->schemaNameToPhpClass($schemaName);

        return $namespace ? $namespace . '\\' . $relative : $relative;
    }

    /**
     * Extracts the short class name from a schema name.
     * "Grok.Batch.BatchState" → "BatchState"
     */
    public function shortName(string $schemaName): string
    {
        $parts = explode('.', $schemaName);
        return end($parts);
    }

    /**
     * Extracts the namespace part from a schema name.
     * "Grok.Batch.BatchState" → "Grok.Batch" or null if no dots.
     */
    public function namespacePart(string $schemaName): ?string
    {
        $lastDot = strrpos($schemaName, '.');
        if ($lastDot === false) return null;
        return substr($schemaName, 0, $lastDot);
    }

    // ─── TypeScript Resolution ───

    /**
     * Resolves a schema type to a TypeScript type string.
     */
    public function toTypeScriptType(string|array $type, bool $nullable = false): string
    {
        if (is_array($type)) {
            $mapped = array_map(fn(string $t) => $this->toTypeScriptSingleType($t), $type);
            $result = implode(' | ', $mapped);
            if ($nullable) $result .= ' | null';
            return $result;
        }

        $result = $this->toTypeScriptSingleType($type);
        if ($nullable) {
            return $result . ' | null';
        }
        return $result;
    }

    private function toTypeScriptSingleType(string $type): string
    {
        $mapped = match ($type) {
            'string' => 'string',
            'int', 'float' => 'number',
            'bool' => 'boolean',
            'any' => 'any',
            'array' => 'any[]',
            'datetime' => 'Date | string',
            default => null,
        };

        if ($mapped !== null) return $mapped;

        if (str_starts_with($type, 'enum:')) {
            return $this->shortName(substr($type, 5));
        }

        return $this->shortName($type);
    }

    /**
     * Resolves an items type to TypeScript array element type.
     */
    public function toTypeScriptItemType(string $items): string
    {
        return match ($items) {
            'string' => 'string',
            'int', 'float' => 'number',
            'bool' => 'boolean',
            'any' => 'any',
            default => $this->shortName($items),
        };
    }

    /**
     * Checks if a schema type is a reference to another type (not a primitive).
     */
    public function isTypeReference(string $type): bool
    {
        if (str_starts_with($type, 'enum:')) return true;

        return !in_array($type, ['string', 'int', 'float', 'bool', 'any', 'array', 'datetime'], true);
    }

    /**
     * Checks if a schema type is a primitive.
     */
    public function isPrimitive(string $type): bool
    {
        return in_array($type, ['string', 'int', 'float', 'bool', 'any', 'datetime'], true);
    }
}

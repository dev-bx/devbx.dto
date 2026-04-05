<?php

namespace DevBX\DTO\Schema\Generator;

use DevBX\DTO\Schema\TypeResolver;
use DevBX\DTO\Schema\Model\SchemaDefinition;
use DevBX\DTO\Schema\Model\TypeDefinition;
use DevBX\DTO\Schema\Model\PropertyDefinition;
use DevBX\DTO\Schema\Model\ComputedDefinition;
use DevBX\DTO\Schema\Model\EnumDefinition;
use DevBX\DTO\Schema\Model\CollectionDefinition;
use DevBX\DTO\Schema\Model\ValidationRule;

class TypeScriptGenerator
{
    private TypeResolver $resolver;
    private SchemaDefinition $schema;

    public function __construct(SchemaDefinition $schema)
    {
        $this->schema = $schema;
        $this->resolver = new TypeResolver($schema);
    }

    /**
     * Generates TypeScript files from schema and writes them to the target directory.
     *
     * @return string[] List of generated file paths
     */
    public function generate(string $targetDir): array
    {
        $files = [];

        foreach ($this->schema->enums as $enum) {
            $path = $this->writeFile($targetDir, $enum->name, $this->generateEnum($enum));
            $files[] = $path;
        }

        foreach ($this->schema->types as $type) {
            $path = $this->writeFile($targetDir, $type->name, $this->generateType($type));
            $files[] = $path;
        }

        foreach ($this->schema->collections as $coll) {
            $path = $this->writeFile($targetDir, $coll->name, $this->generateCollection($coll));
            $files[] = $path;
        }

        // Generate barrel index
        $indexPath = $this->writeFile($targetDir, 'index', $this->generateIndex());
        $files[] = $indexPath;

        return $files;
    }

    // ─── Enum Generation ───

    private function generateEnum(EnumDefinition $enum): string
    {
        $name = $this->resolver->shortName($enum->name);
        $lines = ["export enum {$name} {"];

        $entries = [];
        foreach ($enum->values as $caseName => $value) {
            $valueStr = is_int($value) ? (string)$value : "'{$value}'";
            $entries[] = "    {$caseName} = {$valueStr},";
        }

        $lines[] = implode("\n", $entries);
        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    // ─── Type (DTO) Generation ───

    private function generateType(TypeDefinition $type): string
    {
        $name = $this->resolver->shortName($type->name);
        $imports = $this->collectTypeImports($type);
        $importsCode = $this->renderImports($imports, $type->name);

        // Class declaration
        $extends = '';
        if ($type->extends !== null) {
            $parentName = $this->resolver->shortName($type->extends);
            $extends = " extends {$parentName}";
        }

        $modifier = $type->abstract ? 'abstract ' : '';

        $lines = [];
        if ($importsCode !== '') {
            $lines[] = $importsCode;
            $lines[] = '';
        }

        $lines[] = "export {$modifier}class {$name}{$extends} {";

        // Properties
        foreach ($type->properties as $prop) {
            $lines[] = $this->generateTsProperty($prop);
        }

        // Computed stubs
        foreach ($type->computed as $comp) {
            $lines[] = '';
            $lines[] = $this->generateTsComputedStub($comp);
        }

        // fromObject static factory
        if (!$type->abstract) {
            $lines[] = '';
            $lines[] = $this->generateFromObject($type);
        }

        // toObject export
        $lines[] = '';
        $lines[] = $this->generateToObject($type);

        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    private function generateTsProperty(PropertyDefinition $prop): string
    {
        $tsType = $this->resolveTsPropertyType($prop);
        $optional = $prop->hasDefault ? '?' : '';
        $defaultComment = '';

        if ($prop->hasDefault && $prop->default !== null) {
            $defaultComment = " // default: " . json_encode($prop->default);
        }

        return "    {$prop->name}{$optional}: {$tsType};{$defaultComment}";
    }

    private function resolveTsPropertyType(PropertyDefinition $prop): string
    {
        if ($prop->type === 'array' && $prop->items !== null) {
            $itemType = $this->resolver->toTypeScriptItemType($prop->items);
            $base = "{$itemType}[]";
        } else {
            $base = $this->resolver->toTypeScriptType($prop->type);
        }

        if ($prop->nullable) {
            return "{$base} | null";
        }
        return $base;
    }

    private function generateTsComputedStub(ComputedDefinition $comp): string
    {
        $methodName = 'get' . ucfirst($comp->name);
        $returnType = $this->resolver->toTypeScriptType($comp->returnType);

        return <<<TS
            {$methodName}(): {$returnType} {
                throw new Error('Computed method {$methodName}() not implemented');
            }
        TS;
    }

    private function generateFromObject(TypeDefinition $type): string
    {
        $name = $this->resolver->shortName($type->name);
        $lines = [];
        $lines[] = "    static fromObject(data: Record<string, any>): {$name} {";
        $lines[] = "        const instance = new {$name}();";

        foreach ($type->properties as $prop) {
            $key = $prop->mapFrom ?? $prop->name;
            $assignment = $this->generatePropertyAssignment($prop, $key);
            $lines[] = "        {$assignment}";
        }

        // Call parent fromObject if extends
        if ($type->extends !== null) {
            $parentName = $this->resolver->shortName($type->extends);
            $lines[] = "        // Inherit parent properties";
            $lines[] = "        const parentData = {$parentName}.fromObject(data);";
            $lines[] = "        Object.assign(instance, parentData);";
        }

        $lines[] = "        return instance;";
        $lines[] = "    }";

        return implode("\n", $lines);
    }

    private function generatePropertyAssignment(PropertyDefinition $prop, string $sourceKey): string
    {
        $camelKey = $prop->name;
        $sourceAccess = "data['{$sourceKey}']";

        // Fallback keys: try camelCase, then the mapFrom key
        if ($prop->mapFrom !== null) {
            $sourceAccess = "(data['{$camelKey}'] ?? data['{$prop->mapFrom}'])";
        }

        // Nested DTO
        if (is_string($prop->type) && $this->resolver->isTypeReference($prop->type) && !str_starts_with($prop->type, 'enum:')) {
            $targetClass = $this->resolver->shortName($prop->type);
            if ($prop->nullable) {
                return "instance.{$camelKey} = {$sourceAccess} != null ? {$targetClass}.fromObject({$sourceAccess}) : null;";
            }
            return "instance.{$camelKey} = {$targetClass}.fromObject({$sourceAccess});";
        }

        // Array of DTOs
        if ($prop->type === 'array' && $prop->items !== null && $this->resolver->isTypeReference($prop->items)) {
            $itemClass = $this->resolver->shortName($prop->items);
            return "instance.{$camelKey} = Array.isArray({$sourceAccess}) ? {$sourceAccess}.map((item: any) => {$itemClass}.fromObject(item)) : [];";
        }

        // Simple assignment
        if ($prop->hasDefault) {
            return "instance.{$camelKey} = {$sourceAccess} ?? {$this->renderTsValue($prop->default)};";
        }

        return "instance.{$camelKey} = {$sourceAccess};";
    }

    private function generateToObject(TypeDefinition $type): string
    {
        $lines = [];
        $lines[] = "    toObject(): Record<string, any> {";

        if ($type->extends !== null) {
            $lines[] = "        const data: Record<string, any> = super.toObject();";
        } else {
            $lines[] = "        const data: Record<string, any> = {};";
        }

        foreach ($type->properties as $prop) {
            if (in_array('hidden', $prop->behavior, true)) continue;

            $exportKey = $prop->mapTo ?? $prop->name;

            if (in_array('skipNull', $prop->behavior, true)) {
                $lines[] = "        if (this.{$prop->name} != null) data['{$exportKey}'] = this.{$prop->name};";
                continue;
            }

            if (in_array('masked', $prop->behavior, true)) {
                $mask = $prop->mask ?? '********';
                $lines[] = "        data['{$exportKey}'] = '{$mask}';";
                continue;
            }

            // Nested DTO
            if (is_string($prop->type) && $this->resolver->isTypeReference($prop->type) && !str_starts_with($prop->type, 'enum:')) {
                $lines[] = "        data['{$exportKey}'] = this.{$prop->name}?.toObject() ?? null;";
                continue;
            }

            // Array of DTOs
            if ($prop->type === 'array' && $prop->items !== null && $this->resolver->isTypeReference($prop->items)) {
                $lines[] = "        data['{$exportKey}'] = this.{$prop->name}?.map(item => item.toObject()) ?? [];";
                continue;
            }

            $lines[] = "        data['{$exportKey}'] = this.{$prop->name};";
        }

        $lines[] = "        return data;";
        $lines[] = "    }";

        return implode("\n", $lines);
    }

    // ─── Collection Generation ───

    private function generateCollection(CollectionDefinition $coll): string
    {
        $name = $this->resolver->shortName($coll->name);
        $itemName = $this->resolver->shortName($coll->itemType);

        $imports = $this->collectCollectionImports($coll);
        $importsCode = $this->renderImports($imports, $coll->name);

        $lines = [];
        if ($importsCode !== '') {
            $lines[] = $importsCode;
            $lines[] = '';
        }

        $lines[] = "export class {$name} {";
        $lines[] = "    private items: {$itemName}[] = [];";
        $lines[] = '';
        $lines[] = "    constructor(items: {$itemName}[] = []) {";
        $lines[] = "        this.items = [...items];";
        $lines[] = "    }";
        $lines[] = '';
        $lines[] = "    static fromArray(data: any[]): {$name} {";
        $lines[] = "        return new {$name}(data.map(item => {$itemName}.fromObject(item)));";
        $lines[] = "    }";
        $lines[] = '';
        $lines[] = "    add(item: {$itemName}): this {";
        $lines[] = "        this.items.push(item);";
        $lines[] = "        return this;";
        $lines[] = "    }";
        $lines[] = '';
        $lines[] = "    toArray(): Record<string, any>[] {";
        $lines[] = "        return this.items.map(item => item.toObject());";
        $lines[] = "    }";
        $lines[] = '';
        $lines[] = "    get length(): number {";
        $lines[] = "        return this.items.length;";
        $lines[] = "    }";
        $lines[] = '';
        $lines[] = "    [Symbol.iterator](): Iterator<{$itemName}> {";
        $lines[] = "        return this.items[Symbol.iterator]();";
        $lines[] = "    }";
        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    // ─── Index / Barrel ───

    private function generateIndex(): string
    {
        $exports = [];

        foreach ($this->schema->enums as $enum) {
            $exports[] = $this->generateExportLine($enum->name);
        }
        foreach ($this->schema->types as $type) {
            $exports[] = $this->generateExportLine($type->name);
        }
        foreach ($this->schema->collections as $coll) {
            $exports[] = $this->generateExportLine($coll->name);
        }

        sort($exports);

        return implode("\n", $exports) . "\n";
    }

    private function generateExportLine(string $schemaName): string
    {
        $shortName = $this->resolver->shortName($schemaName);
        $relativePath = './' . str_replace('.', '/', $schemaName);

        return "export { {$shortName} } from '{$relativePath}';";
    }

    // ─── Imports ───

    /**
     * @return array<string, string> Short name => schema name
     */
    private function collectTypeImports(TypeDefinition $type): array
    {
        $imports = [];

        if ($type->extends !== null) {
            $imports[$this->resolver->shortName($type->extends)] = $type->extends;
        }

        foreach ($type->properties as $prop) {
            // Single type reference
            if (is_string($prop->type) && $this->resolver->isTypeReference($prop->type)) {
                $refType = str_starts_with($prop->type, 'enum:') ? substr($prop->type, 5) : $prop->type;
                $imports[$this->resolver->shortName($refType)] = $refType;
            }

            // Array items reference
            if ($prop->items !== null && $this->resolver->isTypeReference($prop->items)) {
                $imports[$this->resolver->shortName($prop->items)] = $prop->items;
            }

            // Union type references
            if (is_array($prop->type)) {
                foreach ($prop->type as $t) {
                    if ($this->resolver->isTypeReference($t)) {
                        $refType = str_starts_with($t, 'enum:') ? substr($t, 5) : $t;
                        $imports[$this->resolver->shortName($refType)] = $refType;
                    }
                }
            }
        }

        return $imports;
    }

    /**
     * @return array<string, string>
     */
    private function collectCollectionImports(CollectionDefinition $coll): array
    {
        return [
            $this->resolver->shortName($coll->itemType) => $coll->itemType,
        ];
    }

    /**
     * @param array<string, string> $imports Short name => schema name
     */
    private function renderImports(array $imports, string $currentSchemaName): string
    {
        if (empty($imports)) return '';

        $lines = [];
        foreach ($imports as $shortName => $schemaName) {
            $relativePath = $this->resolveRelativeImportPath($currentSchemaName, $schemaName);
            $lines[] = "import { {$shortName} } from '{$relativePath}';";
        }

        sort($lines);
        return implode("\n", $lines);
    }

    /**
     * Resolves a relative import path between two schema names.
     * "Grok.Batch.Batch" importing "Grok.GrokApiResponse" → "../GrokApiResponse"
     */
    private function resolveRelativeImportPath(string $fromSchema, string $toSchema): string
    {
        $fromParts = explode('.', $fromSchema);
        $toParts = explode('.', $toSchema);

        // Remove the file name part (last element)
        $fromDir = array_slice($fromParts, 0, -1);
        $toDir = array_slice($toParts, 0, -1);
        $toFile = end($toParts);

        // Find common prefix length
        $common = 0;
        $max = min(count($fromDir), count($toDir));
        while ($common < $max && $fromDir[$common] === $toDir[$common]) {
            $common++;
        }

        // Build relative path
        $ups = count($fromDir) - $common;
        $downs = array_slice($toDir, $common);

        $parts = [];
        for ($i = 0; $i < $ups; $i++) {
            $parts[] = '..';
        }
        foreach ($downs as $d) {
            $parts[] = $d;
        }
        $parts[] = $toFile;

        $path = implode('/', $parts);
        if (!str_starts_with($path, '.')) {
            $path = './' . $path;
        }

        return $path;
    }

    // ─── Helpers ───

    private function renderTsValue(mixed $value): string
    {
        if ($value === null) return 'null';
        if ($value === true) return 'true';
        if ($value === false) return 'false';
        if (is_int($value) || is_float($value)) return (string)$value;
        if (is_string($value)) return "'" . addslashes($value) . "'";
        if (is_array($value)) {
            if (empty($value)) return '[]';
            return json_encode($value);
        }
        return 'null';
    }

    private function writeFile(string $targetDir, string $schemaName, string $content): string
    {
        $relativePath = str_replace('.', DIRECTORY_SEPARATOR, $schemaName) . '.ts';
        $fullPath = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativePath;

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($fullPath, $content);

        return $fullPath;
    }
}

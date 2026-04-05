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

class PhpGenerator
{
    private TypeResolver $resolver;
    private SchemaDefinition $schema;
    private string $baseNamespace;

    public function __construct(SchemaDefinition $schema)
    {
        $this->schema = $schema;
        $this->resolver = new TypeResolver($schema);
        $this->baseNamespace = $schema->package->getPhpNamespace() ?? 'App\\DTO';
    }

    /**
     * Generates PHP files from schema and writes them to the target directory.
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

        return $files;
    }

    // ─── Enum Generation ───

    private function generateEnum(EnumDefinition $enum): string
    {
        $namespace = $this->resolveNamespace($enum->name);
        $className = $this->resolver->shortName($enum->name);
        $backingType = $enum->backingType === 'int' ? 'int' : 'string';

        $cases = [];
        foreach ($enum->values as $name => $value) {
            $valueStr = is_int($value) ? (string)$value : "'{$value}'";
            $cases[] = "    case {$name} = {$valueStr};";
        }

        $casesCode = implode("\n", $cases);

        return "<?php\n\nnamespace {$namespace};\n\nenum {$className}: {$backingType}\n{\n{$casesCode}\n}\n";
    }

    // ─── Type (DTO) Generation ───

    private function generateType(TypeDefinition $type): string
    {
        $namespace = $this->resolveNamespace($type->name);
        $className = $this->resolver->shortName($type->name);

        $imports = $this->collectImports($type);
        $phpDoc = $this->generateTypePhpDoc($type);
        $classModifier = $type->abstract ? 'abstract ' : '';

        // Extends
        $extends = 'BaseDTO';
        if ($type->extends !== null) {
            $extendsShort = $this->resolver->shortName($type->extends);
            $extends = $extendsShort;
        }

        // Strict attribute
        $strictAttr = $type->strict ? "#[Strict]\n" : '';

        // Properties
        $propertiesCode = $this->generateProperties($type);

        // Computed stubs
        $computedCode = $this->generateComputedStubs($type);

        // Hook stubs
        $hooksCode = $this->generateHookStubs($type);

        $bodyParts = array_filter([$propertiesCode, $computedCode, $hooksCode]);
        if (!empty($bodyParts)) {
            $body = "\n" . implode("\n\n", $bodyParts) . "\n";
        } else {
            $body = '';
        }

        $usesCode = $this->renderImports($imports, $namespace);

        $output = "<?php\n\nnamespace {$namespace};\n\n";

        if ($usesCode !== '') {
            $output .= "{$usesCode}\n\n";
        }

        if ($phpDoc !== '') {
            $output .= "{$phpDoc}\n";
        }

        $output .= "{$strictAttr}{$classModifier}class {$className} extends {$extends}\n";
        $output .= "{{$body}}\n";

        return $output;
    }

    private function generateProperties(TypeDefinition $type): string
    {
        $lines = [];

        foreach ($type->properties as $prop) {
            $propLines = [];

            // PHPDoc: property description and/or @var annotation
            $hasDescription = $prop->description !== null;
            $varAnnotation = $this->getPropertyVarAnnotation($prop);

            if ($hasDescription && $varAnnotation !== null) {
                $propLines[] = "    /**";
                foreach (explode("\n", $prop->description) as $descLine) {
                    $propLines[] = "     * {$descLine}";
                }
                $propLines[] = "     * {$varAnnotation}";
                $propLines[] = "     */";
            } elseif ($hasDescription) {
                $descriptionLines = explode("\n", $prop->description);
                if (count($descriptionLines) === 1) {
                    $propLines[] = "    /** {$descriptionLines[0]} */";
                } else {
                    $propLines[] = "    /**";
                    foreach ($descriptionLines as $descLine) {
                        $propLines[] = "     * {$descLine}";
                    }
                    $propLines[] = "     */";
                }
            } elseif ($varAnnotation !== null) {
                $propLines[] = "    /** {$varAnnotation} */";
            }

            // Attributes
            foreach ($this->generatePropertyAttributes($prop) as $attr) {
                $propLines[] = "    {$attr}";
            }

            // Property declaration
            $phpType = $this->resolvePhpPropertyType($prop);
            $declaration = "    public {$phpType} \${$prop->name}";

            if ($prop->hasDefault) {
                $declaration .= ' = ' . $this->renderPhpValue($prop->default);
            }

            $declaration .= ';';
            $propLines[] = $declaration;

            $lines[] = implode("\n", $propLines);
        }

        return implode("\n\n", $lines);
    }

    /**
     * @return string[]
     */
    private function generatePropertyAttributes(PropertyDefinition $prop): array
    {
        $attrs = [];

        if ($prop->mapFrom !== null) {
            $attrs[] = "#[MapFrom('{$prop->mapFrom}')]";
        }
        if ($prop->mapTo !== null) {
            $attrs[] = "#[MapTo('{$prop->mapTo}')]";
        }

        // HTTP source
        if ($prop->source === 'query') {
            $attrs[] = $prop->sourceKey !== null
                ? "#[Query('{$prop->sourceKey}')]"
                : '#[Query]';
        } elseif ($prop->source === 'body') {
            $attrs[] = $prop->sourceKey !== null
                ? "#[Body('{$prop->sourceKey}')]"
                : '#[Body]';
        }

        // Cast (for typed arrays)
        if ($prop->type === 'array' && $prop->items !== null && $this->resolver->isTypeReference($prop->items)) {
            $itemClass = $this->resolver->shortName($prop->items);
            $attrs[] = "#[Cast({$itemClass}::class)]";
        }

        // Behavior
        if (in_array('hidden', $prop->behavior, true)) $attrs[] = '#[Hidden]';
        if (in_array('masked', $prop->behavior, true)) {
            $mask = $prop->mask ?? '********';
            $attrs[] = "#[Masked('{$mask}')]";
        }
        if (in_array('initialize', $prop->behavior, true)) $attrs[] = '#[Initialize]';
        if (in_array('skipNull', $prop->behavior, true)) $attrs[] = '#[SkipNull]';

        // Validation
        foreach ($prop->validation as $rule) {
            $attrs[] = $this->renderValidationAttribute($rule);
        }

        return $attrs;
    }

    private function renderValidationAttribute(ValidationRule $rule): string
    {
        $msg = $rule->message !== null ? ", message: '{$rule->message}'" : '';

        return match ($rule->rule) {
            'email' => "#[Email({$msg})]",
            'min' => "#[Min({$rule->value}{$msg})]",
            'max' => "#[Max({$rule->value}{$msg})]",
            'regex' => "#[Regex('{$rule->pattern}'{$msg})]",
            'inArray' => sprintf(
                "#[InArray(%s%s%s)]",
                $this->renderPhpValue($rule->values),
                $rule->strict !== null ? ', strict: ' . ($rule->strict ? 'true' : 'false') : '',
                $msg
            ),
            default => "// Unknown validation rule: {$rule->rule}",
        };
    }

    private function generateComputedStubs(TypeDefinition $type): string
    {
        if (empty($type->computed)) return '';

        $stubs = [];
        foreach ($type->computed as $comp) {
            $methodName = 'get' . ucfirst($comp->name);
            $phpReturn = $this->resolver->toPhpType($comp->returnType);

            $attrs = ["    #[Computed]"];
            if ($comp->mapTo !== null) {
                $attrs[] = "    #[MapTo('{$comp->mapTo}')]";
            }

            $attrsCode = implode("\n", $attrs);

            $stubs[] = "{$attrsCode}\n"
                . "    public function {$methodName}(): {$phpReturn}\n"
                . "    {\n"
                . "        throw new \\RuntimeException('Computed method {$methodName}() not implemented');\n"
                . "    }";
        }

        return implode("\n\n", $stubs);
    }

    private function generateHookStubs(TypeDefinition $type): string
    {
        $stubs = [];

        foreach ($type->hooks['postHydrate'] as $methodName) {
            $stubs[] = "    #[PostHydrate]\n"
                . "    protected function {$methodName}(): void\n"
                . "    {\n"
                . "        throw new \\RuntimeException('Hook {$methodName}() not implemented');\n"
                . "    }";
        }

        foreach ($type->hooks['preExport'] as $methodName) {
            $stubs[] = "    #[PreExport]\n"
                . "    protected function {$methodName}(): void\n"
                . "    {\n"
                . "        throw new \\RuntimeException('Hook {$methodName}() not implemented');\n"
                . "    }";
        }

        return implode("\n\n", $stubs);
    }

    private function generateTypePhpDoc(TypeDefinition $type): string
    {
        $lines = [];

        // Class description from schema
        if ($type->description !== null) {
            foreach (explode("\n", $type->description) as $descLine) {
                $lines[] = " * {$descLine}";
            }
            // Add separator only if there will be @method lines after
            if (!empty($type->properties)) {
                $lines[] = " *";
            }
        }

        foreach ($type->properties as $prop) {
            $methodSuffix = ucfirst($prop->name);
            $getterType = $this->getPhpDocType($prop, false);
            $setterType = $this->getPhpDocType($prop, true);
            $returnType = 'self';

            $lines[] = " * @method {$getterType} get{$methodSuffix}()";
            $lines[] = " * @method {$returnType} set{$methodSuffix}({$setterType} \$value)";
        }

        if (empty($lines)) return '';

        return "/**\n" . implode("\n", $lines) . "\n */";
    }

    private function getPhpDocType(PropertyDefinition $prop, bool $isSetter): string
    {
        $types = [];

        if (is_array($prop->type)) {
            foreach ($prop->type as $t) {
                $types[] = $this->resolvePhpDocSingleType($t);
            }
        } else {
            if ($prop->type === 'array' && $prop->items !== null) {
                $itemType = $this->resolver->isTypeReference($prop->items)
                    ? '\\' . $this->resolver->schemaNameToFqcn($prop->items)
                    : $prop->items;
                $types[] = "{$itemType}[]";
            } else {
                $phpType = $this->resolvePhpDocSingleType($prop->type);
                $types[] = $phpType;
                if ($isSetter && $this->resolver->isTypeReference($prop->type)) {
                    $types[] = 'array<string, mixed>';
                }
            }
        }

        if ($prop->nullable) {
            $types[] = 'null';
        }

        return implode('|', array_unique($types));
    }

    /**
     * Resolves a schema type to a PHPDoc type string.
     * Type references become FQCN with leading backslash.
     */
    private function resolvePhpDocSingleType(string $type): string
    {
        if ($type === 'array') {
            return 'array<int|string, mixed>';
        }

        if ($this->resolver->isPrimitive($type)) {
            return $this->resolver->toPhpType($type);
        }

        if (str_starts_with($type, 'enum:')) {
            return '\\' . $this->resolver->schemaNameToFqcn(substr($type, 5));
        }

        return '\\' . $this->resolver->schemaNameToFqcn($type);
    }

    // ─── Collection Generation ───

    private function generateCollection(CollectionDefinition $coll): string
    {
        $namespace = $this->resolveNamespace($coll->name);
        $className = $this->resolver->shortName($coll->name);
        $hasTypedItem = $coll->itemType !== 'any' && $this->resolver->isTypeReference($coll->itemType);

        $imports = [
            'DevBX\\DTO\\BaseCollection',
        ];

        if ($hasTypedItem) {
            $imports[] = 'DevBX\\DTO\\Attributes\\CollectionType';
            $itemNs = $this->resolveNamespace($coll->itemType);
            if ($itemNs !== $namespace) {
                $imports[] = $this->resolver->schemaNameToFqcn($coll->itemType);
            }
        }

        $usesCode = $this->renderImports($imports, $namespace);

        $output = "<?php\n\nnamespace {$namespace};\n\n";

        if ($usesCode !== '') {
            $output .= "{$usesCode}\n\n";
        }

        $docLines = [];
        if ($coll->description !== null) {
            foreach (explode("\n", $coll->description) as $descLine) {
                $docLines[] = " * {$descLine}";
            }
        }
        if ($hasTypedItem) {
            $itemClass = $this->resolver->shortName($coll->itemType);
            $itemFqcn = '\\' . $this->resolver->schemaNameToFqcn($coll->itemType);
            if (!empty($docLines)) {
                $docLines[] = " *";
            }
            $docLines[] = " * @extends BaseCollection<{$itemFqcn}>";
        }
        if (!empty($docLines)) {
            $output .= "/**\n" . implode("\n", $docLines) . "\n */\n";
        }
        if ($hasTypedItem) {
            $output .= "#[CollectionType({$itemClass}::class)]\n";
        }

        $output .= "class {$className} extends BaseCollection\n{\n}\n";

        return $output;
    }

    /**
     * Returns @var annotation for a property, or null if not needed.
     * Typed arrays get "@var TypeName[]", untyped arrays get "@var array<string, mixed>".
     */
    private function getPropertyVarAnnotation(PropertyDefinition $prop): ?string
    {
        // Typed array with item type reference → @var TypeName[]
        if ($prop->type === 'array' && $prop->items !== null && $this->resolver->isTypeReference($prop->items)) {
            $itemClass = $this->resolver->shortName($prop->items);
            return "@var {$itemClass}[]";
        }

        // Untyped array (no items or primitive items) → @var array<string, mixed> for PHPStan
        $hasUntypedArray = false;
        if (is_array($prop->type)) {
            $hasUntypedArray = in_array('array', $prop->type, true);
        } elseif ($prop->type === 'array') {
            $hasUntypedArray = true;
        }

        if ($hasUntypedArray) {
            $varType = $this->getPhpDocType($prop, false);
            return "@var {$varType}";
        }

        return null;
    }

    // ─── Helpers ───

    private function resolveNamespace(string $schemaName): string
    {
        $nsPart = $this->resolver->namespacePart($schemaName);
        $relative = $nsPart !== null ? str_replace('.', '\\', $nsPart) : '';

        return $relative !== ''
            ? $this->baseNamespace . '\\' . $relative
            : $this->baseNamespace;
    }

    private function resolvePhpPropertyType(PropertyDefinition $prop): string
    {
        if (is_array($prop->type)) {
            $types = array_map(fn(string $t) => $this->resolveShortPhpType($t), $prop->type);
            if ($prop->nullable) $types[] = 'null';
            return implode('|', $types);
        }

        if ($prop->type === 'array') {
            return $prop->nullable ? '?array' : 'array';
        }

        $resolved = $this->resolveShortPhpType($prop->type);
        if ($prop->nullable) {
            return '?' . $resolved;
        }
        return $resolved;
    }

    /**
     * Resolves a schema type to a short PHP type name (suitable for property declarations).
     * Type references become short names because they are imported via `use`.
     */
    private function resolveShortPhpType(string $type): string
    {
        if ($this->resolver->isPrimitive($type) || $type === 'array') {
            return $this->resolver->toPhpType($type);
        }

        // Enum or type reference — use short name (class is imported via `use`)
        if (str_starts_with($type, 'enum:')) {
            return $this->resolver->shortName(substr($type, 5));
        }

        return $this->resolver->shortName($type);
    }

    /**
     * @return string[]
     */
    private function collectImports(TypeDefinition $type): array
    {
        $imports = [];

        // Base class
        if ($type->extends !== null) {
            $extendsNs = $this->resolveNamespace($type->extends);
            $currentNs = $this->resolveNamespace($type->name);
            if ($extendsNs !== $currentNs) {
                $imports[] = $this->resolver->schemaNameToFqcn($type->extends);
            }
        } else {
            $imports[] = 'DevBX\\DTO\\BaseDTO';
        }

        // Strict
        if ($type->strict) {
            $imports[] = 'DevBX\\DTO\\Attributes\\Behavior\\Strict';
        }

        $needsCast = false;
        $needsMapFrom = false;
        $needsMapTo = false;
        $needsQuery = false;
        $needsBody = false;
        $needsHidden = false;
        $needsMasked = false;
        $needsInitialize = false;
        $needsSkipNull = false;
        $validationImports = [];

        foreach ($type->properties as $prop) {
            if ($prop->mapFrom !== null) $needsMapFrom = true;
            if ($prop->mapTo !== null) $needsMapTo = true;
            if ($prop->source === 'query') $needsQuery = true;
            if ($prop->source === 'body') $needsBody = true;
            if (in_array('hidden', $prop->behavior, true)) $needsHidden = true;
            if (in_array('masked', $prop->behavior, true)) $needsMasked = true;
            if (in_array('initialize', $prop->behavior, true)) $needsInitialize = true;
            if (in_array('skipNull', $prop->behavior, true)) $needsSkipNull = true;

            if ($prop->type === 'array' && $prop->items !== null && $this->resolver->isTypeReference($prop->items)) {
                $needsCast = true;
                $this->addTypeImport($imports, $prop->items, $type->name);
            }

            if (is_string($prop->type) && $this->resolver->isTypeReference($prop->type)) {
                $this->addTypeImport($imports, $prop->type, $type->name);
            }

            foreach ($prop->validation as $rule) {
                $validationImports[$rule->rule] = true;
            }
        }

        if ($needsCast) $imports[] = 'DevBX\\DTO\\Attributes\\Cast';
        if ($needsMapFrom) $imports[] = 'DevBX\\DTO\\Attributes\\Mapping\\MapFrom';
        if ($needsMapTo) $imports[] = 'DevBX\\DTO\\Attributes\\Mapping\\MapTo';
        if ($needsQuery) $imports[] = 'DevBX\\DTO\\Attributes\\Mapping\\Query';
        if ($needsBody) $imports[] = 'DevBX\\DTO\\Attributes\\Mapping\\Body';
        if ($needsHidden) $imports[] = 'DevBX\\DTO\\Attributes\\Behavior\\Hidden';
        if ($needsMasked) $imports[] = 'DevBX\\DTO\\Attributes\\Behavior\\Masked';
        if ($needsInitialize) $imports[] = 'DevBX\\DTO\\Attributes\\Behavior\\Initialize';
        if ($needsSkipNull) $imports[] = 'DevBX\\DTO\\Attributes\\Behavior\\SkipNull';

        if (isset($validationImports['email'])) $imports[] = 'DevBX\\DTO\\Attributes\\Validation\\Email';
        if (isset($validationImports['min'])) $imports[] = 'DevBX\\DTO\\Attributes\\Validation\\Min';
        if (isset($validationImports['max'])) $imports[] = 'DevBX\\DTO\\Attributes\\Validation\\Max';
        if (isset($validationImports['regex'])) $imports[] = 'DevBX\\DTO\\Attributes\\Validation\\Regex';
        if (isset($validationImports['inArray'])) $imports[] = 'DevBX\\DTO\\Attributes\\Validation\\InArray';

        // Computed/hooks imports
        if (!empty($type->computed)) {
            $imports[] = 'DevBX\\DTO\\Attributes\\Mapping\\Computed';
            foreach ($type->computed as $comp) {
                if ($comp->mapTo !== null) $needsMapTo = true;
            }
            if ($needsMapTo && !in_array('DevBX\\DTO\\Attributes\\Mapping\\MapTo', $imports, true)) {
                $imports[] = 'DevBX\\DTO\\Attributes\\Mapping\\MapTo';
            }
        }
        if (!empty($type->hooks['postHydrate'])) $imports[] = 'DevBX\\DTO\\Attributes\\Lifecycle\\PostHydrate';
        if (!empty($type->hooks['preExport'])) $imports[] = 'DevBX\\DTO\\Attributes\\Lifecycle\\PreExport';

        return array_unique($imports);
    }

    private function addTypeImport(array &$imports, string $schemaType, string $currentType): void
    {
        $type = $schemaType;
        if (str_starts_with($type, 'enum:')) {
            $type = substr($type, 5);
        }

        $refNs = $this->resolveNamespace($type);
        $currentNs = $this->resolveNamespace($currentType);

        if ($refNs !== $currentNs) {
            $imports[] = $this->resolver->schemaNameToFqcn($type);
        }
    }

    private function renderImports(array $imports, string $currentNamespace): string
    {
        $filtered = array_filter($imports, function (string $fqcn) use ($currentNamespace) {
            $ns = substr($fqcn, 0, strrpos($fqcn, '\\') ?: 0);
            return $ns !== $currentNamespace;
        });

        $unique = array_unique($filtered);
        sort($unique);

        if (empty($unique)) return '';

        return implode("\n", array_map(fn(string $fqcn) => "use {$fqcn};", $unique));
    }

    private function renderPhpValue(mixed $value): string
    {
        if ($value === null) return 'null';
        if ($value === true) return 'true';
        if ($value === false) return 'false';
        if (is_int($value) || is_float($value)) return (string)$value;
        if (is_string($value)) return "'" . addslashes($value) . "'";
        if (is_array($value)) {
            if (empty($value)) return '[]';
            $items = [];
            $isList = array_is_list($value);
            foreach ($value as $k => $v) {
                $items[] = $isList
                    ? $this->renderPhpValue($v)
                    : "'" . addslashes((string)$k) . "' => " . $this->renderPhpValue($v);
            }
            return '[' . implode(', ', $items) . ']';
        }
        return 'null';
    }

    private function writeFile(string $targetDir, string $schemaName, string $content): string
    {
        $relativePath = str_replace('.', DIRECTORY_SEPARATOR, $schemaName) . '.php';
        $fullPath = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativePath;

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($fullPath, $content);

        return $fullPath;
    }
}

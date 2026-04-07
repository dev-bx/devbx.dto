<?php

namespace DevBX\DTO\Schema;

use DevBX\DTO\BaseDTO;
use DevBX\DTO\BaseCollection;
use DevBX\DTO\Attributes\Cast;
use DevBX\DTO\Attributes\CollectionType;
use DevBX\DTO\Attributes\Mapping\MapFrom;
use DevBX\DTO\Attributes\Mapping\MapTo;
use DevBX\DTO\Attributes\Mapping\Computed;
use DevBX\DTO\Attributes\Mapping\Body;
use DevBX\DTO\Attributes\Mapping\Query;
use DevBX\DTO\Attributes\Behavior\Hidden;
use DevBX\DTO\Attributes\Behavior\Masked;
use DevBX\DTO\Attributes\Behavior\Initialize;
use DevBX\DTO\Attributes\Behavior\SkipNull;
use DevBX\DTO\Attributes\Behavior\Strict;
use DevBX\DTO\Attributes\Lifecycle\PostHydrate;
use DevBX\DTO\Attributes\Lifecycle\PreExport;
use DevBX\DTO\Attributes\Validation\Email;
use DevBX\DTO\Attributes\Validation\Min;
use DevBX\DTO\Attributes\Validation\Max;
use DevBX\DTO\Attributes\Validation\Regex;
use DevBX\DTO\Attributes\Validation\InArray;
use DevBX\DTO\Attributes\Validation\ValidationRuleInterface;
use DevBX\DTO\Schema\Model\SchemaDefinition;
use DevBX\DTO\Schema\Model\PackageInfo;
use DevBX\DTO\Schema\Model\TypeDefinition;
use DevBX\DTO\Schema\Model\PropertyDefinition;
use DevBX\DTO\Schema\Model\ComputedDefinition;
use DevBX\DTO\Schema\Model\ValidationRule;
use DevBX\DTO\Schema\Model\EnumDefinition;
use DevBX\DTO\Schema\Model\CollectionDefinition;
use ReflectionClass;
use ReflectionProperty;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

class SchemaExporter
{
    private string $baseNamespace;
    private string $namespacePrefix;

    /** @var array<string, string> FQCN => dot-separated schema name */
    private array $classMap = [];

    public function __construct(string $baseNamespace)
    {
        $this->baseNamespace = rtrim($baseNamespace, '\\');
        $this->namespacePrefix = $this->baseNamespace . '\\';
    }

    /**
     * Exports PHP DTO classes from a directory into a SchemaDefinition.
     *
     * @param string $directory Directory to scan
     * @param string $packageName Package name for schema
     * @param string $packageVersion Package version
     * @return SchemaDefinition
     */
    public function export(string $directory, string $packageName, string $packageVersion = '1.0.0'): SchemaDefinition
    {
        $this->loadClasses($directory);
        $this->buildClassMap();

        $types = [];
        $enums = [];
        $collections = [];

        /** @var class-string $fqcn */
        foreach ($this->classMap as $fqcn => $schemaName) {
            $reflection = new ReflectionClass($fqcn);

            if ($reflection->isSubclassOf(BaseCollection::class)) {
                $collections[$schemaName] = $this->exportCollection($reflection, $schemaName);
            } elseif ($reflection->isSubclassOf(\BackedEnum::class)) {
                $enums[$schemaName] = $this->exportEnum($reflection, $schemaName);
            } elseif ($reflection->isSubclassOf(BaseDTO::class)) {
                $types[$schemaName] = $this->exportType($reflection, $schemaName);
            }
        }

        $package = new PackageInfo(
            name: $packageName,
            version: $packageVersion,
            codeGen: [
                'php' => ['namespace' => $this->baseNamespace],
            ]
        );

        return new SchemaDefinition(
            package: $package,
            enums: $enums,
            types: $types,
            collections: $collections
        );
    }

    /**
     * Loads all PHP files from directory by registering a PSR-4 autoloader
     * and then explicitly loading all files (respecting class dependencies).
     */
    private function loadClasses(string $directory): void
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Directory not found: {$directory}");
        }

        $baseNs = $this->namespacePrefix;
        $baseDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $directory), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        // Register a PSR-4 autoloader so parent classes resolve automatically
        spl_autoload_register(function (string $class) use ($baseNs, $baseDir) {
            if (!str_starts_with($class, $baseNs)) {
                return;
            }
            $relative = substr($class, strlen($baseNs));
            $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });

        // Now load all files — autoloader will handle dependency order
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                require_once $file->getPathname();
            }
        }
    }

    /**
     * Builds a map of FQCN => schema dot-name for all classes in our namespace.
     */
    private function buildClassMap(): void
    {
        $this->classMap = [];

        foreach (get_declared_classes() as $class) {
            if (str_starts_with($class, $this->namespacePrefix)) {
                $relative = substr($class, strlen($this->namespacePrefix));
                $schemaName = str_replace('\\', '.', $relative);
                $this->classMap[$class] = $schemaName;
            }
        }

        // Also check enums
        foreach (get_declared_classes() as $class) {
            if (str_starts_with($class, $this->namespacePrefix) && is_subclass_of($class, \BackedEnum::class)) {
                $relative = substr($class, strlen($this->namespacePrefix));
                $this->classMap[$class] = str_replace('\\', '.', $relative);
            }
        }
    }

    /**
     * Resolves a FQCN to its schema dot-name, or returns the type as-is for primitives.
     */
    private function resolveTypeReference(string $fqcn): ?string
    {
        if (isset($this->classMap[$fqcn])) {
            return $this->classMap[$fqcn];
        }

        return null;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function exportType(ReflectionClass $reflection, string $schemaName): TypeDefinition
    {
        $properties = [];
        $computed = [];
        $hooks = ['postHydrate' => [], 'preExport' => []];

        // Properties
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) continue;
            if ($prop->getDeclaringClass()->getName() !== $reflection->getName()) continue;

            $properties[$prop->getName()] = $this->exportProperty($prop);
        }

        // Methods: computed and hooks
        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) continue;

            if (!empty($method->getAttributes(Computed::class))) {
                $computed[$this->computedName($method)] = $this->exportComputed($method);
            }

            if (!empty($method->getAttributes(PostHydrate::class))) {
                $hooks['postHydrate'][] = $method->getName();
            }

            if (!empty($method->getAttributes(PreExport::class))) {
                $hooks['preExport'][] = $method->getName();
            }
        }

        // Extends
        $extends = null;
        $parent = $reflection->getParentClass();
        if ($parent && $parent->getName() !== BaseDTO::class) {
            $extends = $this->resolveTypeReference($parent->getName());
        }

        return new TypeDefinition(
            name: $schemaName,
            description: $this->extractDescription($reflection),
            abstract: $reflection->isAbstract(),
            extends: $extends,
            strict: !empty($reflection->getAttributes(Strict::class)),
            properties: $properties,
            computed: $computed,
            hooks: $hooks
        );
    }

    private function exportProperty(ReflectionProperty $prop): PropertyDefinition
    {
        $type = $this->resolvePropertyType($prop);
        $items = $this->resolveArrayItems($prop);
        $nullable = false;
        $propType = $prop->getType();

        if ($propType === null) {
            $nullable = true;
        } elseif ($propType instanceof ReflectionNamedType) {
            $nullable = $propType->allowsNull();
        } elseif ($propType instanceof ReflectionUnionType) {
            $nullable = $propType->allowsNull();
        }

        // Default value
        $hasDefault = $prop->hasDefaultValue();
        $default = $hasDefault ? $prop->getDefaultValue() : null;

        // MapFrom / MapTo
        $mapFrom = $this->getAttributeArg($prop, MapFrom::class, 'key');
        $mapTo = $this->getAttributeArg($prop, MapTo::class, 'key');

        // HTTP source
        $source = null;
        $sourceKey = null;
        if (!empty($prop->getAttributes(Query::class))) {
            $source = 'query';
            $sourceKey = $this->getAttributeArg($prop, Query::class, 'key');
        } elseif (!empty($prop->getAttributes(Body::class))) {
            $source = 'body';
            $sourceKey = $this->getAttributeArg($prop, Body::class, 'key');
        }

        // Behavior
        $behavior = [];
        if (!empty($prop->getAttributes(Hidden::class))) $behavior[] = 'hidden';
        if (!empty($prop->getAttributes(Masked::class))) $behavior[] = 'masked';
        if (!empty($prop->getAttributes(Initialize::class))) $behavior[] = 'initialize';
        if (!empty($prop->getAttributes(SkipNull::class))) $behavior[] = 'skipNull';

        $mask = null;
        $maskedAttrs = $prop->getAttributes(Masked::class);
        if (!empty($maskedAttrs)) {
            $mask = $maskedAttrs[0]->newInstance()->mask;
        }

        // Validation
        $validation = $this->exportValidation($prop);

        return new PropertyDefinition(
            name: $prop->getName(),
            type: $type,
            items: $items,
            nullable: $nullable,
            hasDefault: $hasDefault,
            default: $default,
            mapFrom: $mapFrom,
            mapTo: $mapTo,
            source: $source,
            sourceKey: $sourceKey,
            behavior: $behavior,
            mask: $mask,
            validation: $validation,
            description: $this->extractDescription($prop)
        );
    }

    /**
     * Resolves the schema type for a property.
     *
     * @return string|string[]
     */
    private function resolvePropertyType(ReflectionProperty $prop): string|array
    {
        $propType = $prop->getType();

        if ($propType === null) {
            return 'any';
        }

        if ($propType instanceof ReflectionNamedType) {
            return $this->mapPhpType($propType);
        }

        if ($propType instanceof ReflectionUnionType) {
            $types = [];
            foreach ($propType->getTypes() as $t) {
                if ($t instanceof ReflectionNamedType) {
                    $name = $t->getName();
                    if ($name === 'null') continue;
                    $types[] = $this->mapPhpType($t);
                }
            }
            return count($types) === 1 ? $types[0] : $types;
        }

        return 'any';
    }

    private function mapPhpType(ReflectionNamedType $type): string
    {
        $name = $type->getName();

        if ($type->isBuiltin()) {
            return match ($name) {
                'int', 'string', 'float', 'bool' => $name,
                'array' => 'array',
                default => 'any',
            };
        }

        // DateTime family
        if (is_a($name, \DateTimeInterface::class, true)) {
            return 'datetime';
        }

        // BackedEnum
        if (is_subclass_of($name, \BackedEnum::class)) {
            $ref = $this->resolveTypeReference($name);
            return $ref ? "enum:{$ref}" : 'string';
        }

        // DTO or Collection reference
        $ref = $this->resolveTypeReference($name);
        if ($ref !== null) {
            return $ref;
        }

        // Unknown class — use 'any'
        return 'any';
    }

    /**
     * Resolves array item type from #[Cast] attribute or PHPDoc.
     */
    private function resolveArrayItems(ReflectionProperty $prop): ?string
    {
        $propType = $prop->getType();

        // Only relevant for array type or collection subclass
        $isArray = $propType instanceof ReflectionNamedType && $propType->getName() === 'array';
        $isCollection = $propType instanceof ReflectionNamedType
            && !$propType->isBuiltin()
            && is_subclass_of($propType->getName(), BaseCollection::class);

        if (!$isArray && !$isCollection) {
            return null;
        }

        // Check #[Cast] attribute
        $castAttrs = $prop->getAttributes(Cast::class);
        if (!empty($castAttrs)) {
            $castClass = $castAttrs[0]->newInstance()->className;
            $ref = $this->resolveTypeReference($castClass);
            return $ref ?? 'any';
        }

        // Check PHPDoc @var Type[]
        $docComment = $prop->getDocComment();
        if ($docComment && preg_match('/@var\s+(\S+)\[\]/', $docComment, $matches)) {
            $docType = $matches[1];
            // Try to resolve as class in current namespace
            if (!str_contains($docType, '\\')) {
                $ns = $prop->getDeclaringClass()->getNamespaceName();
                $fqcn = $ns . '\\' . $docType;
                if (class_exists($fqcn)) {
                    $ref = $this->resolveTypeReference($fqcn);
                    if ($ref) return $ref;
                }
            }
            // Primitive types from PHPDoc
            return match ($docType) {
                'int', 'string', 'float', 'bool' => $docType,
                'mixed' => 'any',
                default => 'any',
            };
        }

        return null;
    }

    private function exportComputed(ReflectionMethod $method): ComputedDefinition
    {
        $name = $this->computedName($method);

        $returnType = 'any';
        $rt = $method->getReturnType();
        if ($rt instanceof ReflectionNamedType) {
            $returnType = $this->mapPhpType($rt);
        }

        $mapTo = $this->getMethodAttributeArg($method, MapTo::class, 'key');

        return new ComputedDefinition(
            name: $name,
            returnType: $returnType,
            mapTo: $mapTo
        );
    }

    private function computedName(ReflectionMethod $method): string
    {
        $name = $method->getName();
        if (str_starts_with($name, 'get') && strlen($name) > 3) {
            return lcfirst(substr($name, 3));
        }
        return $name;
    }

    /**
     * @return ValidationRule[]
     */
    private function exportValidation(ReflectionProperty $prop): array
    {
        $rules = [];

        foreach ($prop->getAttributes(ValidationRuleInterface::class, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
            $instance = $attr->newInstance();
            $className = get_class($instance);

            $rule = match (true) {
                $instance instanceof Email => new ValidationRule(
                    rule: 'email',
                    message: $instance->message ?? null
                ),
                $instance instanceof Min => new ValidationRule(
                    rule: 'min',
                    value: $instance->minValue,
                    message: $instance->message ?? null
                ),
                $instance instanceof Max => new ValidationRule(
                    rule: 'max',
                    value: $instance->maxValue,
                    message: $instance->message ?? null
                ),
                $instance instanceof Regex => new ValidationRule(
                    rule: 'regex',
                    pattern: $instance->pattern,
                    message: $instance->message ?? null
                ),
                $instance instanceof InArray => new ValidationRule(
                    rule: 'inArray',
                    values: $instance->allowedValues,
                    strict: $instance->strict,
                    message: $instance->message ?? null
                ),
                default => null,
            };

            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function exportCollection(ReflectionClass $reflection, string $schemaName): CollectionDefinition
    {
        $itemType = 'any';

        $collAttrs = $reflection->getAttributes(CollectionType::class);
        if (!empty($collAttrs)) {
            $className = $collAttrs[0]->newInstance()->className;
            $ref = $this->resolveTypeReference($className);
            if ($ref !== null) {
                $itemType = $ref;
            }
        }

        return new CollectionDefinition(
            name: $schemaName,
            itemType: $itemType,
            description: $this->extractDescription($reflection)
        );
    }

    /**
     * @param ReflectionClass<\BackedEnum> $reflection
     */
    private function exportEnum(ReflectionClass $reflection, string $schemaName): EnumDefinition
    {
        $backingType = 'string';
        $values = [];

        /** @var class-string<\BackedEnum> $enumClass */
        $enumClass = $reflection->getName();
        $cases = $enumClass::cases();
        if (!empty($cases)) {
            $first = $cases[0];
            $backingType = is_int($first->value) ? 'int' : 'string';

            foreach ($cases as $case) {
                $values[$case->name] = $case->value;
            }
        }

        return new EnumDefinition(
            name: $schemaName,
            backingType: $backingType,
            values: $values
        );
    }

    private function getAttributeArg(ReflectionProperty $prop, string $attrClass, string $property): ?string
    {
        $attrs = $prop->getAttributes($attrClass);
        if (empty($attrs)) return null;

        $instance = $attrs[0]->newInstance();
        return $instance->{$property} ?? null;
    }

    private function getMethodAttributeArg(ReflectionMethod $method, string $attrClass, string $property): ?string
    {
        $attrs = $method->getAttributes($attrClass);
        if (empty($attrs)) return null;

        $instance = $attrs[0]->newInstance();
        return $instance->{$property} ?? null;
    }

    /**
     * Extracts human-readable description from PHPDoc comment.
     * For classes: keeps only plain text lines, filters out @-tags.
     * For properties: also extracts inline description from @var tag
     * (e.g. "@var string|null Some description" → "Some description").
     *
     * @param ReflectionClass<object>|ReflectionProperty $reflector
     */
    private function extractDescription(ReflectionClass|ReflectionProperty $reflector): ?string
    {
        $docComment = $reflector->getDocComment();
        if ($docComment === false || $docComment === '') {
            return null;
        }

        $isProperty = $reflector instanceof ReflectionProperty;
        $lines = explode("\n", $docComment);
        $textLines = [];

        foreach ($lines as $line) {
            // Strip leading whitespace, * prefix, trailing */ and whitespace
            $cleaned = preg_replace('/^\s*\/?\*+\/?/', '', $line);
            $cleaned = preg_replace('/\s*\*+\/\s*$/', '', $cleaned);
            $cleaned = trim($cleaned);

            // Skip empty lines from doc delimiters
            if ($cleaned === '' || $cleaned === '/') {
                continue;
            }

            // For properties: extract description from @var line
            if ($isProperty && str_starts_with($cleaned, '@var ')) {
                $varDescription = $this->extractVarDescription($cleaned);
                if ($varDescription !== null) {
                    $textLines[] = $varDescription;
                }
                continue;
            }

            // Skip any other @-tag lines
            if (str_starts_with($cleaned, '@')) {
                continue;
            }

            $textLines[] = $cleaned;
        }

        if (empty($textLines)) {
            return null;
        }

        return implode("\n", $textLines);
    }

    /**
     * Extracts description text from a @var PHPDoc line.
     * "@var string|null Some description" → "Some description"
     * "@var array<int, string>|null Some text" → "Some text"
     * "@var ChoiceDTO[]" → null (no description)
     */
    private function extractVarDescription(string $varLine): ?string
    {
        // Remove "@var " prefix
        $rest = substr($varLine, 5);

        // Skip the type expression which may contain generic syntax with spaces
        // e.g. "array<int, string>|null", "array<string, array<string, string>>"
        $pos = 0;
        $len = strlen($rest);
        $depth = 0;

        while ($pos < $len) {
            $char = $rest[$pos];
            if ($char === '<') {
                $depth++;
            } elseif ($char === '>') {
                $depth--;
            } elseif ($char === ' ' && $depth === 0) {
                // Found space outside of generics — rest is description
                break;
            }
            $pos++;
        }

        if ($pos >= $len) {
            return null;
        }

        $description = trim(substr($rest, $pos));
        return $description !== '' ? $description : null;
    }
}

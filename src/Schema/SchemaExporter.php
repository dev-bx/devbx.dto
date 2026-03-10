<?php

namespace DevBX\DTO\Schema;

use DevBX\DTO\BaseCollection;
use DevBX\DTO\Attributes\Cast;
use DevBX\DTO\Attributes\CollectionType;
use DevBX\DTO\Attributes\Behavior\Hidden;
use DevBX\DTO\Attributes\Behavior\Masked;
use DevBX\DTO\Attributes\Mapping\Computed;
use DevBX\DTO\Attributes\Mapping\MapTo;
use DevBX\DTO\Attributes\Validation\Min;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionMethod;
use ReflectionUnionType;

class SchemaExporter implements SchemaExporterInterface
{
    /**
     * Анализирует класс DTO и возвращает языково-независимый массив схемы.
     * @param class-string $className
     */
    public function export(string $className): array
    {
        if (!class_exists($className)) {
            throw new \InvalidArgumentException("Class {$className} does not exist.");
        }

        $reflection = new ReflectionClass($className);

        $classInfo = $this->parseNamespaceAndName($className);
        $parentClass = $reflection->getParentClass();
        $extends = $parentClass ? $this->parseNamespaceAndName($parentClass->getName())['name'] : null;

        $imports = [];
        $properties = [];

        // 1. Сбор обычных свойств
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            // Игнорируем статические свойства и свойства с атрибутом Hidden
            if ($prop->isStatic() || !empty($prop->getAttributes(Hidden::class))) {
                continue;
            }

            // Учитываем кастомный маппинг имени ключа
            $mapToAttrs = $prop->getAttributes(MapTo::class);
            $propName = !empty($mapToAttrs) ? $mapToAttrs[0]->newInstance()->key : $prop->getName();

            $properties[$propName] = $this->exportProperty($prop, $imports);
        }

        // 2. Сбор вычисляемых свойств (Computed Properties)
        foreach ($reflection->getMethods() as $method) {
            if (!empty($method->getAttributes(Computed::class))) {
                $methodName = $method->getName();

                // Отрезаем get
                if (str_starts_with($methodName, 'get') && strlen($methodName) > 3) {
                    $baseName = lcfirst(substr($methodName, 3));
                } else {
                    $baseName = $methodName;
                }

                $mapToAttrs = $method->getAttributes(MapTo::class);
                $propName = !empty($mapToAttrs) ? $mapToAttrs[0]->newInstance()->key : $baseName;

                $properties[$propName] = $this->exportMethodAsProperty($method, $imports);
            }
        }

        // Дедупликация импортов
        $uniqueImports = [];
        foreach ($imports as $import) {
            $key = $import['module'] . '\\' . $import['name'];
            $selfKey = $classInfo['module'] . '\\' . $classInfo['name'];

            if ($key !== $selfKey) {
                $uniqueImports[$key] = $import;
            }
        }

        return [
            '$schema' => 'https://ваша-компания.ru/schema/agnostic-dto-schema.json',
            'name' => $classInfo['name'],
            'module' => $classInfo['module'],
            'extends' => $extends,
            'description' => $this->parseDocComment($reflection->getDocComment()),
            'imports' => array_values($uniqueImports),
            'properties' => $properties,
        ];
    }

    /**
     * Анализирует отдельное свойство и формирует его метаданные.
     */
    private function exportProperty(ReflectionProperty $prop, array &$imports): array
    {
        $typeSchema = $this->extractTypeSchema($prop, $imports);

        $default = null;
        if ($prop->hasDefaultValue()) {
            $val = $prop->getDefaultValue();
            if ($val instanceof \BackedEnum || $val instanceof \UnitEnum) {
                $default = $val->name;
            } else {
                $default = $val;
            }
        } elseif (in_array('collection', $typeSchema['type'], true) || in_array('array', $typeSchema['type'], true)) {
            $default = [];
        }

        $schema = [
            'type' => $typeSchema['type'],
            'isNullable' => $typeSchema['isNullable'],
            'default' => $default,
            'description' => $this->parseDocComment($prop->getDocComment()),
        ];

        // Маскирование данных
        if (!empty($prop->getAttributes(Masked::class))) {
            $schema['isMasked'] = true;
        }

        // Интеграция правил валидации
        $minAttrs = $prop->getAttributes(Min::class);
        if (!empty($minAttrs)) {
            $minValue = $minAttrs[0]->newInstance()->minValue;
            if (in_array('integer', $typeSchema['type'], true) || in_array('number', $typeSchema['type'], true)) {
                $schema['minimum'] = $minValue;
            } elseif (in_array('string', $typeSchema['type'], true)) {
                $schema['minLength'] = $minValue;
            } elseif (in_array('array', $typeSchema['type'], true) || in_array('collection', $typeSchema['type'], true)) {
                $schema['minItems'] = $minValue;
            }
        }

        if ($typeSchema['isEnum']) {
            $schema['isEnum'] = true;
        }

        if ($typeSchema['items']) {
            $schema['items'] = $typeSchema['items'];
        }

        return $schema;
    }

    /**
     * Преобразует Computed-метод в readOnly свойство схемы.
     */
    private function exportMethodAsProperty(ReflectionMethod $method, array &$imports): array
    {
        $typeSchema = $this->extractTypeSchema($method, $imports);

        $schema = [
            'type' => $typeSchema['type'],
            'isNullable' => $typeSchema['isNullable'],
            'readOnly' => true, // Обязательный флаг для вычисляемых свойств
            'description' => $this->parseDocComment($method->getDocComment()),
        ];

        if ($typeSchema['isEnum']) {
            $schema['isEnum'] = true;
        }
        if ($typeSchema['items']) {
            $schema['items'] = $typeSchema['items'];
        }

        return $schema;
    }

    /**
     * Определяет языково-независимые типы и собирает импорты для свойств и методов.
     */
    private function extractTypeSchema(ReflectionProperty|ReflectionMethod $reflector, array &$imports): array
    {
        $reflectionType = $reflector instanceof ReflectionProperty ? $reflector->getType() : $reflector->getReturnType();

        $types = [];
        $isNullable = false;
        $isEnum = false;
        $items = null;

        if (!$reflectionType) {
            return ['type' => ['any'], 'isNullable' => true, 'isEnum' => false, 'items' => null];
        }

        $processNamedType = function (ReflectionNamedType $t) use ($reflector, &$imports, &$isEnum, &$items) {
            $name = $t->getName();

            if ($t->isBuiltin()) {
                $mapped = match ($name) {
                    'int' => 'integer',
                    'float' => 'number',
                    'bool' => 'boolean',
                    default => $name
                };

                if ($mapped === 'array') {
                    $itemsClass = $this->resolveItemsType($reflector, null);
                    if ($itemsClass) {
                        $info = $this->parseNamespaceAndName($itemsClass);
                        $imports[] = $info;
                        $items = $info['name'];
                    }
                }

                return $mapped;
            }

            if (is_subclass_of($name, \BackedEnum::class) || is_subclass_of($name, \UnitEnum::class)) {
                $isEnum = true;
                $info = $this->parseNamespaceAndName($name);
                $imports[] = $info;
                return $info['name'];
            }

            if (is_subclass_of($name, BaseCollection::class)) {
                $itemsClass = $this->resolveItemsType($reflector, $name);
                if ($itemsClass) {
                    $info = $this->parseNamespaceAndName($itemsClass);
                    $imports[] = $info;
                    $items = $info['name'];
                }

                $info = $this->parseNamespaceAndName($name);
                $imports[] = $info;
                return 'collection';
            }

            $info = $this->parseNamespaceAndName($name);
            $imports[] = $info;
            return $info['name'];
        };

        if ($reflectionType instanceof ReflectionNamedType) {
            $types[] = $processNamedType($reflectionType);
            $isNullable = $reflectionType->allowsNull();
        } elseif ($reflectionType instanceof ReflectionUnionType) {
            foreach ($reflectionType->getTypes() as $t) {
                if ($t instanceof ReflectionNamedType) {
                    $types[] = $processNamedType($t);
                }
                if ($t->allowsNull()) {
                    $isNullable = true;
                }
            }
        }

        return [
            'type' => array_values(array_unique($types)),
            'isNullable' => $isNullable,
            'isEnum' => $isEnum,
            'items' => $items
        ];
    }

    /**
     * Пытается найти тип дочерних элементов для массивов и коллекций.
     */
    private function resolveItemsType(ReflectionProperty|ReflectionMethod $reflector, ?string $collectionClass): ?string
    {
        // Читаем атрибут Cast (может быть как на свойстве, так и на методе)
        $attributes = $reflector->getAttributes(Cast::class);
        if (!empty($attributes)) {
            return $attributes[0]->newInstance()->className;
        }

        if ($collectionClass && class_exists($collectionClass)) {
            $reflection = new ReflectionClass($collectionClass);
            $attributes = $reflection->getAttributes(CollectionType::class);
            if (!empty($attributes)) {
                return $attributes[0]->newInstance()->className;
            }
        }

        return null;
    }

    private function parseNamespaceAndName(string $fqcn): array
    {
        $parts = explode('\\', trim($fqcn, '\\'));
        $name = array_pop($parts);
        $module = empty($parts) ? 'Global' : end($parts);

        return [
            'name' => $name,
            'module' => $module
        ];
    }

    private function parseDocComment(string|false|null $docComment): ?string
    {
        if (!$docComment) {
            return null;
        }

        $lines = explode("\n", $docComment);
        $cleanLines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            $line = preg_replace('/^\/\*\*|^\*\/|^\*\s?/', '', $line);
            $line = trim($line);

            if ($line !== '' && !str_starts_with($line, '@')) {
                $cleanLines[] = $line;
            }
        }

        return empty($cleanLines) ? null : implode("\n", $cleanLines);
    }
}

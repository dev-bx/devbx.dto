<?php

namespace Local\Lib\DTO\Schema;

use Local\Lib\DTO\BaseCollection;
use Local\Lib\DTO\Attributes\Cast;
use Local\Lib\DTO\Attributes\CollectionType;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
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

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            // Игнорируем статические свойства
            if ($prop->isStatic()) {
                continue;
            }

            $propName = $prop->getName();
            $properties[$propName] = $this->exportProperty($prop, $imports);
        }

        // Дедупликация импортов (и удаление импорта самого себя)
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
            // Извлекаем имя константы из Enum, чтобы избежать сериализации объектов
            if ($val instanceof \BackedEnum || $val instanceof \UnitEnum) {
                $default = $val->name;
            } else {
                $default = $val;
            }
        } elseif (in_array('collection', $typeSchema['type'], true) || in_array('array', $typeSchema['type'], true)) {
            // Если дефолт не задан, но это массив или коллекция, в схеме по умолчанию это []
            $default = [];
        }

        $schema = [
            'type' => $typeSchema['type'],
            'isNullable' => $typeSchema['isNullable'],
            'default' => $default,
            'description' => $this->parseDocComment($prop->getDocComment()),
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
     * Определяет языково-независимые типы и собирает импорты.
     */
    private function extractTypeSchema(ReflectionProperty $prop, array &$imports): array
    {
        $reflectionType = $prop->getType();
        $types = [];
        $isNullable = false;
        $isEnum = false;
        $items = null;

        if (!$reflectionType) {
            return ['type' => ['any'], 'isNullable' => true, 'isEnum' => false, 'items' => null];
        }

        $processNamedType = function (ReflectionNamedType $t) use ($prop, &$imports, &$isEnum, &$items) {
            $name = $t->getName();

            // 1. Примитивные типы PHP -> Language Agnostic
            if ($t->isBuiltin()) {
                $mapped = match ($name) {
                    'int' => 'integer',
                    'float' => 'number',
                    'bool' => 'boolean',
                    default => $name // string, array, mixed
                };

                if ($mapped === 'array') {
                    $itemsClass = $this->resolveItemsType($prop, null);
                    if ($itemsClass) {
                        $info = $this->parseNamespaceAndName($itemsClass);
                        $imports[] = $info;
                        $items = $info['name'];
                    }
                }

                return $mapped;
            }

            // 2. Enums
            if (is_subclass_of($name, \BackedEnum::class) || is_subclass_of($name, \UnitEnum::class)) {
                $isEnum = true;
                $info = $this->parseNamespaceAndName($name);
                $imports[] = $info;
                return $info['name'];
            }

            // 3. Коллекции
            if (is_subclass_of($name, BaseCollection::class)) {
                $itemsClass = $this->resolveItemsType($prop, $name);
                if ($itemsClass) {
                    $info = $this->parseNamespaceAndName($itemsClass);
                    $imports[] = $info;
                    $items = $info['name'];
                }

                $info = $this->parseNamespaceAndName($name);
                $imports[] = $info; // Импортируем сам класс коллекции
                return 'collection';
            }

            // 4. Обычные DTO классы
            $info = $this->parseNamespaceAndName($name);
            $imports[] = $info;
            return $info['name'];
        };

        if ($reflectionType instanceof ReflectionNamedType) {
            $types[] = $processNamedType($reflectionType);
            $isNullable = $reflectionType->allowsNull();
        } elseif ($reflectionType instanceof ReflectionUnionType) {
            foreach ($reflectionType->getTypes() as $t) {
                $types[] = $processNamedType($t);
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
    private function resolveItemsType(ReflectionProperty $prop, ?string $collectionClass): ?string
    {
        // Приоритет 1: Атрибут Cast на свойстве
        $attributes = $prop->getAttributes(Cast::class);
        if (!empty($attributes)) {
            return $attributes[0]->newInstance()->className;
        }

        // Приоритет 2: Атрибут CollectionType на классе коллекции
        if ($collectionClass && class_exists($collectionClass)) {
            $reflection = new ReflectionClass($collectionClass);
            $attributes = $reflection->getAttributes(CollectionType::class);
            if (!empty($attributes)) {
                return $attributes[0]->newInstance()->className;
            }
        }

        return null;
    }

    /**
     * Транслирует FQCN (Local\Lib\DTO\Models\UserDTO) в Agnostic-формат.
     * Возвращает [name => 'UserDTO', module => 'Models']
     */
    private function parseNamespaceAndName(string $fqcn): array
    {
        $parts = explode('\\', trim($fqcn, '\\'));
        $name = array_pop($parts);
        // Модуль — это последняя директория/часть неймспейса (например, 'Models', 'Enums')
        $module = empty($parts) ? 'Global' : end($parts);

        return [
            'name' => $name,
            'module' => $module
        ];
    }

    /**
     * Очищает PHPDoc от технических тегов (@var, @method) и символов комментария.
     */
    private function parseDocComment(string|false|null $docComment): ?string
    {
        if (!$docComment) {
            return null;
        }

        $lines = explode("\n", $docComment);
        $cleanLines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            // Убираем /**, */ и начальные *
            $line = preg_replace('/^\/\*\*|^\*\/|^\*\s?/', '', $line);
            $line = trim($line);

            // Игнорируем технические теги, оставляем только человеческий текст
            if ($line !== '' && !str_starts_with($line, '@')) {
                $cleanLines[] = $line;
            }
        }

        return empty($cleanLines) ? null : implode("\n", $cleanLines);
    }
}
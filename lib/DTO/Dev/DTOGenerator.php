<?php

namespace Local\Lib\DTO\Dev;

use Bitrix\Main\Text\StringHelper;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;

class DTOGenerator
{
    /**
     * Генерирует PHP код класса DTO на основе массива данных.
     */
    public static function generate(string $className, string $targetNamespace, array $data): string
    {
        $propertiesCode = [];
        $phpDocLines = [];

        foreach ($data as $key => $value) {
            if (is_numeric($key)) {
                continue;
            }

            $propName = self::normalizePropertyName($key);
            $typeInfo = self::detectType($value);
            $type = $typeInfo['type'];

            $line = sprintf("    public %s \$%s;", $type, $propName);
            if (!empty($typeInfo['comment'])) {
                $line .= " // " . $typeInfo['comment'];
            }
            $propertiesCode[] = $line;

            $methodSuffix = ucfirst($propName);
            $phpDocLines[] = " * @method {$type} get{$methodSuffix}()";

            $setterType = $type;
            if (!in_array($type, ['int', 'float', 'bool', 'string', 'mixed', 'array'], true)) {
                $setterType .= '|array';
            }
            $phpDocLines[] = " * @method self set{$methodSuffix}({$setterType} \$value)";
        }

        $propsOutput = implode("\n", $propertiesCode);

        $classDocBlock = "";
        if (!empty($phpDocLines)) {
            $classDocBlock = "/**\n" . implode("\n", $phpDocLines) . "\n */\n";
        }

        return "<?php\n\n" .
            "namespace {$targetNamespace};\n\n" .
            "use Local\Lib\DTO\BaseDTO;\n" .
            "use Local\Lib\DTO\Attributes\Cast;\n\n" .
            $classDocBlock .
            "class {$className} extends BaseDTO\n" .
            "{\n" .
            $propsOutput . "\n" .
            "}\n";
    }

    /**
     * Генерирует файл-заглушку (Stub) для IDE PHPStorm со всеми классами в указанном неймспейсе.
     * Возвращает строку без открывающего тега "<?php", готовую к записи в файл.
     * * @param string $namespacePrefix Префикс неймспейса (например, "Local\Lib\DTO\Card")
     * @return string Содержимое файла аннотаций
     */
    public static function generateIdeAnnotations(string $namespacePrefix): string
    {
        $allClasses = get_declared_classes();
        $classesByNamespace = [];

        // Группируем классы по их неймспейсам
        foreach ($allClasses as $className) {
            if (str_starts_with($className, $namespacePrefix)) {
                if ($className === self::class) {
                    continue;
                }

                $reflection = new ReflectionClass($className);
                $ns = $reflection->getNamespaceName();
                $classesByNamespace[$ns][] = $className;
            }
        }

        $output = [];
        $output[] = "// Файл сгенерирован автоматически для IDE PHPStorm";
        $output[] = "// Не подключайте его в рабочем коде\n";

        foreach ($classesByNamespace as $ns => $classes) {
            $output[] = "namespace {$ns} {";

            foreach ($classes as $className) {
                $reflection = new ReflectionClass($className);
                $shortName = $reflection->getShortName();

                // Получаем родительский класс, чтобы IDE понимала наследование
                $parentClass = $reflection->getParentClass();
                $extends = $parentClass ? " extends \\" . $parentClass->getName() : "";

                // Генерируем PHPDoc с флагом isStub = true (заменяет 'self' на абсолютный путь)
                $docBlock = self::generateDocsForClass($className, true);

                // Добавляем отступы (табуляцию) для красоты
                $output[] = "\t" . str_replace("\n", "\n\t", $docBlock);
                $output[] = "\tclass {$shortName}{$extends} {}";
                $output[] = "";
            }

            $output[] = "}\n";
        }

        return implode("\n", $output);
    }

    /**
     * Поиск загруженных классов в памяти и генерация массива PHPDoc.
     */
    public static function generateDocsFromMemory(string $namespacePrefix): array
    {
        $results = [];
        $allClasses = get_declared_classes();

        foreach ($allClasses as $className) {
            if (str_starts_with($className, $namespacePrefix)) {
                if ($className === self::class) {
                    continue;
                }
                $results[$className] = self::generateDocsForClass($className);
            }
        }

        return $results;
    }

    /**
     * Генерация PHPDoc блока для конкретного существующего класса.
     * * @param string $className Имя класса
     * @param bool $isStub Если true, возвращает абсолютный путь класса вместо 'self'
     */
    public static function generateDocsForClass(string $className, bool $isStub = false): string
    {
        if (!class_exists($className)) {
            return "Class {$className} not found.";
        }

        $reflection = new ReflectionClass($className);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);
        $lines = [];

        // В заглушках лучше писать полный класс, а не self, чтобы IDE не путалась
        $returnType = $isStub ? '\\' . $className : 'self';

        foreach ($properties as $prop) {
            if ($prop->isStatic()) {
                continue;
            }

            $name = $prop->getName();
            $methodSuffix = ucfirst($name);

            // Getter
            $getterType = self::getPropertyTypeString($prop, false);
            $lines[] = " * @method {$getterType} get{$methodSuffix}()";

            // Setter
            $setterType = self::getPropertyTypeString($prop, true);
            $lines[] = " * @method {$returnType} set{$methodSuffix}({$setterType} \$value)";
        }

        if (empty($lines)) {
            return "/**\n * Class {$reflection->getShortName()}\n */";
        }

        return "/**\n * @see \\{$className}\n" . implode("\n", $lines) . "\n */";
    }

    // --- Вспомогательные методы ---

    private static function normalizePropertyName(string $key): string
    {
        if (!str_contains($key, '_') && !ctype_upper($key)) {
            return lcfirst($key);
        }
        if (class_exists(StringHelper::class)) {
            $camel = StringHelper::snake2camel($key);
        } else {
            $camel = str_replace(' ', '', ucwords(str_replace('_', ' ', strtolower($key))));
        }
        return lcfirst($camel);
    }

    private static function detectType(mixed $value): array
    {
        if ($value === null) return ['type' => '?string', 'comment' => 'TODO: Check type'];
        if (is_int($value)) return ['type' => 'int', 'comment' => ''];
        if (is_float($value)) return ['type' => 'float', 'comment' => ''];
        if (is_bool($value)) return ['type' => 'bool', 'comment' => ''];

        if (is_array($value)) {
            if (empty($value)) {
                return ['type' => 'array', 'comment' => ''];
            }
            if (array_is_list($value)) {
                $first = reset($value);
                if (is_array($first)) {
                    return ['type' => 'array', 'comment' => 'Consider using #[Cast(ItemDTO::class)]'];
                }
                $itemType = get_debug_type($first);
                return ['type' => 'array', 'comment' => "List of {$itemType}"];
            }
            return ['type' => 'array', 'comment' => 'Nested structure. Consider creating a separate DTO'];
        }

        if (is_string($value)) {
            // Распознавание специфичных для Bitrix булевых значений
            if ($value === 'Y' || $value === 'N') {
                return ['type' => 'bool', 'comment' => 'Bitrix bool'];
            }

            if (is_numeric($value)) {
                if (str_contains($value, '.')) {
                    return ['type' => 'float', 'comment' => ''];
                }
                return ['type' => 'int', 'comment' => ''];
            }

            if (strtotime($value) !== false && strpbrk($value, '-.:')) {
                return ['type' => '\Bitrix\Main\Type\DateTime', 'comment' => 'Date'];
            }
            return ['type' => 'string', 'comment' => ''];
        }

        return ['type' => 'mixed', 'comment' => ''];
    }

    /**
     * Формирует строковое представление типа.
     * @param ReflectionProperty $prop Свойство
     * @param bool $isSetter Если true, добавляет расширенные типы (array для классов)
     */
    private static function getPropertyTypeString(ReflectionProperty $prop, bool $isSetter = false): string
    {
        $type = $prop->getType();

        if (!$type) {
            return 'mixed';
        }

        $getTypes = function (ReflectionNamedType $t) use ($isSetter) {
            $name = $t->getName();
            $types = [];

            if (!$t->isBuiltin()) {
                $types[] = ($name === 'self' || $name === 'parent') ? $name : '\\' . $name;
                // Сеттеры для объектов принимают array для гидрации
                if ($isSetter) {
                    $types[] = 'array';
                }
            } else {
                // Обычные типы (int, string, array)
                $types[] = $name;
            }

            return $types;
        };

        $allTypes = [];
        $isNullable = false;

        if ($type instanceof ReflectionNamedType) {
            $allTypes = $getTypes($type);
            $isNullable = $type->allowsNull();
        } elseif ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $t) {
                $allTypes = array_merge($allTypes, $getTypes($t));
                if ($t->allowsNull()) {
                    $isNullable = true;
                }
            }
        }

        $uniqueTypes = array_unique($allTypes);

        if ($isNullable) {
            $uniqueTypes = array_filter($uniqueTypes, fn($t) => $t !== 'null');
            $uniqueTypes[] = 'null';
        }

        return implode('|', $uniqueTypes) ?: 'mixed';
    }
}
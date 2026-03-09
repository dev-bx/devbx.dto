<?php

namespace DevBX\DTO\Dev;

use DevBX\DTO\Utils\StringHelper;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;

/**
 * Утилита для быстрого прототипирования DTO на основе сырых массивов данных.
 * ВНИМАНИЕ: Класс использует механизмы "угадывания" (Data Inference) типов на основе значений.
 * Предназначен исключительно для генерации черновиков (scaffolding) кода, которые
 * разработчик должен проверить и откорректировать вручную.
 * * Для строгой и точной генерации используйте подсистему DTOSchema.
 */
class DTOGenerator
{
    /** Внутренний кэш для хранения объединенной структуры всех вложенных классов */
    private static array $schemas = [];

    /**
     * Генерирует PHP код класса DTO на основе массива данных.
     * Возвращает строку с кодом основного класса (и всех вложенных зависимых DTO).
     */
    public static function generate(string $className, string $targetNamespace, array $data): string
    {
        $classes = self::generateTree($className, $targetNamespace, $data);
        return implode("\n\n// ==========================================\n\n", $classes);
    }

    /**
     * Анализирует вложенную структуру массива и генерирует набор связанных DTO классов.
     * Возвращает ассоциативный массив: ['ClassName' => 'PHP Code', ...]
     */
    public static function generateTree(string $rootClassName, string $targetNamespace, array $data): array
    {
        self::$schemas = [];
        self::analyzeNode($rootClassName, $data);

        $classes = [];
        foreach (self::$schemas as $className => $schema) {
            $classes[$className] = self::renderClass($className, $targetNamespace, $schema);
        }

        return $classes;
    }

    /**
     * Рекурсивный проход по узлам для сбора уникальных свойств.
     */
    private static function analyzeNode(string $className, array $data): void
    {
        if (!isset(self::$schemas[$className])) {
            self::$schemas[$className] = [];
        }

        foreach ($data as $key => $value) {
            if (is_numeric($key)) {
                continue;
            }

            $propName = self::normalizePropertyName($key);
            $typeInfo = self::detectTypeForTree($key, $value);

            // Если свойства еще нет в схеме — добавляем.
            if (!isset(self::$schemas[$className][$propName])) {
                self::$schemas[$className][$propName] = $typeInfo;
            } else {
                // Если свойство уже было, но ранее пришел null (?), а теперь есть конкретика — обновляем (Upgrade type)
                $existingType = self::$schemas[$className][$propName]['type'];
                if (str_starts_with($existingType, '?') && !str_starts_with($typeInfo['type'], '?')) {
                    self::$schemas[$className][$propName] = $typeInfo;
                } elseif ($existingType === 'mixed' && $typeInfo['type'] !== 'mixed') {
                    self::$schemas[$className][$propName] = $typeInfo;
                }
            }
        }
    }

    /**
     * Определение типа и планирование дочерних классов.
     */
    private static function detectTypeForTree(string $key, mixed $value): array
    {
        if ($value === null) return ['type' => '?string', 'comment' => 'TODO: Check type'];
        if (is_int($value)) return ['type' => 'int', 'comment' => ''];
        if (is_float($value)) return ['type' => 'float', 'comment' => ''];
        if (is_bool($value)) return ['type' => 'bool', 'comment' => ''];

        if (is_array($value)) {
            if (empty($value)) {
                return ['type' => 'array', 'comment' => ''];
            }

            // Ассоциативный массив -> Вложенный единичный DTO объект
            if (!array_is_list($value)) {
                $childClassName = ucfirst(self::normalizePropertyName($key)) . 'DTO';
                self::analyzeNode($childClassName, $value);
                return ['type' => $childClassName, 'comment' => ''];
            }

            // Индексированный массив -> Список (List)
            $first = reset($value);
            if (is_array($first) && !array_is_list($first)) {
                // Это массив ассоциативных массивов (например, entities, links) -> Генерируем дочерний DTO
                $baseName = self::normalizePropertyName($key);
                $childClassName = ucfirst(self::singularize($baseName)) . 'DTO';

                // Обязательно сканируем ВСЕ элементы списка, так как в разных элементах (entities) могут быть разные поля
                foreach ($value as $item) {
                    if (is_array($item) && !array_is_list($item)) {
                        self::analyzeNode($childClassName, $item);
                    }
                }

                return [
                    'type' => 'array',
                    'attribute' => "#[Cast({$childClassName}::class)]",
                    'comment' => "List of {$childClassName}",
                    'itemType' => $childClassName
                ];
            }

            $itemType = get_debug_type($first);
            return ['type' => 'array', 'comment' => "List of {$itemType}", 'itemType' => $itemType];
        }

        if (is_string($value)) {
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
     * Примитивная плюрализация/сингуляризация (entities -> entity, links -> link).
     */
    private static function singularize(string $word): string
    {
        if (str_ends_with($word, 'ies')) return substr($word, 0, -3) . 'y';
        if (str_ends_with($word, 'ses')) return substr($word, 0, -2);
        if (str_ends_with($word, 's') && !str_ends_with($word, 'ss')) return substr($word, 0, -1);
        return $word . 'Item';
    }

    /**
     * Финальный рендеринг PHP кода отдельного класса на основе собранной схемы.
     */
    private static function renderClass(string $className, string $targetNamespace, array $schema): string
    {
        $propertiesCode = [];
        $phpDocLines = [];
        $imports = [
            'DevBX\DTO\BaseDTO',
        ];
        $needsCast = false;

        foreach ($schema as $propName => $typeInfo) {
            $type = $typeInfo['type'];
            $itemType = $typeInfo['itemType'] ?? null;

            // Подключаем use, если класс внешний (например DateTime)
            if (str_starts_with($type, '\\')) {
                $typeClass = ltrim($type, '\\');
                if (!in_array($typeClass, $imports, true)) {
                    $imports[] = $typeClass;
                }
                $type = array_reverse(explode('\\', $typeClass))[0];
            }

            // Формируем тип для PHPDoc
            $propDoc = "";
            if ($type === 'array' && $itemType) {
                $propDoc = "    /** @var {$itemType}[] */\n";
                $methodType = "{$itemType}[]";
            } else {
                $methodType = $type;
            }

            $line = "";
            if (!empty($typeInfo['attribute'])) {
                $line .= "    " . $typeInfo['attribute'] . "\n";
                if (str_contains($typeInfo['attribute'], 'Cast(')) {
                    $needsCast = true;
                }
            }

            $line .= $propDoc;
            $line .= sprintf("    public %s \$%s;", $type, $propName);
            if (!empty($typeInfo['comment'])) {
                $line .= " // " . $typeInfo['comment'];
            }
            $propertiesCode[] = $line;

            $methodSuffix = ucfirst($propName);
            $phpDocLines[] = " * @method {$methodType} get{$methodSuffix}()";

            $setterType = $methodType;
            if (!in_array($type, ['int', 'float', 'bool', 'string', 'mixed', 'array'], true) && !str_starts_with($type, '?')) {
                $setterType .= '|array';
            }

            $phpDocLines[] = " * @method self set{$methodSuffix}({$setterType} \$value)";
        }

        if ($needsCast) {
            $imports[] = 'DevBX\DTO\Attributes\Cast';
        }

        $propsOutput = implode("\n", $propertiesCode);

        $classDocBlock = "";
        if (!empty($phpDocLines)) {
            $classDocBlock = "/**\n" . implode("\n", $phpDocLines) . "\n */\n";
        }

        $uses = array_unique($imports);
        sort($uses);
        $usesOutput = implode("\n", array_map(fn($imp) => "use {$imp};", $uses));

        return "<?php\n\n" .
            "namespace {$targetNamespace};\n\n" .
            $usesOutput . "\n\n" .
            $classDocBlock .
            "class {$className} extends BaseDTO\n" .
            "{\n" .
            $propsOutput . "\n" .
            "}\n";
    }

    /**
     * Генерирует файл-заглушку (Stub) для IDE PHPStorm со всеми классами в указанном неймспейсе.
     */
    public static function generateIdeAnnotations(string $namespacePrefix): string
    {
        $allClasses = get_declared_classes();
        $classesByNamespace = [];

        foreach ($allClasses as $className) {
            if (str_starts_with($className, $namespacePrefix)) {
                if ($className === self::class) continue;

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

                $parentClass = $reflection->getParentClass();
                $extends = $parentClass ? " extends \\" . $parentClass->getName() : "";

                $docBlock = self::generateDocsForClass($className, true);

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
                if ($className === self::class) continue;
                $results[$className] = self::generateDocsForClass($className);
            }
        }

        return $results;
    }

    /**
     * @return array<class-string, string>
     */
    public static function generateDocsForDirectory(string $directory): array
    {
        $results = [];

        if (!is_dir($directory))
            return $results;

        $classesBefore = get_declared_classes();

        $dirIterator = new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($dirIterator);

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                require_once $file->getPathname();
            }
        }

        $classesAfter = get_declared_classes();

        foreach (array_diff($classesAfter, $classesBefore) as $className)
        {
            if (str_starts_with($className, 'Local\\Lib\\DTO\\'))
                continue;

            $results[$className] = self::generateDocsForClass($className);
        }

        return $results;
    }

    /**
     * Генерация PHPDoc блока для конкретного существующего класса.
     */
    public static function generateDocsForClass(string $className, bool $isStub = false): string
    {
        if (!class_exists($className)) {
            return "Class {$className} not found.";
        }

        $reflection = new ReflectionClass($className);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);
        $lines = [];

        $returnType = $isStub ? '\\' . $className : 'self';

        // Определяем, является ли класс коллекцией
        if ($reflection->isSubclassOf(\DevBX\DTO\BaseCollection::class)) {
            $itemType = 'mixed';

            // 1. Поиск через атрибут #[CollectionType]
            $collectionAttrs = $reflection->getAttributes(\DevBX\DTO\Attributes\CollectionType::class);
            if (!empty($collectionAttrs)) {
                $itemType = '\\' . ltrim($collectionAttrs[0]->newInstance()->className, '\\');
            }
            // 2. Поиск через возвращаемый тип метода createItem()
            elseif ($reflection->hasMethod('createItem')) {
                $returnTypeObj = $reflection->getMethod('createItem')->getReturnType();
                if ($returnTypeObj instanceof \ReflectionNamedType && !$returnTypeObj->isBuiltin()) {
                    $itemType = '\\' . ltrim($returnTypeObj->getName(), '\\');
                }
            }
            // 3. Парсинг существующего PHPDoc @extends BaseCollection<Type>
            elseif ($doc = $reflection->getDocComment()) {
                if (preg_match('/@extends\s+.*?BaseCollection<([^>]+)>/i', $doc, $matches)) {
                    $parsedType = trim($matches[1]);
                    // Если тип передан без неймспейса, пробуем подставить текущий
                    if (!str_starts_with($parsedType, '\\') && class_exists($reflection->getNamespaceName() . '\\' . $parsedType)) {
                        $itemType = '\\' . $reflection->getNamespaceName() . '\\' . $parsedType;
                    } else {
                        $itemType = $parsedType;
                    }
                }
            }

            // Генерируем аннотации для IDE, если тип найден
            if ($itemType !== 'mixed') {
                $lines[] = " * @extends \\Local\\Lib\\DTO\\BaseCollection<{$itemType}>";
            }
        }

        foreach ($properties as $prop) {
            if ($prop->isStatic()) continue;

            $name = $prop->getName();
            $methodSuffix = ucfirst($name);

            $getterType = self::getPropertyTypeString($prop, false);
            $lines[] = " * @method {$getterType} get{$methodSuffix}()";

            $setterType = self::getPropertyTypeString($prop, true);
            $lines[] = " * @method {$returnType} set{$methodSuffix}({$setterType} \$value)";
        }

        if (empty($lines)) {
            return "/**\n * Class {$reflection->getShortName()}\n */";
        }

        return "/**\n * @see \\{$className}\n" . implode("\n", $lines) . "\n */";
    }

    private static function normalizePropertyName(string $key): string
    {
        if (!str_contains($key, '_') && !ctype_upper($key)) {
            return lcfirst($key);
        }
        return StringHelper::snake2camel($key);
    }

    private static function getPropertyTypeString(ReflectionProperty $prop, bool $isSetter = false): string
    {
        $type = $prop->getType();

        if (!$type) return 'mixed';

        $getTypes = function (ReflectionNamedType $t) use ($isSetter) {
            $name = $t->getName();
            $types = [];

            if (!$t->isBuiltin()) {
                $types[] = ($name === 'self' || $name === 'parent') ? $name : '\\' . $name;
                if ($isSetter) {
                    $types[] = 'array';
                }
            } else {
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

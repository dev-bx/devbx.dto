<?php

namespace Local\Lib\DTO;

use Local\Lib\DTO\Attributes\Cast;
use Local\Lib\DTO\Attributes\CollectionType;
use Local\Lib\DTO\BaseCollection;
use Local\Lib\DTO\Validation\ValidationResult;
use Local\Lib\DTO\Validation\ValidationError;
use Local\Lib\DTO\Utils\StringHelper;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ArrayAccess;
use JsonSerializable;

abstract class BaseDTO implements ArrayAccess, JsonSerializable
{
    // Форматы ключей для экспорта
    public const FORMAT_CAMEL = 'camel';             // userId
    public const FORMAT_SNAKE = 'snake';             // user_id
    public const FORMAT_UPPER_SNAKE = 'upper_snake'; // USER_ID

    public function __construct()
    {
        $properties = self::getReflectedProperties(static::class);

        foreach ($properties as $prop) {
            $type = $prop->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                // Если свойство — наследник BaseCollection, инициализируем его пустым объектом
                if (is_subclass_of($typeName, BaseCollection::class)) {
                    // Проверяем, не инициализировано ли оно уже (например, в определении класса)
                    if (!$prop->isInitialized($this)) {
                        $this->{$prop->getName()} = new $typeName();
                    }
                }
            }
        }
    }

    /**
     * Магический метод для поддержки setProperty() и getProperty().
     * Позволяет писать ->setText('val') вместо ->text = 'val'.
     */
    public function __call(string $name, array $arguments): mixed
    {
        $prefix = substr($name, 0, 3);
        $propertyName = lcfirst(substr($name, 3)); // setUserId -> userId

        // Проверяем существование свойства (учитывая camelCase)
        if (!property_exists($this, $propertyName)) {
            throw new \BadMethodCallException("Property '{$propertyName}' not found in " . static::class);
        }

        if ($prefix === 'get') {
            return $this->{$propertyName};
        }

        if ($prefix === 'set') {
            $value = $arguments[0] ?? null;

            // Получаем рефлексию свойства для процессинга
            $props = self::getReflectedProperties(static::class);
            if (isset($props[$propertyName])) {
                // ВАЖНО: Прогоняем значение через processValue с strict=false (разрешаем кастинг)
                $value = self::processValue($props[$propertyName], $value, false);
            }

            $this->{$propertyName} = $value;
            return $this; // Fluent Interface
        }

        throw new \BadMethodCallException("Method {$name} does not exist in class " . static::class);
    }

    /**
     * Внутренний кэш рефлексии.
     * Структура: [ClassName => [propName => ReflectionProperty]]
     */
    private static array $reflectionCache = [];

    /**
     * @param array $data Входной массив данных
     * @param bool $strict Если true — данные не будут приводиться к типам
     */
    public static function fromArray(array $data, bool $strict = false): static
    {
        $dto = new static();
        $properties = self::getReflectedProperties(static::class);

        foreach ($properties as $propName => $prop) {
            $key = self::findKeyInArray($data, $propName);

            if ($key === null) {
                continue;
            }

            $value = $data[$key];

            if ($value === null) {
                if ($prop->getType()?->allowsNull()) {
                    $prop->setValue($dto, null);
                }
                continue;
            }

            $processedValue = self::processValue($prop, $value, $strict);

            if (self::isValueCompatible($prop, $processedValue)) {
                $prop->setValue($dto, $processedValue);
            }
        }

        return $dto;
    }

    /**
     * Создание коллекции DTO из списка массивов данных.
     * @param iterable $list Список данных
     * @param bool $strict Режим строгой типизации
     * @return BaseCollection<int, static>
     */
    public static function fromCollection(iterable $list, bool $strict = false): BaseCollection // Return type changed
    {
        $items = [];
        foreach ($list as $item) {
            if (is_array($item)) {
                $items[] = self::fromArray($item, $strict);
            }
        }
        return new BaseCollection($items);
    }

    /**
     * Создание DTO из JSON строки.
     * @throws \JsonException Если JSON некорректен
     */
    public static function fromJson(string $json, bool $strict = false): static
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \InvalidArgumentException("JSON must contain an object or array structure.");
        }

        return self::fromArray($data, $strict);
    }

    /**
     * Преобразование DTO в массив.
     */
    public function toArray(string $format = self::FORMAT_CAMEL): array
    {
        $result = [];
        $properties = self::getReflectedProperties(static::class);

        foreach ($properties as $propName => $prop) {
            if (!$prop->isInitialized($this)) {
                continue;
            }

            $value = $prop->getValue($this);

            $key = match ($format) {
                self::FORMAT_SNAKE => StringHelper::camel2snake($propName),
                self::FORMAT_UPPER_SNAKE => strtoupper(StringHelper::camel2snake($propName)),
                default => $propName
            };

            if ($value instanceof self) {
                $result[$key] = $value->toArray($format);
            } elseif (is_array($value)) {
                $result[$key] = array_map(function ($item) use ($format) {
                    return ($item instanceof self) ? $item->toArray($format) : $item;
                }, $value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Валидация данных.
     * Проверяет обязательность полей и рекурсивно валидирует вложенные DTO.
     */
    public function validate(): ValidationResult
    {
        $result = new ValidationResult();
        $properties = self::getReflectedProperties(static::class);

        foreach ($properties as $propName => $prop) {
            if (!$prop->isInitialized($this)) {
                if (!$prop->getType()?->allowsNull()) {
                    $result->addError(new ValidationError("Field '{$propName}' is required.", "REQUIRED_FIELD_{$propName}"));
                }
                continue;
            }

            $value = $prop->getValue($this);

            if ($value instanceof self) {
                $subResult = $value->validate();
                if (!$subResult->isSuccess()) {
                    foreach ($subResult->getErrors() as $error) {
                        $result->addError(new ValidationError($error->getMessage(), "{$propName}." . $error->getCode()));
                    }
                }
            } elseif (is_array($value) || $value instanceof BaseCollection) {
                foreach ($value as $index => $item) {
                    if ($item instanceof self) {
                        $subResult = $item->validate();
                        if (!$subResult->isSuccess()) {
                            foreach ($subResult->getErrors() as $error) {
                                $result->addError(new ValidationError($error->getMessage(), "{$propName}[{$index}]." . $error->getCode()));
                            }
                        }
                    }
                }
            }
        }

        return $result;
    }

    // --- Protected/Private ---

    /**
     * Обработка значения перед присвоением.
     * Использует Pipeline из Casters для разделения ответственности.
     */
    private static function processValue(ReflectionProperty $prop, mixed $value, bool $strict): mixed
    {
        $type = $prop->getType();

        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        $casters = self::getCasters();

        foreach ($casters as $caster) {
            if ($caster->supports($type, $value)) {
                return $caster->cast($type, $prop, $value, $strict);
            }
        }

        return $value;
    }

    /**
     * Возвращает зарегистрированные обработчики типов (Pipeline).
     * @return \Local\Lib\DTO\Casters\CasterInterface[]
     */
    private static function getCasters(): array
    {
        static $casters = null;

        if ($casters === null) {
            $casters = [
                new \Local\Lib\DTO\Casters\DtoCaster(),
                new \Local\Lib\DTO\Casters\CollectionCaster(),
                new \Local\Lib\DTO\Casters\ArrayCaster(),
                new \Local\Lib\DTO\Casters\EnumCaster(),
                new \Local\Lib\DTO\Casters\DateTimeCaster(),
                new \Local\Lib\DTO\Casters\ScalarCaster(),
            ];
        }

        return $casters;
    }

    /**
     * Поиск ключа в массиве данных с кэшированием вариантов написания.
     */
    private static function findKeyInArray(array $data, string $propName): ?string
    {
        if (array_key_exists($propName, $data)) {
            return $propName;
        }

        static $snakeCache = [];

        if (!isset($snakeCache[$propName])) {
            $snake = StringHelper::camel2snake($propName);
            $snakeCache[$propName] = [
                'snake' => $snake,
                'upper' => strtoupper($snake)
            ];
        }

        $variants = $snakeCache[$propName];

        if (array_key_exists($variants['snake'], $data)) {
            return $variants['snake'];
        }

        if (array_key_exists($variants['upper'], $data)) {
            return $variants['upper'];
        }

        return null;
    }

    /**
     * Проверка совместимости значения с типом свойства.
     */
    private static function isValueCompatible(ReflectionProperty $prop, mixed $value): bool
    {
        $type = $prop->getType();

        if (!$type) return true;
        if ($value === null) return $type->allowsNull();

        $checkNamedType = function (ReflectionNamedType $namedType) use ($value) {
            $typeName = $namedType->getName();

            if ($namedType->isBuiltin()) {
                return match ($typeName) {
                    'int' => is_int($value),
                    'float' => is_float($value) || is_int($value),
                    'string' => is_string($value),
                    'bool' => is_bool($value),
                    'array' => is_array($value),
                    'object' => is_object($value),
                    'mixed' => true,
                    default => true
                };
            }

            return $value instanceof $typeName;
        };

        if ($type instanceof ReflectionNamedType) {
            return $checkNamedType($type);
        }

        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $unionPart) {
                if ($checkNamedType($unionPart)) {
                    return true;
                }
            }
            return false;
        }

        return false;
    }

    /**
     * Получение свойств класса с кэшированием.
     * @return ReflectionProperty[]
     */
    private static function getReflectedProperties(string $className): array
    {
        if (!isset(self::$reflectionCache[$className])) {
            $reflection = new ReflectionClass($className);
            $props = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

            $assocProps = [];
            foreach ($props as $prop) {
                $assocProps[$prop->getName()] = $prop;
            }

            self::$reflectionCache[$className] = $assocProps;
        }

        return self::$reflectionCache[$className];
    }

    // --- Реализация интерфейсов ---

    public function jsonSerialize(): mixed
    {
        return $this->toArray(self::FORMAT_CAMEL);
    }

    public function offsetExists($offset): bool
    {
        return property_exists($this, $offset) && isset($this->{$offset});
    }

    public function offsetGet($offset): mixed
    {
        return $this->{$offset} ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        if (property_exists($this, $offset)) {
            $this->{$offset} = $value;
        }
    }

    public function offsetUnset($offset): void
    {
        if (property_exists($this, $offset)) {
            unset($this->{$offset});
        }
    }

    /**
     * Возвращает массив, содержащий только указанные ключи.
     * @param string ...$keys
     * @return array
     */
    public function only(string ...$keys): array
    {
        $array = $this->toArray();
        return array_intersect_key($array, array_flip($keys));
    }

    /**
     * Возвращает массив, исключая указанные ключи.
     * @param string ...$keys
     * @return array
     */
    public function except(string ...$keys): array
    {
        $array = $this->toArray();
        return array_diff_key($array, array_flip($keys));
    }

    /**
     * Deep Clone implementation.
     * Гарантирует, что вложенные DTO также клонируются.
     */
    public function __clone()
    {
        $properties = self::getReflectedProperties(static::class);
        foreach ($properties as $propName => $prop) {
            if (!$prop->isInitialized($this)) {
                continue;
            }

            $value = $prop->getValue($this);

            if (is_object($value) && method_exists($value, '__clone')) {
                $this->{$propName} = clone $value;
            } elseif (is_array($value)) {
                // Клонируем массивы объектов
                $this->{$propName} = array_map(
                    fn($item) => (is_object($item) ? clone $item : $item),
                    $value
                );
            }
        }
    }
}
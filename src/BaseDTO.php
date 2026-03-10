<?php

namespace DevBX\DTO;

use DevBX\DTO\Attributes\Cast;
use DevBX\DTO\Attributes\CollectionType;
use DevBX\DTO\Attributes\Validation\ValidationRuleInterface;
use DevBX\DTO\Attributes\Mapping\MapFrom;
use DevBX\DTO\Attributes\Mapping\MapTo;
use DevBX\DTO\Attributes\Mapping\Computed;
use DevBX\DTO\Attributes\Lifecycle\PostHydrate;
use DevBX\DTO\Attributes\Lifecycle\PreExport;
use DevBX\DTO\Attributes\Behavior\Strict;
use DevBX\DTO\Attributes\Behavior\Hidden;
use DevBX\DTO\Attributes\Behavior\Masked;
use DevBX\DTO\Attributes\Behavior\Initialize;
use DevBX\DTO\Exceptions\UnmappedPropertiesException;
use DevBX\DTO\Validation\ValidationResult;
use DevBX\DTO\Validation\ValidationError;
use DevBX\DTO\Utils\StringHelper;
use DevBX\DTO\BaseCollection;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionMethod;
use ReflectionAttribute;
use JsonSerializable;

/**
 * @phpstan-consistent-constructor
 */
abstract class BaseDTO implements JsonSerializable
{
    public const FORMAT_CAMEL = 'camel';
    public const FORMAT_SNAKE = 'snake';
    public const FORMAT_UPPER_SNAKE = 'upper_snake';

    private static array $schemaCache = [];

    public function __construct()
    {
        $schema = self::getClassSchema(static::class);

        foreach ($schema['properties'] as $propName => $propConfig) {
            $prop = $propConfig['reflector'];
            if (!empty($propConfig['typeData'])) {
                $t = $propConfig['typeData'][0];

                // Инициализируем если это коллекция ИЛИ есть атрибут #[Initialize]
                if (!$t['isBuiltin'] && (is_subclass_of($t['name'], BaseCollection::class) || $propConfig['isAutoInit'])) {
                    if (!$prop->isInitialized($this)) {
                        $this->{$propName} = new $t['name']();
                    }
                }
            }
        }
    }

    private static function getClassSchema(string $className): array
    {
        if (isset(self::$schemaCache[$className])) return self::$schemaCache[$className];

        if (!class_exists($className)) {
            throw new \InvalidArgumentException("Invalid class name: " . (string)$className);
        }

        $reflection = new ReflectionClass($className);
        $schema = [
            'reflectionClass' => $reflection,
            'isStrict' => !empty($reflection->getAttributes(Strict::class)),
            'constructor' => null,
            'properties' => [],
            'computed' => [],
            'hooks' => ['postHydrate' => [], 'preExport' => []]
        ];

        // 1. Конструктор
        if ($constructor = $reflection->getConstructor()) {
            $schema['constructor'] = [];
            foreach ($constructor->getParameters() as $param) {
                $paramName = $param->getName();
                $mapFromAttr = $param->getAttributes(MapFrom::class);
                $type = $param->getType();
                $snake = StringHelper::camel2snake($paramName);

                $schema['constructor'][$paramName] = [
                    'reflector' => $param,
                    'allowsNull' => $type === null || $type->allowsNull(),
                    'mapFrom' => !empty($mapFromAttr) ? $mapFromAttr[0]->newInstance()->key : null,
                    'searchKeys' => [$paramName, $snake, strtoupper($snake)]
                ];
            }
        }

        // 2. Свойства (максимальный пре-расчет)
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) continue;

            $propName = $prop->getName();
            $mapFromAttr = $prop->getAttributes(MapFrom::class);
            $mapToAttr = $prop->getAttributes(MapTo::class);
            $maskedAttr = $prop->getAttributes(Masked::class);
            $initAttr = $prop->getAttributes(Initialize::class); // Поиск нового атрибута

            $mapFromKey = !empty($mapFromAttr) ? $mapFromAttr[0]->newInstance()->key : null;
            $mapToKey = !empty($mapToAttr) ? $mapToAttr[0]->newInstance()->key : null;
            $snake = StringHelper::camel2snake($propName);

            $type = $prop->getType();
            $typeData = [];
            if ($type instanceof ReflectionNamedType) {
                $typeData[] = ['name' => $type->getName(), 'isBuiltin' => $type->isBuiltin(), 'namedType' => $type];
            } elseif ($type instanceof \ReflectionUnionType) {
                foreach ($type->getTypes() as $t) {
                    if ($t instanceof ReflectionNamedType) {
                        $typeData[] = ['name' => $t->getName(), 'isBuiltin' => $t->isBuiltin(), 'namedType' => $t];
                    }
                }
            }

            $validators = [];
            foreach ($prop->getAttributes(ValidationRuleInterface::class, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
                $validators[] = $attr->newInstance();
            }

            $schema['properties'][$propName] = [
                'reflector' => $prop,
                'allowsNull' => $type ? $type->allowsNull() : true,
                'typeData' => $typeData,
                'mapFrom' => $mapFromKey,
                'searchKeys' => [$propName, $snake, strtoupper($snake)],
                'exportKeys' => [
                    self::FORMAT_CAMEL => $mapToKey ?? $propName,
                    self::FORMAT_SNAKE => $mapToKey ?? $snake,
                    self::FORMAT_UPPER_SNAKE => $mapToKey ?? strtoupper($snake),
                ],
                'isHidden' => !empty($prop->getAttributes(Hidden::class)),
                'mask' => !empty($maskedAttr) ? $maskedAttr[0]->newInstance()->mask : null,
                'isAutoInit' => !empty($initAttr), // Сохраняем флаг инициализации
                'validators' => $validators
            ];
        }

        // 3. Вычисляемые методы и Хуки
        foreach ($reflection->getMethods() as $method) {
            $methodName = $method->getName();
            $isAccessible = false;

            if (!empty($method->getAttributes(PostHydrate::class))) {
                if (!$method->isPublic()) $method->setAccessible(true);
                $schema['hooks']['postHydrate'][] = $method;
                $isAccessible = true;
            }

            if (!empty($method->getAttributes(PreExport::class))) {
                if (!$method->isPublic() && !$isAccessible) $method->setAccessible(true);
                $schema['hooks']['preExport'][] = $method;
                $isAccessible = true;
            }

            if (!empty($method->getAttributes(Computed::class))) {
                if (!$method->isPublic() && !$isAccessible) $method->setAccessible(true);

                $baseName = (str_starts_with($methodName, 'get') && strlen($methodName) > 3) ? lcfirst(substr($methodName, 3)) : $methodName;
                $mapToAttr = $method->getAttributes(MapTo::class);
                $mapToKey = !empty($mapToAttr) ? $mapToAttr[0]->newInstance()->key : null;
                $snake = StringHelper::camel2snake($baseName);

                $schema['computed'][] = [
                    'reflector' => $method,
                    'exportKeys' => [
                        self::FORMAT_CAMEL => $mapToKey ?? $baseName,
                        self::FORMAT_SNAKE => $mapToKey ?? $snake,
                        self::FORMAT_UPPER_SNAKE => $mapToKey ?? strtoupper($snake),
                    ]
                ];
            }
        }

        self::$schemaCache[$className] = $schema;
        return $schema;
    }

    public static function fromArray(array $data, bool $strict = false): static
    {
        $schema = self::getClassSchema(static::class);
        $constructorArgs = [];
        $handledProperties = [];
        $usedArrayKeys = [];

        if ($schema['constructor'] !== null) {
            foreach ($schema['constructor'] as $paramName => $paramConfig) {
                $key = null;
                if ($paramConfig['mapFrom'] !== null && array_key_exists($paramConfig['mapFrom'], $data)) {
                    $key = $paramConfig['mapFrom'];
                } else {
                    foreach ($paramConfig['searchKeys'] as $sKey) {
                        if (array_key_exists($sKey, $data)) { $key = $sKey; break; }
                    }
                }

                if ($key !== null) {
                    $usedArrayKeys[] = $key;
                    $value = $data[$key];
                    if ($value === null) {
                        if ($paramConfig['allowsNull']) $constructorArgs[$paramName] = null;
                    } else {
                        $propConfig = $schema['properties'][$paramName] ?? null;
                        if ($propConfig) {
                            $constructorArgs[$paramName] = self::processValue($propConfig, $value, $strict);
                        } else {
                            $constructorArgs[$paramName] = $value;
                        }
                    }
                    $handledProperties[$paramName] = true;
                }
            }
            $dto = $schema['reflectionClass']->newInstanceArgs($constructorArgs);
        } else {
            $dto = new static();
        }

        foreach ($schema['properties'] as $propName => $propConfig) {
            if (isset($handledProperties[$propName])) continue;

            $key = null;
            if ($propConfig['mapFrom'] !== null && array_key_exists($propConfig['mapFrom'], $data)) {
                $key = $propConfig['mapFrom'];
            } else {
                foreach ($propConfig['searchKeys'] as $sKey) {
                    if (array_key_exists($sKey, $data)) { $key = $sKey; break; }
                }
            }

            if ($key === null) continue;
            $usedArrayKeys[] = $key;
            $value = $data[$key];

            if ($value === null) {
                if ($propConfig['allowsNull']) $propConfig['reflector']->setValue($dto, null);
                continue;
            }

            $processedValue = self::processValue($propConfig, $value, $strict);
            if (self::isValueCompatible($propConfig['typeData'], $propConfig['allowsNull'], $processedValue)) {
                $propConfig['reflector']->setValue($dto, $processedValue);
            }
        }

        if ($schema['isStrict']) {
            $unmappedKeys = array_diff(array_keys($data), $usedArrayKeys);
            if (!empty($unmappedKeys)) throw new UnmappedPropertiesException($unmappedKeys);
        }

        foreach ($schema['hooks']['postHydrate'] as $method) $method->invoke($dto);

        return $dto;
    }

    public function toArray(string $format = self::FORMAT_CAMEL): array
    {
        $schema = self::getClassSchema(static::class);
        $result = [];

        foreach ($schema['hooks']['preExport'] as $method) $method->invoke($this);

        foreach ($schema['properties'] as $propName => $propConfig) {
            if ($propConfig['isHidden']) continue;
            $prop = $propConfig['reflector'];
            if (!$prop->isInitialized($this)) continue;

            $value = $propConfig['mask'] ?? $prop->getValue($this);
            $key = $propConfig['exportKeys'][$format];
            $result[$key] = self::exportValue($value, $format);
        }

        foreach ($schema['computed'] as $compConfig) {
            $value = $compConfig['reflector']->invoke($this);
            $key = $compConfig['exportKeys'][$format];
            $result[$key] = self::exportValue($value, $format);
        }

        return $result;
    }

    private static function exportValue(mixed $value, string $format): mixed
    {
        if ($value instanceof self) return $value->toArray($format);
        if (is_array($value)) return array_map(fn($item) => ($item instanceof self) ? $item->toArray($format) : $item, $value);
        return $value;
    }

    public function validate(): ValidationResult
    {
        $result = new ValidationResult();
        $schema = self::getClassSchema(static::class);

        foreach ($schema['properties'] as $propName => $propConfig) {
            $prop = $propConfig['reflector'];

            if (!$prop->isInitialized($this)) {
                if (!$propConfig['allowsNull']) $result->addError(new ValidationError("Field '{$propName}' is required.", "REQUIRED_FIELD_{$propName}"));
                continue;
            }

            $value = $prop->getValue($this);

            foreach ($propConfig['validators'] as $rule) {
                $error = $rule->validate($value);
                if ($error !== null) $result->addError(new ValidationError($error->getMessage(), "{$propName}." . $error->getCode()));
            }

            if ($value instanceof self) {
                $subResult = $value->validate();
                if (!$subResult->isSuccess()) {
                    foreach ($subResult->getErrors() as $error) $result->addError(new ValidationError($error->getMessage(), "{$propName}." . $error->getCode()));
                }
            } elseif (is_array($value) || $value instanceof BaseCollection) {
                foreach ($value as $index => $item) {
                    if ($item instanceof self) {
                        $subResult = $item->validate();
                        if (!$subResult->isSuccess()) {
                            foreach ($subResult->getErrors() as $error) $result->addError(new ValidationError($error->getMessage(), "{$propName}[{$index}]." . $error->getCode()));
                        }
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Создает коллекцию DTO из массива данных.
     *
     * @param iterable<mixed> $list
     * @return BaseCollection<static>
     */
    public static function fromCollection(iterable $list, bool $strict = false): BaseCollection
    {
        $items = [];
        foreach ($list as $item) {
            if (is_array($item)) $items[] = self::fromArray($item, $strict);
        }
        return new BaseCollection($items);
    }

    public static function fromJson(string $json, bool $strict = false): static
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \InvalidArgumentException("JSON must contain an object or array structure.");
        return self::fromArray($data, $strict);
    }

    private static function processValue(array $propConfig, mixed $value, bool $strict): mixed
    {
        if (empty($propConfig['typeData'])) return $value;

        // Восстанавливаем оригинальное поведение: не пытаемся кастовать Union Types
        $propType = $propConfig['reflector'] instanceof ReflectionProperty
            ? $propConfig['reflector']->getType()
            : null;

        if (!$propType instanceof ReflectionNamedType) {
            return $value;
        }

        $type = $propConfig['typeData'][0]['namedType'];

        foreach (self::getCasters() as $caster) {
            if ($caster->supports($type, $value)) {
                return $caster->cast($type, $propConfig['reflector'], $value, $strict);
            }
        }
        return $value;
    }

    private static function getCasters(): array
    {
        static $casters = null;
        if ($casters === null) {
            $casters = [
                new \DevBX\DTO\Casters\DtoCaster(),
                new \DevBX\DTO\Casters\CollectionCaster(),
                new \DevBX\DTO\Casters\ArrayCaster(),
                new \DevBX\DTO\Casters\EnumCaster(),
                new \DevBX\DTO\Casters\DateTimeCaster(),
                new \DevBX\DTO\Casters\ScalarCaster(),
            ];
        }
        return $casters;
    }

    private static function isValueCompatible(array $typeData, bool $allowsNull, mixed $value): bool
    {
        if (empty($typeData)) return true;
        if ($value === null) return $allowsNull;

        foreach ($typeData as $t) {
            $typeName = $t['name'];
            if ($t['isBuiltin']) {
                $compatible = match ($typeName) {
                    'int' => is_int($value),
                    'float' => is_float($value) || is_int($value),
                    'string' => is_string($value),
                    'bool' => is_bool($value),
                    'array' => is_array($value),
                    'object' => is_object($value),
                    'mixed' => true,
                    default => true
                };
                if ($compatible) return true;
            } else {
                if ($value instanceof $typeName) return true;
            }
        }
        return false;
    }

    public function __call(string $name, array $arguments): mixed
    {
        $prefix = substr($name, 0, 3);
        $propertyName = lcfirst(substr($name, 3));

        if (!property_exists($this, $propertyName)) {
            throw new \BadMethodCallException("Property '{$propertyName}' not found in " . static::class);
        }

        if ($prefix === 'get') return $this->{$propertyName};

        if ($prefix === 'set') {
            $value = $arguments[0] ?? null;
            $schema = self::getClassSchema(static::class);
            if (isset($schema['properties'][$propertyName])) {
                $value = self::processValue($schema['properties'][$propertyName], $value, false);
            }
            $this->{$propertyName} = $value;
            return $this;
        }
        throw new \BadMethodCallException("Method {$name} does not exist in class " . static::class);
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray(self::FORMAT_CAMEL);
    }

    public function only(string ...$keys): array
    {
        return array_intersect_key($this->toArray(), array_flip($keys));
    }

    public function except(string ...$keys): array
    {
        return array_diff_key($this->toArray(), array_flip($keys));
    }

    public function __clone()
    {
        $schema = self::getClassSchema(static::class);
        foreach ($schema['properties'] as $propName => $propConfig) {
            $prop = $propConfig['reflector'];
            if (!$prop->isInitialized($this)) continue;

            $value = $prop->getValue($this);
            if (is_object($value) && method_exists($value, '__clone')) {
                $this->{$propName} = clone $value;
            } elseif (is_array($value)) {
                $this->{$propName} = array_map(fn($item) => (is_object($item) ? clone $item : $item), $value);
            }
        }
    }
}

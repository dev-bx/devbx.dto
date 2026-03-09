<?php

namespace DevBX\DTO\Casters;

use DevBX\DTO\BaseDTO;
use DevBX\DTO\BaseCollection;
use DevBX\DTO\Attributes\Cast;
use DevBX\DTO\Attributes\CollectionType;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

class CollectionCaster implements CasterInterface
{
    /** Внутренний кэш для максимальной скорости */
    private static array $cache = [];

    public function supports(ReflectionNamedType $type, mixed $value): bool
    {
        return is_subclass_of($type->getName(), BaseCollection::class) && is_array($value);
    }

    public function cast(ReflectionNamedType $type, ReflectionProperty $prop, mixed $value, bool $strict): mixed
    {
        $typeName = $type->getName();
        $propId = $prop->class . '::' . $prop->name;

        // Ищем целевой класс только 1 раз, затем берем из кэша
        if (!array_key_exists($propId, self::$cache)) {
            $targetClass = null;
            $attributes = $prop->getAttributes(Cast::class);
            if (!empty($attributes)) {
                $targetClass = $attributes[0]->newInstance()->className;
            } else {
                $collectionReflection = new ReflectionClass($typeName);
                $collectionAttributes = $collectionReflection->getAttributes(CollectionType::class);
                if (!empty($collectionAttributes)) {
                    $targetClass = $collectionAttributes[0]->newInstance()->className;
                }
            }
            self::$cache[$propId] = $targetClass;
        }

        $targetClass = self::$cache[$propId];
        $items = $value;

        if ($targetClass && is_subclass_of($targetClass, BaseDTO::class)) {
            $items = array_map(
                fn($item) => is_array($item) ? $targetClass::fromArray($item, $strict) : $item,
                $value
            );
        }

        return new $typeName($items);
    }
}
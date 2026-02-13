<?php

namespace Local\Lib\DTO\Casters;

use Local\Lib\DTO\BaseDTO;
use Local\Lib\DTO\BaseCollection;
use Local\Lib\DTO\Attributes\Cast;
use Local\Lib\DTO\Attributes\CollectionType;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

class CollectionCaster implements CasterInterface
{
    public function supports(ReflectionNamedType $type, mixed $value): bool
    {
        return is_subclass_of($type->getName(), BaseCollection::class) && is_array($value);
    }

    public function cast(ReflectionNamedType $type, ReflectionProperty $prop, mixed $value, bool $strict): mixed
    {
        $typeName = $type->getName();
        $items = $value;
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

        if ($targetClass && is_subclass_of($targetClass, BaseDTO::class)) {
            $items = array_map(
                fn($item) => is_array($item) ? $targetClass::fromArray($item, $strict) : $item,
                $value
            );
        }

        return new $typeName($items);
    }
}
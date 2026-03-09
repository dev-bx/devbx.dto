<?php

namespace DevBX\DTO\Casters;

use DevBX\DTO\BaseDTO;
use DevBX\DTO\Attributes\Cast;
use ReflectionNamedType;
use ReflectionProperty;

class ArrayCaster implements CasterInterface
{
    public function supports(ReflectionNamedType $type, mixed $value): bool
    {
        return $type->getName() === 'array' && is_array($value);
    }

    public function cast(ReflectionNamedType $type, ReflectionProperty $prop, mixed $value, bool $strict): mixed
    {
        $attributes = $prop->getAttributes(Cast::class);
        if (!empty($attributes)) {
            $targetClass = $attributes[0]->newInstance()->className;
            if (is_subclass_of($targetClass, BaseDTO::class)) {
                return array_map(
                    fn($item) => is_array($item) ? $targetClass::fromArray($item, $strict) : $item,
                    $value
                );
            }
        }
        return $value;
    }
}
<?php

namespace Local\Lib\DTO\Casters;

use ReflectionNamedType;
use ReflectionProperty;

class EnumCaster implements CasterInterface
{
    public function supports(ReflectionNamedType $type, mixed $value): bool
    {
        return is_subclass_of($type->getName(), \BackedEnum::class) && (is_string($value) || is_int($value));
    }

    public function cast(ReflectionNamedType $type, ReflectionProperty $prop, mixed $value, bool $strict): mixed
    {
        $typeName = $type->getName();
        return $typeName::tryFrom($value) ?? $value;
    }
}
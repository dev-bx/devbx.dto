<?php

namespace DevBX\DTO\Casters;

use ReflectionNamedType;
use ReflectionProperty;

class ScalarCaster implements CasterInterface
{
    public function supports(ReflectionNamedType $type, mixed $value): bool
    {
        // Срабатывает только если включен strict=false (обрабатывается внутри cast)
        // и значение скалярное
        return is_scalar($value);
    }

    public function cast(ReflectionNamedType $type, ReflectionProperty $prop, mixed $value, bool $strict): mixed
    {
        if ($strict) {
            return $value;
        }

        $typeName = $type->getName();

        if ($typeName === 'bool' && is_string($value)) {
            if ($value === 'Y') return true;
            if ($value === 'N') return false;
        }

        return match ($typeName) {
            'int' => (int)$value,
            'float' => (float)$value,
            'string' => (string)$value,
            'bool' => (bool)$value,
            default => $value
        };
    }
}
<?php

namespace DevBX\DTO\Casters;

use DevBX\DTO\BaseDTO;
use ReflectionNamedType;
use ReflectionProperty;

class DtoCaster implements CasterInterface
{
    public function supports(ReflectionNamedType $type, mixed $value): bool
    {
        return is_subclass_of($type->getName(), BaseDTO::class) && is_array($value);
    }

    public function cast(ReflectionNamedType $type, ReflectionProperty $prop, mixed $value, bool $strict): mixed
    {
        $typeName = $type->getName();
        return $typeName::fromArray($value, $strict);
    }
}
<?php

namespace Local\Lib\DTO\Casters;

use ReflectionNamedType;
use ReflectionProperty;

interface CasterInterface
{
    public function supports(ReflectionNamedType $type, mixed $value): bool;
    public function cast(ReflectionNamedType $type, ReflectionProperty $prop, mixed $value, bool $strict): mixed;
}
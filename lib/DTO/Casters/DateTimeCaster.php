<?php

namespace DevBX\DTO\Casters;

use ReflectionNamedType;
use ReflectionProperty;

class DateTimeCaster implements CasterInterface
{
    public function supports(ReflectionNamedType $type, mixed $value): bool
    {
        $typeName = $type->getName();
        return is_string($value) && (
                is_a($typeName, \DateTimeInterface::class, true) ||
                is_a($typeName, \Bitrix\Main\Type\Date::class, true)
            );
    }

    public function cast(ReflectionNamedType $type, ReflectionProperty $prop, mixed $value, bool $strict): mixed
    {
        $typeName = $type->getName();
        try {
            return new $typeName($value);
        } catch (\Throwable $e) {
            try {
                $intermediate = new \DateTime($value);
                if (is_a($typeName, \Bitrix\Main\Type\Date::class, true)) {
                    return $typeName::createFromPhp($intermediate);
                }
                return new $typeName($intermediate);
            } catch (\Throwable $ex) {
                return $value;
            }
        }
    }
}
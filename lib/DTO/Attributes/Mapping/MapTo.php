<?php

namespace Local\Lib\DTO\Attributes\Mapping;

use Attribute;

/**
 * Явное указание ключа в исходящем массиве при экспорте DTO (игнорирует формат toArray).
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class MapTo
{
    public function __construct(
        public string $key
    ) {}
}
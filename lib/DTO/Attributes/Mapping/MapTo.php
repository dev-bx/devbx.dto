<?php

namespace DevBX\DTO\Attributes\Mapping;

use Attribute;

/**
 * Явное указание ключа в исходящем массиве при экспорте DTO (игнорирует формат toArray).
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER | Attribute::TARGET_METHOD)]
class MapTo
{
    public function __construct(
        public string $key
    ) {}
}
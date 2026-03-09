<?php

namespace DevBX\DTO\Attributes\Mapping;

use Attribute;

/**
 * Явное указание ключа во входящем массиве для гидратации свойства.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class MapFrom
{
    public function __construct(
        public string $key
    ) {}
}
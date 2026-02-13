<?php

namespace Local\Lib\DTO\Attributes\Mapping;

use Attribute;

/**
 * Указывает, что значение для свойства должно быть взято из GET-параметров (Query String).
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Query
{
    /**
     * @param string|null $key Явное указание ключа в массиве (если отличается от имени свойства)
     */
    public function __construct(
        public ?string $key = null
    ) {}
}
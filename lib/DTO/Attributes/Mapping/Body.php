<?php

namespace DevBX\DTO\Attributes\Mapping;

use Attribute;

/**
 * Указывает, что значение для свойства должно быть взято из тела запроса (POST/JSON).
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Body
{
    /**
     * @param string|null $key Явное указание ключа в массиве (если отличается от имени свойства)
     */
    public function __construct(
        public ?string $key = null
    ) {}
}
<?php

namespace DevBX\DTO\Attributes;

use Attribute;

/**
 * Атрибут для указания класса элементов коллекции.
 * Устанавливается на классы-наследники BaseCollection.
 * * Пример использования:
 * #[CollectionType(UserDTO::class)]
 * class UserCollection extends BaseCollection {}
 */

#[Attribute(Attribute::TARGET_CLASS)]
class CollectionType
{
    public function __construct(
        public string $className
    ) {}
}
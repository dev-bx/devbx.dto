<?php

namespace Local\Lib\DTO\Attributes;

use Attribute;

/**
 * Атрибут для указания класса элементов массива.
 * Используется, когда свойство является массивом объектов DTO.
 * Пример:
 * #[Cast(ProductDTO::class)]
 * public array $products;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Cast
{
    public function __construct(
        public string $className
    ) {}
}
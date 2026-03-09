<?php

namespace DevBX\DTO\Attributes\Behavior;

use Attribute;

/**
 * Указывает, что при экспорте DTO в массив (toArray) реальное значение
 * свойства будет заменено на маску (по умолчанию '********').
 * Длина маски фиксирована, чтобы не раскрывать длину исходной строки.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Masked
{
    public function __construct(
        public string $mask = '********'
    ) {}
}
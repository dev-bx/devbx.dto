<?php

namespace Local\Lib\DTO\Attributes\Behavior;

use Attribute;

/**
 * Указывает, что свойство должно быть автоматически инициализировано
 * (через оператор new) в базовом конструкторе BaseDTO.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Initialize
{
}

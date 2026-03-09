<?php

namespace DevBX\DTO\Attributes\Behavior;

use Attribute;

/**
 * Указывает, что свойство должно быть полностью исключено
 * при экспорте DTO в массив (toArray) или JSON.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Hidden
{
}
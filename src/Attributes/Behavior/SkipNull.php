<?php

namespace DevBX\DTO\Attributes\Behavior;

use Attribute;

/**
 * Указывает, что свойство должно быть исключено из массива при экспорте (toArray),
 * если его фактическое значение равно null.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class SkipNull
{
}

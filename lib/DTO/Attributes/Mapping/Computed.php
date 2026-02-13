<?php

namespace Local\Lib\DTO\Attributes\Mapping;

use Attribute;

/**
 * Указывает, что результат выполнения метода должен быть добавлен
 * в результирующий массив при экспорте DTO (toArray).
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Computed
{
}
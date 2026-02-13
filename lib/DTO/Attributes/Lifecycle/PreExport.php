<?php

namespace Local\Lib\DTO\Attributes\Lifecycle;

use Attribute;

/**
 * Метод, помеченный этим атрибутом, будет автоматически вызван
 * непосредственно перед конвертацией объекта в массив (toArray).
 */
#[Attribute(Attribute::TARGET_METHOD)]
class PreExport
{
}
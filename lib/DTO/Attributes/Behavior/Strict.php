<?php

namespace Local\Lib\DTO\Attributes\Behavior;

use Attribute;

/**
 * Включает "Строгий режим" для DTO.
 * Если во входящем массиве есть ключи, которые не маппятся ни на одно свойство,
 * будет выброшено исключение UnmappedPropertiesException.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Strict
{
}
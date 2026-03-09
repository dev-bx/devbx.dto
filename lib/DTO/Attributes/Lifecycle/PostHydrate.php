<?php

namespace DevBX\DTO\Attributes\Lifecycle;

use Attribute;

/**
 * Метод, помеченный этим атрибутом, будет автоматически вызван
 * сразу после завершения гидратации объекта (fromArray).
 */
#[Attribute(Attribute::TARGET_METHOD)]
class PostHydrate
{
}
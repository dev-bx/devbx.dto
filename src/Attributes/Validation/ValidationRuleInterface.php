<?php

namespace DevBX\DTO\Attributes\Validation;

use DevBX\DTO\Validation\ValidationError;

interface ValidationRuleInterface
{
    /**
     * Валидирует значение свойства.
     * Возвращает ValidationError при ошибке или null, если значение валидно.
     * * @param mixed $value Фактическое значение свойства после гидратации.
     */
    public function validate(mixed $value): ?ValidationError;
}